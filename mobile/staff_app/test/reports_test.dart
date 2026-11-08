import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:staff_app/core/auth/auth_controller.dart';
import 'package:staff_app/core/auth/session_store.dart';
import 'package:staff_app/core/auth/staff.dart';
import 'package:staff_app/core/db/app_database.dart';
import 'package:staff_app/core/providers.dart';
import 'package:staff_app/core/ui/motion.dart';
import 'package:staff_app/core/ui/theme.dart';
import 'package:staff_app/features/doctor/data/doctor_repository.dart';
import 'package:staff_app/features/doctor/data/doctor_sync.dart';
import 'package:staff_app/features/doctor/data/lab_flags.dart';
import 'package:staff_app/features/doctor/data/models.dart';
import 'package:staff_app/features/doctor/ui/doctor_home.dart';
import 'package:staff_app/features/doctor/ui/doctor_providers.dart';
import 'package:staff_app/features/doctor/ui/reports_screen.dart';

import 'support/fake_server.dart';

Map<String, dynamic> lab(int id, int patientId, String test, {String? reported, List<List<String>> params = const [], bool file = false, String kind = 'pathology'}) => {
      'id': id,
      'kind': kind,
      'patient_id': patientId,
      'test_name': test,
      'reporting_date': reported,
      'collection_date': today(-3),
      'has_file': file,
      'file_name': file ? 'report.pdf' : null,
      'parameters': [for (final p in params) {'name': p[0], 'value': p[1], 'unit': p[2], 'reference_range': p[3]}],
    };

class _FakeAuth extends AuthController {
  _FakeAuth(AuthState s) : super(SessionStore()) {
    state = s;
  }
}

Future<AppDatabase> seeded() async {
  final adb = await openTestDb();
  final body = pullBody(patients: [patient(1), patient(2, name: 'Ravi Kale', allergies: '')], visits: [visit(10, 1), visit(11, 2, opdId: 101)], scope: [1, 2], alive: []);
  (body['data'] as Map)['lab_reports'] = [
    lab(1, 1, 'Complete Blood Count', reported: today(), params: [
      ['Haemoglobin', '9.1', 'g/dL', '12-15'],
      ['Platelets', '450000', '/uL', '150000 - 410000'],
      ['WBC', '7200', '/uL', '4000-11000'],
    ], file: true),
    lab(2, 2, 'Lipid profile', reported: today(-1), params: [
      ['Total cholesterol', '182', 'mg/dL', '<200'],
    ]),
    lab(3, 2, 'Chest X-Ray', kind: 'radiology'), // awaiting
    lab(4, 1, 'HbA1c', reported: today(-40), params: [
      ['HbA1c', '7.4', '%', '4 to 5.6'],
    ]),
  ];
  await DoctorSyncModule.applyPull(adb, body);
  return adb;
}

void main() {
  group('reference ranges', () {
    test('between, upper and lower limits', () {
      expect(flagFor('9.1', '12-15'), LabFlag.low);
      expect(flagFor('16', '12 – 15'), LabFlag.high);
      expect(flagFor('13', '12 to 15'), LabFlag.normal);
      expect(flagFor('450,000', '150000 - 410000'), LabFlag.high);
      expect(flagFor('182', '<200'), LabFlag.normal);
      expect(flagFor('240', '< 200'), LabFlag.high);
      expect(flagFor('35', '>40'), LabFlag.low);
      expect(flagFor('8', 'up to 10'), LabFlag.normal);
    });

    test('text results and odd ranges are not guessed', () {
      expect(flagFor('Negative', 'Negative'), LabFlag.unknown);
      expect(flagFor('12', ''), LabFlag.unknown);
      expect(flagFor('12', null), LabFlag.unknown);
      expect(flagFor('12', 'Male 13-17, Female 12-15'), LabFlag.unknown, reason: 'sex-specific bands are not guessed');
      expect(flagFor('5', '15-12'), LabFlag.unknown, reason: 'inverted range');
    });

    test('a report lists its abnormal parameters', () {
      final r = LabReport(lab(1, 1, 'CBC', reported: today(), params: [
        ['Hb', '9.1', 'g/dL', '12-15'],
        ['WBC', '7200', '/uL', '4000-11000'],
      ]));
      expect(r.isAbnormal, isTrue);
      expect(r.abnormal.single.$1.name, 'Hb');
      expect(r.abnormal.single.$2, LabFlag.low);
    });
  });

  test('inbox: reported newest first, awaiting last; new until seen; old reports never new', () async {
    final adb = await seeded();
    final repo = DoctorRepository(adb, doctorId);
    var inbox = await repo.reportsInbox();
    expect(inbox.map((e) => e.report.testName), ['Complete Blood Count', 'Lipid profile', 'HbA1c', 'Chest X-Ray']);
    expect(inbox.first.patient!.name, 'Asha Patil');
    expect(inbox.where((e) => e.isNew).map((e) => e.report.id), [1, 2], reason: 'HbA1c is 40 days old; X-ray is not reported yet');

    await repo.markReportsSeen([inbox.first.report.key]);
    inbox = await repo.reportsInbox();
    expect(inbox.where((e) => e.isNew).map((e) => e.report.id), [2]);
    await adb.db.close();
  });

  testWidgets('Reports tab: badge, flags, filters, detail marks seen', (t) async {
    late AppDatabase adb;
    await t.runAsync(() async => adb = await seeded());
    t.view.physicalSize = const Size(1080, 2340);
    t.view.devicePixelRatio = 3;
    addTearDown(t.view.reset);
    final session = StaffSession.fromJson({
      'staff': {'id': doctorId, 'name': 'Anil', 'surname': 'Mehta', 'role': 'Doctor', 'modules': ['doctor']},
      'hospital': {'hospital_name': 'Skylark Hospital'},
    });
    await t.pumpWidget(ProviderScope(
      overrides: [
        authControllerProvider.overrideWith((ref) => _FakeAuth(SignedIn(session: session, serverUrl: 'https://hms.test', api: (FakeServer()..offline = true).client(), db: adb))),
      ],
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
    final reportsBadge = find.descendant(of: find.byType(NavigationBar), matching: find.byType(Badge)).first;
    expect((t.widget<Badge>(reportsBadge)).isLabelVisible, isTrue);
    expect(find.descendant(of: reportsBadge, matching: find.text('2')), findsOneWidget, reason: '2 new reports');
    await t.tap(find.text('Reports'));
    await settle();

    expect(find.text('Medical reports'), findsOneWidget);
    expect(find.text('Complete Blood Count'), findsOneWidget);
    expect(find.text('Haemoglobin 9.1 · Low'), findsOneWidget);
    expect(find.text('Platelets 450000 · High'), findsOneWidget);
    expect(find.text('Within range'), findsOneWidget, reason: 'lipid profile');
    expect(find.text('Awaiting result'), findsNothing);
    await t.scrollUntilVisible(find.textContaining('Collected'), 200, scrollable: find.descendant(of: find.byType(ReportsScreen), matching: find.byType(Scrollable)).last);
    expect(find.textContaining('Collected'), findsOneWidget, reason: 'X-ray awaiting');
    expect(find.text('Awaiting results'), findsOneWidget, reason: 'grouped under its own heading');

    await t.tap(find.text('Abnormal 2'));
    await settle();
    expect(find.text('Lipid profile'), findsNothing);
    expect(find.text('HbA1c'), findsOneWidget);

    await t.tap(find.text('Complete Blood Count'));
    await settle();
    expect(find.byType(ReportDetailScreen), findsOneWidget);
    expect(find.text('Open report.pdf'), findsOneWidget, reason: 'uploaded file can be opened');
    expect(find.byIcon(Icons.arrow_downward_rounded), findsOneWidget);
    expect(find.byIcon(Icons.arrow_upward_rounded), findsOneWidget);
    expect(find.textContaining('Penicillin', findRichText: true), findsWidgets, reason: 'allergies shown with the report');

    final container = ProviderScope.containerOf(t.element(find.byType(ReportDetailScreen)));
    await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 200)));
    final seen = await t.runAsync(() => container.read(doctorRepoProvider).seenReports());
    expect(seen, contains('pathology:1'));
    expect(t.takeException(), isNull);
  });
}
