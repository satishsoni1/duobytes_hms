import 'dart:io';

import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../api/api_client.dart';
import '../db/app_database.dart';
import '../db/open_database.dart';
import '../ui/report_files.dart';
import 'patient_account.dart';
import 'session_store.dart';
import 'staff.dart';

sealed class AuthState {
  const AuthState();
}

class AuthLoading extends AuthState {
  const AuthLoading();
}

class SignedOut extends AuthState {
  const SignedOut({this.serverUrl, this.message, this.lastKind});
  final String? serverUrl;
  final String? message;

  /// Which sign-in tab to show first (the kind of account used last on this phone).
  final AccountKind? lastKind;
}

/// Common to staff and patient sessions.
sealed class SignedInBase extends AuthState {
  const SignedInBase();
  String get serverUrl;
  ApiClient get api;
  AppDatabase get db;

  /// The app keeps working offline; only syncing pauses until the password is entered again.
  bool get tokenExpired;
}

class SignedIn extends SignedInBase {
  const SignedIn({required this.session, required this.serverUrl, required this.api, required this.db, this.tokenExpired = false});
  final StaffSession session;
  @override
  final String serverUrl;
  @override
  final ApiClient api;
  @override
  final AppDatabase db;
  @override
  final bool tokenExpired;

  SignedIn copyWith({StaffSession? session, bool? tokenExpired}) =>
      SignedIn(session: session ?? this.session, serverUrl: serverUrl, api: api, db: db, tokenExpired: tokenExpired ?? this.tokenExpired);
}

class PatientSignedIn extends SignedInBase {
  const PatientSignedIn({required this.session, required this.serverUrl, required this.api, required this.db, this.tokenExpired = false});
  final PatientSession session;
  @override
  final String serverUrl;
  @override
  final ApiClient api;
  @override
  final AppDatabase db;
  @override
  final bool tokenExpired;

  PatientSignedIn copyWith({PatientSession? session, bool? tokenExpired}) =>
      PatientSignedIn(session: session ?? this.session, serverUrl: serverUrl, api: api, db: db, tokenExpired: tokenExpired ?? this.tokenExpired);
}

class AuthController extends StateNotifier<AuthState> {
  AuthController(this._store) : super(const AuthLoading());

  final SessionStore _store;

  Future<void> bootstrap() async {
    try {
      final server = await _store.serverUrl();
      final kind = await _store.kind();
      if (server == null || kind == null) {
        state = SignedOut(serverUrl: server, lastKind: kind);
        return;
      }
      final token = await _store.token();
      if (kind == AccountKind.patient) {
        final session = await _store.patientSession();
        if (session == null) {
          state = SignedOut(serverUrl: server, lastKind: kind);
          return;
        }
        state = PatientSignedIn(session: session, serverUrl: server, api: ApiClient(baseUrl: server, token: token), db: await _openDb(), tokenExpired: token == null);
      } else {
        final session = await _store.session();
        if (session == null) {
          state = SignedOut(serverUrl: server, lastKind: kind);
          return;
        }
        state = SignedIn(session: session, serverUrl: server, api: ApiClient(baseUrl: server, token: token), db: await _openDb(), tokenExpired: token == null);
      }
    } catch (e) {
      state = SignedOut(message: 'Could not open saved data ($e). Sign in again.');
    }
  }

  Future<AppDatabase> _openDb() async {
    final key = await _store.databaseKey();
    try {
      return await openAppDatabase(key);
    } catch (_) {
      // Key no longer matches the file (keystore reset / reinstall). The data cannot be read; start fresh.
      await deleteAppDatabase();
      return openAppDatabase(key);
    }
  }

  /// Database for the account about to sign in. Another person's (or another hospital's) data is wiped.
  Future<AppDatabase> _dbFor(String newIdentity) async {
    final current = state;
    final db = current is SignedInBase ? current.db : await _openDb();
    var previous = await _store.identity();
    if (previous == null) {
      // Installs from before patient login stored only the staff session.
      final s = await _store.session();
      final server = await _store.serverUrl();
      if (s != null) previous = 'staff:${s.staff.id}@$server';
    }
    if (previous != newIdentity) await db.wipe();
    return db;
  }

  /// Staff sign-in. Throws [ApiException] with a message suitable for the sign-in form.
  Future<void> signIn({required String serverUrl, required String email, required String password}) async {
    final server = ApiClient.normalizeServerUrl(serverUrl);
    final api = ApiClient(baseUrl: server);
    final res = await api.post('auth/login', {'email': email.trim(), 'password': password, ...await _deviceInfo()});
    final token = res['token'] as String;
    final session = StaffSession.fromJson(res);

    final current = state;
    final db = await _dbFor('staff:${session.staff.id}@$server');
    await _store.saveSignIn(serverUrl: server, token: token, session: session);
    if (current is SignedIn && current.serverUrl == server) {
      current.api.setToken(token);
      state = current.copyWith(session: session, tokenExpired: false);
    } else {
      api.setToken(token);
      state = SignedIn(session: session, serverUrl: server, api: api, db: db);
    }
  }

  /// Patient sign-in with the same username/password as the hospital's patient web portal.
  Future<void> signInPatient({required String serverUrl, required String username, required String password}) async {
    final server = ApiClient.normalizeServerUrl(serverUrl);
    final api = ApiClient(baseUrl: server);
    final res = await api.post('patient/login', {'username': username.trim(), 'password': password, ...await _deviceInfo()});
    final token = res['token'] as String;
    final session = PatientSession.fromJson(res);

    final current = state;
    final db = await _dbFor('patient:${session.userId}@$server');
    await _store.savePatientSignIn(serverUrl: server, token: token, session: session);
    if (current is PatientSignedIn && current.serverUrl == server) {
      current.api.setToken(token);
      state = current.copyWith(session: session, tokenExpired: false);
    } else {
      api.setToken(token);
      state = PatientSignedIn(session: session, serverUrl: server, api: api, db: db);
    }
  }

  Future<void> markTokenExpired() async {
    final s = state;
    if (s is! SignedInBase || s.tokenExpired) return;
    await _store.clearToken();
    s.api.setToken(null);
    state = switch (s) {
      SignedIn() => s.copyWith(tokenExpired: true),
      PatientSignedIn() => s.copyWith(tokenExpired: true),
    };
  }

  /// Refresh name/branding when online. Failures are ignored (offline is normal).
  Future<void> refreshProfile() async {
    final s = state;
    if (s is! SignedInBase || s.tokenExpired) return;
    try {
      if (s is SignedIn) {
        final session = StaffSession.fromJson(await s.api.get('auth/me'));
        await _store.updateSession(session);
        final now = state;
        if (now is SignedIn) state = now.copyWith(session: session);
      } else if (s is PatientSignedIn) {
        final session = PatientSession.fromJson(await s.api.get('patient/me'));
        await _store.updatePatientSession(session);
        final now = state;
        if (now is PatientSignedIn) state = now.copyWith(session: session);
      }
    } on ApiException catch (e) {
      if (e.kind == ApiErrorKind.unauthorized) await markTokenExpired();
    }
  }

  /// Signs out and removes all data from this device.
  Future<void> signOut() async {
    final s = state;
    AccountKind? kind;
    if (s is SignedInBase) {
      kind = s is PatientSignedIn ? AccountKind.patient : AccountKind.staff;
      if (!s.tokenExpired) {
        try {
          await s.api.post(s is PatientSignedIn ? 'patient/logout' : 'auth/logout', const {});
        } catch (_) {}
      }
      await s.db.wipe();
      await s.db.db.close();
      await deleteAppDatabase();
      await ReportFiles.clear();
    }
    await _store.clearAll();
    state = SignedOut(serverUrl: s is SignedInBase ? s.serverUrl : await _store.serverUrl(), lastKind: kind);
  }

  Future<Map<String, String>> _deviceInfo() async {
    var name = Platform.operatingSystem;
    var version = '';
    try {
      final info = DeviceInfoPlugin();
      if (Platform.isAndroid) {
        final a = await info.androidInfo;
        name = '${a.manufacturer} ${a.model}';
      } else if (Platform.isIOS) {
        final i = await info.iosInfo;
        name = i.utsname.machine;
      }
      final pkg = await PackageInfo.fromPlatform();
      version = '${pkg.version}+${pkg.buildNumber}';
    } catch (_) {}
    return {
      'device_id': await _store.deviceId(),
      'device_name': name,
      'platform': Platform.operatingSystem,
      'app_version': version,
    };
  }
}
