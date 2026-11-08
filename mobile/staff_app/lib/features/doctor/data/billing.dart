import 'dart:convert';

import 'package:sqflite_common/sqlite_api.dart';
import 'package:uuid/uuid.dart';

import '../../../core/db/app_database.dart';
import 'models.dart';

double _d(Object? v) => v is num ? v.toDouble() : double.tryParse('$v') ?? 0;

/// A service from the hospital charge list.
class ChargeOption {
  ChargeOption(this.j);
  final Map<String, dynamic> j;
  int get id => (j['id'] as num).toInt();
  String get name => '${j['name'] ?? ''}';
  String? get category => j['category'] as String?;
  String? get type => j['type'] as String?;
  double get standardCharge => _d(j['standard_charge']);
  double get taxPercent => _d(j['tax']);
  String? get unit => j['unit'] as String?;

  /// Same arithmetic as the server (Mobilebilling_model::add_charge).
  ({double apply, double tax, double total}) price(int qty, double discountPercent) {
    final apply = _r(standardCharge * qty * (1 - discountPercent / 100));
    final tax = _r(apply * taxPercent / 100);
    return (apply: apply, tax: tax, total: _r(apply + tax));
  }

  static double _r(double v) => (v * 100).roundToDouble() / 100;
}

class BillCharge {
  BillCharge(this.j);
  final Map<String, dynamic> j;
  String get name => '${j['charge_name'] ?? 'Charge'}';
  String? get category => j['category'] as String?;
  int get qty => (j['qty'] as num?)?.toInt() ?? 1;
  double get amount => _d(j['amount']);
  double get applyCharge => _d(j['apply_charge']);
  double get taxPercent => _d(j['tax']);
  DateTime? get date => parseServerDate(j['date']);
  int? get opdId => (j['opd_id'] as num?)?.toInt();
  int? get ipdId => (j['ipd_id'] as num?)?.toInt();
  String? get note => (j['note'] as String?)?.trim().isEmpty == true ? null : j['note'] as String?;
}

class BillPayment {
  BillPayment(this.j);
  final Map<String, dynamic> j;
  bool get isRefund => j['type'] == 'refund';
  double get amount => _d(j['amount']);
  String? get mode => j['payment_mode'] as String?;
  DateTime? get date => parseServerDate(j['payment_date']);
}

/// A service added on the phone that has not reached the server yet (or was refused).
class PendingCharge {
  PendingCharge(this.j);
  final Map<String, dynamic> j;
  String get opId => j['op_id'] as String;
  String get name => '${j['name'] ?? 'Service'}';
  int get qty => (j['qty'] as num?)?.toInt() ?? 1;
  double get total => _d(j['total']);
  String? get error => j['error'] as String?;
  DateTime? get date => parseServerDate(j['date']);
}

class PatientBill {
  PatientBill(this.patientId, this.charges, this.payments, this.pending);
  final int patientId;
  final List<BillCharge> charges;
  final List<BillPayment> payments;
  final List<PendingCharge> pending;

  double get totalCharges => charges.fold(0, (s, c) => s + c.amount);
  double get totalPaid => payments.fold(0, (s, p) => s + (p.isRefund ? -p.amount : p.amount));
  double get balance => totalCharges - totalPaid;
  double get pendingTotal => pending.where((p) => p.error == null).fold(0, (s, p) => s + p.total);

  static PatientBill empty(int patientId) => PatientBill(patientId, const [], const [], const []);
}

class BillingRepository {
  BillingRepository(this.adb);
  final AppDatabase adb;
  static const _uuid = Uuid();

  Future<List<ChargeOption>> chargeOptions() async {
    final j = await adb.lookup('charges');
    return ((j?['items'] as List?) ?? const []).map((e) => ChargeOption(Map<String, dynamic>.from(e as Map))).toList();
  }

  Future<PatientBill> bill(int patientId) async {
    final r = await adb.db.query('patient_billing', where: 'patient_id = ?', whereArgs: [patientId]);
    if (r.isEmpty) return PatientBill.empty(patientId);
    final j = jsonDecode(r.first['json'] as String) as Map<String, dynamic>;
    List<Map<String, dynamic>> rows(String k) => ((j[k] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    return PatientBill(patientId, rows('charges').map(BillCharge.new).toList(), rows('payments').map(BillPayment.new).toList(), rows('pending').map(PendingCharge.new).toList());
  }

  /// Queue a service for the patient's visit/admission. Shown as pending on the bill until the server accepts it.
  Future<void> addService({required Encounter encounter, required ChargeOption charge, required int qty, double discount = 0, String note = ''}) async {
    final opId = _uuid.v4();
    final now = DateTime.now();
    final date = '${ymdOf(now)} ${now.hour.toString().padLeft(2, '0')}:${now.minute.toString().padLeft(2, '0')}:00';
    final price = charge.price(qty, discount);
    await adb.db.transaction((tx) async {
      await _mutate(tx, encounter.patientId, (j) {
        (j['pending'] as List).add({'op_id': opId, 'name': charge.name, 'qty': qty, 'total': price.total, 'date': date});
      });
      await tx.insert('outbox', {
        'op_id': opId,
        'type': 'charge.add',
        'ref_local_id': '${encounter.patientId}',
        'payload': jsonEncode({
          'kind': encounter.kind,
          'case_ref_id': encounter.refId,
          'charge_id': charge.id,
          'qty': qty,
          'discount': discount,
          'note': note,
          'date': date,
        }),
        'attempts': 0,
        'created_at': now.toIso8601String(),
      });
    });
  }

  /// Remove a refused pending service from the list.
  Future<void> dismissPending(int patientId, String opId) =>
      adb.db.transaction((tx) => _mutate(tx, patientId, (j) => (j['pending'] as List).removeWhere((p) => p['op_id'] == opId)));

  static Future<void> _mutate(DatabaseExecutor tx, int patientId, void Function(Map<String, dynamic>) f) async {
    final r = await tx.query('patient_billing', where: 'patient_id = ?', whereArgs: [patientId]);
    final j = r.isEmpty ? <String, dynamic>{'patient_id': patientId, 'charges': [], 'payments': []} : jsonDecode(r.first['json'] as String) as Map<String, dynamic>;
    j['pending'] = List.of((j['pending'] as List?) ?? const []);
    j['charges'] = List.of((j['charges'] as List?) ?? const []);
    f(j);
    await tx.insert('patient_billing', {'patient_id': patientId, 'json': jsonEncode(j)}, conflictAlgorithm: ConflictAlgorithm.replace);
  }

  // ------------------------------------------------------------------ sync helpers
  /// Replace server bills; keep services still waiting to upload.
  static Future<void> applyServer(Transaction tx, List<Map<String, dynamic>> bills) async {
    for (final b in bills) {
      final pid = (b['patient_id'] as num).toInt();
      final r = await tx.query('patient_billing', columns: ['json'], where: 'patient_id = ?', whereArgs: [pid]);
      final pending = r.isEmpty ? const [] : ((jsonDecode(r.first['json'] as String) as Map)['pending'] as List? ?? const []);
      await tx.insert('patient_billing', {'patient_id': pid, 'json': jsonEncode({...b, 'pending': pending})}, conflictAlgorithm: ConflictAlgorithm.replace);
    }
  }

  static Future<void> applyResult(Transaction tx, int patientId, String opId, Map<String, dynamic> result) => _mutate(tx, patientId, (j) {
        final pending = j['pending'] as List;
        if (result['status'] == 'ok') {
          pending.removeWhere((p) => p['op_id'] == opId);
          if (result['charge'] is Map) (j['charges'] as List).insert(0, result['charge']);
        } else {
          for (final p in pending) {
            if (p['op_id'] == opId) p['error'] = result['message'] ?? 'Not accepted by the server';
          }
        }
      });
}

String ymdOf(DateTime d) => '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
