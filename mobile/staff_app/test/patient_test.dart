import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:staff_app/core/auth/auth_controller.dart';
import 'package:staff_app/core/auth/patient_account.dart';
import 'package:staff_app/core/auth/session_store.dart';
import 'package:staff_app/core/db/app_database.dart';
import 'package:staff_app/core/providers.dart';
import 'package:staff_app/core/ui/theme.dart';
import 'package:staff_app/features/doctor/ui/rx_widgets.dart';
import 'package:staff_app/features/patient/data/patient_records.dart';
import 'package:staff_app/features/patient/ui/patient_home.dart';

import 'support/fake_server.dart';

Map<String, dynamic> snapshot({bool radiology = true}) => {
      'modules': {'opd': true, 'ipd': true, 'pathology': true, 'radiology': radiology, 'my_appointments': true},
      'data': {
        'patients': [patient(1)],
        'visits': [visit(10, 1)],
        'admissions': [],
        'diagnoses': [
          {'kind': 'opd', 'case_id': 100, 'codes': [{'id': 5, 'code': 'A90', 'description': 'Dengue fever'}]},
        ],
        'prescriptions': [serverRx(500)],
        'vitals': [],
        'lab_reports': [
          {'id': 1, 'kind': 'pathology', 'patient_id': 1, 'test_name': 'Complete Blood Count', 'reporting_date': today(), 'parameters': [{'name': 'Haemoglobin', 'value': '11.2', 'unit': 'g/dL', 'reference_range': '12-15'}]},
        ],
        'timeline': [],
        'appointments': [
          {'id': 9, 'date': '${today(3)} 11:00:00', 'status': 'approved', 'doctor_name': 'Anil Mehta', 'department': 'Medicine'},
          {'id': 8, 'date': '${today(-20)} 11:00:00', 'status': 'approved', 'doctor_name': 'Anil Mehta'},
        ],
      },
    };

class _FakeAuth extends AuthController {
  _FakeAuth(AuthState s) : super(SessionStore()) {
    state = s;
  }
}

void main() {
  late AppDatabase adb;
  late FakeServer server;

  setUp(() async {
    adb = await openTestDb();
    server = FakeServer()..etag = '"p1"';
    server.routes['patient/data'] = (_, o) async => o.headers['If-None-Match'] == server.etag ? (304, null) : (200, snapshot());
  });

  tearDown(() => adb.db.close());

  test('records download once, are cached, and are not re-downloaded when unchanged', () async {
    final repo = PatientRepository(adb);
    expect(await repo.load(), isNull);
    expect(await repo.refresh(server.client()), isTrue);
    expect(await repo.refresh(server.client()), isFalse, reason: '304 via ETag');

    final r = (await repo.load())!;
    expect(r.patient!.name, 'Asha Patil');
    expect(r.allergies, ['Penicillin']);
    expect(r.prescriptions.single.items.single.medicineName, 'Paracetamol 650');
    expect(r.encounterFor(r.prescriptions.single)!.refId, 10);
    expect(r.diagnoses['opd:10']!.single.code, 'A90');
    expect(r.nextAppointment!.id, 9, reason: 'future, not the past one');
    expect(r.labs.single.parameters.single.value, '11.2');
  });

  test('offline keeps the cached records and reports offline', () async {
    final repo = PatientRepository(adb);
    await repo.refresh(server.client());
    server.offline = true;
    final c = PatientSyncController(repo: repo, api: server.client(), onChanged: () {}, onUnauthorized: () {});
    await c.refresh();
    expect(c.state.phase, PatientSyncPhase.offline);
    expect((await repo.load())!.prescriptions, hasLength(1));
    c.dispose();
  });

  test('expired login pauses refresh and asks to sign in', () async {
    server.routes['patient/data'] = (_, _) async => (401, {'error': {'code': 'token_invalid', 'message': 'Your session has expired. Sign in again.'}});
    var unauthorized = 0;
    final c = PatientSyncController(repo: PatientRepository(adb), api: server.client(), onChanged: () {}, onUnauthorized: () => unauthorized++);
    await c.refresh();
    expect(c.state.phase, PatientSyncPhase.needsSignIn);
    expect(unauthorized, 1);
    c.dispose();
  });

  test('patient session json round-trip and module switches', () {
    final s = PatientSession.fromJson({
      'patient': {'user_id': 31, 'patient_id': 1, 'username': 'pat1', 'name': 'Asha Patil'},
      'modules': {'opd': true, 'radiology': false},
      'hospital': {'hospital_name': 'Skylark Hospital'},
    });
    final back = PatientSession.fromJson(s.toJson());
    expect(back.userId, 31);
    expect(back.firstName, 'Asha');
    expect(back.has('radiology'), isFalse);
    expect(back.has('opd'), isTrue);
  });

  testWidgets('patient home, prescriptions and profile render at phone size', (t) async {
    final session = PatientSession.fromJson({
      'patient': {'user_id': 31, 'patient_id': 1, 'username': 'pat1', 'name': 'Asha Patil', 'known_allergies': 'Penicillin'},
      'modules': {'opd': true, 'ipd': true, 'pathology': true, 'radiology': true, 'my_appointments': true},
      'hospital': {'hospital_name': 'Skylark Hospital'},
    });
    await t.runAsync(() => PatientRepository(adb).refresh(server.client()));
    server.offline = true; // nothing else should need the network
    t.view.physicalSize = const Size(1080, 2340);
    t.view.devicePixelRatio = 3;
    addTearDown(t.view.reset);

    await t.pumpWidget(ProviderScope(
      overrides: [
        authControllerProvider.overrideWith((ref) => _FakeAuth(PatientSignedIn(session: session, serverUrl: 'https://hms.test', api: server.client(), db: adb))),
      ],
      child: MaterialApp(theme: buildTheme(), home: const PatientHome()),
    ));
    for (var i = 0; i < 6; i++) {
      await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 30)));
      await t.pump();
    }
    await t.pumpAndSettle();

    expect(find.text('Asha'), findsOneWidget, reason: 'greeting uses first name');
    expect(find.text('Next appointment'), findsOneWidget);
    expect(find.text('Dr. Anil Mehta'), findsOneWidget);
    expect(find.text('Latest prescription'), findsOneWidget);
    expect(find.textContaining('Penicillin', findRichText: true), findsWidgets);

    await t.ensureVisible(find.byType(PrescriptionCard));
    await t.pumpAndSettle();
    await t.tap(find.byType(PrescriptionCard));
    await t.pumpAndSettle();
    expect(find.text('Your medicines'), findsOneWidget);
    expect(find.text('3 times a day (TDS)'), findsOneWidget);
    expect(find.text('Plenty of fluids'), findsOneWidget);
    await t.pageBack();
    await t.pumpAndSettle();

    await t.tap(find.text('Profile'));
    await t.pumpAndSettle();
    expect(find.text('pat1'), findsOneWidget);
    await t.dragUntilVisible(find.text('Sign out'), find.byType(ListView).last, const Offset(0, -300));
    expect(find.text('Sign out'), findsOneWidget);
    expect(t.takeException(), isNull);
  });
}
