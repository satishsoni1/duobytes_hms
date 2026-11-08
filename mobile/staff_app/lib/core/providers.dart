import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../modules.dart';
import 'api/api_client.dart';
import 'auth/auth_controller.dart';
import 'auth/session_store.dart';
import 'auth/staff.dart';
import 'db/app_database.dart';
import 'sync/sync_engine.dart';

final sessionStoreProvider = Provider<SessionStore>((_) => SessionStore());

final authControllerProvider = StateNotifierProvider<AuthController, AuthState>(
  (ref) => AuthController(ref.read(sessionStoreProvider))..bootstrap(),
);

/// Current signed-in state without subscribing to every auth change (callers watch a narrow select first).
SignedIn _signedIn(Ref ref) {
  final s = ref.read(authControllerProvider);
  if (s is SignedIn) return s;
  throw StateError('Not signed in');
}

// Narrow selectors so a token refresh does not rebuild the database / sync engine.
final databaseProvider = Provider<AppDatabase>((ref) {
  ref.watch(authControllerProvider.select((s) => s is SignedIn ? s.db : null));
  return _signedIn(ref).db;
});

final apiProvider = Provider<ApiClient>((ref) {
  ref.watch(authControllerProvider.select((s) => s is SignedIn ? s.api : null));
  return _signedIn(ref).api;
});

/// The signed-in user. Rebuilds only when a different user signs in; use [sessionProvider] for display data.
final staffProvider = Provider<Staff>((ref) {
  ref.watch(authControllerProvider.select((s) => s is SignedIn ? s.session.staff.id : null));
  return _signedIn(ref).session.staff;
});

final sessionProvider = Provider<StaffSession>((ref) {
  ref.watch(authControllerProvider.select((s) => s is SignedIn ? s.session : null));
  return _signedIn(ref).session;
});

final tokenExpiredProvider = Provider<bool>((ref) {
  final s = ref.watch(authControllerProvider);
  return s is SignedInBase && s.tokenExpired;
});

/// Bumped whenever local data changes (sync applied, prescription saved). Screens that read the
/// local database watch it so they refresh automatically.
final dataRevisionProvider = StateProvider<int>((_) => 0);

final syncEngineProvider = StateNotifierProvider<SyncEngine, SyncStatus>((ref) {
  final staff = ref.watch(staffProvider);
  return SyncEngine(
    api: ref.watch(apiProvider),
    adb: ref.watch(databaseProvider),
    modules: modulesFor(staff).map((m) => m.createSync()).toList(),
    onDataChanged: () => ref.read(dataRevisionProvider.notifier).state++,
    onUnauthorized: () => ref.read(authControllerProvider.notifier).markTokenExpired(),
  );
});
