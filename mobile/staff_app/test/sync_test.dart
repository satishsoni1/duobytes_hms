import 'package:flutter_test/flutter_test.dart';
import 'package:staff_app/core/db/app_database.dart';
import 'package:staff_app/core/sync/sync_engine.dart';
import 'package:staff_app/features/doctor/data/doctor_repository.dart';
import 'package:staff_app/features/doctor/data/doctor_sync.dart';
import 'package:staff_app/features/doctor/data/models.dart';

import 'support/fake_server.dart';

void main() {
  late AppDatabase adb;
  late FakeServer server;
  late SyncEngine engine;
  late DoctorRepository repo;
  var unauthorized = 0;

  /// Server answers for push: op_id -> result builder.
  late Map<String, dynamic> Function(Map<String, dynamic> op) pushResult;
  late Map<String, dynamic> Function(Map<String, dynamic>? body) pull;

  /// Prescriptions as the server currently has them (pull returns these).
  late Map<int, Map<String, dynamic>> store;

  setUp(() async {
    adb = await openTestDb();
    server = FakeServer();
    server.etag = '"m1"';
    unauthorized = 0;
    store = {500: serverRx(500)};
    pull = (_) => pullBody(patients: [patient(1)], visits: [visit(10, 1)], prescriptions: store.values.toList(), scope: [1], alive: store.keys.toList());
    var nextId = 600;
    pushResult = (op) {
      final rx = store[nextId] = serverRx(nextId, version: '2026-11-07 11:00:00');
      return {'op_id': op['op_id'], 'status': 'ok', 'server_id': nextId++, 'prescription': rx};
    };

    server.routes['sync/masters'] = (_, o) async => o.headers['If-None-Match'] == server.etag ? (304, null) : (200, mastersBody);
    server.routes['sync/pull'] = (b, _) async => (200, pull(b));
    server.routes['sync/push'] = (b, _) async => (200, {'results': [for (final op in (b!['ops'] as List)) pushResult(Map<String, dynamic>.from(op as Map))]});

    engine = SyncEngine(api: server.client(), adb: adb, modules: [DoctorSyncModule()], onDataChanged: () {}, onUnauthorized: () => unauthorized++);
    repo = DoctorRepository(adb, doctorId);
  });

  tearDown(() async {
    engine.dispose();
    await adb.db.close();
  });

  Future<Encounter> encounter() async => (await repo.encounter('opd', 10))!;

  Future<String> writeRx({int medicineId = 3}) async {
    final d = PrescriptionDraft(patientId: 1, encounter: await encounter(), items: [
      RxItem(medicineId: medicineId, medicineName: 'Azithral 500', categoryId: 1, dosageId: 11, intervalId: 22, durationId: 31),
    ])
      ..diagnoses = [IcdCode(id: 5, code: 'A90', description: 'Dengue fever')];
    return repo.savePrescription(d);
  }

  Future<int> outboxCount() async => (await adb.db.rawQuery('SELECT COUNT(*) n FROM outbox')).first['n'] as int;

  test('first sync downloads masters, patients and history for offline use', () async {
    await engine.sync();

    expect(engine.state.phase, SyncPhase.idle);
    expect((await repo.searchPatients('asha')).single.allergies, ['Penicillin']);
    expect((await repo.searchPatients('98220')).length, 1, reason: 'search by phone');
    final t = await repo.today();
    expect(t.opd.single.refId, 10);
    expect(t.hasRx(t.opd.single), isTrue);
    final dx = await repo.diagnoses(await repo.encounters(1));
    expect(dx['opd:10']!.single.code, 'A90');
    expect((await repo.searchMedicines('amoxicillin')).single.name, 'Amoxyclav 625', reason: 'search by generic name');
    expect((await repo.masters()).dosagesFor(1).length, 2);
    expect(await adb.getMeta('cursor'), '2026-11-07 10:00:00');
  });

  test('masters are not re-downloaded when unchanged (ETag)', () async {
    await engine.sync();
    await engine.sync();
    expect(await repo.medicineCount(), 3, reason: '304 must not wipe the medicine list');
    expect(server.calls.where((c) => c.$1 == 'sync/masters').length, 2);
  });

  test('second pull sends cursor and known patients', () async {
    await engine.sync();
    await engine.sync();
    final second = server.bodiesFor('sync/pull').last!;
    expect(second['since'], '2026-11-07 10:00:00');
    expect(second['known_patient_ids'], [1]);
  });

  test('a prescription written offline is kept and uploaded later', () async {
    await engine.sync();
    server.offline = true;

    final id = await writeRx();
    await engine.sync();
    expect(engine.state.phase, SyncPhase.offline);
    expect(engine.state.pending, 1);
    expect((await repo.prescription(id))!.status, RxStatus.pending);

    server.offline = false;
    await engine.sync();
    final rx = (await repo.prescription(id))!;
    expect(rx.status, RxStatus.synced);
    expect(rx.serverId, 600);
    expect(await outboxCount(), 0);

    final sent = (server.bodiesFor('sync/push').single!['ops'] as List).single as Map;
    expect(sent['type'], 'prescription.save');
    final p = sent['payload'] as Map;
    expect(p['kind'], 'opd');
    expect(p['case_ref_id'], 10);
    expect(p['server_id'], isNull);
    expect(p['icd_ids'], [5]);
    expect((p['items'] as List).single['interval_id'], 22);
  });

  test('editing again while the upload is in flight keeps the edit and sends an update', () async {
    await engine.sync();
    final id = await writeRx();

    var calls = 0;
    pushResult = (op) {
      calls++;
      if (calls == 1) {
        // Doctor edits while the first upload is on the wire.
        repo.prescription(id).then((rx) async {
          final d = PrescriptionDraft.fromPrescription(rx!, await encounter())..footerNote = 'Review after 3 days';
          await repo.savePrescription(d);
        });
      }
      final rx = store[600] = serverRx(600, version: '2026-11-07 11:0$calls:00');
      return {'op_id': op['op_id'], 'status': 'ok', 'server_id': 600, 'prescription': rx};
    };
    // Let the async edit land before results are applied.
    server.routes['sync/push'] = (b, _) async {
      final r = [for (final op in (b!['ops'] as List)) pushResult(Map<String, dynamic>.from(op as Map))];
      await Future<void>.delayed(const Duration(milliseconds: 50));
      return (200, {'results': r});
    };

    await engine.sync();

    final pushes = server.bodiesFor('sync/push');
    expect(pushes.length, 2, reason: 'second push carries the edit');
    final second = ((pushes[1]!['ops'] as List).single as Map)['payload'] as Map;
    expect(second['server_id'], 600);
    expect(second['footer_note'], 'Review after 3 days');
    final rx = (await repo.prescription(id))!;
    expect(rx.status, RxStatus.synced);
    expect(await outboxCount(), 0);
  });

  test('conflict: doctor can keep their version, which is force-sent', () async {
    await engine.sync();
    final synced = (await repo.prescriptions(1)).single;
    final d = PrescriptionDraft.fromPrescription(synced, await encounter())..footerNote = 'Mine';
    await repo.savePrescription(d);

    store[500] = serverRx(500, version: '2026-11-07 09:00:00');
    pushResult = (op) => {'op_id': op['op_id'], 'status': 'conflict', 'message': 'Changed by someone else', 'server': store[500]};
    await engine.sync();
    var rx = (await repo.prescription(synced.localId))!;
    expect(rx.status, RxStatus.conflict);
    expect(engine.state.conflicts, 1);
    expect(rx.j['footer_note'], 'Mine', reason: 'local edit is not overwritten by the pull');

    await repo.keepMine(rx.localId);
    pushResult = (op) => {'op_id': op['op_id'], 'status': 'ok', 'server_id': 500, 'prescription': store[500] = serverRx(500, version: '2026-11-07 12:00:00')};
    await engine.sync();
    final last = ((server.bodiesFor('sync/push').last!['ops'] as List).single as Map)['payload'] as Map;
    expect(last['force'], isTrue);
    expect(last['base_version'], '2026-11-07 09:00:00');
    rx = (await repo.prescription(synced.localId))!;
    expect(rx.status, RxStatus.synced);
  });

  test('conflict: using the server version drops local changes', () async {
    await engine.sync();
    final synced = (await repo.prescriptions(1)).single;
    await repo.savePrescription(PrescriptionDraft.fromPrescription(synced, await encounter())..footerNote = 'Mine');
    store[500] = serverRx(500, version: '2026-11-07 09:00:00');
    pushResult = (op) => {'op_id': op['op_id'], 'status': 'conflict', 'message': 'x', 'server': store[500]};
    await engine.sync();

    await repo.useServerVersion(synced.localId);
    final rx = (await repo.prescription(synced.localId))!;
    expect(rx.status, RxStatus.synced);
    expect(rx.j['footer_note'], 'Plenty of fluids');
    expect(rx.version, '2026-11-07 09:00:00');
  });

  test('rejected prescriptions are flagged with the server message and leave the queue', () async {
    await engine.sync();
    final id = await writeRx();
    pushResult = (op) => {'op_id': op['op_id'], 'status': 'rejected', 'message': 'The patient has been discharged; this visit is closed.'};
    await engine.sync();
    final rx = (await repo.prescription(id))!;
    expect(rx.status, RxStatus.rejected);
    expect(rx.error, contains('discharged'));
    expect(await outboxCount(), 0);
    expect(engine.state.rejected, 1);
  });

  test('transient server errors keep the change queued', () async {
    await engine.sync();
    final id = await writeRx();
    pushResult = (op) => {'op_id': op['op_id'], 'status': 'error', 'message': 'Server error; will retry.'};
    await engine.sync();
    expect((await repo.prescription(id))!.status, RxStatus.pending);
    expect(await outboxCount(), 1);
    final attempts = (await adb.db.query('outbox')).single['attempts'];
    expect(attempts, 1);
  });

  test('a lost upload reply does not leave a duplicate prescription', () async {
    await engine.sync();
    final id = await writeRx();
    // Upload applied on the server, but the reply was lost (simulate with an error status);
    // the following pull then brings the new prescription down as its own row.
    pushResult = (op) => {'op_id': op['op_id'], 'status': 'error'};
    pull = (_) => pullBody(patients: [patient(1)], visits: [visit(10, 1)], prescriptions: [serverRx(500), serverRx(600)], scope: [1], alive: [500, 600]);
    await engine.sync();
    expect((await repo.prescriptions(1)).length, 3);

    // Retry: the server replays the stored result for the same op_id.
    pushResult = (op) => {'op_id': op['op_id'], 'status': 'ok', 'server_id': 600, 'prescription': serverRx(600)};
    await engine.sync();
    final all = await repo.prescriptions(1);
    expect(all.length, 2);
    expect(all.where((p) => p.serverId == 600).single.localId, id);
  });

  test('pull removes prescriptions deleted on the web and patients no longer assigned', () async {
    pull = (_) => pullBody(patients: [patient(1), patient(2, name: 'Ravi Kale')], visits: [visit(10, 1), visit(11, 2, opdId: 101)], prescriptions: [serverRx(500)], scope: [1, 2], alive: [500]);
    await engine.sync();
    expect((await repo.searchPatients('')).length, 2);

    pull = (_) => pullBody(scope: [1], alive: []);
    await engine.sync();
    expect((await repo.prescriptions(1)), isEmpty, reason: 'deleted on the web');
    expect(await repo.patient(2), isNull, reason: 'no longer assigned');
    expect(await repo.patient(1), isNotNull);
  });

  test('a patient leaving scope is kept while they have unsent prescriptions', () async {
    await engine.sync();
    server.offline = true;
    await writeRx();
    server.offline = false;
    pushResult = (op) => {'op_id': op['op_id'], 'status': 'error'};
    pull = (_) => pullBody(scope: [], alive: []);
    await engine.sync();
    expect(await repo.patient(1), isNotNull);
  });

  test('expired token pauses sync without touching local data', () async {
    await engine.sync();
    await writeRx();
    server.routes['sync/push'] = (_, _) async => (401, {'error': {'code': 'token_invalid', 'message': 'Your session has expired. Sign in again.'}});
    await engine.sync();
    expect(engine.state.phase, SyncPhase.needsSignIn);
    expect(unauthorized, 1);
    expect(await outboxCount(), 1);
  });

  test('templates: saved offline, uploaded, deletion queued for server copies', () async {
    await engine.sync();
    final d = PrescriptionDraft(patientId: 1, encounter: await encounter(), items: [RxItem(medicineId: 1, medicineName: 'Paracetamol 650', categoryId: 1, dosageId: 11, intervalId: 21)]);
    await repo.saveTemplate('Fever', true, d);
    pushResult = (op) => {'op_id': op['op_id'], 'status': 'ok', 'server_id': 77};
    await engine.sync();
    final t = (await repo.templates()).single;
    expect(t.serverId, 77);
    expect(t.items.single.medicineId, 1);
    final sent = ((server.bodiesFor('sync/push').last!['ops'] as List).single as Map);
    expect(sent['type'], 'template.save');
    expect(((sent['payload'] as Map)['payload'] as Map)['items'][0]['medicineId'], 1, reason: 'web Smart Prescription format');

    await repo.deleteTemplate(t);
    expect((await adb.db.query('outbox')).single['type'], 'template.delete');
  });

  test('discarding a never-synced prescription deletes it and its queued upload', () async {
    await engine.sync();
    server.offline = true;
    final id = await writeRx();
    await repo.discardLocalChanges(id);
    expect(await repo.prescription(id), isNull);
    expect(await outboxCount(), 0);
  });
}
