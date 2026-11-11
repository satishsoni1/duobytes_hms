import 'dart:convert';

import 'package:sqflite_common/sqlite_api.dart';

import '../../features/doctor/data/clinical.dart';
import '../../features/opd/registration.dart';
import '../db/app_database.dart';

/// What clinic devices share with each other over the hotspot / local Wi-Fi, without internet:
///
/// * patients and OPD visits registered on a device and not uploaded yet (negative local ids),
/// * today's OPD queue state (who is waiting / with the doctor / seen),
/// * vitals recorded on a device and not uploaded yet (triage),
/// * the server ids a device got for its uploads, so others can re-link what they recorded
///   against those patients and visits (e.g. the doctor's prescription for a patient reception registered).
///
/// Only the device that created a record uploads it to the cloud; copies received from other devices
/// are marked `foreign` and are never queued for upload. Every exchange sends the full current set
/// (it is small: one clinic day), and merging is idempotent.
class LanStore {
  LanStore(this.adb);
  final AppDatabase adb;
  Database get db => adb.db;

  static const _ownMapKey = 'lan_own_id_map';
  static const _knownMapKey = 'lan_known_id_map';

  /// Remember the server id given to one of this device's local records (called from sync results).
  static Future<void> recordOwnMapping(DatabaseExecutor tx, String kind, int localId, int serverId) async {
    final r = await tx.query('meta', where: 'key = ?', whereArgs: [_ownMapKey]);
    final m = r.isEmpty ? <String, dynamic>{} : jsonDecode(r.first['value'] as String) as Map<String, dynamic>;
    m['$kind:$localId'] = serverId;
    // Keep the newest 2000 mappings.
    final trimmed = Map.fromEntries(m.entries.toList().reversed.take(2000).toList().reversed);
    await tx.insert('meta', {'key': _ownMapKey, 'value': jsonEncode(trimmed)}, conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<Map<String, int>> _map(DatabaseExecutor tx, String key) async {
    final r = await tx.query('meta', where: 'key = ?', whereArgs: [key]);
    if (r.isEmpty) return {};
    return (jsonDecode(r.first['value'] as String) as Map).map((k, v) => MapEntry('$k', (v as num).toInt()));
  }

  /// Everything this device shares right now.
  Future<Map<String, dynamic>> snapshot({DateTime? now}) async {
    final today = ymd(now ?? DateTime.now());
    final yesterday = ymd((now ?? DateTime.now()).subtract(const Duration(days: 1)));
    List<Map<String, dynamic>> js(List<Map<String, Object?>> rows) => [for (final r in rows) jsonDecode(r['json'] as String) as Map<String, dynamic>];
    final patients = js(await db.query('patients', columns: ['json'], where: 'id < 0'));
    final visits = js(await db.query('visits', columns: ['json'], where: 'id < 0'));
    final vitals = js(await db.query('vitals', columns: ['json'], where: 'id < 0'));
    final queue = [
      for (final r in await db.query('clinical_records', where: "type = 'opd_status' AND deleted = 0 AND substr(event_date, 1, 10) IN (?, ?)", whereArgs: [today, yesterday]))
        ClinicalRecord.fromRow(r).toPushPayload()..['updated_at'] = r['updated_at'],
    ];
    final map = {...await _map(db, _knownMapKey), ...await _map(db, _ownMapKey)};
    return {'device': await RegistrationRepository.devicePrefix(db), 'patients': patients, 'visits': visits, 'vitals': vitals, 'queue': queue, 'id_map': map};
  }

  /// Merge what another device shared. Returns the number of rows that changed.
  Future<int> merge(Map<String, dynamic> s) async {
    var changed = 0;
    await db.transaction((tx) async {
      // 1. Id mappings first, so records below can be translated.
      final known = await _map(tx, _knownMapKey);
      final own = await _map(tx, _ownMapKey);
      final incoming = ((s['id_map'] as Map?) ?? const {}).map((k, v) => MapEntry('$k', (v as num).toInt()));
      for (final e in incoming.entries) {
        if (own.containsKey(e.key) || known[e.key] == e.value) continue;
        known[e.key] = e.value;
        final parts = e.key.split(':');
        final from = int.parse(parts[1]);
        if (parts[0] == 'p') {
          if ((await tx.query('patients', columns: ['id'], where: 'id = ?', whereArgs: [from])).isNotEmpty) {
            await RegistrationRepository.remapPatient(tx, from, e.value);
            changed++;
          }
        } else if (parts[0] == 'v') {
          final row = await tx.query('visits', columns: ['json'], where: 'id = ?', whereArgs: [from]);
          if (row.isNotEmpty) {
            final hasReal = (await tx.query('visits', columns: ['id'], where: 'id = ?', whereArgs: [e.value])).isNotEmpty;
            if (!hasReal) {
              // Keep the copy under its real id until the cloud pull brings the server version.
              final j = jsonDecode(row.first['json'] as String) as Map<String, dynamic>..['id'] = e.value;
              await tx.insert('visits', {'id': e.value, 'patient_id': j['patient_id'], 'opd_id': j['opd_id'], 'doctor_id': j['doctor_id'], 'appointment_date': j['appointment_date'], 'json': jsonEncode(j)},
                  conflictAlgorithm: ConflictAlgorithm.replace);
            }
            await RegistrationRepository.remapVisit(tx, from, e.value);
            changed++;
          }
        }
      }
      await tx.insert('meta', {'key': _knownMapKey, 'value': jsonEncode(known)}, conflictAlgorithm: ConflictAlgorithm.replace);
      final map = {...known, ...own};
      int pid(Object? v) {
        final n = (v as num).toInt();
        return n < 0 ? (map['p:$n'] ?? n) : n;
      }

      Future<bool> skip(String kind, int id) async => id >= 0 || map.containsKey('$kind:$id') || await RegistrationRepository.isOwnLocalId(tx, id);

      // 2. Patients and visits registered on other devices.
      for (final p in ((s['patients'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map))) {
        final id = (p['id'] as num).toInt();
        if (await skip('p', id)) continue;
        final j = {...p, 'foreign': true}..remove('upload_error');
        final values = {'name': '${j['name'] ?? ''}', 'search': searchKey([j['name'], j['mobileno'], j['guardian_name'], j['identification_number']]), 'json': jsonEncode(j)};
        if (await tx.update('patients', values, where: 'id = ?', whereArgs: [id]) == 0) {
          await tx.insert('patients', {'id': id, 'pinned': 1, 'updated_at': DateTime.now().toIso8601String(), ...values});
        }
        changed++;
      }
      for (final v in ((s['visits'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map))) {
        final id = (v['id'] as num).toInt();
        if (await skip('v', id)) continue;
        final j = {...v, 'foreign': true, 'patient_id': pid(v['patient_id'])}..remove('upload_error');
        await tx.insert('visits', {'id': id, 'patient_id': j['patient_id'], 'opd_id': j['opd_id'], 'doctor_id': j['doctor_id'], 'appointment_date': j['appointment_date'], 'json': jsonEncode(j)},
            conflictAlgorithm: ConflictAlgorithm.replace);
        changed++;
      }
      // 3. Queue state: the latest change wins.
      for (final q in ((s['queue'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map))) {
        final data = Map<String, dynamic>.from(q['data'] as Map? ?? const {});
        var visitId = (data['visit_id'] as num?)?.toInt();
        if (visitId == null) continue;
        if (visitId < 0 && map['v:$visitId'] != null) continue; // the server has the real record now
        final mine = await tx.query('clinical_records', columns: ['json'], where: 'uuid = ?', whereArgs: [q['uuid']]);
        if (mine.isNotEmpty) {
          final at = '${(jsonDecode(mine.first['json'] as String) as Map)['at'] ?? ''}';
          if (at.compareTo('${data['at'] ?? ''}') >= 0) continue;
        }
        // Received copies are never uploaded by this device: the device that changed it uploads it.
        await tx.insert(
            'clinical_records',
            {
              'uuid': q['uuid'],
              'patient_id': pid(q['patient_id']),
              'type': RecordType.opdStatus,
              'event_date': q['event_date'],
              'status': q['status'] ?? 'active',
              'deleted': 0,
              'read_only': 0,
              'pending': 0,
              'local_rev': 0,
              'error': null,
              'updated_at': q['updated_at'],
              'updated_by_name': null,
              'json': jsonEncode(data),
            },
            conflictAlgorithm: ConflictAlgorithm.replace);
        await tx.delete('outbox', where: "type = 'record.save' AND ref_local_id = ?", whereArgs: [q['uuid']]);
        changed++;
      }
      // 4. Triage vitals not uploaded yet by the device that took them.
      for (final v in ((s['vitals'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map))) {
        final id = (v['id'] as num).toInt();
        if (await skip('vital', id)) continue;
        final exists = (await tx.query('vitals', columns: ['id'], where: 'id = ?', whereArgs: [id])).isNotEmpty;
        if (exists) continue;
        final j = {...v, 'foreign': true, 'origin': s['device'], 'patient_id': pid(v['patient_id'])};
        await tx.insert('vitals', {'id': id, 'patient_id': j['patient_id'], 'measured_at': j['measured_at'], 'json': jsonEncode(j)});
        changed++;
      }
      // Vitals another device has since uploaded (no longer in its snapshot) are dropped here; the
      // cloud pull brings the server copy.
      final theirs = {for (final v in ((s['vitals'] as List?) ?? const [])) ((v as Map)['id'] as num).toInt()};
      final origin = s['device'];
      if (origin != null) {
        for (final r in await tx.query('vitals', columns: ['id', 'json'], where: 'id < 0')) {
          final j = jsonDecode(r['json'] as String) as Map;
          if (j['foreign'] == true && j['origin'] == origin && !theirs.contains(r['id'])) {
            await tx.delete('vitals', where: 'id = ?', whereArgs: [r['id']]);
            changed++;
          }
        }
      }
    });
    return changed;
  }
}
