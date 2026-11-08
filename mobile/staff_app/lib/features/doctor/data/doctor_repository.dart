import 'dart:convert';

import 'package:sqflite_common/sqlite_api.dart';
import 'package:uuid/uuid.dart';

import '../../../core/db/app_database.dart';
import 'clinical.dart';
import 'lab_flags.dart';
import 'queue.dart';
import 'models.dart';

/// Everything the doctor screens read and write. Reads only touch the local database, so every
/// screen works offline; writes go to the local database plus the outbox, and SyncEngine pushes them.
class DoctorRepository {
  DoctorRepository(this.adb, this.staffId);

  final AppDatabase adb;
  final int staffId;
  static const _uuid = Uuid();

  Database get db => adb.db;

  // ================================================================== patients
  /// Local patient search by name, phone or id; most recently seen first.
  Future<List<Patient>> searchPatients(String query, {int limit = 100}) async {
    final terms = query.toLowerCase().trim().split(RegExp(r'\s+')).where((t) => t.isNotEmpty).toList();
    final where = terms.isEmpty ? '1=1' : terms.map((_) => 'p.search LIKE ?').join(' AND ');
    final rows = await db.rawQuery('''
      SELECT p.json, p.pinned,
        MAX(COALESCE((SELECT MAX(v.appointment_date) FROM visits v WHERE v.patient_id = p.id), ''),
            COALESCE((SELECT MAX(a.date) FROM admissions a WHERE a.patient_id = p.id), '')) last_seen
      FROM patients p WHERE $where ORDER BY last_seen DESC, p.name LIMIT $limit''', [for (final t in terms) '%$t%']);
    return rows.map(_patient).toList();
  }

  Future<Patient?> patient(int id) async {
    final r = await db.query('patients', columns: ['json', 'pinned'], where: 'id = ?', whereArgs: [id]);
    return r.isEmpty ? null : _patient(r.first);
  }

  Patient _patient(Map<String, Object?> r) =>
      Patient(jsonDecode(r['json'] as String) as Map<String, dynamic>, pinned: (r['pinned'] as int? ?? 0) == 1);

  Future<void> setPinned(int patientId, bool pinned) =>
      db.update('patients', {'pinned': pinned ? 1 : 0}, where: 'id = ?', whereArgs: [patientId]);

  // ================================================================== encounters & history
  /// OPD visits and IPD admissions, newest first.
  Future<List<Encounter>> encounters(int patientId) async {
    final v = await db.query('visits', columns: ['json'], where: 'patient_id = ?', whereArgs: [patientId]);
    final a = await db.query('admissions', columns: ['json'], where: 'patient_id = ?', whereArgs: [patientId]);
    final list = [
      ...v.map((r) => Encounter.visit(_json(r))),
      ...a.map((r) => Encounter.admission(_json(r))),
    ];
    list.sort((x, y) => (y.date ?? DateTime(1900)).compareTo(x.date ?? DateTime(1900)));
    return list;
  }

  Future<Encounter?> encounter(String kind, int refId) async {
    final r = await db.query(kind == 'ipd' ? 'admissions' : 'visits', columns: ['json'], where: 'id = ?', whereArgs: [refId]);
    if (r.isEmpty) return null;
    return kind == 'ipd' ? Encounter.admission(_json(r.first)) : Encounter.visit(_json(r.first));
  }

  /// Encounters a new prescription can be written against: open ones first, then the latest.
  Future<List<Encounter>> prescribableEncounters(int patientId) async {
    final all = await encounters(patientId);
    final open = all.where((e) => e.isOpen).toList();
    return open.isNotEmpty ? open : all.take(1).toList();
  }

  /// ICD-10 codes per encounter key (`opd:<visit id>` / `ipd:<ipd id>`).
  Future<Map<String, List<IcdCode>>> diagnoses(List<Encounter> encounters) async {
    final out = <String, List<IcdCode>>{};
    if (encounters.isEmpty) return out;
    final rows = await db.query('diagnoses');
    final byCase = {for (final r in rows) '${r['kind']}:${r['case_id']}': r['json'] as String};
    for (final e in encounters) {
      final raw = byCase['${e.kind}:${e.caseId}'];
      if (raw == null) continue;
      out[e.key] = (jsonDecode(raw) as List).map((c) => IcdCode.fromJson(Map<String, dynamic>.from(c as Map))).toList();
    }
    return out;
  }

  Future<List<Prescription>> prescriptions(int patientId) async {
    final r = await db.query('prescriptions', where: 'patient_id = ?', whereArgs: [patientId],
        orderBy: 'date DESC, COALESCE(server_id, 999999999) DESC, updated_local_at DESC');
    return r.map(Prescription.fromRow).toList();
  }

  Future<Prescription?> prescription(String localId) async {
    final r = await db.query('prescriptions', where: 'local_id = ?', whereArgs: [localId]);
    return r.isEmpty ? null : Prescription.fromRow(r.first);
  }

  /// Most recent prescription with medicines, for "Copy last prescription".
  Future<Prescription?> lastPrescription(int patientId, {String? excludeLocalId}) async {
    for (final p in await prescriptions(patientId)) {
      if (p.localId != excludeLocalId && p.items.isNotEmpty) return p;
    }
    return null;
  }

  Future<List<VitalReading>> vitals(int patientId) async {
    final r = await db.query('vitals', columns: ['json'], where: 'patient_id = ?', whereArgs: [patientId], orderBy: 'measured_at DESC');
    return r.map((e) => VitalReading(_json(e))).toList();
  }

  Future<List<LabReport>> labReports(int patientId) async {
    final r = await db.query('lab_reports', columns: ['json'], where: 'patient_id = ?', whereArgs: [patientId],
        orderBy: 'COALESCE(reporting_date, \'9999\') DESC, id DESC');
    return r.map((e) => LabReport(_json(e))).toList();
  }

  Future<List<TimelineEntry>> timeline(int patientId) async {
    final r = await db.query('timeline', columns: ['json'], where: 'patient_id = ?', whereArgs: [patientId], orderBy: 'timeline_date DESC');
    return r.map((e) => TimelineEntry(_json(e))).toList();
  }

  // ================================================================== insights (key reports)
  /// The doctor's practice over the last [days] days, from data on this phone.
  Future<Insights> insights(int days, {DateTime? now}) async {
    final end = now ?? DateTime.now();
    final from = DateTime(end.year, end.month, end.day).subtract(Duration(days: days - 1));
    final fromYmd = ymd(from);
    bool inPeriod(DateTime? d) => d != null && !d.isBefore(from) && !d.isAfter(end.add(const Duration(days: 1)));

    // OPD visits with this doctor
    final allVisits = (await db.query('visits', columns: ['json'])).map((r) => Encounter.visit(_json(r))).toList();
    final firstSeen = <int, DateTime>{};
    for (final v in allVisits) {
      final d = v.date;
      if (d != null && (firstSeen[v.patientId] == null || d.isBefore(firstSeen[v.patientId]!))) firstSeen[v.patientId] = d;
    }
    final visits = allVisits.where((v) => v.doctorId == staffId && inPeriod(v.date)).toList();
    final patientIds = visits.map((v) => v.patientId).toSet();
    final newPatients = patientIds.where((pid) => inPeriod(firstSeen[pid])).length;

    final perDay = <String, int>{};
    for (var i = 0; i < days; i++) {
      perDay[ymd(from.add(Duration(days: i)))] = 0;
    }
    for (final v in visits) {
      final k = ymd(v.date!);
      if (perDay.containsKey(k)) perDay[k] = perDay[k]! + 1;
    }

    // Prescriptions written by this doctor (unsent ones were written on this phone, so they are theirs)
    final rxs = (await db.query('prescriptions', where: 'substr(date, 1, 10) >= ?', whereArgs: [fromYmd]))
        .map(Prescription.fromRow)
        .where((p) => p.isLocalOnly || p.generatedBy == staffId || (p.j['prescribe_by'] as num?)?.toInt() == staffId)
        .toList();
    final meds = <String, int>{}, tests = <String, int>{};
    for (final p in rxs) {
      for (final it in p.items) {
        meds[it.medicineName] = (meds[it.medicineName] ?? 0) + 1;
      }
      for (final t in [...p.pathology, ...p.radiology]) {
        tests[t.name] = (tests[t.name] ?? 0) + 1;
      }
    }
    final medCount = rxs.fold<int>(0, (s, p) => s + p.items.length);

    // Diagnoses on those visits
    final dx = <String, int>{};
    final byCase = await diagnoses(visits);
    for (final codes in byCase.values) {
      for (final c in codes) {
        final k = '${c.code} ${c.description}';
        dx[k] = (dx[k] ?? 0) + 1;
      }
    }

    // Demographics
    var male = 0, female = 0;
    final ages = <String, int>{'0–12': 0, '13–17': 0, '18–40': 0, '41–60': 0, '60+': 0};
    if (patientIds.isNotEmpty) {
      for (final r in await db.query('patients', columns: ['json'], where: 'id IN (${patientIds.join(',')})')) {
        final p = Patient(_json(r));
        final g = (p.gender ?? '').toLowerCase();
        if (g.startsWith('m')) male++;
        if (g.startsWith('f')) female++;
        final y = p.dob != null ? end.difference(p.dob!).inDays / 365.25 : (p.j['age'] as num?)?.toDouble();
        if (y == null) continue;
        final band = y <= 12 ? '0–12' : y < 18 ? '13–17' : y <= 40 ? '18–40' : y <= 60 ? '41–60' : '60+';
        ages[band] = ages[band]! + 1;
      }
    }

    // Services billed on those visits
    final opdIds = visits.map((v) => v.caseId).toSet();
    var billed = 0.0;
    var services = 0;
    for (final r in await db.query('patient_billing', columns: ['json'])) {
      for (final c in (((jsonDecode(r['json'] as String) as Map)['charges'] as List?) ?? const [])) {
        final opd = (c['opd_id'] as num?)?.toInt();
        if (opd != null && opdIds.contains(opd) && inPeriod(parseServerDate(c['date']))) {
          billed += (c['amount'] as num?)?.toDouble() ?? 0;
          services++;
        }
      }
    }

    // Follow-ups and abnormal reports
    final fu = (await db.query('clinical_records', where: "type = 'followup' AND deleted = 0")).map(ClinicalRecord.fromRow).toList();
    final today = DateTime(end.year, end.month, end.day);
    final fuScheduled = fu.where((f) => inPeriod(f.updatedAt) || inPeriod(f.eventDate)).length;
    final fuDone = fu.where((f) => f.status == 'done' && inPeriod(f.date('done_on'))).length;
    final fuOverdue = fu.where((f) => f.status == 'active' && f.eventDate != null && f.eventDate!.isBefore(today)).length;
    final labs = (await db.query('lab_reports', columns: ['json'])).map((r) => LabReport(_json(r))).where((l) => inPeriod(l.reportingDate)).toList();

    List<(String, int)> top(Map<String, int> m) => (m.entries.toList()..sort((a, b) => b.value.compareTo(a.value))).take(5).map((e) => (e.key, e.value)).toList();
    return Insights(
      days: days,
      visits: visits.length,
      patients: patientIds.length,
      newPatients: newPatients,
      prescriptions: rxs.length,
      avgMedicines: rxs.isEmpty ? 0 : medCount / rxs.length,
      services: services,
      billed: billed,
      followUpsScheduled: fuScheduled,
      followUpsDone: fuDone,
      followUpsOverdue: fuOverdue,
      reports: labs.length,
      abnormalReports: labs.where((l) => l.isAbnormal).length,
      visitsPerDay: [for (final e in perDay.entries) (DateTime.parse(e.key), e.value)],
      topDiagnoses: top(dx),
      topMedicines: top(meds),
      topTests: top(tests),
      male: male,
      female: female,
      ageBands: ages,
    );
  }

  // ================================================================== medical reports inbox
  static const _seenKey = 'reports_seen';
  static const newWindow = Duration(days: 14);

  /// Lab and imaging reports for every patient on the phone: reported first (newest first), then awaiting.
  Future<List<ReportEntry>> reportsInbox() async {
    final rows = await db.rawQuery('''SELECT l.json, p.json pjson, p.pinned FROM lab_reports l LEFT JOIN patients p ON p.id = l.patient_id''');
    final seen = await seenReports();
    final cutoff = DateTime.now().subtract(newWindow);
    final out = [
      for (final r in rows)
        () {
          final report = LabReport(jsonDecode(r['json'] as String) as Map<String, dynamic>);
          final patient = r['pjson'] == null ? null : Patient(jsonDecode(r['pjson'] as String) as Map<String, dynamic>, pinned: r['pinned'] == 1);
          final when = report.reportingDate;
          return ReportEntry(report, patient, isNew: report.isReported && when != null && when.isAfter(cutoff) && !seen.contains(report.key));
        }(),
    ];
    out.sort((a, b) {
      if (a.report.isReported != b.report.isReported) return a.report.isReported ? -1 : 1;
      final da = a.report.reportingDate ?? a.report.collectionDate ?? DateTime(1900);
      final dbb = b.report.reportingDate ?? b.report.collectionDate ?? DateTime(1900);
      final c = dbb.compareTo(da);
      return c != 0 ? c : b.report.id.compareTo(a.report.id);
    });
    return out;
  }

  Future<Set<String>> seenReports() async {
    final raw = await adb.getMeta(_seenKey);
    return raw == null ? <String>{} : (jsonDecode(raw) as List).map((e) => '$e').toSet();
  }

  Future<void> markReportsSeen(Iterable<String> keys) async {
    final seen = await seenReports()..addAll(keys);
    // Keep the list bounded; old reports drop out of "new" by date anyway.
    final list = seen.toList();
    await adb.setMeta(_seenKey, jsonEncode(list.length > 3000 ? list.sublist(list.length - 3000) : list));
  }

  // ================================================================== today
  Future<TodayData> today([DateTime? now]) async {
    final d = now ?? DateTime.now();
    final day = '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

    final visitRows = await db.query('visits', columns: ['json'],
        where: 'doctor_id = ? AND substr(appointment_date, 1, 10) = ?', whereArgs: [staffId, day], orderBy: 'appointment_date');
    final opd = visitRows.map((r) => Encounter.visit(_json(r))).toList();
    final opdVisitIds = opd.map((e) => e.refId).toSet();

    // Appointments not yet turned into a visit (or whose visit has not synced yet).
    final apptRows = await db.query('appointments', columns: ['json'], where: 'substr(date, 1, 10) = ?', whereArgs: [day], orderBy: 'date');
    final appointments = apptRows
        .map((r) => Appointment(_json(r)))
        .where((a) => !const {'cancel', 'cancelled', 'reject', 'rejected'}.contains(a.status.toLowerCase()))
        .where((a) => a.visitId == null || !opdVisitIds.contains(a.visitId))
        .toList();

    final ipdRows = await db.query('admissions', columns: ['json'], where: "COALESCE(discharged, 'no') <> 'yes'", orderBy: 'date DESC');
    final ipd = ipdRows.map((r) => Encounter.admission(_json(r))).where((e) => e.involvesDoctor(staffId)).toList();

    final followUps = await ClinicalRepository(adb).followUpsDue(d);
    final statusRows = await db.query('clinical_records', where: "type = 'opd_status' AND deleted = 0 AND substr(event_date, 1, 10) = ?", whereArgs: [day]);
    final statusByVisit = <int, ClinicalRecord>{
      for (final r in statusRows.map(ClinicalRecord.fromRow))
        if (r.data['visit_id'] != null) (r.data['visit_id'] as num).toInt(): r,
    };

    final patientIds = {...opd.map((e) => e.patientId), ...ipd.map((e) => e.patientId), ...appointments.map((a) => a.patientId), ...followUps.map((f) => f.patientId)};
    final patients = <int, Patient>{};
    if (patientIds.isNotEmpty) {
      final rows = await db.query('patients', columns: ['json', 'pinned'], where: 'id IN (${patientIds.join(',')})');
      for (final r in rows) {
        final p = _patient(r);
        patients[p.id] = p;
      }
    }
    final rxRows = await db.rawQuery("SELECT DISTINCT kind || ':' || case_ref_id k FROM prescriptions");
    final withRx = rxRows.map((r) => r['k'] as String).toSet();

    return TodayData(opd: opd, ipd: ipd, appointments: appointments, patients: patients, encountersWithRx: withRx, queueStatus: statusByVisit, followUps: followUps.where((f) => patients.containsKey(f.patientId)).toList());
  }

  // ================================================================== masters
  Future<Masters> masters() async {
    final m = await adb.lookup('masters');
    if (m == null) return Masters.empty();
    List<Option> opts(String k) =>
        ((m[k] as List?) ?? const []).map((e) => Option((e['id'] as num).toInt(), '${e['name'] ?? e['label'] ?? ''}')).toList();
    List<TestRef> tests(String k) => ((m[k] as List?) ?? const []).map((e) {
          final short = e['short_name'];
          final name = '${e['name'] ?? ''}';
          return TestRef((e['id'] as num).toInt(), short != null && '$short'.isNotEmpty && '$short' != name ? '$name ($short)' : name);
        }).toList();

    final byCat = <int, List<Option>>{};
    final all = <Option>[];
    final seenLabels = <String>{};
    for (final d in (m['dosages'] as List?) ?? const []) {
      final o = Option((d['id'] as num).toInt(), '${d['label'] ?? ''}');
      final cat = (d['category_id'] as num?)?.toInt();
      if (cat != null) byCat.putIfAbsent(cat, () => []).add(o);
      if (seenLabels.add(o.label.toLowerCase())) all.add(o);
    }
    return Masters(
      dosagesByCategory: byCat,
      allDosages: all,
      intervals: opts('intervals'),
      durations: opts('durations'),
      pathology: tests('pathology'),
      radiology: tests('radiology'),
      frequentMedicineIds: ((m['frequent_medicine_ids'] as List?) ?? const []).map((e) => (e as num).toInt()).toList(),
    );
  }

  /// Medicine search over name, composition (generic) and company. Empty query -> this doctor's frequent medicines.
  Future<List<Medicine>> searchMedicines(String query, {List<int> frequentIds = const [], int limit = 40}) async {
    final terms = query.toLowerCase().trim().split(RegExp(r'\s+')).where((t) => t.isNotEmpty).toList();
    if (terms.isEmpty) {
      if (frequentIds.isEmpty) return [];
      final rows = await db.query('medicines', where: 'id IN (${frequentIds.join(',')})');
      final byId = {for (final r in rows) r['id'] as int: Medicine.fromRow(r)};
      return [for (final id in frequentIds) ?byId[id]];
    }
    final rows = await db.rawQuery(
      'SELECT * FROM medicines WHERE ${terms.map((_) => 'search LIKE ?').join(' AND ')} '
      'ORDER BY (lower(name) LIKE ?) DESC, name LIMIT $limit',
      [for (final t in terms) '%$t%', '${terms.first}%'],
    );
    return rows.map(Medicine.fromRow).toList();
  }

  Future<int> medicineCount() async => (await db.rawQuery('SELECT COUNT(*) n FROM medicines')).first['n'] as int;

  Future<List<IcdCode>> searchIcd(String query, {int limit = 30}) async {
    final terms = query.toLowerCase().trim().split(RegExp(r'\s+')).where((t) => t.isNotEmpty).toList();
    if (terms.isEmpty) return [];
    final rows = await db.rawQuery(
      'SELECT id, code, description FROM icd WHERE ${terms.map((_) => 'search LIKE ?').join(' AND ')} '
      'ORDER BY (lower(code) LIKE ?) DESC, code LIMIT $limit',
      [for (final t in terms) '%$t%', '${terms.first}%'],
    );
    return rows.map((r) => IcdCode(id: r['id'] as int, code: r['code'] as String, description: r['description'] as String)).toList();
  }

  Future<List<IcdCode>> icdByIds(List<int> ids) async {
    if (ids.isEmpty) return [];
    final rows = await db.query('icd', where: 'id IN (${ids.map((e) => e.toInt()).join(',')})');
    return rows.map((r) => IcdCode(id: r['id'] as int, code: r['code'] as String, description: r['description'] as String)).toList();
  }

  // ================================================================== prescriptions (write)
  /// Save on the device and queue for upload. Returns the local id.
  Future<String> savePrescription(PrescriptionDraft d) async {
    final now = DateTime.now().toIso8601String();
    final json = d.toJson();
    return db.transaction((tx) async {
      String localId;
      if (d.localId == null) {
        localId = _uuid.v4();
        await tx.insert('prescriptions', {
          'local_id': localId,
          'server_id': null,
          'patient_id': d.patientId,
          'kind': d.encounter.kind,
          'case_ref_id': d.encounter.refId,
          'date': json['date'],
          'status': RxStatus.pending.name,
          'version': null,
          'local_rev': 1,
          'force_save': 0,
          'json': jsonEncode(json),
          'updated_local_at': now,
        });
      } else {
        localId = d.localId!;
        final existing = await tx.query('prescriptions', where: 'local_id = ?', whereArgs: [localId]);
        if (existing.isEmpty) throw StateError('Prescription was removed while editing.');
        final row = existing.first;
        // Keep the prescriber name etc. from the server copy; overwrite what the doctor edited.
        final merged = {...jsonDecode(row['json'] as String) as Map<String, dynamic>, ...json};
        await tx.update(
            'prescriptions',
            {
              'date': json['date'],
              'status': RxStatus.pending.name,
              'error': null,
              'local_rev': (row['local_rev'] as int) + 1,
              'json': jsonEncode(merged),
              'updated_local_at': now,
            },
            where: 'local_id = ?',
            whereArgs: [localId]);
      }
      await _enqueue(tx, 'prescription.save', localId);
      return localId;
    });
  }

  /// Throw away unsent changes: a never-synced prescription is deleted, an edited one reverts to the server copy.
  Future<void> discardLocalChanges(String localId) async {
    await db.transaction((tx) async {
      final r = await tx.query('prescriptions', where: 'local_id = ?', whereArgs: [localId]);
      if (r.isEmpty) return;
      final row = r.first;
      await tx.delete('outbox', where: 'ref_local_id = ?', whereArgs: [localId]);
      if (row['server_id'] == null || row['server_json'] == null) {
        await tx.delete('prescriptions', where: 'local_id = ?', whereArgs: [localId]);
      } else {
        final server = jsonDecode(row['server_json'] as String) as Map<String, dynamic>;
        await tx.update(
            'prescriptions',
            {
              'status': RxStatus.synced.name,
              'error': null,
              'force_save': 0,
              'json': row['server_json'],
              'date': server['date'],
              'version': server['version'] ?? row['version'],
            },
            where: 'local_id = ?',
            whereArgs: [localId]);
      }
    });
  }

  /// Conflict: overwrite the server copy with this device's version on the next sync.
  Future<void> keepMine(String localId) async {
    await db.transaction((tx) async {
      final r = await tx.query('prescriptions', where: 'local_id = ?', whereArgs: [localId]);
      if (r.isEmpty) return;
      final server = r.first['server_json'] == null ? null : jsonDecode(r.first['server_json'] as String) as Map<String, dynamic>;
      await tx.update(
          'prescriptions',
          {
            'status': RxStatus.pending.name,
            'force_save': 1,
            'error': null,
            'version': server?['version'] ?? r.first['version'],
            'local_rev': (r.first['local_rev'] as int) + 1,
          },
          where: 'local_id = ?',
          whereArgs: [localId]);
      await _enqueue(tx, 'prescription.save', localId);
    });
  }

  /// Conflict: accept the server copy and drop this device's changes.
  Future<void> useServerVersion(String localId) => discardLocalChanges(localId);

  Future<void> _enqueue(DatabaseExecutor tx, String type, String localId) async {
    final exists = await tx.query('outbox', columns: ['seq'], where: 'type = ? AND ref_local_id = ?', whereArgs: [type, localId]);
    if (exists.isNotEmpty) return; // payload is built from the latest local row at send time
    await tx.insert('outbox', {
      'op_id': _uuid.v4(),
      'type': type,
      'ref_local_id': localId,
      'payload': null,
      'attempts': 0,
      'created_at': DateTime.now().toIso8601String(),
    });
  }

  // ================================================================== templates
  Future<List<RxTemplate>> templates() async {
    final r = await db.query('templates', orderBy: 'mine DESC, name');
    return r
        .map((e) => RxTemplate(
              localId: e['local_id'] as String,
              serverId: e['server_id'] as int?,
              name: e['name'] as String,
              shared: e['shared'] == 1,
              mine: e['mine'] == 1,
              payload: jsonDecode(e['json'] as String) as Map<String, dynamic>,
            ))
        .toList();
  }

  Future<void> saveTemplate(String name, bool shared, PrescriptionDraft d) async {
    final localId = _uuid.v4();
    final payload = RxTemplate.payloadFrom(d);
    await db.transaction((tx) async {
      await tx.insert('templates', {
        'local_id': localId,
        'server_id': null,
        'name': name,
        'shared': shared ? 1 : 0,
        'mine': 1,
        'status': RxStatus.pending.name,
        'json': jsonEncode(payload),
      });
      await tx.insert('outbox', {
        'op_id': _uuid.v4(),
        'type': 'template.save',
        'ref_local_id': localId,
        'payload': jsonEncode({'name': name, 'shared': shared, 'payload': payload}),
        'attempts': 0,
        'created_at': DateTime.now().toIso8601String(),
      });
    });
  }

  Future<void> deleteTemplate(RxTemplate t) async {
    await db.transaction((tx) async {
      await tx.delete('templates', where: 'local_id = ?', whereArgs: [t.localId]);
      // Not uploaded yet: dropping the queued save is enough.
      await tx.delete('outbox', where: "type = 'template.save' AND ref_local_id = ?", whereArgs: [t.localId]);
      if (t.serverId != null) {
        await tx.insert('outbox', {
          'op_id': _uuid.v4(),
          'type': 'template.delete',
          'ref_local_id': t.localId,
          'payload': jsonEncode({'server_id': t.serverId}),
          'attempts': 0,
          'created_at': DateTime.now().toIso8601String(),
        });
      }
    });
  }

  // ================================================================== sync status
  Future<List<Prescription>> unsyncedPrescriptions() async {
    final r = await db.query('prescriptions', where: 'status <> ?', whereArgs: [RxStatus.synced.name], orderBy: 'updated_local_at DESC');
    return r.map(Prescription.fromRow).toList();
  }

  Map<String, dynamic> _json(Map<String, Object?> r) => jsonDecode(r['json'] as String) as Map<String, dynamic>;
}

class TodayData {
  TodayData({required this.opd, required this.ipd, required this.appointments, required this.patients, required this.encountersWithRx, this.followUps = const [], this.queueStatus = const {}});

  /// Queue state records for today's visits, by visit id.
  final Map<int, ClinicalRecord> queueStatus;

  /// Today's OPD queue in token/time order.
  late final List<QueueEntry> queue = buildQueue(opd: opd, patients: patients, statusByVisit: queueStatus, hasRx: hasRx);

  final List<Encounter> opd;
  final List<Encounter> ipd;
  final List<Appointment> appointments;
  final Map<int, Patient> patients;
  final Set<String> encountersWithRx;

  /// Pending follow-ups due today or overdue.
  final List<ClinicalRecord> followUps;

  bool hasRx(Encounter e) => encountersWithRx.contains(e.key);
  bool get isEmpty => opd.isEmpty && ipd.isEmpty && appointments.isEmpty && followUps.isEmpty;
}

class ReportEntry {
  ReportEntry(this.report, this.patient, {required this.isNew});
  final LabReport report;
  final Patient? patient;
  final bool isNew;
}

class Insights {
  Insights({
    required this.days,
    required this.visits,
    required this.patients,
    required this.newPatients,
    required this.prescriptions,
    required this.avgMedicines,
    required this.services,
    required this.billed,
    required this.followUpsScheduled,
    required this.followUpsDone,
    required this.followUpsOverdue,
    required this.reports,
    required this.abnormalReports,
    required this.visitsPerDay,
    required this.topDiagnoses,
    required this.topMedicines,
    required this.topTests,
    required this.male,
    required this.female,
    required this.ageBands,
  });

  final int days, visits, patients, newPatients, prescriptions, services, followUpsScheduled, followUpsDone, followUpsOverdue, reports, abnormalReports, male, female;
  final double avgMedicines, billed;
  final List<(DateTime, int)> visitsPerDay;
  final List<(String, int)> topDiagnoses, topMedicines, topTests;
  final Map<String, int> ageBands;
}
