import 'dart:convert';
import 'dart:math';

import 'package:sqflite_common/sqlite_api.dart';
import 'package:uuid/uuid.dart';

import '../../core/db/app_database.dart';
import '../doctor/data/billing.dart';
import '../doctor/data/clinical.dart';
import '../doctor/data/models.dart';
import '../doctor/data/queue.dart';

/// Offline patient registration and OPD visits.
///
/// A patient or visit made on the device gets a negative local id and is queued as `patient.register` /
/// `visit.create`. Everything else (prescriptions, services, specialty records, queue state) can be
/// recorded against it straight away. When the server answers, [remapPatient] / [remapVisit] move all
/// of it to the real ids; until then ops that refer to a negative id wait in the outbox.

class OpdDoctor {
  OpdDoctor(this.j);
  final Map<String, dynamic> j;
  int get id => (j['id'] as num).toInt();
  String get name => '${j['name'] ?? 'Doctor'}';
  String? get department => j['department'] as String?;
  int? get chargeId => (j['charge_id'] as num?)?.toInt();
}

class NewPatient {
  NewPatient({
    required this.name,
    this.gender,
    this.ageYears = 0,
    this.ageMonths = 0,
    this.dob,
    this.mobile = '',
    this.guardian = '',
    this.address = '',
    this.allergies = '',
    this.bloodGroup = '',
    this.identification = '',
  });

  final String name;
  final String? gender;
  final int ageYears;
  final int ageMonths;
  final DateTime? dob;
  final String mobile;
  final String guardian;
  final String address;
  final String allergies;
  final String bloodGroup;
  final String identification;

  String? validate() {
    if (name.trim().isEmpty) return 'Enter the patient\'s name';
    if (dob == null && ageYears == 0 && ageMonths == 0) return 'Enter the age or date of birth';
    if (ageYears > 130 || ageMonths > 11) return 'Check the age';
    final m = mobile.replaceAll(RegExp(r'[^0-9+]'), '');
    if (m.isNotEmpty && !RegExp(r'^\+?[0-9]{6,15}$').hasMatch(m)) return 'Check the mobile number';
    return null;
  }

  /// Same field names as the server patient rows, so the local copy reads like a synced patient.
  Map<String, dynamic> toJson(int localId) => {
        'id': localId,
        'name': name.trim(),
        'gender': gender,
        'dob': dob == null ? null : ymd(dob!),
        'age': ageYears,
        'month': ageMonths,
        'day': 0,
        'mobileno': mobile.replaceAll(RegExp(r'[^0-9+]'), ''),
        'guardian_name': guardian.trim(),
        'address': address.trim(),
        'known_allergies': allergies.trim(),
        'blood_group': bloodGroup.trim(),
        'identification_number': identification.trim(),
        'local': true,
      };
}

class NewVisit {
  NewVisit({
    required this.patientId,
    required this.doctor,
    required this.charge,
    this.discount = 0,
    this.paid = 0,
    this.paymentMode = 'Cash',
    this.symptoms = '',
    this.bp = '',
    this.pulse = '',
    this.temperature = '',
    this.weight = '',
    this.height = '',
    this.casualty = false,
    DateTime? date,
  }) : date = date ?? DateTime.now();

  final int patientId;
  final OpdDoctor doctor;
  final ChargeOption? charge;
  final double discount;
  final double paid;
  final String paymentMode;
  final String symptoms;
  final String bp, pulse, temperature, weight, height;
  final bool casualty;
  final DateTime date;

  double get total => charge?.price(1, discount).total ?? 0;

  String? validate() {
    if (charge == null) return 'Pick the consultation charge';
    if (paid < 0 || paid > total + 0.005) return 'Paid amount cannot be more than ${total.toStringAsFixed(2)}';
    return null;
  }
}

const paymentModes = ['Cash', 'UPI', 'Card', 'Online', 'Other'];

String _dt(DateTime d) => '${ymd(d)} ${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}:00';

class RegistrationRepository {
  RegistrationRepository(this.adb, {required this.staffId, required this.staffName});
  final AppDatabase adb;
  final int staffId;
  final String staffName;
  static const _uuid = Uuid();

  Database get db => adb.db;

  Future<List<OpdDoctor>> doctors() async {
    final m = await adb.lookup('masters');
    return ((m?['opd_doctors'] as List?) ?? const []).map((e) => OpdDoctor(Map<String, dynamic>.from(e as Map))).toList();
  }

  /// Patients on the device with this mobile number (to avoid registering someone twice).
  Future<List<Patient>> withMobile(String mobile) async {
    final digits = mobile.replaceAll(RegExp(r'[^0-9]'), '');
    if (digits.length < 6) return const [];
    final tail = digits.length > 10 ? digits.substring(digits.length - 10) : digits;
    final rows = await db.query('patients', columns: ['json', 'pinned'], where: 'search LIKE ?', whereArgs: ['%$tail%'], limit: 10);
    return rows.map((r) => Patient(jsonDecode(r['json'] as String) as Map<String, dynamic>, pinned: r['pinned'] == 1)).where((p) => (p.mobile ?? '').replaceAll(RegExp(r'[^0-9]'), '').endsWith(tail)).toList();
  }

  /// Local ids are negative and unique per device (each device owns a block of 100000), so patients
  /// and visits shared between clinic devices over the hotspot never collide.
  static Future<int> devicePrefix(DatabaseExecutor tx) async {
    final r = await tx.query('meta', where: 'key = ?', whereArgs: ['device_prefix']);
    if (r.isNotEmpty) return int.parse(r.first['value'] as String);
    final p = 1 + Random.secure().nextInt(89999);
    await tx.insert('meta', {'key': 'device_prefix', 'value': '$p'}, conflictAlgorithm: ConflictAlgorithm.replace);
    return p;
  }

  static const idBlock = 100000;

  Future<int> _nextLocalId(DatabaseExecutor tx, String table) => nextLocalId(tx, table);

  static Future<int> nextLocalId(DatabaseExecutor tx, String table) async {
    final base = -(await devicePrefix(tx)) * idBlock;
    final r = await tx.rawQuery('SELECT MIN(id) m FROM $table WHERE id < ? AND id > ?', [base, base - idBlock]);
    final min = r.first['m'] as int?;
    return min == null ? base - 1 : min - 1;
  }

  /// True when [id] is a local id created on this device (not one received from another device).
  static Future<bool> isOwnLocalId(DatabaseExecutor tx, int id) async {
    if (id >= 0) return false;
    final base = -(await devicePrefix(tx)) * idBlock;
    return id < base && id > base - idBlock;
  }

  Future<void> _queue(DatabaseExecutor tx, String type, String ref) => tx.insert('outbox', {
        'op_id': _uuid.v4(),
        'type': type,
        'ref_local_id': ref,
        'attempts': 0,
        'created_at': DateTime.now().toIso8601String(),
      });

  /// Register a patient on the device. Returns the local (negative) patient id.
  Future<int> registerPatient(NewPatient p) async {
    final err = p.validate();
    if (err != null) throw ArgumentError(err);
    return db.transaction((tx) async {
      final id = await _nextLocalId(tx, 'patients');
      final j = p.toJson(id);
      await tx.insert('patients', {
        'id': id,
        'name': j['name'],
        'search': searchKey([j['name'], j['mobileno'], j['guardian_name'], j['identification_number']]),
        'pinned': 1, // stays on the device whatever the doctor's scope
        'updated_at': DateTime.now().toIso8601String(),
        'json': jsonEncode(j),
      });
      await _queue(tx, 'patient.register', '$id');
      return id;
    });
  }

  /// Provisional token: next number after the doctor's visits on this device today.
  Future<int> provisionalToken(DatabaseExecutor ex, int doctorId, DateTime day) async {
    final rows = await ex.query('visits', columns: ['json'], where: 'doctor_id = ? AND substr(appointment_date, 1, 10) = ?', whereArgs: [doctorId, ymd(day)]);
    var max = 0;
    for (final r in rows) {
      final t = ((jsonDecode(r['json'] as String) as Map)['token'] as num?)?.toInt() ?? 0;
      if (t > max) max = t;
    }
    return rows.length > max ? rows.length + 1 : max + 1;
  }

  /// Open an OPD visit for a patient (new or existing). Returns the local visit as an encounter.
  Future<Encounter> createVisit(NewVisit v) async {
    final err = v.validate();
    if (err != null) throw ArgumentError(err);
    return db.transaction((tx) async {
      final id = await _nextLocalId(tx, 'visits');
      final token = await provisionalToken(tx, v.doctor.id, v.date);
      final j = {
        'id': id,
        'opd_id': id,
        'patient_id': v.patientId,
        'doctor_id': v.doctor.id,
        'doctor_name': v.doctor.name,
        'appointment_date': _dt(v.date),
        'symptoms': v.symptoms.trim(),
        'bp': v.bp.trim(),
        'pulse': v.pulse.trim(),
        'temperature': v.temperature.trim(),
        'weight': v.weight.trim(),
        'height': v.height.trim(),
        'casualty': v.casualty ? 'Yes' : 'No',
        'discharged': 'no',
        'token': token,
        'provisional_token': true,
        'local': true,
        // upload details
        'charge_id': v.charge!.id,
        'charge_name': v.charge!.name,
        'discount_percentage': v.discount,
        'paid_amount': v.paid,
        'payment_mode': v.paymentMode,
        'total': v.total,
      };
      await tx.insert('visits', {'id': id, 'patient_id': v.patientId, 'opd_id': id, 'doctor_id': v.doctor.id, 'appointment_date': j['appointment_date'], 'json': jsonEncode(j)});
      await _queue(tx, 'visit.create', '$id');
      return Encounter.visit(j);
    });
  }

  // ================================================================== sync helpers
  static Future<Map<String, dynamic>?> patientPayload(Transaction tx, int localId) async {
    final r = await tx.query('patients', columns: ['json'], where: 'id = ?', whereArgs: [localId]);
    if (r.isEmpty) return null; // removed on the device
    final j = jsonDecode(r.first['json'] as String) as Map<String, dynamic>;
    return {for (final k in const ['name', 'gender', 'dob', 'age', 'month', 'day', 'mobileno', 'guardian_name', 'address', 'known_allergies', 'blood_group', 'identification_number']) k: j[k]};
  }

  static Future<Map<String, dynamic>?> visitPayload(Transaction tx, int localId) async {
    final r = await tx.query('visits', columns: ['json'], where: 'id = ?', whereArgs: [localId]);
    if (r.isEmpty) return null;
    final j = jsonDecode(r.first['json'] as String) as Map<String, dynamic>;
    final q = await tx.query('clinical_records', columns: ['json'], where: 'uuid = ?', whereArgs: [queueRecordId(localId)]);
    return {
      'patient_id': j['patient_id'],
      'doctor_id': j['doctor_id'],
      'date': j['appointment_date'],
      'charge_id': j['charge_id'],
      'discount_percentage': j['discount_percentage'],
      'paid_amount': j['paid_amount'],
      'payment_mode': j['payment_mode'],
      'symptoms': j['symptoms'],
      'known_allergies': j['known_allergies'],
      'casualty': j['casualty'] == 'Yes',
      for (final k in const ['bp', 'pulse', 'temperature', 'weight', 'height']) k: j[k],
      if (q.isNotEmpty) 'queue_state': (jsonDecode(q.first['json'] as String) as Map)['state'],
    };
  }

  static Future<int?> _visitPatient(Transaction tx, int visitId) async {
    final r = await tx.query('visits', columns: ['patient_id'], where: 'id = ?', whereArgs: [visitId]);
    return r.isEmpty ? null : r.first['patient_id'] as int;
  }

  /// A visit can be uploaded once its patient exists on the server.
  static Future<bool> visitReady(Transaction tx, int localVisitId) async => ((await _visitPatient(tx, localVisitId)) ?? 1) > 0;

  /// Mark a local record as refused by the server (kept so reception can see why).
  static Future<void> markRejected(Transaction tx, String table, int id, String message) async {
    final r = await tx.query(table, columns: ['json'], where: 'id = ?', whereArgs: [id]);
    if (r.isEmpty) return;
    final j = jsonDecode(r.first['json'] as String) as Map<String, dynamic>..['upload_error'] = message;
    await tx.update(table, {'json': jsonEncode(j)}, where: 'id = ?', whereArgs: [id]);
  }

  /// Move everything recorded against local patient [from] to server patient [to].
  static Future<void> remapPatient(Transaction tx, int from, int to) async {
    final exists = (await tx.query('patients', columns: ['id'], where: 'id = ?', whereArgs: [to])).isNotEmpty;
    if (exists) {
      await tx.delete('patients', where: 'id = ?', whereArgs: [from]); // linked to a patient already on the device
    } else {
      await tx.update('patients', {'id': to}, where: 'id = ?', whereArgs: [from]);
    }
    for (final t in const ['visits', 'admissions', 'prescriptions', 'vitals', 'lab_reports', 'timeline', 'appointments']) {
      await tx.update(t, {'patient_id': to}, where: 'patient_id = ?', whereArgs: [from]);
    }
    for (final r in await tx.query('visits', columns: ['id', 'json'], where: 'patient_id = ?', whereArgs: [to])) {
      final j = jsonDecode(r['json'] as String) as Map<String, dynamic>;
      if (j['patient_id'] == from) {
        j['patient_id'] = to;
        await tx.update('visits', {'json': jsonEncode(j)}, where: 'id = ?', whereArgs: [r['id']]);
      }
    }
    // Specialty records: the queue record's id is derived from the visit, so only the patient moves.
    for (final r in await tx.query('clinical_records', columns: ['uuid'], where: 'patient_id = ?', whereArgs: [from])) {
      await tx.update('clinical_records', {'patient_id': to}, where: 'uuid = ?', whereArgs: [r['uuid']]);
    }
    // Bill: services queued against the local patient.
    final bill = await tx.query('patient_billing', where: 'patient_id = ?', whereArgs: [from]);
    if (bill.isNotEmpty) {
      final j = jsonDecode(bill.first['json'] as String) as Map<String, dynamic>;
      final target = await tx.query('patient_billing', where: 'patient_id = ?', whereArgs: [to]);
      if (target.isNotEmpty) {
        final t = jsonDecode(target.first['json'] as String) as Map<String, dynamic>;
        t['pending'] = [...(t['pending'] as List? ?? const []), ...(j['pending'] as List? ?? const [])];
        await tx.update('patient_billing', {'json': jsonEncode(t)}, where: 'patient_id = ?', whereArgs: [to]);
        await tx.delete('patient_billing', where: 'patient_id = ?', whereArgs: [from]);
      } else {
        j['patient_id'] = to;
        await tx.update('patient_billing', {'patient_id': to, 'json': jsonEncode(j)}, where: 'patient_id = ?', whereArgs: [from]);
      }
    }
    await tx.update('outbox', {'ref_local_id': '$to'}, where: "type = 'charge.add' AND ref_local_id = ?", whereArgs: ['$from']);
    // Vitals and nurse notes recorded for the patient before upload carry the id in their payload.
    for (final r in await tx.query('outbox', columns: ['seq', 'payload'], where: "type IN ('vitals.save', 'nurse_note.save') AND ref_local_id = ?", whereArgs: ['$from'])) {
      final p = jsonDecode(r['payload'] as String) as Map<String, dynamic>..['patient_id'] = to;
      await tx.update('outbox', {'ref_local_id': '$to', 'payload': jsonEncode(p)}, where: 'seq = ?', whereArgs: [r['seq']]);
    }
  }

  /// Move everything recorded against local visit [from] to server visit [to].
  static Future<void> remapVisit(Transaction tx, int from, int to) async {
    await tx.delete('visits', where: 'id = ?', whereArgs: [from]); // the server copy arrives with the result
    for (final r in await tx.query('prescriptions', columns: ['local_id', 'json'], where: "kind = 'opd' AND case_ref_id = ?", whereArgs: [from])) {
      final j = jsonDecode(r['json'] as String) as Map<String, dynamic>;
      if (j.containsKey('visit_id')) j['visit_id'] = to;
      await tx.update('prescriptions', {'case_ref_id': to, 'json': jsonEncode(j)}, where: 'local_id = ?', whereArgs: [r['local_id']]);
    }
    for (final r in await tx.query('outbox', columns: ['seq', 'payload'], where: "type = 'charge.add'")) {
      final p = r['payload'] == null ? null : jsonDecode(r['payload'] as String) as Map<String, dynamic>;
      if (p != null && p['kind'] == 'opd' && p['case_ref_id'] == from) {
        p['case_ref_id'] = to;
        await tx.update('outbox', {'payload': jsonEncode(p)}, where: 'seq = ?', whereArgs: [r['seq']]);
      }
    }
    // The server created the real queue record (with the state we sent); drop the local one.
    final local = queueRecordId(from);
    await tx.delete('clinical_records', where: 'uuid = ?', whereArgs: [local]);
    await tx.delete('outbox', where: "type = 'record.save' AND ref_local_id = ?", whereArgs: [local]);
    // Follow-ups linked to the visit.
    for (final r in await tx.query('clinical_records', columns: ['uuid', 'json'], where: "type = ?", whereArgs: [RecordType.followup])) {
      final j = jsonDecode(r['json'] as String) as Map<String, dynamic>;
      if (j['visit_ref_id'] == from) {
        j['visit_ref_id'] = to;
        await tx.update('clinical_records', {'json': jsonEncode(j)}, where: 'uuid = ?', whereArgs: [r['uuid']]);
      }
    }
  }

  /// Does [payload] (a queued op) still point at a local, not-yet-uploaded id?
  static bool refersToLocal(String type, Map<String, dynamic>? payload) {
    if (payload == null) return false;
    num? n(Object? v) => v is num ? v : null;
    return (n(payload['case_ref_id']) ?? 1) < 0 || (n(payload['patient_id']) ?? 1) < 0;
  }
}
