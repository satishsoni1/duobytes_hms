import 'dart:convert';

import 'package:sqflite_common/sqlite_api.dart';
import 'package:uuid/uuid.dart';

import '../../../core/db/app_database.dart';
import 'models.dart';

/// Record types shared with the server (Mobileclinical_model::TYPES).
class RecordType {
  static const followup = 'followup';
  static const vaccination = 'vaccination';
  static const growth = 'growth';
  static const pregnancy = 'pregnancy';
  static const opdStatus = 'opd_status'; // OPD queue state per visit
  static const obstetricHistory = 'obstetric_history'; // read-only, from the web antenatal module
}

/// A specialty clinical record: follow-up, vaccination, growth measurement, pregnancy, ...
class ClinicalRecord {
  ClinicalRecord({
    required this.uuid,
    required this.patientId,
    required this.type,
    this.eventDate,
    this.status = 'active',
    Map<String, dynamic>? data,
    this.deleted = false,
    this.readOnly = false,
    this.pending = false,
    this.error,
    this.updatedAt,
    this.updatedByName,
  }) : data = data ?? {};

  final String uuid;
  final int patientId;
  final String type;
  DateTime? eventDate;
  String status;
  final Map<String, dynamic> data;
  bool deleted;
  final bool readOnly;
  final bool pending;
  final String? error;
  final DateTime? updatedAt;
  final String? updatedByName;

  factory ClinicalRecord.create({required int patientId, required String type, DateTime? eventDate, String status = 'active', Map<String, dynamic>? data}) =>
      ClinicalRecord(uuid: const Uuid().v4(), patientId: patientId, type: type, eventDate: eventDate, status: status, data: data);

  factory ClinicalRecord.fromServer(Map<String, dynamic> j) => ClinicalRecord(
        uuid: j['uuid'] as String,
        patientId: (j['patient_id'] as num).toInt(),
        type: j['type'] as String,
        eventDate: parseServerDate(j['event_date']),
        status: (j['status'] as String?) ?? 'active',
        data: j['data'] is Map ? Map<String, dynamic>.from(j['data'] as Map) : {},
        deleted: j['deleted'] == true || j['deleted'] == 1,
        readOnly: j['read_only'] == true,
        updatedAt: parseServerDate(j['updated_at']),
        updatedByName: j['updated_by_name'] as String?,
      );

  factory ClinicalRecord.fromRow(Map<String, Object?> r) => ClinicalRecord(
        uuid: r['uuid'] as String,
        patientId: r['patient_id'] as int,
        type: r['type'] as String,
        eventDate: parseServerDate(r['event_date']),
        status: r['status'] as String,
        data: jsonDecode(r['json'] as String) as Map<String, dynamic>,
        deleted: r['deleted'] == 1,
        readOnly: r['read_only'] == 1,
        pending: r['pending'] == 1,
        error: r['error'] as String?,
        updatedAt: parseServerDate(r['updated_at']),
        updatedByName: r['updated_by_name'] as String?,
      );

  String? get eventYmd => eventDate == null ? null : ymd(eventDate!);

  Map<String, dynamic> toPushPayload() => {
        'uuid': uuid,
        'patient_id': patientId,
        'type': type,
        'event_date': eventYmd ?? '',
        'status': status,
        'data': data,
        'deleted': deleted,
      };

  String? str(String k) {
    final v = data[k];
    if (v == null) return null;
    final s = '$v'.trim();
    return s.isEmpty ? null : s;
  }

  double? num_(String k) => data[k] == null ? null : double.tryParse('${data[k]}');
  DateTime? date(String k) => parseServerDate(data[k]);
}

String ymd(DateTime d) => '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

/// Local store for specialty records; saves are queued as `record.save` ops for sync.
class ClinicalRepository {
  ClinicalRepository(this.adb);
  final AppDatabase adb;
  static const _uuid = Uuid();

  Database get db => adb.db;

  Future<List<ClinicalRecord>> forPatient(int patientId, {String? type}) async {
    final rows = await db.query('clinical_records',
        where: 'patient_id = ? AND deleted = 0${type == null ? '' : ' AND type = ?'}',
        whereArgs: [patientId, ?type],
        orderBy: 'COALESCE(event_date, updated_at) DESC');
    return rows.map(ClinicalRecord.fromRow).toList();
  }

  /// Pending follow-ups due on or before [until], across all patients on the phone.
  Future<List<ClinicalRecord>> followUpsDue(DateTime until) async {
    final rows = await db.query('clinical_records',
        where: "type = ? AND status = 'active' AND deleted = 0 AND event_date IS NOT NULL AND substr(event_date, 1, 10) <= ?",
        whereArgs: [RecordType.followup, ymd(until)],
        orderBy: 'event_date');
    return rows.map(ClinicalRecord.fromRow).toList();
  }

  Future<void> save(ClinicalRecord r) async {
    if (r.readOnly) throw StateError('Read-only record');
    await db.transaction((tx) async {
      final existing = await tx.query('clinical_records', columns: ['local_rev'], where: 'uuid = ?', whereArgs: [r.uuid]);
      final rev = existing.isEmpty ? 1 : (existing.first['local_rev'] as int) + 1;
      await tx.insert(
          'clinical_records',
          {
            'uuid': r.uuid,
            'patient_id': r.patientId,
            'type': r.type,
            'event_date': r.eventYmd,
            'status': r.status,
            'deleted': r.deleted ? 1 : 0,
            'read_only': 0,
            'pending': 1,
            'local_rev': rev,
            'error': null,
            'updated_at': DateTime.now().toIso8601String(),
            'updated_by_name': null,
            'json': jsonEncode(r.data),
          },
          conflictAlgorithm: ConflictAlgorithm.replace);
      final queued = await tx.query('outbox', columns: ['seq'], where: "type = 'record.save' AND ref_local_id = ?", whereArgs: [r.uuid]);
      if (queued.isEmpty) {
        await tx.insert('outbox', {
          'op_id': _uuid.v4(),
          'type': 'record.save',
          'ref_local_id': r.uuid,
          'attempts': 0,
          'created_at': DateTime.now().toIso8601String(),
        });
      }
    });
  }

  Future<void> delete(ClinicalRecord r) async {
    r.deleted = true;
    await save(r);
  }

  // ------------------------------------------------------------------ sync helpers (used by DoctorSyncModule)
  static Future<void> applyServer(Transaction tx, List<Map<String, dynamic>> records) async {
    if (records.isEmpty) return;
    final pending = {
      for (final r in await tx.query('clinical_records', columns: ['uuid'], where: 'pending = 1')) r['uuid'] as String,
    };
    final b = tx.batch();
    for (final j in records) {
      final r = ClinicalRecord.fromServer(j);
      if (pending.contains(r.uuid)) continue; // unsent local edit wins until it is uploaded
      b.insert('clinical_records', _serverRow(r), conflictAlgorithm: ConflictAlgorithm.replace);
    }
    await b.commit(noResult: true);
  }

  static Map<String, Object?> _serverRow(ClinicalRecord r) => {
        'uuid': r.uuid,
        'patient_id': r.patientId,
        'type': r.type,
        'event_date': r.eventYmd,
        'status': r.status,
        'deleted': r.deleted ? 1 : 0,
        'read_only': r.readOnly ? 1 : 0,
        'pending': 0,
        'local_rev': 0,
        'error': null,
        'updated_at': r.updatedAt?.toIso8601String(),
        'updated_by_name': r.updatedByName,
        'json': jsonEncode(r.data),
      };

  static Future<Map<String, dynamic>?> buildPayload(Transaction tx, String uuid, int seq) async {
    final rows = await tx.query('clinical_records', where: 'uuid = ?', whereArgs: [uuid]);
    if (rows.isEmpty) return null;
    await tx.update('outbox', {'sent_rev': rows.first['local_rev']}, where: 'seq = ?', whereArgs: [seq]);
    return ClinicalRecord.fromRow(rows.first).toPushPayload();
  }

  static Future<void> applyResult(Transaction tx, String uuid, int? sentRev, Map<String, dynamic> result) async {
    final rows = await tx.query('clinical_records', columns: ['local_rev'], where: 'uuid = ?', whereArgs: [uuid]);
    if (rows.isEmpty) return;
    final edited = (rows.first['local_rev'] as int) > (sentRev ?? 0);
    if (result['status'] == 'ok') {
      if (edited) {
        // Changed again during upload: keep the local copy and send it again.
        await tx.insert('outbox', {'op_id': _uuid.v4(), 'type': 'record.save', 'ref_local_id': uuid, 'attempts': 0, 'created_at': DateTime.now().toIso8601String()});
      } else if (result['record'] is Map) {
        await tx.insert('clinical_records', _serverRow(ClinicalRecord.fromServer(Map<String, dynamic>.from(result['record'] as Map))),
            conflictAlgorithm: ConflictAlgorithm.replace);
      } else {
        await tx.update('clinical_records', {'pending': 0}, where: 'uuid = ?', whereArgs: [uuid]);
      }
    } else {
      // rejected: keep it visible with the reason; it is no longer queued.
      await tx.update('clinical_records', {'pending': 0, 'error': result['message'] ?? 'Not accepted by the server'}, where: 'uuid = ?', whereArgs: [uuid]);
    }
  }
}
