import 'package:flutter_test/flutter_test.dart';
import 'package:staff_app/core/whatsapp.dart';
import 'package:staff_app/features/doctor/data/models.dart';

void main() {
  test('mobile numbers are normalised for wa.me', () {
    expect(whatsappNumber('98765 43210'), '919876543210');
    expect(whatsappNumber('098765-43210'), '919876543210');
    expect(whatsappNumber('+91 98765 43210'), '919876543210');
    expect(whatsappNumber('0091 98765 43210'), '919876543210');
    expect(whatsappNumber('+44 7700 900123'), '447700900123');
    expect(whatsappNumber('12345'), isNull);
    expect(whatsappNumber(''), isNull);
    expect(whatsappNumber(null), isNull);
    expect(whatsappUri('919876543210', 'Hi & bye').toString(), 'https://wa.me/919876543210?text=Hi%20%26%20bye');
  });

  test('follow-up reminder in English and Hindi', () {
    final en = followUpReminderText(lang: 'en', patientName: 'Asha', doctor: 'Dr. Mehta', hospital: 'Skylark', date: '18 Nov 2026', reason: 'CBC report');
    expect(en, contains('Namaste Asha'));
    expect(en, contains('with Dr. Mehta at Skylark on 18 Nov 2026 (CBC report).'));
    final hi = followUpReminderText(lang: 'hi', patientName: 'Asha', doctor: 'Dr. Mehta', hospital: 'Skylark', date: '18 Nov 2026', reason: 'Review');
    expect(hi, startsWith('नमस्ते Asha'));
    expect(hi, isNot(contains('Review')), reason: 'the default reason adds nothing');
  });

  test('prescription text lists medicines with dosing in the chosen language', () {
    final rx = Prescription.fromRow({
      'local_id': 'l1', 'server_id': 5, 'patient_id': 1, 'kind': 'opd', 'case_ref_id': 10, 'status': 'synced', 'local_rev': 0,
      'json': '{"date":"2026-11-11","footer_note":"Plenty of fluids","items":[{"medicine_id":1,"medicine_name":"Paracetamol 500","dosage_label":"1 tab","interval_name":"TDS","duration_name":"5 days","instruction":"After food"}],"pathology":[{"id":3,"name":"CBC"}]}',
    });
    final text = prescriptionText(lang: 'hi', patient: Patient({'id': 1, 'name': 'Asha'}), rx: rx, doctor: 'Dr. Mehta', hospital: 'Skylark', date: '11 Nov 2026');
    expect(text, contains('1. Paracetamol 500 - 1 tab, दिन में 3 बार (TDS), 5 दिन'));
    expect(text, contains('CBC'));
    expect(text, contains('Plenty of fluids'));
  });
}
