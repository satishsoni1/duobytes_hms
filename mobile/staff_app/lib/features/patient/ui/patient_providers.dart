import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/auth/auth_controller.dart';
import '../../../core/auth/patient_account.dart';
import '../../../core/providers.dart';
import '../data/patient_records.dart';

PatientSignedIn _patientAuth(Ref ref) {
  final s = ref.read(authControllerProvider);
  if (s is PatientSignedIn) return s;
  throw StateError('No patient signed in');
}

final patientSessionProvider = Provider<PatientSession>((ref) {
  ref.watch(authControllerProvider.select((s) => s is PatientSignedIn ? s.session : null));
  return _patientAuth(ref).session;
});

final patientRepositoryProvider = Provider<PatientRepository>((ref) {
  ref.watch(authControllerProvider.select((s) => s is PatientSignedIn ? s.db : null));
  return PatientRepository(_patientAuth(ref).db);
});

final patientApiProvider = Provider((ref) {
  ref.watch(authControllerProvider.select((s) => s is PatientSignedIn ? s.api : null));
  return _patientAuth(ref).api;
});

final patientRecordsProvider = FutureProvider.autoDispose<PatientRecords?>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(patientRepositoryProvider).load();
});

final patientSyncProvider = StateNotifierProvider<PatientSyncController, PatientSyncStatus>((ref) {
  ref.watch(authControllerProvider.select((s) => s is PatientSignedIn ? s.api : null));
  return PatientSyncController(
    repo: ref.watch(patientRepositoryProvider),
    api: _patientAuth(ref).api,
    onChanged: () => ref.read(dataRevisionProvider.notifier).state++,
    onUnauthorized: () => ref.read(authControllerProvider.notifier).markTokenExpired(),
  );
});
