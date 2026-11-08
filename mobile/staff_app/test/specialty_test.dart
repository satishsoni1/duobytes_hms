import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:staff_app/core/auth/auth_controller.dart';
import 'package:staff_app/core/auth/session_store.dart';
import 'package:staff_app/core/auth/staff.dart';
import 'package:staff_app/core/db/app_database.dart';
import 'package:staff_app/core/providers.dart';
import 'package:staff_app/core/sync/sync_engine.dart';
import 'package:staff_app/core/ui/theme.dart';
import 'package:staff_app/core/ui/motion.dart';
import 'package:staff_app/features/doctor/data/clinical.dart';
import 'package:staff_app/features/doctor/data/doctor_repository.dart';
import 'package:staff_app/features/doctor/data/doctor_sync.dart';
import 'package:staff_app/features/doctor/data/models.dart';
import 'package:staff_app/features/doctor/specialty/care_tab.dart';
import 'package:staff_app/features/doctor/specialty/chronic_care.dart';
import 'package:staff_app/features/doctor/specialty/followups.dart';
import 'package:staff_app/features/doctor/specialty/obstetrics.dart';
import 'package:staff_app/features/doctor/specialty/specialty.dart';
import 'package:staff_app/features/doctor/specialty/vaccine_schedule.dart';
import 'package:staff_app/features/doctor/ui/doctor_home.dart';
import 'package:staff_app/features/doctor/ui/doctor_providers.dart';
import 'package:staff_app/features/doctor/ui/patient_screen.dart';

import 'support/fake_server.dart';

PatientDetail detail(Map<String, dynamic> p, {List<Encounter> enc = const [], List<VitalReading> vitals = const []}) =>
    PatientDetail(patient: Patient(p), encounters: enc, diagnoses: const {}, prescriptions: const [], vitals: vitals, labs: const [], timeline: const []);

class _FakeAuth extends AuthController {
  _FakeAuth(AuthState s) : super(SessionStore()) {
    state = s;
  }
}

void main() {
  group('vaccination schedule', () {
    final dob = DateTime(2026, 1, 1);
    test('due, overdue, upcoming and given', () {
      final given = ClinicalRecord.create(patientId: 1, type: RecordType.vaccination, eventDate: DateTime(2026, 1, 1), status: 'given', data: {'vaccine': 'BCG', 'dose': '1'});
      final st = vaccineStatuses(dob, [given], today: DateTime(2026, 2, 20)); // 50 days old
      VaccineState of(String v, String d) => st.firstWhere((s) => s.dose.vaccine == v && s.dose.dose == d).state;
      expect(of('BCG', '1'), VaccineState.given);
      expect(of('OPV', '0'), VaccineState.overdue, reason: 'birth dose, now 50 days late');
      expect(of('DTwP/DTaP', '1'), VaccineState.due, reason: 'due at 42 days');
      expect(of('MMR', '1'), VaccineState.upcoming);
      expect(st.firstWhere((s) => s.dose.key == 'PCV#1').dueDate, DateTime(2026, 2, 12));
    });

    test('unscheduled vaccines are listed separately', () {
      final je = ClinicalRecord.create(patientId: 1, type: RecordType.vaccination, status: 'given', data: {'vaccine': 'JE', 'dose': '1'});
      expect(otherVaccinations([je]).single.str('vaccine'), 'JE');
    });
  });

  group('pregnancy dating', () {
    test('LMP gives EDD (+280 days), GA, trimester and the WHO visit plan', () {
      final r = ClinicalRecord.create(patientId: 1, type: RecordType.pregnancy, data: {'lmp': '2026-05-01'});
      final d = PregnancyDating.of(r, today: DateTime(2026, 11, 8))!;
      expect(d.edd, DateTime(2027, 2, 5));
      expect(d.gaLabel, '27w 2d'); // 1 May -> 8 Nov = 191 days
      expect(d.trimester, 2);
      expect(d.ancPlan.first, (12, DateTime(2026, 7, 24)));
      expect(d.ancPlan.last.$2, d.edd);
    });

    test('ultrasound EDD overrides LMP', () {
      final r = ClinicalRecord.create(patientId: 1, type: RecordType.pregnancy, data: {'lmp': '2026-05-01', 'edd_usg': '2027-02-12'});
      final d = PregnancyDating.of(r, today: DateTime(2026, 11, 8))!;
      expect(d.fromUsg, isTrue);
      expect(d.gaLabel, '26w 2d'); // 280 - 96 days to EDD = 184 days
    });
  });

  test('follow-up labels', () {
    final today = DateTime(2026, 11, 8, 15);
    expect(followUpWhen(DateTime(2026, 11, 8), today: today), ('Due today', true));
    expect(followUpWhen(DateTime(2026, 11, 5), today: today), ('Overdue by 3 days', true));
    expect(followUpWhen(DateTime(2026, 11, 9), today: today).$1, 'Tomorrow');
    expect(followUpWhen(DateTime(2026, 11, 15), today: today).$2, isFalse);
  });

  test('vital trends split blood pressure and merge recorded vitals', () {
    final d = detail({'id': 1}, enc: [
      Encounter.visit({'id': 1, 'patient_id': 1, 'appointment_date': '2026-10-01 10:00:00', 'bp': '150/95', 'pulse': '88', 'weight': '72 kg'}),
      Encounter.visit({'id': 2, 'patient_id': 1, 'appointment_date': '2026-11-01 10:00:00', 'bp': '138/86'}),
    ], vitals: [
      VitalReading({'name': 'Blood sugar (F)', 'value': '126', 'measured_at': '2026-11-02 08:00:00'}),
      VitalReading({'name': 'BP', 'value': '130/84', 'measured_at': '2026-11-03 08:00:00'}),
    ]);
    final s = vitalSeries(d);
    expect(s['BP systolic']!.map((p) => p.$2), [150, 138, 130]);
    expect(s['BP diastolic']!.last.$2, 84);
    expect(s['Weight']!.single.$2, 72);
    expect(s['Blood sugar (F)']!.single.$2, 126);
  });

  group('toolkits', () {
    SpecialtyToolkit kit(String id) => specialtyToolkits.firstWhere((k) => k.id == id);
    test('match the hospital specialist names', () {
      expect(kit('paediatrics').matches(['Paediatrics']), isTrue);
      expect(kit('paediatrics').matches(['Pediatric Surgery']), isTrue);
      expect(kit('obstetrics').matches(['Obstetrics & Gynaecology']), isTrue);
      expect(kit('chronic').matches(['Cardiology']), isTrue);
      expect(kit('obstetrics').matches(['Orthopaedics']), isFalse);
    });

    test('apply only to relevant patients', () {
      final now = DateTime.now();
      final child = detail({'id': 1, 'dob': '${now.year - 3}-01-01', 'gender': 'Male'});
      final woman = detail({'id': 2, 'dob': '${now.year - 28}-01-01', 'gender': 'Female'});
      final man = detail({'id': 3, 'dob': '${now.year - 60}-01-01', 'gender': 'Male'});
      expect(kit('paediatrics').appliesTo(child, const []), isTrue);
      expect(kit('paediatrics').appliesTo(woman, const []), isFalse);
      expect(kit('obstetrics').appliesTo(woman, const []), isTrue);
      expect(kit('obstetrics').appliesTo(man, const []), isFalse);
      expect(kit('chronic').appliesTo(man, const []), isTrue);
      expect(kit('chronic').appliesTo(child, const []), isFalse);
      expect(kit('followups').appliesTo(child, const []), isTrue);
    });
  });

  group('record sync', () {
    late AppDatabase adb;
    late FakeServer server;
    late SyncEngine engine;
    late ClinicalRepository repo;
    late Map<String, dynamic> Function(Map<String, dynamic> op) result;
    late List<Map<String, dynamic>> serverRecords;

    setUp(() async {
      adb = await openTestDb();
      server = FakeServer()..etag = '"m"';
      serverRecords = [];
      result = (op) {
        final p = Map<String, dynamic>.from(op['payload'] as Map);
        final rec = {...p, 'updated_at': '2026-11-08 10:00:00', 'updated_by_name': 'Anil Mehta'};
        serverRecords.removeWhere((r) => r['uuid'] == p['uuid']);
        serverRecords.add(rec);
        return {'op_id': op['op_id'], 'status': 'ok', 'record': rec};
      };
      server.routes['sync/masters'] = (_, o) async => o.headers['If-None-Match'] == server.etag ? (304, null) : (200, mastersBody);
      server.routes['sync/pull'] = (_, _) async {
        final b = pullBody(patients: [patient(1)], visits: [visit(10, 1)], scope: [1], alive: []);
        (b['data'] as Map)['clinical_records'] = serverRecords;
        return (200, b);
      };
      server.routes['sync/push'] = (b, _) async => (200, {'results': [for (final op in (b!['ops'] as List)) result(Map<String, dynamic>.from(op as Map))]});
      engine = SyncEngine(api: server.client(), adb: adb, modules: [DoctorSyncModule()], onDataChanged: () {}, onUnauthorized: () {});
      repo = ClinicalRepository(adb);
      await engine.sync();
    });

    tearDown(() async {
      engine.dispose();
      await adb.db.close();
    });

    test('saved offline, uploaded later, then synced', () async {
      server.offline = true;
      final f = ClinicalRecord.create(patientId: 1, type: RecordType.followup, eventDate: DateTime(2026, 11, 15), data: {'reason': 'CBC review'});
      await repo.save(f);
      await engine.sync();
      expect((await repo.forPatient(1)).single.pending, isTrue);

      server.offline = false;
      await engine.sync();
      final saved = (await repo.forPatient(1)).single;
      expect(saved.pending, isFalse);
      expect(saved.updatedByName, 'Anil Mehta');
      final sent = ((server.bodiesFor('sync/push').single!['ops'] as List).single as Map);
      expect(sent['type'], 'record.save');
      expect((sent['payload'] as Map)['event_date'], '2026-11-15');
      expect(((sent['payload'] as Map)['data'] as Map)['reason'], 'CBC review');
    });

    test('a pull never overwrites an unsent local edit', () async {
      final f = ClinicalRecord.create(patientId: 1, type: RecordType.followup, eventDate: DateTime(2026, 11, 15), data: {'reason': 'A'});
      await repo.save(f);
      await engine.sync(); // uploaded: server has "A"
      f.data['reason'] = 'B';
      await repo.save(f);
      await adb.db.transaction((tx) => ClinicalRepository.applyServer(tx, serverRecords));
      expect((await repo.forPatient(1)).single.str('reason'), 'B');
    });

    test('rejected records keep the reason and leave the queue', () async {
      result = (op) => {'op_id': op['op_id'], 'status': 'rejected', 'message': 'This patient is assigned to another doctor.'};
      await repo.save(ClinicalRecord.create(patientId: 1, type: RecordType.growth, eventDate: DateTime(2026, 11, 8), data: {'weight_kg': 12.4}));
      await engine.sync();
      final r = (await repo.forPatient(1)).single;
      expect(r.error, contains('another doctor'));
      expect((await adb.db.query('outbox')), isEmpty);
    });

    test('deleting syncs as a soft delete and hides the record', () async {
      final v = ClinicalRecord.create(patientId: 1, type: RecordType.vaccination, eventDate: DateTime(2026, 1, 1), status: 'given', data: {'vaccine': 'BCG', 'dose': '1'});
      await repo.save(v);
      await engine.sync();
      await repo.delete(v);
      await engine.sync();
      expect(await repo.forPatient(1), isEmpty);
      expect(((server.bodiesFor('sync/push').last!['ops'] as List).single as Map)['payload']['deleted'], isTrue);
    });

    test('follow-ups due appear on Today, future ones do not', () async {
      await repo.save(ClinicalRecord.create(patientId: 1, type: RecordType.followup, eventDate: DateTime.now().subtract(const Duration(days: 2)), data: {'reason': 'Overdue'}));
      await repo.save(ClinicalRecord.create(patientId: 1, type: RecordType.followup, eventDate: DateTime.now().add(const Duration(days: 5)), data: {'reason': 'Later'}));
      final today = await DoctorRepository(adb, doctorId).today();
      expect(today.followUps.single.str('reason'), 'Overdue');
    });
  });

  test('database upgrade from version 1 adds the clinical records table', () async {
    sqfliteFfiInit();
    final path = inMemoryDatabasePath;
    final v1 = await databaseFactoryFfi.openDatabase(path,
        options: OpenDatabaseOptions(version: 1, singleInstance: false, onCreate: (db, _) => db.execute('CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT)')));
    // Same connection, upgraded in place by the real migration.
    await AppDatabase.onUpgrade(v1, 1, 2);
    final tables = (await v1.rawQuery("SELECT name FROM sqlite_master WHERE type='table'")).map((r) => r['name']).toList();
    expect(tables, contains('clinical_records'));
    await v1.close();
  });

  testWidgets('Care tab shows paediatric tools for a child; Today lists follow-ups due', (t) async {
    late AppDatabase adb;
    await t.runAsync(() async {
      adb = await openTestDb();
      final now = DateTime.now();
      await DoctorSyncModule.applyMasters(adb, mastersBody);
      await DoctorSyncModule.applyPull(
          adb,
          pullBody(
            patients: [{...patient(1, name: 'Baby Kale', allergies: ''), 'dob': ymd(now.subtract(const Duration(days: 50))), 'age': 0, 'month': 1, 'day': 20}],
            visits: [visit(10, 1, date: today(-30))],
            scope: [1],
            alive: [],
          ));
      await ClinicalRepository(adb).save(ClinicalRecord.create(patientId: 1, type: RecordType.followup, eventDate: now, data: {'reason': 'Weight check'}));
    });
    t.view.physicalSize = const Size(1080, 2340);
    t.view.devicePixelRatio = 3;
    addTearDown(t.view.reset);
    final session = StaffSession.fromJson({
      'staff': {'id': doctorId, 'name': 'Anil', 'surname': 'Mehta', 'role': 'Doctor', 'modules': ['doctor'], 'specialties': ['Paediatrics']},
      'hospital': {'hospital_name': 'Skylark Hospital'},
    });
    final server = FakeServer()..offline = true;
    await t.pumpWidget(ProviderScope(
      overrides: [authControllerProvider.overrideWith((ref) => _FakeAuth(SignedIn(session: session, serverUrl: 'https://hms.test', api: server.client(), db: adb)))],
      child: MaterialApp(theme: buildTheme(), home: const DoctorHome()),
    ));
    // Real database I/O completes outside the fake clock: keep pumping until nothing is loading.
    Future<void> settle() async {
      for (var i = 0; i < 6 || (i < 60 && find.byType(Shimmer, skipOffstage: false).evaluate().isNotEmpty); i++) {
        await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 50)));
        await t.pump();
      }
      await t.pumpAndSettle();
    }

    await settle();
    expect(find.text('Follow-ups due'), findsOneWidget);
    expect(find.text('Weight check'), findsOneWidget);

    await t.tap(find.text('Weight check'));
    await settle();
    expect(find.byType(PatientScreen), findsOneWidget);
    await t.tap(find.text('Care'));
    for (var k = 0; k < 15; k++) {
      await t.pump(const Duration(milliseconds: 50)); // let the tab page animation run so the Care tab is built
    }
    await settle();
    expect(find.text('Follow-ups'), findsNWidgets(2)); // toolkit chip + card
    final care = find.descendant(of: find.byType(CareTab), matching: find.byType(Scrollable)).first;
    for (final title in ['Vaccination chart', 'Growth', 'Weight-based dose calculator']) {
      await t.scrollUntilVisible(find.text(title), 150, scrollable: care);
      expect(find.text(title), findsOneWidget);
    }
    expect(find.text('Pregnancy'), findsNothing, reason: 'not relevant for a 50-day-old');
    expect(find.textContaining('overdue'), findsWidgets, reason: 'birth doses are overdue at 50 days');
    expect(t.takeException(), isNull);
  });
}
