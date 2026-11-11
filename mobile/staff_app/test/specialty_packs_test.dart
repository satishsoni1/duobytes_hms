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
import 'package:staff_app/features/doctor/data/clinical.dart';
import 'package:staff_app/features/doctor/data/doctor_sync.dart';
import 'package:staff_app/features/doctor/data/models.dart';
import 'package:staff_app/features/doctor/specialty/care_tab.dart';
import 'package:staff_app/features/doctor/specialty/forms/form_spec.dart';
import 'package:staff_app/features/doctor/specialty/forms/specialty_forms.dart';
import 'package:staff_app/features/doctor/specialty/specialty.dart';
import 'package:staff_app/features/doctor/specialty/specialty_packs.dart';
import 'package:staff_app/features/doctor/specialty/specialty_registry.dart';
import 'package:staff_app/features/doctor/ui/doctor_providers.dart';

import 'support/fake_server.dart';

class _FakeAuth extends AuthController {
  _FakeAuth(AuthState s) : super(SessionStore()) {
    state = s;
  }
}

ClinicalRecord rec(String type, Map<String, dynamic> data, {String status = 'active', DateTime? on}) =>
    ClinicalRecord.create(patientId: 1, type: type, status: status, eventDate: on ?? DateTime(2026, 11, 1), data: data);

void main() {
  test('every specialty form uses an open server type and unique keys', () {
    for (final f in SpForms.all) {
      expect(f.type, matches(RegExp(r'^sp_[a-z0-9_]{1,36}$')), reason: f.type);
      final keys = [for (final x in f.fields) ...x.keys];
      expect(keys.toSet().length, keys.length, reason: 'duplicate key in ${f.type}');
      for (final k in keys) {
        expect(k, matches(RegExp(r'^[a-z][a-z0-9_]{0,39}$')), reason: '${f.type}.$k');
      }
      for (final s in f.statuses ?? const <(String, String)>[]) {
        expect(const ['active', 'done', 'cancelled', 'given', 'due', 'closed'], contains(s.$1));
      }
    }
  });

  test('form validation and summaries', () {
    expect(SpForms.dental.validate({}), 'Tooth (FDI) is required');
    expect(SpForms.dental.validate({'tooth': '36'}), isNull);
    final eye = rec(SpForms.eye.type, {'va_re': '6/9', 'va_le': '6/12', 'iop_re': '16', 'diagnosis': 'Myopia'});
    expect(SpForms.eye.summaryOf(eye), 'Visual acuity R 6/9 · L 6/12 · IOP R 16 · L – · Myopia');
    expect(const FieldSpec('itch', 'Itch', kind: FieldKind.scale).display(rec('sp_derm', {'itch': 7})), '7 / 10');
  });

  test('tooth chart state: planned beats finding, extraction done means missing', () {
    final s = toothStates([
      rec(SpForms.dental.type, {'tooth': '36', 'finding': 'Deep caries'}),
      rec(SpForms.dental.type, {'tooth': '36', 'finding': 'Deep caries', 'procedure': 'RCT'}),
      rec(SpForms.dental.type, {'tooth': '48', 'procedure': 'Extraction'}, status: 'done'),
      rec(SpForms.dental.type, {'tooth': '11, 21', 'procedure': 'Scaling'}, status: 'done'),
      rec(SpForms.dental.type, {'tooth': '16', 'finding': 'Healthy'}),
    ]);
    expect(s['36'], ToothState.planned);
    expect(s['48'], ToothState.missing);
    expect(s['11'], ToothState.done);
    expect(s['21'], ToothState.done);
    expect(s['16'], ToothState.healthy);
    expect(s['26'], isNull);
  });

  test('lab trends match common parameter names', () {
    LabReport lab(String date, List<(String, String)> params) => LabReport({
          'id': date.hashCode,
          'kind': 'pathology',
          'reporting_date': date,
          'parameters': [for (final (n, v) in params) {'name': n, 'value': v}],
        });
    final s = labSeries([
      lab('2026-09-01', [('HbA1c (Glycated Haemoglobin)', '8.4'), ('Fasting Blood Sugar', '160 mg/dl')]),
      lab('2026-06-01', [('HbA1c', '9.1'), ('LDL Cholesterol', '130'), ('Remarks', 'see doctor')]),
    ]);
    expect(s['HbA1c']!.map((p) => p.$2), [9.1, 8.4], reason: 'oldest first');
    expect(s['Fasting sugar']!.single.$2, 160);
    expect(s['LDL']!.single.$2, 130);
    expect(s.containsKey('Remarks'), isFalse);
  });

  test('diabetes annual checks due', () {
    final now = DateTime.now();
    expect(diabetesChecksDue(null), ['eye', 'foot', 'kidney']);
    final r = rec(SpForms.diabetes.type, {
      'eye_check': ymd(now.subtract(const Duration(days: 30))),
      'foot_check': ymd(now.subtract(const Duration(days: 400))),
    });
    expect(diabetesChecksDue(r), ['foot', 'kidney']);
  });

  test('opt-in packs follow the specialty; a doctor with none gets only the general tools', () {
    final dentist = {for (final t in specialtyToolkits) if (t.alwaysOn || t.matches(['Dental Surgeon'])) t.id};
    expect(dentist, containsAll(['followups', 'dental']));
    expect(dentist.contains('orthopaedics'), isFalse, reason: '"orthodont" must not match orthopaedics');
    expect({for (final t in specialtyToolkits) if (t.matches(['Orthodontist'])) t.id}, {'dental'});
    expect({for (final t in specialtyToolkits) if (t.matches(['Ophthalmology'])) t.id}, {'ophthalmology'});
    expect({for (final t in specialtyToolkits) if (t.matches(['Homeopathy'])) t.id}, {'ayush'});
    final kit = allSpecialtyToolkits.firstWhere((t) => t.id == 'dental');
    expect(kit.hasRecords([rec(SpForms.dental.type, {'tooth': '11'})]), isTrue);
  });

  testWidgets('Dentist: Care tab shows the tooth chart; tapping a tooth records a finding offline', (t) async {
    late AppDatabase adb;
    await t.runAsync(() async {
      adb = await openTestDb();
      await DoctorSyncModule.applyMasters(adb, mastersBody);
      await DoctorSyncModule.applyPull(adb, pullBody(patients: [patient(1, name: 'Ravi Kale')], visits: [visit(10, 1, date: today())], scope: [1], alive: []));
    });
    t.view.physicalSize = const Size(1080, 2340);
    t.view.devicePixelRatio = 3;
    addTearDown(t.view.reset);
    final session = StaffSession.fromJson({
      'staff': {'id': doctorId, 'name': 'Neha', 'surname': 'Rao', 'role': 'Doctor', 'modules': ['doctor'], 'specialties': ['Dental Surgeon']},
      'hospital': {'hospital_name': 'Skylark Hospital'},
    });
    final server = FakeServer()..offline = true;
    late WidgetRef ref;
    await t.pumpWidget(ProviderScope(
      overrides: [authControllerProvider.overrideWith((r) => _FakeAuth(SignedIn(session: session, serverUrl: 'https://hms.test', api: server.client(), db: adb)))],
      child: MaterialApp(
        theme: buildTheme(),
        home: Consumer(builder: (context, r, _) {
          ref = r;
          final d = r.watch(patientDetailProvider(1)).valueOrNull;
          return Scaffold(body: d == null ? const SizedBox() : CareTab(d: d));
        }),
      ),
    ));
    Future<void> settle() async {
      for (var i = 0; i < 6 || (i < 60 && find.byType(Shimmer, skipOffstage: false).evaluate().isNotEmpty); i++) {
        await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 50)));
        await t.pump();
      }
      await t.pumpAndSettle();
    }

    await settle();
    expect(find.text('Dental chart'), findsOneWidget);
    expect(find.text('Eye examination'), findsNothing, reason: 'not this doctor\'s specialty');
    expect(find.byType(ToothChart), findsOneWidget);

    await t.tap(find.bySemanticsLabel(RegExp(r'^Tooth 36 ')));
    await settle();
    expect(find.text('Add tooth record'), findsOneWidget);
    final sheet = find.descendant(of: find.byType(DraggableScrollableSheet), matching: find.byType(Scrollable)).first;
    await t.scrollUntilVisible(find.text('Deep caries'), 120, scrollable: sheet);
    await t.tap(find.text('Deep caries'));
    await t.scrollUntilVisible(find.text('RCT'), 120, scrollable: sheet);
    await t.tap(find.text('RCT'));
    await t.tap(find.text('Save'));
    await settle();

    final saved = await t.runAsync(() => ref.read(clinicalRepoProvider).forPatient(1, type: SpForms.dental.type));
    expect(saved!.single.str('tooth'), '36');
    expect(saved.single.str('procedure'), 'RCT');
    expect(saved.single.pending, isTrue);
    final outbox = await t.runAsync(() => adb.db.query('outbox'));
    expect(outbox!.single['type'], 'record.save');
    expect(find.textContaining('1 planned'), findsOneWidget);
    expect(t.takeException(), isNull);
  });
}
