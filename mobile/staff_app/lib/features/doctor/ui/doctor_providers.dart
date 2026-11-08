import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/providers.dart';
import '../data/doctor_repository.dart';
import '../data/models.dart';

final doctorRepoProvider = Provider<DoctorRepository>(
  (ref) => DoctorRepository(ref.watch(databaseProvider), ref.watch(staffProvider).id),
);

// Every read below watches dataRevisionProvider, so screens refresh after a sync or a local save.

final todayProvider = FutureProvider.autoDispose<TodayData>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(doctorRepoProvider).today();
});

final patientSearchProvider = FutureProvider.autoDispose.family<List<Patient>, String>((ref, q) {
  ref.watch(dataRevisionProvider);
  return ref.watch(doctorRepoProvider).searchPatients(q);
});

class PatientDetail {
  PatientDetail({
    required this.patient,
    required this.encounters,
    required this.diagnoses,
    required this.prescriptions,
    required this.vitals,
    required this.labs,
    required this.timeline,
  });

  final Patient patient;
  final List<Encounter> encounters;
  final Map<String, List<IcdCode>> diagnoses;
  final List<Prescription> prescriptions;
  final List<VitalReading> vitals;
  final List<LabReport> labs;
  final List<TimelineEntry> timeline;

  /// Patient allergies plus any recorded on visits/admissions.
  List<String> get allergies => splitAllergies([patient.allergies.join(','), for (final e in encounters) e.allergies.join(',')]);

  List<Encounter> get openEncounters => encounters.where((e) => e.isOpen).toList();

  Encounter? encounterFor(Prescription p) {
    for (final e in encounters) {
      if (e.kind == p.kind && e.refId == p.caseRefId) return e;
    }
    return null;
  }
}

final patientDetailProvider = FutureProvider.autoDispose.family<PatientDetail?, int>((ref, id) async {
  ref.watch(dataRevisionProvider);
  final repo = ref.watch(doctorRepoProvider);
  final patient = await repo.patient(id);
  if (patient == null) return null;
  final encounters = await repo.encounters(id);
  return PatientDetail(
    patient: patient,
    encounters: encounters,
    diagnoses: await repo.diagnoses(encounters),
    prescriptions: await repo.prescriptions(id),
    vitals: await repo.vitals(id),
    labs: await repo.labReports(id),
    timeline: await repo.timeline(id),
  );
});

final prescriptionProvider = FutureProvider.autoDispose.family<Prescription?, String>((ref, localId) {
  ref.watch(dataRevisionProvider);
  return ref.watch(doctorRepoProvider).prescription(localId);
});

final mastersProvider = FutureProvider.autoDispose<Masters>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(doctorRepoProvider).masters();
});

final templatesProvider = FutureProvider.autoDispose<List<RxTemplate>>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(doctorRepoProvider).templates();
});

final unsyncedPrescriptionsProvider = FutureProvider.autoDispose<List<Prescription>>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(doctorRepoProvider).unsyncedPrescriptions();
});

/// Call after a local write so screens refresh and the change is pushed soon.
void afterLocalWrite(WidgetRef ref) {
  ref.read(dataRevisionProvider.notifier).state++;
  ref.read(syncEngineProvider.notifier)
    ..refreshCounts()
    ..scheduleSoon();
}
