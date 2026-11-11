import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:staff_app/core/db/app_database.dart';
import 'package:staff_app/core/lan/lan_service.dart';
import 'package:staff_app/core/lan/lan_store.dart';
import 'package:staff_app/core/sync/sync_engine.dart';
import 'package:staff_app/features/doctor/data/billing.dart';
import 'package:staff_app/features/doctor/data/clinical.dart';
import 'package:staff_app/features/doctor/data/doctor_repository.dart';
import 'package:staff_app/features/doctor/data/doctor_sync.dart';
import 'package:staff_app/features/doctor/data/models.dart';
import 'package:staff_app/features/doctor/data/queue.dart';
import 'package:staff_app/features/opd/registration.dart';

import 'support/fake_server.dart';

final consult = ChargeOption({'id': 70, 'name': 'Consultation', 'standard_charge': 300, 'tax': 0});
final doctor = OpdDoctor({'id': doctorId, 'name': 'Dr. Mehta', 'charge_id': 70});
const server = 'https://hms.test';

Future<AppDatabase> device(int prefix) async {
  final adb = await openTestDb();
  await adb.setMeta('device_prefix', '$prefix');
  await DoctorSyncModule.applyMasters(adb, {...mastersBody, 'opd_doctors': [doctor.j]});
  return adb;
}

/// A cloud that answers this device's uploads.
(FakeServer, List<Map<String, dynamic>>) cloud() {
  final s = FakeServer()..etag = '"m"';
  final pushed = <Map<String, dynamic>>[];
  var rx = 800;
  s.routes['sync/masters'] = (_, _) async => (304, null);
  s.routes['sync/pull'] = (_, _) async => (200, pullBody(scope: [501], alive: []));
  s.routes['sync/push'] = (b, _) async {
    final ops = [for (final o in b!['ops'] as List) Map<String, dynamic>.from(o as Map)];
    pushed.addAll(ops);
    return (200, {
      'results': [
        for (final o in ops)
          {
            'op_id': o['op_id'],
            ...switch (o['type']) {
              'patient.register' => {'status': 'ok', 'patient_id': 501, 'data': {'patients': [patient(501, name: 'Sunita Yadav')]}},
              'visit.create' => {'status': 'ok', 'visit_id': 901, 'opd_id': 301, 'token': 4, 'data': {'visits': [{...visit(901, 501, opdId: 301), 'token': 4}]}},
              'prescription.save' => {'status': 'ok', 'server_id': rx, 'prescription': serverRx(rx++, visitId: 901, patientId: 501)},
              _ => {'status': 'ok'},
            },
          },
      ],
    });
  };
  return (s, pushed);
}

void main() {
  late AppDatabase reception, doctorTab;
  late LanHubServer hub;
  final key = lanKey('482913', server);

  setUp(() async {
    reception = await device(11);
    doctorTab = await device(22);
    hub = LanHubServer(store: LanStore(reception), key: key, deviceName: 'Reception tablet', onPeer: (_) {}, onChanged: (_) {});
    await hub.start(port: 0, address: InternetAddress.loopbackIPv4);
  });

  tearDown(() async {
    await hub.stop();
    await reception.db.close();
    await doctorTab.db.close();
  });

  Future<int> exchange({String k = ''}) =>
      lanExchange(store: LanStore(doctorTab), key: k.isEmpty ? key : k, host: '127.0.0.1', port: hub.port!, deviceName: 'Dr. Mehta tablet');

  test('local ids never collide between devices', () async {
    final a = await RegistrationRepository(reception, staffId: 1, staffName: 'r').registerPatient(NewPatient(name: 'A', ageYears: 1));
    final b = await RegistrationRepository(doctorTab, staffId: 1, staffName: 'd').registerPatient(NewPatient(name: 'B', ageYears: 1));
    expect(a, isNot(b));
    expect(await RegistrationRepository.isOwnLocalId(reception.db, a), isTrue);
    expect(await RegistrationRepository.isOwnLocalId(reception.db, b), isFalse);
  });

  test('a device with the wrong code is refused', () async {
    await expectLater(exchange(k: lanKey('000000', server)), throwsA(isA<LanException>().having((e) => e.message, 'message', contains('6-digit code'))));
    await expectLater(exchange(k: lanKey('482913', 'https://other-hospital.test')), throwsA(isA<LanException>()));
  });

  test('no internet: reception registers, doctor sees and treats; after reception uploads, the doctor\'s work follows with real ids', () async {
    // Reception, offline.
    final reg = RegistrationRepository(reception, staffId: 30, staffName: 'Reception');
    final pid = await reg.registerPatient(NewPatient(name: 'Sunita Yadav', gender: 'Female', ageYears: 31, mobile: '9812345678'));
    final enc = await reg.createVisit(NewVisit(patientId: pid, doctor: doctor, charge: consult, paid: 300));

    // Doctor's tablet joins the hub and sees her in the queue.
    expect(await exchange(), greaterThan(0));
    final drRepo = DoctorRepository(doctorTab, doctorId);
    var today = await drRepo.today();
    expect(today.opd.single.patientId, pid);
    expect(today.patients[pid]!.name, 'Sunita Yadav');
    expect(await doctorTab.db.query('outbox'), isEmpty, reason: 'received copies are never uploaded by the doctor tablet');

    // Doctor calls her in and prescribes, still offline.
    final drEnc = today.opd.single;
    await ClinicalRepository(doctorTab).save(queueRecord(drEnc, QueueState.withDoctor));
    final rxLocal = await drRepo.savePrescription(PrescriptionDraft(patientId: pid, encounter: drEnc, items: [
      RxItem(medicineId: 1, medicineName: 'Paracetamol 650', categoryId: 1, dosageId: 11, intervalId: 21, durationId: 31),
    ]));
    await exchange();
    final q = (await DoctorRepository(reception, doctorId).today()).queueStatus[enc.refId];
    expect(q?.str('state'), 'with_doctor', reason: 'reception sees the doctor called her');

    // The doctor tablet goes online first: its work waits, because the patient is not on the server yet.
    final (drCloud, drPushed) = cloud();
    final drEngine = SyncEngine(api: drCloud.client(), adb: doctorTab, modules: [DoctorSyncModule()], onDataChanged: () {}, onUnauthorized: () {});
    await doctorTab.setMeta('masters_etag', '"m"');
    await drEngine.sync();
    expect(drPushed, isEmpty);

    // Reception gets internet and uploads; its visit carries the doctor's queue state.
    final (rcCloud, rcPushed) = cloud();
    final rcEngine = SyncEngine(api: rcCloud.client(), adb: reception, modules: [DoctorSyncModule()], onDataChanged: () {}, onUnauthorized: () {});
    await reception.setMeta('masters_etag', '"m"');
    await rcEngine.sync();
    expect(rcPushed.map((o) => o['type']), ['patient.register', 'visit.create']);
    expect((rcPushed[1]['payload'] as Map)['queue_state'], 'with_doctor');

    // Next exchange tells the doctor tablet the server ids; its prescription is re-linked and uploads.
    await exchange();
    expect((await drRepo.prescription(rxLocal))!.caseRefId, 901);
    expect((await drRepo.prescription(rxLocal))!.patientId, 501);
    await drEngine.sync();
    final rxOp = drPushed.singleWhere((o) => o['type'] == 'prescription.save');
    expect((rxOp['payload'] as Map)['case_ref_id'], 901);
    expect(drPushed.where((o) => o['type'] == 'record.save'), isEmpty, reason: 'the queue state went up with reception\'s visit');
    expect(await doctorTab.db.query('outbox'), isEmpty);
    expect(await doctorTab.db.query('patients', where: 'id < 0'), isEmpty);

    drEngine.dispose();
    rcEngine.dispose();
  });

  test('triage vitals taken on one device show on the other until uploaded', () async {
    await DoctorSyncModule.applyPull(reception, pullBody(patients: [patient(1)], visits: [visit(10, 1, date: today())], scope: [1], alive: []));
    await DoctorSyncModule.applyPull(doctorTab, pullBody(patients: [patient(1)], visits: [visit(10, 1, date: today())], scope: [1], alive: []));
    final rows = await reception.db.query('vitals');
    expect(rows, isEmpty);
    await LanStore(reception).db.insert('vitals', {'id': -1100001, 'patient_id': 1, 'measured_at': '${today()} 10:00:00', 'json': '{"id":-1100001,"patient_id":1,"name":"BP","value":"150/95","local":true}'});
    await exchange();
    final dr = await DoctorRepository(doctorTab, doctorId).vitals(1);
    expect(dr.single.value, '150/95');
    await reception.db.delete('vitals'); // uploaded by reception
    await exchange();
    expect(await DoctorRepository(doctorTab, doctorId).vitals(1), isEmpty);
  });
}
