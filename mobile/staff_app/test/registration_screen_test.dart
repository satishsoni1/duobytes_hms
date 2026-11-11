import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:staff_app/core/auth/auth_controller.dart';
import 'package:staff_app/core/auth/session_store.dart';
import 'package:staff_app/core/auth/staff.dart';
import 'package:staff_app/core/db/app_database.dart';
import 'package:staff_app/core/providers.dart';
import 'package:staff_app/core/ui/theme.dart';
import 'package:staff_app/features/doctor/data/doctor_sync.dart';
import 'package:staff_app/features/doctor/ui/patient_screen.dart';
import 'package:staff_app/features/opd/registration_screen.dart';

import 'support/fake_server.dart';

class _FakeAuth extends AuthController {
  _FakeAuth(AuthState s) : super(SessionStore()) {
    state = s;
  }
}

void main() {
  testWidgets('Register a walk-in patient offline: token shown, patient opens, both uploads queued', (t) async {
    late AppDatabase adb;
    await t.runAsync(() async {
      adb = await openTestDb();
      await DoctorSyncModule.applyMasters(adb, {
        ...mastersBody,
        'opd_doctors': [{'id': doctorId, 'name': 'Dr. Anil Mehta', 'department': 'General Medicine', 'charge_id': 70}],
        'charges': [{'id': 70, 'name': 'OPD Consultation', 'standard_charge': 300, 'tax': 0}, {'id': 71, 'name': 'Dressing', 'standard_charge': 100, 'tax': 0}],
      });
    });
    t.view.physicalSize = const Size(1080, 2340);
    t.view.devicePixelRatio = 3;
    addTearDown(t.view.reset);
    final session = StaffSession.fromJson({
      'staff': {'id': doctorId, 'name': 'Anil', 'surname': 'Mehta', 'role': 'Doctor', 'modules': ['doctor']},
      'hospital': {'hospital_name': 'Skylark Hospital'},
    });
    final server = FakeServer()..offline = true;
    await t.pumpWidget(ProviderScope(
      overrides: [authControllerProvider.overrideWith((r) => _FakeAuth(SignedIn(session: session, serverUrl: 'https://hms.test', api: server.client(), db: adb)))],
      child: MaterialApp(theme: buildTheme(), home: const RegisterPatientScreen()),
    ));
    Future<void> settle() async {
      for (var i = 0; i < 8; i++) {
        await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 40)));
        await t.pump();
      }
    }

    await settle();
    await t.enterText(find.widgetWithText(TextField, 'Full name *'), 'Sunita Yadav');
    await t.enterText(find.widgetWithText(TextField, 'Mobile number'), '9812345678');
    await t.tap(find.text('Female'));
    await t.enterText(find.widgetWithText(TextField, 'Age (years)'), '31');
    await settle();
    await t.scrollUntilVisible(find.textContaining('Total ₹'), 200, scrollable: find.byType(Scrollable).first);
    expect(find.text('OPD Consultation · ₹300'), findsOneWidget);
    expect(find.textContaining('Total ₹300'), findsOneWidget, reason: 'doctor and consultation charge picked automatically');

    await t.tap(find.text('Register & add to queue'));
    await settle();
    expect(find.text('Token 1'), findsOneWidget);
    await t.tap(find.text('Done'));
    await settle();
    await t.pumpAndSettle();
    expect(find.byType(PatientScreen), findsOneWidget);
    expect(find.text('Sunita Yadav'), findsWidgets);

    final outbox = await t.runAsync(() => adb.db.query('outbox', orderBy: 'seq'));
    expect(outbox!.map((r) => r['type']), ['patient.register', 'visit.create']);
    expect(t.takeException(), isNull);
  });
}
