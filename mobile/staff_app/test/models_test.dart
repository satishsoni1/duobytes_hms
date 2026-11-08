import 'package:flutter_test/flutter_test.dart';
import 'package:staff_app/core/api/api_client.dart';
import 'package:staff_app/features/doctor/data/allergy_check.dart';
import 'package:staff_app/features/doctor/data/models.dart';

void main() {
  group('allergies', () {
    test('split and ignore "none" style entries', () {
      expect(splitAllergies(['Penicillin, Sulfa; peanuts']), ['Penicillin', 'Sulfa', 'peanuts']);
      expect(splitAllergies(['None', 'NKDA', 'nil', null]), isEmpty);
    });

    test('flags direct mentions and common classes', () {
      final c = AllergyCheck(['Penicillin', 'NSAIDs']);
      expect(c.match('Amoxyclav 625', 'Amoxicillin + Clavulanic acid'), 'penicillin');
      expect(c.match('Brufen 400', 'Ibuprofen'), 'nsaids');
      expect(c.match('Azithral 500', 'Azithromycin'), isNull);
      expect(AllergyCheck(['Sulfa drugs']).match('Septran', 'Sulfamethoxazole + Trimethoprim'), 'sulfa drugs');
      expect(AllergyCheck(const []).match('Anything', null), isNull);
    });
  });

  test('draft validation mirrors the server rules', () {
    final e = Encounter.visit({'id': 10, 'opd_id': 100, 'patient_id': 1});
    final d = PrescriptionDraft(patientId: 1, encounter: e);
    expect(d.validate(), ['Add at least one medicine or test.']);
    d.items.add(RxItem(medicineId: 1, medicineName: 'Paracetamol'));
    expect(d.validate(), ['Paracetamol: choose a dose.', 'Paracetamol: choose how often.']);
    d.items.first
      ..dosageId = 1
      ..intervalId = 2;
    expect(d.validate(), isEmpty);
    d.items.clear();
    d.pathology.add(TestRef(41, 'CBC'));
    expect(d.validate(), isEmpty, reason: 'tests alone are a valid prescription');
  });

  test('push payload carries ids, not display labels', () {
    final e = Encounter.admission({'id': 55, 'patient_id': 1, 'discharged': 'no'});
    final d = PrescriptionDraft(patientId: 1, encounter: e, date: DateTime(2026, 11, 7), items: [
      RxItem(id: 9, medicineId: 1, medicineName: 'X', dosageId: 11, dosageLabel: '1 Tab', intervalId: 21, durationId: 31, instruction: 'After food'),
    ]);
    final rx = Prescription(localId: 'l', serverId: 500, patientId: 1, kind: 'ipd', caseRefId: 55, status: RxStatus.pending, version: 'v1', error: null, j: d.toJson());
    final p = prescriptionPushPayload(rx, force: false);
    expect(p['kind'], 'ipd');
    expect(p['case_ref_id'], 55);
    expect(p['date'], '2026-11-07');
    expect(p['base_version'], 'v1');
    expect(p['items'], [
      {'id': 9, 'medicine_id': 1, 'dosage_id': 11, 'interval_id': 21, 'duration_id': 31, 'instruction': 'After food'},
    ]);
  });

  test('patient age label', () {
    final now = DateTime.now();
    expect(Patient({'id': 1, 'dob': '${now.year - 30}-01-01'}).ageLabel, '30y');
    expect(Patient({'id': 1, 'age': 0, 'month': 5, 'day': 0}).ageLabel, '5m');
    expect(Patient({'id': 1, 'age': 0, 'month': 0, 'day': 0}).ageLabel, isNull);
  });

  test('server url normalisation', () {
    expect(ApiClient.normalizeServerUrl(' hms.skylarkhospital.com/ '), 'https://hms.skylarkhospital.com');
    expect(ApiClient.normalizeServerUrl('http://10.0.2.2:8888//'), 'http://10.0.2.2:8888');
  });
}
