import 'dart:convert';
import 'dart:math';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:uuid/uuid.dart';

import 'patient_account.dart';
import 'staff.dart';

enum AccountKind { staff, patient }

/// Secrets and the signed-in identity, kept in the platform keystore (Keychain / Android Keystore).
class SessionStore {
  SessionStore([FlutterSecureStorage? storage]) : _s = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _s;

  static const _kServer = 'server_url';
  static const _kToken = 'api_token';
  static const _kKind = 'account_kind';
  static const _kSession = 'session_json'; // staff
  static const _kPatientSession = 'patient_session_json';
  static const _kIdentity = 'identity';
  static const _kDbKey = 'db_key';
  static const _kDeviceId = 'device_id';

  Future<String?> serverUrl() => _s.read(key: _kServer);
  Future<String?> token() => _s.read(key: _kToken);

  Future<AccountKind?> kind() async => switch (await _s.read(key: _kKind)) {
        'staff' => AccountKind.staff,
        'patient' => AccountKind.patient,
        _ => (await _s.read(key: _kSession)) != null ? AccountKind.staff : null, // installs from before patient login
      };

  /// "staff:7@https://host" / "patient:12@https://host": whose data is in the local database.
  Future<String?> identity() => _s.read(key: _kIdentity);

  Future<StaffSession?> session() async {
    final raw = await _s.read(key: _kSession);
    if (raw == null) return null;
    try {
      return StaffSession.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      return null;
    }
  }

  Future<PatientSession?> patientSession() async {
    final raw = await _s.read(key: _kPatientSession);
    if (raw == null) return null;
    try {
      return PatientSession.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      return null;
    }
  }

  Future<void> saveSignIn({required String serverUrl, required String token, required StaffSession session}) async {
    await _s.write(key: _kServer, value: serverUrl);
    await _s.write(key: _kToken, value: token);
    await _s.write(key: _kKind, value: 'staff');
    await _s.write(key: _kSession, value: jsonEncode(session.toJson()));
    await _s.delete(key: _kPatientSession);
    await _s.write(key: _kIdentity, value: 'staff:${session.staff.id}@$serverUrl');
  }

  Future<void> savePatientSignIn({required String serverUrl, required String token, required PatientSession session}) async {
    await _s.write(key: _kServer, value: serverUrl);
    await _s.write(key: _kToken, value: token);
    await _s.write(key: _kKind, value: 'patient');
    await _s.write(key: _kPatientSession, value: jsonEncode(session.toJson()));
    await _s.delete(key: _kSession);
    await _s.write(key: _kIdentity, value: 'patient:${session.userId}@$serverUrl');
  }

  Future<void> updateSession(StaffSession session) => _s.write(key: _kSession, value: jsonEncode(session.toJson()));
  Future<void> updatePatientSession(PatientSession session) => _s.write(key: _kPatientSession, value: jsonEncode(session.toJson()));

  /// Token expired or revoked: keep the identity (and offline data) so the same person can sign back in.
  Future<void> clearToken() => _s.delete(key: _kToken);

  Future<void> clearAll() async {
    for (final k in const [_kToken, _kKind, _kSession, _kPatientSession, _kIdentity, _kDbKey]) {
      await _s.delete(key: k);
    }
  }

  /// Random 256-bit key for the SQLCipher database, created on first use.
  Future<String> databaseKey() async {
    final existing = await _s.read(key: _kDbKey);
    if (existing != null) return existing;
    final r = Random.secure();
    final key = base64UrlEncode(List<int>.generate(32, (_) => r.nextInt(256)));
    await _s.write(key: _kDbKey, value: key);
    return key;
  }

  Future<String> deviceId() async {
    final existing = await _s.read(key: _kDeviceId);
    if (existing != null) return existing;
    final id = const Uuid().v4();
    await _s.write(key: _kDeviceId, value: id);
    return id;
  }
}
