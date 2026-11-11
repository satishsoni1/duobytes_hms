import 'package:flutter_test/flutter_test.dart';
import 'package:staff_app/core/db/app_database.dart';
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

void main() {
  late AppDatabase adb;
  late FakeServer server;
  late SyncEngine engine;
  late DoctorRepository repo;
  late RegistrationRepository reg;
  late List<List<String>> batches; // op types per push request
  late Map<String, dynamic> Function(Map<String, dynamic> op) answer;

  Map<String, dynamic> serverBundle(int pid, {List<Map<String, dynamic>> visits = const []}) => {
        'patients': [{...patient(pid, name: 'Sunita Yadav', allergies: ''), 'mobileno': '9812345678'}],
        'visits': visits,
        'admissions': [], 'diagnoses': [], 'prescriptions': [], 'vitals': [], 'lab_reports': [], 'timeline': [],
        'clinical_records': [], 'billing': [],
      };

  setUp(() async {
    adb = await openTestDb();
    await DoctorSyncModule.applyMasters(adb, {...mastersBody, 'opd_doctors': [doctor.j]});
    server = FakeServer();
    batches = [];
    var rxId = 800;
    answer = (op) {
      final p = Map<String, dynamic>.from(op['payload'] as Map);
      return switch (op['type']) {
        'patient.register' => {'status': 'ok', 'patient_id': 501, 'merged': false, 'data': serverBundle(501)},
        'visit.create' => {
            'status': 'ok', 'visit_id': 901, 'opd_id': 301, 'token': 4,
            'data': serverBundle(501, visits: [{...visit(901, 501, opdId: 301), 'token': 4}]),
          },
        'prescription.save' => {'status': 'ok', 'server_id': rxId, 'prescription': {...serverRx(rxId++, visitId: p['case_ref_id'] as int, patientId: 501)}},
        'charge.add' => {'status': 'ok', 'charge': {'id': 1, 'charge_name': 'Dressing', 'amount': 100, 'opd_id': 301}},
        _ => {'status': 'ok'},
      };
    };
    server.routes['sync/masters'] = (_, _) async => (304, null);
    server.etag = '"m"';
    await adb.setMeta('masters_etag', '"m"');
    server.routes['sync/pull'] = (b, _) async => (200, pullBody(scope: [501], alive: [800]));
    server.routes['sync/push'] = (b, _) async {
      final ops = [for (final o in b!['ops'] as List) Map<String, dynamic>.from(o as Map)];
      batches.add([for (final o in ops) o['type'] as String]);
      return (200, {'results': [for (final o in ops) {'op_id': o['op_id'], ...answer(o)}]});
    };
    engine = SyncEngine(api: server.client(), adb: adb, modules: [DoctorSyncModule()], onDataChanged: () {}, onUnauthorized: () {});
    repo = DoctorRepository(adb, doctorId);
    reg = RegistrationRepository(adb, staffId: doctorId, staffName: 'Dr. Mehta');
  });

  tearDown(() async {
    engine.dispose();
    await adb.db.close();
  });

  test('validation', () {
    expect(NewPatient(name: '').validate(), contains('name'));
    expect(NewPatient(name: 'A').validate(), contains('age'));
    expect(NewPatient(name: 'A', ageYears: 30, mobile: '12a').validate(), contains('mobile'));
    expect(NewPatient(name: 'A', ageYears: 30, mobile: '98123 45678').validate(), isNull);
    expect(NewVisit(patientId: 1, doctor: doctor, charge: null).validate(), contains('charge'));
    expect(NewVisit(patientId: 1, doctor: doctor, charge: consult, paid: 400).validate(), contains('300.00'));
  });

  test('offline: register, open visit, prescribe, add service, mark with doctor; one sync moves it all to server ids', () async {
    server.offline = true;
    final pid = await reg.registerPatient(NewPatient(name: 'Sunita Yadav', gender: 'Female', ageYears: 31, mobile: '98123 45678'));
    expect(pid, lessThan(0));
    expect((await reg.withMobile('9812345678')).single.name, 'Sunita Yadav', reason: 'duplicate check finds her');
    final enc = await reg.createVisit(NewVisit(patientId: pid, doctor: doctor, charge: consult, paid: 300, symptoms: 'Thyroid follow-up'));
    expect(enc.refId, lessThan(0));
    expect(enc.j['token'], 1);

    // She shows up in the doctor's queue straight away.
    final t = await repo.today();
    expect(t.opd.single.patientId, pid);
    expect(t.patients[pid]!.name, 'Sunita Yadav');

    final localRx = await repo.savePrescription(PrescriptionDraft(patientId: pid, encounter: enc, items: [
      RxItem(medicineId: 1, medicineName: 'Paracetamol 650', categoryId: 1, dosageId: 11, intervalId: 21, durationId: 31),
    ]));
    await BillingRepository(adb).addService(encounter: enc, charge: ChargeOption({'id': 80, 'name': 'Dressing', 'standard_charge': 100, 'tax': 0}), qty: 1);
    await ClinicalRepository(adb).save(queueRecord(enc, QueueState.withDoctor));
    await ClinicalRepository(adb).save(ClinicalRecord.create(patientId: pid, type: RecordType.followup, eventDate: DateTime.now().add(const Duration(days: 7)), data: {'visit_kind': 'opd', 'visit_ref_id': enc.refId, 'reason': 'TSH'}));

    await engine.sync();
    expect(engine.state.phase, SyncPhase.offline);
    expect((await adb.db.query('outbox')).length, 6, reason: 'nothing lost offline');

    server.offline = false;
    await engine.sync();
    expect(engine.state.phase, SyncPhase.idle, reason: '${engine.state.message}');
    expect(await adb.db.query('outbox'), isEmpty);

    // Registration and visit each go alone, before anything that depends on them.
    expect(batches[0], ['patient.register']);
    expect(batches[1], ['visit.create']);
    expect(batches.skip(2).expand((b) => b).toSet(), {'prescription.save', 'charge.add', 'record.save'});

    final pushed = [for (final b in server.bodiesFor('sync/push')) for (final o in b!['ops'] as List) Map<String, dynamic>.from(o as Map)];
    Map<String, dynamic> payloadOf(String type) => Map<String, dynamic>.from(pushed.firstWhere((o) => o['type'] == type)['payload'] as Map);
    expect(payloadOf('patient.register')['mobileno'], '9812345678');
    expect(payloadOf('visit.create')['patient_id'], 501);
    expect(payloadOf('visit.create')['queue_state'], 'with_doctor', reason: 'queue state made offline reaches the server');
    expect(payloadOf('prescription.save')['case_ref_id'], 901);
    expect(payloadOf('charge.add')['case_ref_id'], 901);
    expect(payloadOf('record.save')['patient_id'], 501);
    expect(Map<String, dynamic>.from(payloadOf('record.save')['data'] as Map)['visit_ref_id'], 901);
    expect(pushed.where((o) => o['type'] == 'record.save'), hasLength(1), reason: 'the local queue record is replaced by the server one');

    // Nothing left under local ids.
    for (final table in const ['patients', 'visits']) {
      expect(await adb.db.query(table, where: 'id < 0'), isEmpty, reason: table);
    }
    expect((await repo.patient(501))!.name, 'Sunita Yadav');
    expect((await repo.today()).opd.single.j['token'], 4, reason: 'server token replaces the provisional one');
    expect((await repo.prescription(localRx))!.serverId, 800);
    expect((await BillingRepository(adb).bill(501)).pending, isEmpty);
  });

  test('a refused registration keeps the patient on the device with the reason, and its visit stops waiting', () async {
    answer = (op) => op['type'] == 'patient.register' ? {'status': 'rejected', 'message': 'You do not have permission to register patients.'} : {'status': 'ok'};
    final pid = await reg.registerPatient(NewPatient(name: 'Ravi', ageYears: 40));
    final visit = await reg.createVisit(NewVisit(patientId: pid, doctor: doctor, charge: consult));
    await engine.sync();
    expect(await adb.db.query('outbox'), isEmpty);
    expect((await repo.patient(pid))!.j['upload_error'], contains('permission'));
    final v = (await repo.encounter('opd', visit.refId))!;
    expect(v.j['upload_error'], contains('Patient registration was refused'));
  });

  test('a registration that fails for now keeps everything queued without retry penalties for dependants', () async {
    answer = (op) => op['type'] == 'patient.register' ? {'status': 'error', 'message': 'Server error; will retry.'} : {'status': 'ok'};
    final pid = await reg.registerPatient(NewPatient(name: 'Ravi', ageYears: 40));
    await reg.createVisit(NewVisit(patientId: pid, doctor: doctor, charge: consult));
    await engine.sync();
    final rows = await adb.db.query('outbox', orderBy: 'seq');
    expect(rows.map((r) => r['type']), ['patient.register', 'visit.create']);
    expect(rows.map((r) => r['attempts']), [1, 0]);
    expect(batches, [['patient.register']], reason: 'the visit is never sent with a local patient id');
  });

  test('provisional token follows the doctor\'s visits already on the device', () async {
    await DoctorSyncModule.applyPull(adb, pullBody(patients: [patient(1)], visits: [{...visit(10, 1), 'token': 3}], scope: [1], alive: []));
    final pid = await reg.registerPatient(NewPatient(name: 'Ravi', ageYears: 40));
    final e = await reg.createVisit(NewVisit(patientId: pid, doctor: doctor, charge: consult));
    expect(e.j['token'], 4);
  });
}
