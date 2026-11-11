import 'dart:convert';

import 'package:sqflite_common/sqlite_api.dart';
import 'package:uuid/uuid.dart';

import '../../core/db/app_database.dart';
import '../doctor/data/clinical.dart';
import '../doctor/data/models.dart';
import '../doctor/data/queue.dart';
import '../opd/registration.dart';

/// Data for the reception, nursing, lab and pharmacy sections. Role lists (bed board, lab worklist,
/// stock, vital types) arrive whole with every pull as `extras`; writes are queued like everything else.

class Bed {
  Bed(this.j);
  final Map<String, dynamic> j;
  int get id => (j['id'] as num).toInt();
  String get name => '${j['name'] ?? ''}';
  String? get type => j['type'] as String?;
  String get ward => '${j['ward'] ?? 'Ward'}';
  String? get floor => j['floor'] as String?;
  int? get ipdId => (j['ipd_id'] as num?)?.toInt();
  int? get patientId => (j['patient_id'] as num?)?.toInt();
  String? get patientName => j['patient_name'] as String?;
  String? get doctorName => (j['doctor_name'] as String?)?.trim().isEmpty == true ? null : j['doctor_name'] as String?;
  DateTime? get admittedOn => parseServerDate(j['admitted_on']);
  bool get occupied => ipdId != null;
}

class VitalType {
  VitalType(this.j);
  final Map<String, dynamic> j;
  int get id => (j['id'] as num).toInt();
  String get name => '${j['name'] ?? ''}'.trim();
  String? get unit => (j['unit'] as String?)?.trim();
  String? get range => (j['reference_range'] as String?)?.replaceAll(RegExp(r'\s+'), ' ').trim();
}

class LabParamEntry {
  LabParamEntry(this.j);
  final Map<String, dynamic> j;
  int get detailId => (j['detail_id'] as num).toInt();
  String get name => '${j['name'] ?? ''}';
  String? get unit => j['unit'] as String?;
  String? get range => j['reference_range'] as String?;
  String get value => '${j['value'] ?? ''}';
}

class LabOrder {
  LabOrder(this.j);
  final Map<String, dynamic> j;
  int get id => (j['id'] as num).toInt();
  int? get patientId => (j['patient_id'] as num?)?.toInt();
  String get patientName => '${j['patient_name'] ?? 'Patient'}';
  String get testName => '${j['test_name'] ?? 'Test'}';
  DateTime? get collected => parseServerDate(j['collection_date']);
  DateTime? get reported => parseServerDate(j['reporting_date']);
  DateTime? get ordered => parseServerDate(j['created_at']);
  String? get result => j['result'] as String?;
  bool get pending => reported == null;
  bool get uploading => j['uploading'] == true;
  String? get error => j['upload_error'] as String?;
  List<LabParamEntry> get parameters => ((j['parameters'] as List?) ?? const []).map((e) => LabParamEntry(Map<String, dynamic>.from(e as Map))).toList();
  int get filled => parameters.where((p) => p.value.trim().isNotEmpty).length;
}

class StockItem {
  StockItem(this.j);
  final Map<String, dynamic> j;
  int get id => (j['id'] as num).toInt();
  String get name => '${j['name'] ?? ''}';
  String? get category => j['category'] as String?;
  int get available => (j['available'] as num?)?.toInt() ?? 0;
  int get expired => (j['expired'] as num?)?.toInt() ?? 0;
  int get expiring => (j['expiring'] as num?)?.toInt() ?? 0;
  int? get minLevel => (j['min_level'] as num?)?.toInt();
  int? get reorderLevel => (j['reorder_level'] as num?)?.toInt();
  DateTime? get nextExpiry => parseServerDate(j['next_expiry']);
  double? get saleRate => (j['sale_rate'] as num?)?.toDouble();

  bool get outOfStock => available <= 0;
  bool get low => !outOfStock && available <= (reorderLevel ?? minLevel ?? 0);
}

class PendingNote {
  PendingNote(this.opId, this.p, {this.error});
  final String opId;
  final Map<String, dynamic> p;
  final String? error;
  int get patientId => (p['patient_id'] as num).toInt();
  String get note => '${p['note'] ?? ''}';
  DateTime? get date => parseServerDate(p['date']);
}

String _dt(DateTime d) => '${ymd(d)} ${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}:00';

class StaffRepository {
  StaffRepository(this.adb);
  final AppDatabase adb;
  static const _uuid = Uuid();
  Database get db => adb.db;

  Future<Map<String, dynamic>> extras() async => await adb.lookup('extras') ?? const {};

  Future<List<Bed>> beds() async => [for (final b in (await extras())['beds'] as List? ?? const []) Bed(Map<String, dynamic>.from(b as Map))];
  Future<List<VitalType>> vitalTypes() async => [for (final v in (await extras())['vital_types'] as List? ?? const []) VitalType(Map<String, dynamic>.from(v as Map))];
  Future<List<LabOrder>> labWorklist() async => [for (final v in (await extras())['lab_worklist'] as List? ?? const []) LabOrder(Map<String, dynamic>.from(v as Map))];
  Future<List<StockItem>> stock() async => [for (final v in (await extras())['pharmacy_stock'] as List? ?? const []) StockItem(Map<String, dynamic>.from(v as Map))];

  /// Every doctor's OPD queue for [day] (reception and triage).
  Future<({List<QueueEntry> queue, Map<int, String> doctors})> queueAll(DateTime day) async {
    final rows = await db.query('visits', columns: ['json'], where: 'substr(appointment_date, 1, 10) = ?', whereArgs: [ymd(day)], orderBy: 'appointment_date');
    final opd = [for (final r in rows) Encounter.visit(jsonDecode(r['json'] as String) as Map<String, dynamic>)];
    final pids = opd.map((e) => e.patientId).toSet();
    final patients = <int, Patient>{};
    if (pids.isNotEmpty) {
      for (final r in await db.query('patients', columns: ['json', 'pinned'], where: 'id IN (${pids.join(',')})')) {
        final p = Patient(jsonDecode(r['json'] as String) as Map<String, dynamic>, pinned: r['pinned'] == 1);
        patients[p.id] = p;
      }
    }
    final statusRows = await db.query('clinical_records', where: "type = 'opd_status' AND deleted = 0 AND substr(event_date, 1, 10) = ?", whereArgs: [ymd(day)]);
    final status = <int, ClinicalRecord>{
      for (final r in statusRows.map(ClinicalRecord.fromRow))
        if (r.data['visit_id'] != null) (r.data['visit_id'] as num).toInt(): r,
    };
    final rx = (await db.rawQuery("SELECT DISTINCT kind || ':' || case_ref_id k FROM prescriptions")).map((r) => r['k'] as String).toSet();
    final queue = buildQueue(opd: opd, patients: patients, statusByVisit: status, hasRx: (e) => rx.contains(e.key));
    return (queue: queue, doctors: {for (final e in opd) if (e.doctorId != null) e.doctorId!: e.doctorName ?? 'Doctor'});
  }

  Future<List<Encounter>> admitted() async {
    final rows = await db.query('admissions', columns: ['json'], where: "COALESCE(discharged, 'no') <> 'yes'", orderBy: 'date DESC');
    return [for (final r in rows) Encounter.admission(jsonDecode(r['json'] as String) as Map<String, dynamic>)];
  }

  Future<void> _queue(DatabaseExecutor tx, String type, String ref, Map<String, dynamic> payload, {String? opId}) => tx.insert('outbox', {
        'op_id': opId ?? _uuid.v4(),
        'type': type,
        'ref_local_id': ref,
        'payload': jsonEncode(payload),
        'attempts': 0,
        'created_at': DateTime.now().toIso8601String(),
      });

  /// Record vital signs. They show on the patient straight away and upload on the next sync.
  Future<void> recordVitals(int patientId, Map<VitalType, String> values, {DateTime? at}) async {
    final when = _dt(at ?? DateTime.now());
    final filled = {for (final e in values.entries) if (e.value.trim().isNotEmpty) e.key: e.value.trim()};
    if (filled.isEmpty) throw ArgumentError('Enter at least one reading');
    await db.transaction((tx) async {
      var next = await RegistrationRepository.nextLocalId(tx, 'vitals');
      final localIds = <int>[];
      for (final e in filled.entries) {
        final id = next--;
        localIds.add(id);
        await tx.insert('vitals', {
          'id': id,
          'patient_id': patientId,
          'measured_at': when,
          'json': jsonEncode({'id': id, 'patient_id': patientId, 'name': e.key.name, 'value': e.value, 'unit': e.key.unit, 'normal_range': e.key.range, 'measured_at': when, 'local': true}),
        });
      }
      await _queue(tx, 'vitals.save', '$patientId', {
        'patient_id': patientId,
        'measured_at': when,
        'values': {for (final e in filled.entries) '${e.key.id}': e.value},
        'local_ids': localIds,
      });
    });
  }

  Future<void> addNurseNote({required int patientId, required int ipdId, required String note, String comment = ''}) async {
    if (note.trim().isEmpty) throw ArgumentError('Write the note first');
    await db.transaction((tx) => _queue(tx, 'nurse_note.save', '$patientId', {
          'patient_id': patientId,
          'ipd_id': ipdId,
          'date': _dt(DateTime.now()),
          'note': note.trim(),
          'comment': comment.trim(),
        }));
  }

  /// Notes written on this device that are not on the server yet (or were refused).
  Future<List<PendingNote>> pendingNotes(int patientId) async {
    final rows = await db.query('outbox', where: "type = 'nurse_note.save' AND ref_local_id = ?", whereArgs: ['$patientId'], orderBy: 'seq DESC');
    final refused = ((await adb.lookup('refused_notes'))?['items'] as List? ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).where((e) => e['patient_id'] == patientId);
    return [
      for (final r in rows) PendingNote(r['op_id'] as String, jsonDecode(r['payload'] as String) as Map<String, dynamic>),
      for (final e in refused) PendingNote('${e['op_id']}', e, error: '${e['error']}'),
    ];
  }

  /// Save lab results (draft or final). The worklist copy updates at once; one upload per report.
  Future<void> saveLabResult(LabOrder order, Map<int, String> values, {String? result, required bool complete}) async {
    await db.transaction((tx) async {
      await updateLabCopy(tx, order.id, (j) {
        for (final p in (j['parameters'] as List)) {
          final m = p as Map;
          final v = values[(m['detail_id'] as num).toInt()];
          if (v != null) m['value'] = v;
        }
        if (result != null) j['result'] = result;
        if (complete) j['reporting_date'] = ymd(DateTime.now());
        j['uploading'] = true;
        j.remove('upload_error');
      });
      await tx.delete('outbox', where: "type = 'lab.result' AND ref_local_id = ? AND attempts = 0", whereArgs: ['${order.id}']);
      await _queue(tx, 'lab.result', '${order.id}', {
        'report_id': order.id,
        'values': {for (final e in values.entries) '${e.key}': e.value},
        'result': ?result,
        'complete': complete,
      });
    });
  }

  static Future<void> updateLabCopy(DatabaseExecutor tx, int reportId, void Function(Map<String, dynamic>) f) async {
    final r = await tx.query('lookups', where: 'key = ?', whereArgs: ['extras']);
    if (r.isEmpty) return;
    final x = jsonDecode(r.first['json'] as String) as Map<String, dynamic>;
    for (final o in (x['lab_worklist'] as List? ?? const [])) {
      if ((o as Map)['id'] == reportId) f(o.cast<String, dynamic>());
    }
    await tx.update('lookups', {'json': jsonEncode(x)}, where: 'key = ?', whereArgs: ['extras']);
  }

  // ================================================================== sync helpers
  /// Store a pull's role lists; lab results still waiting to upload stay on top of the server copy.
  static Future<void> applyExtras(Transaction tx, Map<String, dynamic>? extras) async {
    if (extras == null) return;
    await tx.insert('lookups', {'key': 'extras', 'json': jsonEncode(extras)}, conflictAlgorithm: ConflictAlgorithm.replace);
    for (final r in await tx.query('outbox', columns: ['payload'], where: "type = 'lab.result'", orderBy: 'seq')) {
      final p = jsonDecode(r['payload'] as String) as Map<String, dynamic>;
      await updateLabCopy(tx, (p['report_id'] as num).toInt(), (j) {
        final values = Map<String, dynamic>.from(p['values'] as Map);
        for (final m in (j['parameters'] as List)) {
          final v = values['${(m as Map)['detail_id']}'];
          if (v != null) m['value'] = v;
        }
        if (p['result'] != null) j['result'] = p['result'];
        if (p['complete'] == true) j['reporting_date'] ??= ymd(DateTime.now());
        j['uploading'] = true;
      });
    }
  }

  static Future<void> applyResult(Transaction tx, String type, Map<String, dynamic> payload, String opId, Map<String, dynamic> result) async {
    final ok = result['status'] == 'ok';
    switch (type) {
      case 'vitals.save':
        final ids = ((payload['local_ids'] as List?) ?? const []).map((e) => (e as num).toInt()).toList();
        if (ok) {
          if (ids.isNotEmpty) await tx.delete('vitals', where: 'id IN (${ids.join(',')})');
        } else {
          for (final id in ids) {
            final r = await tx.query('vitals', columns: ['json'], where: 'id = ?', whereArgs: [id]);
            if (r.isEmpty) continue;
            final j = jsonDecode(r.first['json'] as String) as Map<String, dynamic>..['upload_error'] = result['message'];
            await tx.update('vitals', {'json': jsonEncode(j)}, where: 'id = ?', whereArgs: [id]);
          }
        }
      case 'nurse_note.save':
        if (!ok) {
          final cur = (await _lookup(tx, 'refused_notes'))?['items'] as List? ?? const [];
          await tx.insert('lookups', {'key': 'refused_notes', 'json': jsonEncode({'items': [...cur, {...payload, 'op_id': opId, 'error': result['message']}]})},
              conflictAlgorithm: ConflictAlgorithm.replace);
        }
      case 'lab.result':
        await updateLabCopy(tx, (payload['report_id'] as num).toInt(), (j) {
          j.remove('uploading');
          if (!ok) j['upload_error'] = result['message'] ?? 'Not accepted by the server';
        });
    }
  }

  static Future<Map<String, dynamic>?> _lookup(DatabaseExecutor tx, String key) async {
    final r = await tx.query('lookups', where: 'key = ?', whereArgs: [key]);
    return r.isEmpty ? null : jsonDecode(r.first['json'] as String) as Map<String, dynamic>;
  }

  static Future<void> dismissRefusedNote(AppDatabase adb, String opId) async {
    final cur = ((await adb.lookup('refused_notes'))?['items'] as List? ?? const []).where((e) => (e as Map)['op_id'] != opId).toList();
    await adb.db.insert('lookups', {'key': 'refused_notes', 'json': jsonEncode({'items': cur})}, conflictAlgorithm: ConflictAlgorithm.replace);
  }

  /// Ops carrying a patient id in their payload, rewritten when a local patient gets its server id.
  static const patientPayloadOps = ['vitals.save', 'nurse_note.save'];
}
