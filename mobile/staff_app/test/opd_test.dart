import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:staff_app/core/auth/auth_controller.dart';
import 'package:staff_app/core/auth/session_store.dart';
import 'package:staff_app/core/auth/staff.dart';
import 'package:staff_app/core/db/app_database.dart';
import 'package:staff_app/core/providers.dart';
import 'package:staff_app/core/sync/sync_engine.dart';
import 'package:staff_app/core/ui/motion.dart';
import 'package:staff_app/core/ui/theme.dart';
import 'package:staff_app/features/doctor/data/billing.dart';
import 'package:staff_app/features/doctor/data/clinical.dart';
import 'package:staff_app/features/doctor/data/doctor_repository.dart';
import 'package:staff_app/features/doctor/data/doctor_sync.dart';
import 'package:staff_app/features/doctor/data/models.dart';
import 'package:staff_app/features/doctor/data/queue.dart';
import 'package:staff_app/features/doctor/print/prescription_page.dart';
import 'package:staff_app/features/doctor/print/print_settings.dart';
import 'package:staff_app/features/doctor/print/print_strings.dart';
import 'package:staff_app/features/doctor/ui/doctor_home.dart';

import 'support/fake_server.dart';

class _FakeAuth extends AuthController {
  _FakeAuth(AuthState s) : super(SessionStore()) {
    state = s;
  }
}

final staff = Staff.fromJson({'id': doctorId, 'name': 'Anil', 'surname': 'Mehta', 'role': 'Doctor', 'qualification': 'MBBS, MD', 'modules': ['doctor']});
final hospital = Hospital.fromJson({'hospital_name': 'Skylark Hospital', 'address': 'Akola', 'phone': '0724-000000'});

Future<AppDatabase> seeded() async {
  final adb = await openTestDb();
  final m = Map<String, dynamic>.from(mastersBody)
    ..['charges'] = [
      {'id': 70, 'name': 'Dressing', 'category': 'Procedures', 'type': 'OPD', 'standard_charge': 200, 'tax': 18},
      {'id': 71, 'name': 'Injection', 'category': 'Procedures', 'type': 'OPD', 'standard_charge': 50, 'tax': 0},
    ]
    ..['staff_settings'] = {}
    ..['has_print_header'] = false;
  await DoctorSyncModule.applyMasters(adb, m);
  final body = pullBody(
    patients: [patient(1), patient(2, name: 'Ravi Kale', allergies: ''), patient(3, name: 'Sunita Deshmukh', allergies: '')],
    visits: [
      {...visit(10, 1), 'token': 2},
      {...visit(11, 2, opdId: 101), 'token': 1},
      {...visit(12, 3, opdId: 102), 'token': 3},
    ],
    prescriptions: [serverRx(500)], // patient 1 (token 2) already has an Rx
    scope: [1, 2, 3],
    alive: [500],
  );
  (body['data'] as Map)['billing'] = [
    {
      'patient_id': 1,
      'charges': [
        {'id': 9, 'opd_id': 100, 'date': '${today()} 10:00:00', 'charge_name': 'OPD consultation', 'qty': 1, 'apply_charge': 300, 'tax': 0, 'amount': 300},
      ],
      'payments': [
        {'id': 4, 'type': 'payment', 'amount': 200, 'payment_mode': 'Cash', 'payment_date': '${today()} 10:05:00'},
      ],
    },
  ];
  await DoctorSyncModule.applyPull(adb, body);
  return adb;
}

void main() {
  group('print text', () {
    test('labels, dosing, durations and instructions per language', () {
      const hi = PrintStrings('hi');
      const mr = PrintStrings('mr');
      expect(hi.t('advice'), 'सलाह');
      expect(mr.t('advice'), 'सल्ला');
      expect(const PrintStrings('hi', bilingual: true).t('advice'), 'Advice / सलाह');
      expect(hi.dosing('TDS'), 'दिन में 3 बार (TDS)');
      expect(mr.dosing('bd'), 'दिवसातून 2 वेळा (bd)');
      expect(const PrintStrings('en').dosing('Weekly custom'), 'Weekly custom');
      expect(hi.duration('5 days'), '5 दिन');
      expect(mr.duration('2 weeks'), '2 आठवडे');
      expect(hi.instruction('After food, with water'), 'खाने के बाद, with water');
      expect(mr.instruction('If needed (SOS)'), 'गरज असल्यास');
      expect(hi.sex('Female'), 'महिला');
    });

    test('settings round-trip and per-language header falls back to English', () {
      final s = PrintSettings.defaults(staff, hospital)
        ..paper = PaperSize.a5
        ..area = PrintArea.bottomHalf
        ..header = HeaderMode.blank
        ..headers['hi'] = HeaderText(hospital: 'स्कायलार्क अस्पताल');
      final back = PrintSettings.fromJson(jsonDecode(jsonEncode(s.toJson())) as Map<String, dynamic>);
      expect(back.paper, PaperSize.a5);
      expect(back.area, PrintArea.bottomHalf);
      expect(back.header, HeaderMode.blank);
      expect(back.headerFor('hi').hospital, 'स्कायलार्क अस्पताल');
      expect(back.headerFor('hi').address, 'Akola', reason: 'empty Hindi field uses English');
      expect(back.headerFor('en').doctor, 'Dr. Anil Mehta');
      expect(back.headerFor('en').qualification, 'MBBS, MD');
    });

    test('settings: saved locally and queued; server copy wins once uploaded', () async {
      final adb = await seeded();
      final repo = PrintSettingsRepository(adb);
      final s = await repo.load(staff, hospital);
      expect(s.headerFor('en').hospital, 'Skylark Hospital');
      s.language = 'mr';
      await repo.save(s);
      await repo.save(s..paper = PaperSize.a5); // second save replaces the queued op
      expect((await adb.db.query('outbox', where: "type = 'settings.save'")).length, 1);
      expect((await repo.load(staff, hospital)).paper, PaperSize.a5);

      // After upload, the server copy (e.g. changed on another phone) is used.
      await adb.db.delete('outbox');
      await adb.db.rawInsert('INSERT OR REPLACE INTO lookups (key, json) VALUES (?, ?)', [
        'server_settings',
        jsonEncode({'staff_settings': {'print': (s.copy()..language = 'hi').toJson()}, 'has_print_header': true}),
      ]);
      expect((await repo.load(staff, hospital)).language, 'hi');
      expect(await repo.hasLetterheadImage(), isTrue);
      await adb.db.close();
    });
  });

  testWidgets('printed page fits every paper, half and header mode without overflow', (t) async {
    final rx = Prescription(
      localId: 'x', serverId: 500, patientId: 1, kind: 'opd', caseRefId: 10, status: RxStatus.synced, version: null, error: null,
      j: {
        ...serverRx(500),
        'print_findings': true,
        'finding_description': 'Throat congested',
        'items': [
          for (var i = 0; i < 8; i++) {'medicine_name': 'Medicine $i 500 mg', 'composition': 'Generic $i', 'dosage_label': '1 Tablet', 'interval_name': 'TDS', 'duration_name': '5 days', 'instruction': 'After food'},
        ],
        'pathology': [{'id': 41, 'name': 'CBC'}],
      },
    );
    final job = PrintJob(
      patient: Patient(patient(1)),
      rx: rx,
      encounter: Encounter.visit({...visit(10, 1), 'token': 4}),
      diagnoses: [IcdCode(id: 5, code: 'A90', description: 'Dengue fever')],
      allergies: const ['Penicillin'],
      nextVisit: DateTime(2026, 11, 15),
      token: 4,
    );
    for (final paper in PaperSize.values) {
      for (final area in PrintArea.values) {
        for (final header in [HeaderMode.text, HeaderMode.blank, HeaderMode.none]) {
          for (final lang in ['en', 'hi']) {
            final s = PrintSettings.defaults(staff, hospital)
              ..paper = paper
              ..area = area
              ..header = header
              ..headers['en']!.regNo = 'MMC-12345';
            final size = paperPoints(paper);
            await t.pumpWidget(MaterialApp(
              home: Center(child: FittedBox(child: SizedBox(width: size.width, height: size.height, child: PrescriptionPage(job: job, settings: s, lang: lang)))),
            ));
            expect(t.takeException(), isNull, reason: '$paper $area $header $lang');
          }
        }
      }
    }
    // Spot-check content in Hindi on A4.
    expect(find.text('Medicine 0 500 mg'), findsOneWidget);
    expect(find.text('दिन में 3 बार (TDS)'), findsNWidgets(8));
    expect(find.textContaining('MMC-12345'), findsWidgets);
  });

  group('services & billing', () {
    test('price matches the server rule: charge x qty less discount, plus tax', () {
      final c = ChargeOption({'id': 70, 'name': 'Dressing', 'standard_charge': 200, 'tax': 18});
      final p = c.price(2, 10);
      expect(p.apply, 360);
      expect(p.tax, 64.8);
      expect(p.total, 424.8);
    });

    test('added offline as pending, then billed after sync; refused ones keep the reason', () async {
      final adb = await seeded();
      final server = FakeServer()..etag = '"m"';
      server.routes['sync/masters'] = (_, _) async => (304, null);
      server.routes['sync/pull'] = (_, _) async => (200, pullBody(patients: [patient(1)], visits: [visit(10, 1)], scope: [1], alive: [500], prescriptions: [serverRx(500)]));
      var reject = false;
      server.routes['sync/push'] = (b, _) async => (200, {
            'results': [
              for (final op in (b!['ops'] as List))
                reject
                    ? {'op_id': (op as Map)['op_id'], 'status': 'rejected', 'message': 'The visit is closed; add charges at the billing counter.'}
                    : {
                        'op_id': (op as Map)['op_id'],
                        'status': 'ok',
                        'charge': {'id': 99, 'opd_id': 100, 'date': '${today()} 11:00:00', 'charge_name': 'Dressing', 'qty': 2, 'apply_charge': 400, 'tax': 18, 'amount': 472},
                      },
            ],
          });
      final engine = SyncEngine(api: server.client(), adb: adb, modules: [DoctorSyncModule()], onDataChanged: () {}, onUnauthorized: () {});
      final repo = BillingRepository(adb);
      final enc = Encounter.visit(visit(10, 1));
      final dressing = (await repo.chargeOptions()).firstWhere((c) => c.id == 70);

      var bill = await repo.bill(1);
      expect(bill.totalCharges, 300);
      expect(bill.balance, 100);

      await repo.addService(encounter: enc, charge: dressing, qty: 2);
      bill = await repo.bill(1);
      expect(bill.pending.single.total, 472);
      final payload = jsonDecode((await adb.db.query('outbox', where: "type = 'charge.add'")).single['payload'] as String) as Map;
      expect(payload, containsPair('charge_id', 70));
      expect(payload, containsPair('case_ref_id', 10));
      expect(payload, containsPair('qty', 2));

      await engine.sync();
      bill = await repo.bill(1);
      expect(bill.pending, isEmpty);
      expect(bill.charges.map((c) => c.name), contains('Dressing'), reason: 'kept even though the pull did not send billing');

      reject = true;
      await repo.addService(encounter: enc, charge: dressing, qty: 1);
      await engine.sync();
      bill = await repo.bill(1);
      expect(bill.pending.single.error, contains('billing counter'));
      await repo.dismissPending(1, bill.pending.single.opId);
      expect((await repo.bill(1)).pending, isEmpty);
      engine.dispose();
      await adb.db.close();
    });
  });

  group('queue', () {
    test('token order, Rx counts as seen, record id is stable per visit', () async {
      final adb = await seeded();
      final d = await DoctorRepository(adb, doctorId).today();
      expect(d.queue.map((e) => e.token), [1, 2, 3]);
      expect(d.queue.map((e) => e.state), [QueueState.waiting, QueueState.seen, QueueState.waiting]);
      expect(queueRecordId(11), queueRecordId(11));
      expect(queueRecordId(11), isNot(queueRecordId(12)));

      await ClinicalRepository(adb).save(queueRecord(d.queue.first.encounter, QueueState.withDoctor));
      final d2 = await DoctorRepository(adb, doctorId).today();
      expect(d2.queue.first.state, QueueState.withDoctor);
      await adb.db.close();
    });
  });

  test('insights summarise visits, prescriptions, services and top items', () async {
    final adb = await seeded();
    final i = await DoctorRepository(adb, doctorId).insights(7);
    expect(i.visits, 3);
    expect(i.patients, 3);
    expect(i.prescriptions, 1);
    expect(i.avgMedicines, 1);
    expect(i.billed, 300);
    expect(i.topMedicines.single, ('Paracetamol 650', 1));
    expect(i.topDiagnoses.single.$1, 'A90 Dengue fever');
    expect(i.female, 3);
    expect(i.visitsPerDay.length, 7);
    expect(i.visitsPerDay.last.$2, 3);
    await adb.db.close();
  });

  testWidgets('Today: queue chips, Call next moves the next token to "with doctor"', (t) async {
    late AppDatabase adb;
    await t.runAsync(() async => adb = await seeded());
    t.view.physicalSize = const Size(1080, 2340);
    t.view.devicePixelRatio = 3;
    addTearDown(t.view.reset);
    final session = StaffSession(staff: staff, hospital: hospital);
    await t.pumpWidget(ProviderScope(
      overrides: [authControllerProvider.overrideWith((ref) => _FakeAuth(SignedIn(session: session, serverUrl: 'x', api: (FakeServer()..offline = true).client(), db: adb)))],
      child: MaterialApp(theme: buildTheme(), home: const DoctorHome()),
    ));
    Future<void> settle() async {
      for (var i = 0; i < 6 || (i < 60 && find.byType(Shimmer, skipOffstage: false).evaluate().isNotEmpty); i++) {
        await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 50)));
        await t.pump(const Duration(milliseconds: 50));
      }
      await t.pumpAndSettle();
    }

    await settle();
    expect(find.text('OPD queue'), findsOneWidget);
    expect(find.text('Waiting 2'), findsOneWidget);
    expect(find.text('Seen 1', skipOffstage: false), findsOneWidget);
    expect(find.textContaining('Call next · Token 1'), findsOneWidget);

    await t.tap(find.textContaining('Call next'));
    await settle();
    final rec = await t.runAsync(() => adb.db.query('clinical_records', where: "type = 'opd_status'"));
    expect(jsonDecode(rec!.single['json'] as String)['state'], 'with_doctor');
    expect(jsonDecode(rec.single['json'] as String)['visit_id'], 11, reason: 'token 1 = Ravi');
    expect(find.text('Ravi Kale'), findsWidgets, reason: 'patient screen opened');
    expect(t.takeException(), isNull);
  });
}
