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
import 'package:staff_app/features/doctor/data/doctor_repository.dart';
import 'package:staff_app/features/doctor/data/doctor_sync.dart';
import 'package:staff_app/features/opd/registration.dart';
import 'package:staff_app/features/staff/staff_data.dart';
import 'package:staff_app/modules.dart';

import 'support/fake_server.dart';

final extras = {
  'vital_types': [
    {'id': 3, 'name': 'Pulse ', 'unit': 'Beats per', 'reference_range': '70 -   100 '},
    {'id': 5, 'name': 'BP', 'unit': 'mmHg', 'reference_range': '90/60 - 140/90'},
  ],
  'beds': [
    {'id': 1, 'name': 'GW-01', 'type': 'General', 'ward': 'General Ward', 'floor': 'Ground Floor', 'ipd_id': 40, 'patient_id': 1, 'patient_name': 'Asha Patil', 'doctor_name': 'Anil Mehta'},
    {'id': 2, 'name': 'GW-02', 'type': 'General', 'ward': 'General Ward', 'floor': 'Ground Floor'},
  ],
  'lab_worklist': [
    {'id': 70, 'patient_id': 1, 'patient_name': 'Asha Patil', 'test_name': 'CBC', 'collection_date': '2026-11-11', 'reporting_date': null, 'result': '',
      'parameters': [{'detail_id': 7, 'name': 'Haemoglobin', 'unit': 'g/dl', 'reference_range': '12-15', 'value': ''}, {'detail_id': 8, 'name': 'WBC', 'unit': '/cumm', 'reference_range': '4000-11000', 'value': ''}]},
  ],
  'pharmacy_stock': [
    {'id': 1, 'name': 'Paracetamol 650', 'available': 0, 'expired': 0, 'expiring': 0, 'reorder_level': 50},
    {'id': 2, 'name': 'Azithral 500', 'available': 30, 'expired': 10, 'expiring': 5, 'reorder_level': 50, 'next_expiry': '2026-12-01'},
    {'id': 3, 'name': 'Amoxyclav 625', 'available': 300, 'expired': 0, 'expiring': 0, 'reorder_level': 50},
  ],
};

class _FakeAuth extends AuthController {
  _FakeAuth(AuthState s) : super(SessionStore()) {
    state = s;
  }
}

void main() {
  late AppDatabase adb;
  late FakeServer server;
  late SyncEngine engine;
  late StaffRepository staff;
  late List<Map<String, dynamic>> pushed;
  late Map<String, dynamic> Function(Map<String, dynamic>) answer;

  Map<String, dynamic> pull() => {
        ...pullBody(patients: [patient(1)], visits: [visit(10, 1, date: today()), {...visit(11, 1, opdId: 101, doctor: 9, date: today()), 'doctor_name': 'Rao'}], scope: [1], alive: []),
        'extras': extras,
      };

  setUp(() async {
    adb = await openTestDb();
    server = FakeServer()..etag = '"m"';
    await adb.setMeta('masters_etag', '"m"');
    pushed = [];
    answer = (_) => {'status': 'ok'};
    server.routes['sync/masters'] = (_, _) async => (304, null);
    server.routes['sync/pull'] = (_, _) async => (200, pull());
    server.routes['sync/push'] = (b, _) async {
      final ops = [for (final o in b!['ops'] as List) Map<String, dynamic>.from(o as Map)];
      pushed.addAll(ops);
      return (200, {'results': [for (final o in ops) {'op_id': o['op_id'], ...answer(o)}]});
    };
    engine = SyncEngine(api: server.client(), adb: adb, modules: [DoctorSyncModule()], onDataChanged: () {}, onUnauthorized: () {});
    staff = StaffRepository(adb);
    await engine.sync();
  });

  tearDown(() async {
    engine.dispose();
    await adb.db.close();
  });

  test('role lists arrive with the pull; stock flags; every doctor\'s queue for reception', () async {
    expect((await staff.beds()).where((b) => b.occupied).single.patientName, 'Asha Patil');
    expect((await staff.vitalTypes()).first.name, 'Pulse');
    final stock = await staff.stock();
    expect(stock.firstWhere((s) => s.id == 1).outOfStock, isTrue);
    expect(stock.firstWhere((s) => s.id == 2).low, isTrue);
    expect(stock.firstWhere((s) => s.id == 3).low, isFalse);
    final q = await staff.queueAll(DateTime.now());
    expect(q.queue, hasLength(2));
    expect(q.doctors.keys, containsAll([doctorId, 9]));
  });

  test('vitals show at once, upload as one op, and the local copies give way to the server rows', () async {
    final types = await staff.vitalTypes();
    await staff.recordVitals(1, {types[0]: '96', types[1]: '130/85'});
    final repo = DoctorRepository(adb, doctorId);
    expect((await repo.vitals(1)).map((v) => v.value), containsAll(['96', '130/85']));
    await engine.sync();
    final op = pushed.singleWhere((o) => o['type'] == 'vitals.save');
    expect((op['payload'] as Map)['values'], {'3': '96', '5': '130/85'});
    expect(await adb.db.query('vitals', where: 'id < 0'), isEmpty);
  });

  test('vitals for a patient registered offline wait for the registration, then carry the server id', () async {
    final reg = RegistrationRepository(adb, staffId: doctorId, staffName: 'x');
    answer = (o) => o['type'] == 'patient.register'
        ? {'status': 'ok', 'patient_id': 77, 'data': {'patients': [patient(77, name: 'New One')]}}
        : {'status': 'ok'};
    final pid = await reg.registerPatient(NewPatient(name: 'New One', ageYears: 20));
    await staff.recordVitals(pid, {(await staff.vitalTypes()).first: '88'});
    await engine.sync();
    expect((pushed.singleWhere((o) => o['type'] == 'vitals.save')['payload'] as Map)['patient_id'], 77);
  });

  test('nursing notes: pending until uploaded; a refused note stays visible with the reason', () async {
    await staff.addNurseNote(patientId: 1, ipdId: 40, note: 'IV fluids running');
    expect((await staff.pendingNotes(1)).single.note, 'IV fluids running');
    answer = (o) => {'status': 'rejected', 'message': 'You do not have permission to add nurse notes.'};
    await engine.sync();
    final left = await staff.pendingNotes(1);
    expect(left.single.error, contains('permission'));
    await StaffRepository.dismissRefusedNote(adb, left.single.opId);
    expect(await staff.pendingNotes(1), isEmpty);
  });

  test('lab results: entered values survive a pull that happens before they upload', () async {
    final order = (await staff.labWorklist()).single;
    server.offline = true;
    await staff.saveLabResult(order, {7: '10.2', 8: '12500'}, result: 'Leucocytosis', complete: true);
    await DoctorSyncModule.applyPull(adb, pull()); // server copy still blank
    var o = (await staff.labWorklist()).single;
    expect(o.parameters.map((p) => p.value), ['10.2', '12500']);
    expect(o.pending, isFalse);
    expect(o.uploading, isTrue);
    server.offline = false;
    await engine.sync();
    final p = pushed.singleWhere((x) => x['type'] == 'lab.result')['payload'] as Map;
    expect(p['values'], {'7': '10.2', '8': '12500'});
    expect(p['complete'], isTrue);
  });

  testWidgets('each staff role opens its own home without errors', (t) async {
    t.view.physicalSize = const Size(1080, 2340);
    t.view.devicePixelRatio = 3;
    addTearDown(t.view.reset);
    for (final (module, expectText) in const [('reception', 'OPD queue'), ('nursing', 'Ward'), ('lab', 'Lab worklist'), ('pharmacy', 'Medicine stock')]) {
      final session = StaffSession.fromJson({
        'staff': {'id': 30, 'name': 'Meena', 'surname': 'K', 'role': 'Staff', 'modules': [module]},
        'hospital': {'hospital_name': 'Skylark Hospital'},
      });
      final s = FakeServer()..offline = true;
      await t.pumpWidget(ProviderScope(
        key: ValueKey(module),
        overrides: [authControllerProvider.overrideWith((r) => _FakeAuth(SignedIn(session: session, serverUrl: 'https://hms.test', api: s.client(), db: adb)))],
        child: MaterialApp(theme: buildTheme(), home: appModules[module]!.buildHome()),
      ));
      for (var i = 0; i < 8 || (i < 40 && find.byType(Shimmer, skipOffstage: false).evaluate().isNotEmpty); i++) {
        await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 40)));
        await t.pump();
      }
      expect(find.text(expectText), findsWidgets, reason: module);
      if (module == 'pharmacy') expect(find.textContaining('Out of stock (1)'), findsOneWidget);
      if (module == 'lab') expect(find.textContaining('CBC · Asha Patil'), findsOneWidget);
      if (module == 'reception') expect(find.textContaining('2 OPD today'), findsOneWidget);
      expect(t.takeException(), isNull, reason: module);
    }
  });
}
