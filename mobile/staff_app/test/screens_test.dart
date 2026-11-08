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
import 'package:staff_app/features/doctor/data/models.dart';
import 'package:staff_app/features/doctor/ui/doctor_home.dart';
import 'package:staff_app/features/doctor/ui/prescription_editor_screen.dart';

import 'support/fake_server.dart';

class _FakeAuth extends AuthController {
  _FakeAuth(SignedIn s) : super(SessionStore()) {
    state = s;
  }
}

void main() {
  late AppDatabase adb;
  late FakeServer server;

  Future<void> settle(WidgetTester t, [int rounds = 6]) async {
    for (var i = 0; i < rounds; i++) {
      await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 30)));
      await t.pump();
    }
  }

  Widget app(Widget home) {
    final session = StaffSession.fromJson({
      'staff': {'id': doctorId, 'name': 'Anil', 'surname': 'Mehta', 'role': 'Doctor', 'email': 'anil@skylark.test', 'modules': ['doctor'], 'department': 'Medicine'},
      'hospital': {'hospital_name': 'Skylark Hospital', 'primary_color': '#1A7A7E'},
    });
    final signedIn = SignedIn(session: session, serverUrl: 'https://hms.test', api: server.client(), db: adb);
    return ProviderScope(
      overrides: [authControllerProvider.overrideWith((ref) => _FakeAuth(signedIn))],
      child: MaterialApp(theme: buildTheme(), home: home),
    );
  }

  setUp(() {
    server = FakeServer()..offline = true;
  });

  Future<void> seed(WidgetTester t) async {
    await t.runAsync(() async {
      adb = await openTestDb();
      await DoctorSyncModule.applyMasters(adb, mastersBody);
      await DoctorSyncModule.applyPull(
        adb,
        pullBody(
          patients: [patient(1), patient(2, name: 'Ravi Kale', allergies: 'None')],
          visits: [visit(10, 1), visit(11, 2, opdId: 101)],
          prescriptions: [serverRx(500)],
          scope: [1, 2],
          alive: [500],
        ),
      );
    });
    t.view.physicalSize = const Size(1080, 2340);
    t.view.devicePixelRatio = 3;
    addTearDown(t.view.reset);
  }

  testWidgets('Today shows the OPD queue with Rx status; patient opens with allergies and history', (t) async {
    await seed(t);
    await t.pumpWidget(app(const DoctorHome()));
    await settle(t);

    expect(find.text('Dr. Anil Mehta'), findsOneWidget);
    expect(find.text('Asha Patil'), findsOneWidget);
    expect(find.text('Ravi Kale'), findsOneWidget);
    expect(find.text('Rx done'), findsOneWidget);
    expect(find.text('Write Rx'), findsOneWidget, reason: 'Ravi has no prescription yet');
    expect(find.textContaining('Offline'), findsNothing, reason: 'no sync attempted in this test');

    await t.tap(find.text('Asha Patil'));
    await settle(t);
    await t.pumpAndSettle();
    expect(find.textContaining('Penicillin', findRichText: true), findsWidgets);
    expect(find.text('Repeat'), findsOneWidget);
    expect(find.textContaining('Paracetamol 650'), findsWidgets);
    expect(find.textContaining('A90'), findsWidgets, reason: 'diagnosis on the current visit');

    await t.tap(find.text('Prescriptions'));
    await t.pumpAndSettle();
    await t.tap(find.text('Visits'));
    await t.pumpAndSettle();
    expect(find.text('Fever for 3 days'), findsOneWidget);
    await t.tap(find.text('Labs'));
    await t.pumpAndSettle();
    expect(find.text('No lab or imaging reports'), findsOneWidget);
    await t.tap(find.text('Timeline'));
    await t.pumpAndSettle();
    expect(t.takeException(), isNull);
  });

  testWidgets('Patients tab searches the phone', (t) async {
    await seed(t);
    await t.pumpWidget(app(const DoctorHome()));
    await settle(t);
    await t.tap(find.text('Patients').last);
    await settle(t);
    await t.enterText(find.byType(TextField), 'ravi');
    await t.pump(const Duration(milliseconds: 250));
    await settle(t);
    expect(find.text('Ravi Kale'), findsOneWidget);
    expect(find.text('Asha Patil'), findsNothing);
    expect(find.textContaining('Search all hospital patients'), findsOneWidget);
  });

  testWidgets('Prescription editor: repeat last Rx, validate, save offline', (t) async {
    await seed(t);
    final rx = await t.runAsync(() async {
      final rows = await adb.db.query('prescriptions');
      return Prescription.fromRow(rows.single);
    });
    final enc = Encounter.visit(visit(10, 1));
    await t.pumpWidget(app(Builder(
      builder: (context) => Scaffold(
        body: Center(
          child: FilledButton(
            onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => PrescriptionEditorScreen.create(patientId: 1, encounter: enc, copyFrom: rx))),
            child: const Text('open'),
          ),
        ),
      ),
    )));
    await t.tap(find.text('open'));
    await settle(t);
    await t.pumpAndSettle();

    expect(find.text('New prescription'), findsOneWidget);
    expect(find.text('Paracetamol 650'), findsOneWidget, reason: 'copied from last prescription');
    expect(find.text('1 Tablet'), findsOneWidget);
    expect(find.text('TDS'), findsOneWidget);
    expect(find.textContaining('Penicillin'), findsWidgets, reason: 'allergy banner on the editor');

    await t.drag(find.byType(ListView).first, const Offset(0, -2000));
    await t.pumpAndSettle();
    expect(find.text('Advice (diet, follow-up, precautions)'), findsOneWidget);

    await t.tap(find.text('Save prescription'));
    await settle(t);
    await t.pumpAndSettle();
    expect(find.text('New prescription'), findsNothing, reason: 'editor closes after saving');

    final rows = await t.runAsync(() => adb.db.query('prescriptions', where: 'status = ?', whereArgs: ['pending']));
    expect(rows!.length, 1);
    final saved = Prescription.fromRow(rows.single);
    expect(saved.items.single.medicineId, 1);
    expect(saved.items.single.id, isNull, reason: 'copied lines are new lines, not edits of the old prescription');
    expect(saved.footerNote, 'Plenty of fluids');
    final outbox = await t.runAsync(() => adb.db.query('outbox'));
    expect(outbox!.length, 1);
    expect(t.takeException(), isNull);
  });

  testWidgets('Prescription editor blocks saving an incomplete medicine', (t) async {
    await seed(t);
    final enc = Encounter.visit(visit(11, 2, opdId: 101));
    final draftRx = Prescription(
      localId: 'x', serverId: null, patientId: 2, kind: 'opd', caseRefId: 11, status: RxStatus.synced, version: null, error: null,
      j: {'items': [{'medicine_id': 3, 'medicine_name': 'Azithral 500', 'category_id': 1}]},
    );
    await t.pumpWidget(app(PrescriptionEditorScreen.create(patientId: 2, encounter: enc, copyFrom: draftRx)));
    await settle(t);
    await t.pumpAndSettle();
    await t.tap(find.text('Save prescription'));
    await t.pumpAndSettle();
    expect(find.text('Almost done'), findsOneWidget);
    expect(find.textContaining('Azithral 500: choose a dose.'), findsOneWidget);
    expect(find.textContaining('Azithral 500: choose how often.'), findsOneWidget);
  });
}
