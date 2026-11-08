import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sqflite_common/sqlite_api.dart';

import '../../../core/api/api_client.dart';
import '../../../core/db/app_database.dart';
import '../../doctor/data/clinical.dart';
import '../../doctor/data/models.dart';

/// One of the patient's own appointments.
class MyAppointment {
  MyAppointment(this.j);
  final Map<String, dynamic> j;
  int get id => (j['id'] as num).toInt();
  DateTime? get date => parseServerDate(j['date']);
  String get status => (j['status'] as String?)?.trim().isNotEmpty == true ? j['status'] as String : 'pending';
  String? get doctorName => (j['doctor_name'] as String?)?.trim().isEmpty == true ? null : j['doctor_name'] as String?;
  String? get department => j['department'] as String?;
  String? get priority => j['priority'] as String?;
  String? get message => (j['message'] as String?)?.trim().isEmpty == true ? null : j['message'] as String?;
  bool get isCancelled => const {'cancel', 'cancelled', 'reject', 'rejected'}.contains(status.toLowerCase());
  bool get isUpcoming => !isCancelled && (date?.isAfter(DateTime.now().subtract(const Duration(hours: 2))) ?? false);
}

/// Everything the patient app shows, decoded from api/v1/patient/data.
class PatientRecords {
  PatientRecords._(this.raw, this.modules);

  final Map<String, dynamic> raw;
  final Map<String, bool> modules;

  factory PatientRecords.fromJson(Map<String, dynamic> body) => PatientRecords._(
        Map<String, dynamic>.from(body['data'] as Map? ?? const {}),
        {for (final e in ((body['modules'] as Map?) ?? const {}).entries) '${e.key}': e.value == true},
      );

  List<Map<String, dynamic>> _rows(String k) => ((raw[k] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();

  late final Patient? patient = _rows('patients').map(Patient.new).firstOrNull;

  late final List<Encounter> encounters = () {
    final list = [..._rows('visits').map(Encounter.visit), ..._rows('admissions').map(Encounter.admission)];
    list.sort((a, b) => (b.date ?? DateTime(1900)).compareTo(a.date ?? DateTime(1900)));
    return list;
  }();

  late final Map<String, List<IcdCode>> diagnoses = () {
    final byCase = {
      for (final d in _rows('diagnoses'))
        '${d['kind']}:${d['case_id']}': ((d['codes'] as List?) ?? const []).map((c) => IcdCode.fromJson(Map<String, dynamic>.from(c as Map))).toList(),
    };
    return {for (final e in encounters) e.key: byCase['${e.kind}:${e.caseId}'] ?? const <IcdCode>[]};
  }();

  late final List<Prescription> prescriptions = () {
    final list = _rows('prescriptions').map((p) {
      final ipd = p['ipd_id'] != null;
      return Prescription(
        localId: 'srv-${p['id']}',
        serverId: (p['id'] as num).toInt(),
        patientId: (p['patient_id'] as num?)?.toInt() ?? 0,
        kind: ipd ? 'ipd' : 'opd',
        caseRefId: ((ipd ? p['ipd_id'] : p['visit_id']) as num?)?.toInt() ?? 0,
        status: RxStatus.synced,
        version: p['version'] as String?,
        error: null,
        j: p,
      );
    }).toList();
    list.sort((a, b) => (b.date ?? DateTime(1900)).compareTo(a.date ?? DateTime(1900)));
    return list;
  }();

  late final List<LabReport> labs = () {
    final list = _rows('lab_reports').map(LabReport.new).toList();
    list.sort((a, b) => (b.reportingDate ?? b.collectionDate ?? DateTime(2999)).compareTo(a.reportingDate ?? a.collectionDate ?? DateTime(2999)));
    return list;
  }();

  late final List<VitalReading> vitals = () {
    final list = _rows('vitals').map(VitalReading.new).toList();
    list.sort((a, b) => (b.measuredAt ?? DateTime(1900)).compareTo(a.measuredAt ?? DateTime(1900)));
    return list;
  }();

  late final List<TimelineEntry> timeline = () {
    final list = _rows('timeline').map(TimelineEntry.new).toList();
    list.sort((a, b) => (b.date ?? DateTime(1900)).compareTo(a.date ?? DateTime(1900)));
    return list;
  }();

  late final List<MyAppointment> appointments = _rows('appointments').map(MyAppointment.new).toList();

  /// Vaccinations, follow-ups, growth, pregnancy records written by the patient's doctors.
  late final List<ClinicalRecord> clinical = _rows('clinical_records').map(ClinicalRecord.fromServer).where((r) => !r.deleted).toList();

  List<ClinicalRecord> ofType(String type) => clinical.where((r) => r.type == type).toList();

  /// Soonest pending follow-up (including overdue ones).
  ClinicalRecord? get nextFollowUp {
    final f = ofType(RecordType.followup).where((r) => r.status == 'active' && r.eventDate != null).toList()..sort((a, b) => a.eventDate!.compareTo(b.eventDate!));
    return f.firstOrNull;
  }

  ClinicalRecord? get activePregnancy => ofType(RecordType.pregnancy).where((r) => r.status == 'active').firstOrNull;

  /// Soonest upcoming appointment that is not cancelled.
  MyAppointment? get nextAppointment {
    final up = appointments.where((a) => a.isUpcoming).toList()..sort((a, b) => a.date!.compareTo(b.date!));
    return up.firstOrNull;
  }

  Encounter? encounterFor(Prescription p) => encounters.where((e) => e.kind == p.kind && e.refId == p.caseRefId).firstOrNull;

  List<String> get allergies => splitAllergies([patient?.allergies.join(','), for (final e in encounters) e.allergies.join(',')]);

  bool has(String module) => modules[module] ?? true;
}

/// Local cache of the patient's records (inside the encrypted database) and refresh from the server.
class PatientRepository {
  PatientRepository(this.adb);
  final AppDatabase adb;

  static const _key = 'patient_records';

  Future<PatientRecords?> load() async {
    final j = await adb.lookup(_key);
    return j == null ? null : PatientRecords.fromJson(j);
  }

  Future<DateTime?> lastRefreshed() async {
    final v = await adb.getMeta('patient_last_sync');
    return v == null ? null : DateTime.tryParse(v);
  }

  /// Download the latest records. Returns true when something changed.
  Future<bool> refresh(ApiClient api) async {
    final cached = await adb.lookup(_key);
    final etag = cached == null ? null : await adb.getMeta('patient_etag');
    final res = await api.getIfChanged('patient/data', etag);
    await adb.setMeta('patient_last_sync', DateTime.now().toIso8601String());
    if (res == null) return false;
    await adb.db.transaction((tx) async {
      await tx.insert('lookups', {'key': _key, 'json': jsonEncode(res.body)}, conflictAlgorithm: ConflictAlgorithm.replace);
      await adb.setMeta('patient_etag', res.etag, tx);
    });
    return true;
  }
}

enum PatientSyncPhase { idle, syncing, offline, needsSignIn, error }

class PatientSyncStatus {
  const PatientSyncStatus({this.phase = PatientSyncPhase.idle, this.lastSyncAt, this.message});
  final PatientSyncPhase phase;
  final DateTime? lastSyncAt;
  final String? message;
}

/// Refreshes the patient's records; single-flight, never throws.
class PatientSyncController extends StateNotifier<PatientSyncStatus> {
  PatientSyncController({required this.repo, required this.api, required this.onChanged, required this.onUnauthorized}) : super(const PatientSyncStatus()) {
    repo.lastRefreshed().then((t) {
      if (mounted) state = PatientSyncStatus(phase: state.phase, lastSyncAt: t, message: state.message);
    });
  }

  final PatientRepository repo;
  final ApiClient api;
  final void Function() onChanged;
  final void Function() onUnauthorized;
  Future<void>? _running;

  Future<void> refresh() => _running ??= _run().whenComplete(() => _running = null);

  Future<void> _run() async {
    state = PatientSyncStatus(phase: PatientSyncPhase.syncing, lastSyncAt: state.lastSyncAt);
    try {
      final changed = await repo.refresh(api);
      if (!mounted) return;
      state = PatientSyncStatus(lastSyncAt: DateTime.now());
      if (changed) onChanged();
    } on ApiException catch (e) {
      if (!mounted) return;
      if (e.kind == ApiErrorKind.unauthorized) {
        state = PatientSyncStatus(phase: PatientSyncPhase.needsSignIn, lastSyncAt: state.lastSyncAt, message: e.message);
        onUnauthorized();
      } else {
        state = PatientSyncStatus(phase: e.isOffline ? PatientSyncPhase.offline : PatientSyncPhase.error, lastSyncAt: state.lastSyncAt, message: e.message);
      }
    } catch (e) {
      if (mounted) state = PatientSyncStatus(phase: PatientSyncPhase.error, lastSyncAt: state.lastSyncAt, message: '$e');
    }
  }
}
