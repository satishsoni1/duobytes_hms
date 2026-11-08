import 'dart:convert';

import 'package:sqflite_common/sqlite_api.dart';
import 'package:uuid/uuid.dart';

import '../../../core/api/api_client.dart';
import '../../../core/db/app_database.dart';
import '../../../core/sync/sync_module.dart';
import 'billing.dart';
import 'clinical.dart';
import 'models.dart';

/// Doctor module's part of sync: masters, patient history pull, prescription & template pushes.
class DoctorSyncModule implements SyncModule {
  static const _uuid = Uuid();

  // ================================================================== masters
  @override
  Future<void> pullMasters(ApiClient api, AppDatabase adb) async {
    final etag = await adb.getMeta('masters_etag');
    final res = await api.getIfChanged('sync/masters', etag);
    if (res == null) return; // unchanged
    await applyMasters(adb, res.body);
    await adb.setMeta('masters_etag', res.etag);
  }

  static Future<void> applyMasters(AppDatabase adb, Map<String, dynamic> m) async {
    await adb.db.transaction((tx) async {
      await tx.delete('medicines');
      await tx.delete('icd');
      final b = tx.batch();
      for (final e in (m['medicines'] as List? ?? const [])) {
        b.insert('medicines', {
          'id': e['id'],
          'name': e['name'] ?? '',
          'composition': e['composition'],
          'company': e['company'],
          'category_id': e['category_id'],
          'category': e['category'],
          'search': searchKey([e['name'], e['composition'], e['company']]),
        });
      }
      for (final e in (m['icd'] as List? ?? const [])) {
        b.insert('icd', {'id': e['id'], 'code': e['code'], 'description': e['description'], 'search': searchKey([e['code'], e['description']])},
            conflictAlgorithm: ConflictAlgorithm.replace);
      }
      b.insert('lookups', {'key': 'charges', 'json': jsonEncode({'items': m['charges'] ?? const []})}, conflictAlgorithm: ConflictAlgorithm.replace);
      b.insert(
          'lookups',
          {
            'key': 'server_settings',
            'json': jsonEncode({'staff_settings': m['staff_settings'] ?? const {}, 'has_print_header': m['has_print_header'] == true}),
          },
          conflictAlgorithm: ConflictAlgorithm.replace);
      b.insert(
          'lookups',
          {
            'key': 'masters',
            'json': jsonEncode({
              for (final k in const ['dosages', 'intervals', 'durations', 'pathology', 'radiology', 'frequent_medicine_ids']) k: m[k] ?? const [],
            }),
          },
          conflictAlgorithm: ConflictAlgorithm.replace);

      // Server templates replace the synced copies; templates still waiting to upload are kept.
      b.delete('templates', where: 'status = ?', whereArgs: [RxStatus.synced.name]);
      for (final t in (m['templates'] as List? ?? const [])) {
        b.insert(
            'templates',
            {
              'local_id': 'srv-${t['id']}',
              'server_id': t['id'],
              'name': t['name'] ?? '',
              'shared': t['shared'] == true ? 1 : 0,
              'mine': t['mine'] == true ? 1 : 0,
              'status': RxStatus.synced.name,
              'json': jsonEncode(t['payload'] ?? const {}),
            },
            conflictAlgorithm: ConflictAlgorithm.replace);
      }
      await b.commit(noResult: true);
    });
  }

  // ================================================================== pull
  @override
  Future<void> pull(ApiClient api, AppDatabase adb) async {
    final since = await adb.getMeta('cursor');
    final known = (await adb.db.query('patients', columns: ['id'])).map((r) => r['id'] as int).toList();
    final pinned = (await adb.db.query('patients', columns: ['id'], where: 'pinned = 1')).map((r) => r['id'] as int).toList();
    final body = await api.post('sync/pull', {
      'since': since,
      'known_patient_ids': since == null ? const <int>[] : known,
      'pinned_patient_ids': pinned,
    });
    await applyPull(adb, body);
  }

  static Future<void> applyPull(AppDatabase adb, Map<String, dynamic> body) async {
    final data = Map<String, dynamic>.from(body['data'] as Map? ?? const {});
    final scope = ((body['scope_patient_ids'] as List?) ?? const []).map((e) => (e as num).toInt()).toSet();
    final alive = ((body['prescription_ids'] as List?) ?? const []).map((e) => (e as num).toInt()).toSet();

    await adb.db.transaction((tx) async {
      await applyPatientData(tx, data);

      final appts = tx.batch();
      for (final a in (body['appointments'] as List? ?? const [])) {
        appts.insert('appointments', {'id': a['id'], 'patient_id': a['patient_id'], 'date': a['date'], 'json': jsonEncode(a)},
            conflictAlgorithm: ConflictAlgorithm.replace);
      }
      final cutoff = DateTime.now().subtract(const Duration(days: 31)).toIso8601String().substring(0, 10);
      appts.delete('appointments', where: 'date < ?', whereArgs: [cutoff]);
      await appts.commit(noResult: true);

      // Prescriptions deleted on the web (only synced copies; local edits are kept until resolved).
      final synced = await tx.query('prescriptions', columns: ['local_id', 'server_id', 'patient_id'],
          where: 'server_id IS NOT NULL AND status = ?', whereArgs: [RxStatus.synced.name]);
      for (final r in synced) {
        if (scope.contains(r['patient_id']) && !alive.contains(r['server_id'])) {
          await tx.delete('prescriptions', where: 'local_id = ?', whereArgs: [r['local_id']]);
        }
      }

      // Patients no longer assigned to this doctor leave the device, unless they have unsent work.
      final local = (await tx.query('patients', columns: ['id'])).map((r) => r['id'] as int);
      for (final pid in local.where((id) => !scope.contains(id)).toList()) {
        final unsent = await tx.query('prescriptions', columns: ['local_id'], where: 'patient_id = ? AND status <> ?', whereArgs: [pid, RxStatus.synced.name], limit: 1);
        final unsentRecords = await tx.query('clinical_records', columns: ['uuid'], where: 'patient_id = ? AND pending = 1', whereArgs: [pid], limit: 1);
        if (unsent.isEmpty && unsentRecords.isEmpty) await _deletePatient(tx, pid);
      }

      await adb.setMeta('cursor', body['cursor'] as String?, tx);
    });
  }

  /// Upsert patients and their history. Used by pull and by "keep this patient offline" (bundle).
  static Future<void> applyPatientData(Transaction tx, Map<String, dynamic> data, {bool pin = false}) async {
    List<Map<String, dynamic>> rows(String k) => ((data[k] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();

    for (final p in rows('patients')) {
      final values = {
        'name': p['name'] ?? '',
        'search': searchKey([p['name'], p['mobileno'], p['id'], p['identification_number'], p['guardian_name']]),
        'updated_at': p['updated_at'],
        'json': jsonEncode(p),
      };
      final n = await tx.update('patients', {...values, if (pin) 'pinned': 1}, where: 'id = ?', whereArgs: [p['id']]);
      if (n == 0) await tx.insert('patients', {'id': p['id'], 'pinned': pin ? 1 : 0, ...values});
    }

    final b = tx.batch();
    for (final v in rows('visits')) {
      b.insert('visits', {'id': v['id'], 'patient_id': v['patient_id'], 'opd_id': v['opd_id'], 'doctor_id': v['doctor_id'], 'appointment_date': v['appointment_date'], 'json': jsonEncode(v)},
          conflictAlgorithm: ConflictAlgorithm.replace);
    }
    for (final a in rows('admissions')) {
      b.insert('admissions', {'id': a['id'], 'patient_id': a['patient_id'], 'doctor_id': a['doctor_id'], 'discharged': a['discharged'], 'date': a['date'], 'json': jsonEncode(a)},
          conflictAlgorithm: ConflictAlgorithm.replace);
    }
    for (final d in rows('diagnoses')) {
      b.insert('diagnoses', {'kind': d['kind'], 'case_id': d['case_id'], 'json': jsonEncode(d['codes'] ?? const [])}, conflictAlgorithm: ConflictAlgorithm.replace);
    }
    for (final v in rows('vitals')) {
      b.insert('vitals', {'id': v['id'], 'patient_id': v['patient_id'], 'measured_at': v['measured_at'], 'json': jsonEncode(v)}, conflictAlgorithm: ConflictAlgorithm.replace);
    }
    for (final l in rows('lab_reports')) {
      b.insert('lab_reports', {'kind': l['kind'], 'id': l['id'], 'patient_id': l['patient_id'], 'reporting_date': l['reporting_date'], 'json': jsonEncode(l)},
          conflictAlgorithm: ConflictAlgorithm.replace);
    }
    for (final t in rows('timeline')) {
      b.insert('timeline', {'id': t['id'], 'patient_id': t['patient_id'], 'timeline_date': t['timeline_date'], 'json': jsonEncode(t)}, conflictAlgorithm: ConflictAlgorithm.replace);
    }
    await b.commit(noResult: true);

    await ClinicalRepository.applyServer(tx, rows('clinical_records'));
    await BillingRepository.applyServer(tx, rows('billing'));

    final rx = rows('prescriptions');
    if (rx.isEmpty) return;
    final existing = {
      for (final r in await tx.query('prescriptions', columns: ['local_id', 'server_id', 'status'], where: 'server_id IS NOT NULL'))
        r['server_id'] as int: r,
    };
    for (final p in rx) {
      await upsertServerPrescription(tx, p, existing[p['id']]);
    }
  }

  /// Store a prescription as the server has it. A copy with unsent local edits keeps the edits
  /// (the server copy is remembered for "discard changes" and conflict resolution).
  static Future<void> upsertServerPrescription(Transaction tx, Map<String, dynamic> p, Map<String, Object?>? existing) async {
    final kind = p['ipd_id'] != null ? 'ipd' : 'opd';
    final serverJson = jsonEncode(p);
    if (existing == null) {
      await tx.insert('prescriptions', {
        'local_id': 'srv-${p['id']}',
        'server_id': p['id'],
        'patient_id': p['patient_id'],
        'kind': kind,
        'case_ref_id': kind == 'ipd' ? p['ipd_id'] : p['visit_id'],
        'date': p['date'],
        'status': RxStatus.synced.name,
        'version': p['version'],
        'local_rev': 0,
        'json': serverJson,
        'server_json': serverJson,
      }, conflictAlgorithm: ConflictAlgorithm.replace);
    } else if (existing['status'] == RxStatus.synced.name) {
      await tx.update('prescriptions', {'date': p['date'], 'version': p['version'], 'json': serverJson, 'server_json': serverJson, 'patient_id': p['patient_id']},
          where: 'local_id = ?', whereArgs: [existing['local_id']]);
    } else {
      await tx.update('prescriptions', {'server_json': serverJson}, where: 'local_id = ?', whereArgs: [existing['local_id']]);
    }
  }

  static Future<void> _deletePatient(Transaction tx, int pid) async {
    for (final v in await tx.query('visits', columns: ['opd_id'], where: 'patient_id = ?', whereArgs: [pid])) {
      await tx.delete('diagnoses', where: "kind = 'opd' AND case_id = ?", whereArgs: [v['opd_id']]);
    }
    for (final a in await tx.query('admissions', columns: ['id'], where: 'patient_id = ?', whereArgs: [pid])) {
      await tx.delete('diagnoses', where: "kind = 'ipd' AND case_id = ?", whereArgs: [a['id']]);
    }
    for (final t in const ['visits', 'admissions', 'prescriptions', 'vitals', 'lab_reports', 'timeline', 'appointments', 'clinical_records']) {
      await tx.delete(t, where: 'patient_id = ?', whereArgs: [pid]);
    }
    await tx.delete('patient_billing', where: 'patient_id = ?', whereArgs: [pid]);
    await tx.delete('patients', where: 'id = ?', whereArgs: [pid]);
  }

  // ================================================================== push
  @override
  bool handles(String opType) =>
      opType.startsWith('prescription.') || opType.startsWith('template.') || const {'record.save', 'charge.add', 'settings.save'}.contains(opType);

  @override
  Future<Map<String, dynamic>?> buildPayload(Transaction tx, OutboxOp op) async {
    if (op.type == 'record.save') return ClinicalRepository.buildPayload(tx, op.refLocalId!, op.seq);
    if (op.type == 'prescription.save') {
      final r = await tx.query('prescriptions', where: 'local_id = ?', whereArgs: [op.refLocalId]);
      if (r.isEmpty) return null;
      final p = Prescription.fromRow(r.first);
      await tx.update('outbox', {'sent_rev': r.first['local_rev']}, where: 'seq = ?', whereArgs: [op.seq]);
      return prescriptionPushPayload(p, force: r.first['force_save'] == 1);
    }
    final payload = op.payload == null ? null : jsonDecode(op.payload!) as Map<String, dynamic>;
    if (payload == null) return null;
    if (op.type == 'template.save') {
      final t = await tx.query('templates', columns: ['local_id'], where: 'local_id = ?', whereArgs: [op.refLocalId]);
      if (t.isEmpty) return null; // deleted before it was uploaded
    }
    return payload;
  }

  @override
  Future<void> applyResult(Transaction tx, OutboxOp op, Map<String, dynamic> result) async {
    final status = result['status'] as String;
    switch (op.type) {
      case 'record.save':
        await ClinicalRepository.applyResult(tx, op.refLocalId!, op.sentRev, result);
      case 'charge.add':
        await BillingRepository.applyResult(tx, int.parse(op.refLocalId!), op.opId, result);
      case 'prescription.save':
        await _applyPrescriptionResult(tx, op, status, result);
      case 'template.save':
        if (status == 'ok') {
          await tx.update('templates', {'server_id': result['server_id'], 'status': RxStatus.synced.name}, where: 'local_id = ?', whereArgs: [op.refLocalId]);
        } else {
          await tx.delete('templates', where: 'local_id = ?', whereArgs: [op.refLocalId]);
        }
      default:
        break; // template.delete: nothing to update locally
    }
  }

  Future<void> _applyPrescriptionResult(Transaction tx, OutboxOp op, String status, Map<String, dynamic> result) async {
    final rows = await tx.query('prescriptions', where: 'local_id = ?', whereArgs: [op.refLocalId]);
    if (rows.isEmpty) return; // discarded on the device meanwhile
    final row = rows.first;

    if (status == 'rejected') {
      await tx.update('prescriptions', {'status': RxStatus.rejected.name, 'error': result['message'], 'force_save': 0}, where: 'local_id = ?', whereArgs: [op.refLocalId]);
      return;
    }
    if (status == 'conflict') {
      final server = result['server'];
      await tx.update(
          'prescriptions',
          {
            'status': RxStatus.conflict.name,
            'error': result['message'],
            if (server != null) 'server_json': jsonEncode(server),
          },
          where: 'local_id = ?',
          whereArgs: [op.refLocalId]);
      return;
    }

    // ok
    final serverId = (result['server_id'] as num).toInt();
    final server = Map<String, dynamic>.from(result['prescription'] as Map);
    // If an earlier pull already brought this prescription down as a separate row
    // (the first upload's reply was lost), drop that duplicate.
    await tx.delete('prescriptions', where: 'server_id = ? AND local_id <> ?', whereArgs: [serverId, op.refLocalId]);

    final editedSinceSend = (row['local_rev'] as int) > (op.sentRev ?? 0);
    if (editedSinceSend) {
      // The doctor changed it again while it was uploading: keep their edits and send them as an update.
      await tx.update('prescriptions', {'server_id': serverId, 'version': server['version'], 'server_json': jsonEncode(server), 'force_save': 0},
          where: 'local_id = ?', whereArgs: [op.refLocalId]);
      await tx.insert('outbox', {
        'op_id': _uuid.v4(),
        'type': 'prescription.save',
        'ref_local_id': op.refLocalId,
        'attempts': 0,
        'created_at': DateTime.now().toIso8601String(),
      });
    } else {
      final json = jsonEncode(server);
      await tx.update(
          'prescriptions',
          {
            'server_id': serverId,
            'status': RxStatus.synced.name,
            'version': server['version'],
            'error': null,
            'force_save': 0,
            'date': server['date'],
            'json': json,
            'server_json': json,
          },
          where: 'local_id = ?',
          whereArgs: [op.refLocalId]);
    }
  }
}
