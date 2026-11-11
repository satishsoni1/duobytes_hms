import 'dart:convert';
import 'dart:math';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:local_auth/local_auth.dart';

/// Small key-value secret store (the platform keystore in the app, a map in tests).
abstract class SecureKv {
  Future<String?> read(String key);
  Future<void> write(String key, String? value);
}

class KeystoreKv implements SecureKv {
  KeystoreKv([FlutterSecureStorage? s]) : _s = s ?? const FlutterSecureStorage();
  final FlutterSecureStorage _s;
  // No keystore plugin (unit tests, unsupported desktop builds): behave as an empty store.
  @override
  Future<String?> read(String key) async {
    try {
      return await _s.read(key: key);
    } on MissingPluginException {
      return null;
    }
  }

  @override
  Future<void> write(String key, String? value) async {
    try {
      value == null ? await _s.delete(key: key) : await _s.write(key: key, value: value);
    } on MissingPluginException {
      return;
    }
  }
}

class MemoryKv implements SecureKv {
  final Map<String, String> data = {};
  @override
  Future<String?> read(String key) async => data[key];
  @override
  Future<void> write(String key, String? value) async => value == null ? data.remove(key) : data[key] = value;
}

/// Fingerprint / face unlock.
abstract class BiometricAuth {
  Future<bool> available();
  Future<bool> authenticate(String reason);
}

class DeviceBiometrics implements BiometricAuth {
  final _auth = LocalAuthentication();
  @override
  Future<bool> available() async {
    try {
      return await _auth.isDeviceSupported() && (await _auth.getAvailableBiometrics()).isNotEmpty;
    } catch (_) {
      return false;
    }
  }

  @override
  Future<bool> authenticate(String reason) async {
    try {
      return await _auth.authenticate(localizedReason: reason, biometricOnly: true, persistAcrossBackgrounding: true);
    } catch (_) {
      return false;
    }
  }
}

@immutable
class AppLockState {
  const AppLockState({this.loaded = false, this.enabled = false, this.locked = false, this.biometric = false, this.timeout = const Duration(minutes: 1), this.lockedUntil, this.failures = 0});

  final bool loaded;

  /// A PIN is set.
  final bool enabled;
  final bool locked;
  final bool biometric;

  /// How long the app may stay in the background before it locks again.
  final Duration timeout;

  /// After repeated wrong PINs, entry is blocked until this time.
  final DateTime? lockedUntil;
  final int failures;

  AppLockState copyWith({bool? loaded, bool? enabled, bool? locked, bool? biometric, Duration? timeout, DateTime? lockedUntil, bool clearLockedUntil = false, int? failures}) => AppLockState(
        loaded: loaded ?? this.loaded,
        enabled: enabled ?? this.enabled,
        locked: locked ?? this.locked,
        biometric: biometric ?? this.biometric,
        timeout: timeout ?? this.timeout,
        lockedUntil: clearLockedUntil ? null : (lockedUntil ?? this.lockedUntil),
        failures: failures ?? this.failures,
      );
}

/// PIN / fingerprint lock on top of the phone's own lock screen. Patient data stays encrypted either
/// way; this stops someone who picks up an unlocked clinic tablet from opening records.
///
/// The PIN is never stored: only a salted, iterated SHA-256 hash, in the platform keystore.
class AppLockController extends StateNotifier<AppLockState> {
  AppLockController({required this.kv, required this.biometrics, DateTime Function()? clock})
      : _now = clock ?? DateTime.now,
        super(const AppLockState()) {
    _load();
  }

  final SecureKv kv;
  final BiometricAuth biometrics;
  final DateTime Function() _now;

  static const _kHash = 'lock_pin_hash';
  static const _kSalt = 'lock_pin_salt';
  static const _kBio = 'lock_bio';
  static const _kTimeout = 'lock_timeout_s';
  static const _kFailures = 'lock_failures';
  static const _kUntil = 'lock_until';
  static const iterations = 20000;
  static const minPin = 4;
  static const maxPin = 6;

  DateTime? _backgroundedAt;

  Future<void> _load() async {
    final hash = await kv.read(_kHash);
    final until = DateTime.tryParse(await kv.read(_kUntil) ?? '');
    state = AppLockState(
      loaded: true,
      enabled: hash != null,
      locked: hash != null, // a cold start always asks
      biometric: await kv.read(_kBio) == '1',
      timeout: Duration(seconds: int.tryParse(await kv.read(_kTimeout) ?? '') ?? 60),
      failures: int.tryParse(await kv.read(_kFailures) ?? '') ?? 0,
      lockedUntil: until != null && until.isAfter(_now()) ? until : null,
    );
  }

  Future<void> ready() async {
    while (!state.loaded) {
      await Future<void>.delayed(const Duration(milliseconds: 5));
    }
  }

  static String hashPin(String pin, String salt) {
    var h = utf8.encode('$salt:$pin');
    for (var i = 0; i < iterations; i++) {
      h = Uint8List.fromList(sha256.convert([...h, ...utf8.encode(salt)]).bytes);
    }
    return base64Encode(h);
  }

  static bool validPin(String pin) => RegExp('^[0-9]{$minPin,$maxPin}\$').hasMatch(pin);

  Future<void> setPin(String pin) async {
    if (!validPin(pin)) throw ArgumentError('PIN must be $minPin to $maxPin digits');
    final rnd = Random.secure();
    final salt = base64Encode(List<int>.generate(16, (_) => rnd.nextInt(256)));
    await kv.write(_kSalt, salt);
    await kv.write(_kHash, await compute(_hashArgs, [pin, salt]));
    await _resetFailures();
    state = state.copyWith(enabled: true, locked: false);
  }

  Future<bool> _matches(String pin) async {
    final salt = await kv.read(_kSalt), hash = await kv.read(_kHash);
    if (salt == null || hash == null) return false;
    return await compute(_hashArgs, [pin, salt]) == hash;
  }

  /// Turn the lock off (needs the current PIN).
  Future<bool> disable(String currentPin) async {
    if (!await _matches(currentPin)) return false;
    await clear();
    return true;
  }

  /// Forget the lock entirely (sign-out, or a different user on this phone).
  Future<void> clear() async {
    for (final k in const [_kHash, _kSalt, _kBio, _kTimeout, _kFailures, _kUntil]) {
      await kv.write(k, null);
    }
    state = const AppLockState(loaded: true);
  }

  Future<void> setBiometric(bool on) async {
    await kv.write(_kBio, on ? '1' : null);
    state = state.copyWith(biometric: on);
  }

  Future<void> setTimeout(Duration d) async {
    await kv.write(_kTimeout, '${d.inSeconds}');
    state = state.copyWith(timeout: d);
  }

  /// Wait after repeated wrong PINs: 5 → 30 s, then doubling, at most 15 min.
  static Duration penalty(int failures) {
    if (failures < 5) return Duration.zero;
    return Duration(seconds: min(30 * pow(2, failures - 5).toInt(), 900));
  }

  /// Try a PIN. Returns null on success, else a message for the user.
  Future<String?> unlockWithPin(String pin) async {
    final until = state.lockedUntil;
    if (until != null && until.isAfter(_now())) return 'Too many wrong PINs. Try again in ${_wait(until)}.';
    if (await _matches(pin)) {
      await _resetFailures();
      state = state.copyWith(locked: false, failures: 0, clearLockedUntil: true);
      return null;
    }
    final f = state.failures + 1;
    final wait = penalty(f);
    final next = wait == Duration.zero ? null : _now().add(wait);
    await kv.write(_kFailures, '$f');
    await kv.write(_kUntil, next?.toIso8601String());
    state = state.copyWith(failures: f, lockedUntil: next);
    return next == null ? 'Wrong PIN. ${5 - f} tries left before a wait.' : 'Too many wrong PINs. Try again in ${_wait(next)}.';
  }

  Future<bool> unlockWithBiometrics() async {
    if (!state.biometric) return false;
    final ok = await biometrics.authenticate('Unlock DuoBytes HMS');
    if (ok) {
      await _resetFailures();
      state = state.copyWith(locked: false, failures: 0, clearLockedUntil: true);
    }
    return ok;
  }

  Future<void> _resetFailures() async {
    await kv.write(_kFailures, null);
    await kv.write(_kUntil, null);
  }

  String _wait(DateTime until) {
    final s = (until.difference(_now()).inMilliseconds / 1000).ceil();
    return s >= 60 ? '${(s / 60).ceil()} min' : '$s s';
  }

  // ------------------------------------------------------------------ app lifecycle
  void onBackground() => _backgroundedAt ??= _now();

  void onForeground() {
    final at = _backgroundedAt;
    _backgroundedAt = null;
    if (!state.enabled || at == null) return;
    if (_now().difference(at) >= state.timeout) state = state.copyWith(locked: true);
  }

  void lockNow() {
    if (state.enabled) state = state.copyWith(locked: true);
  }
}

String _hashArgs(List<String> a) => AppLockController.hashPin(a[0], a[1]);

final secureKvProvider = Provider<SecureKv>((_) => KeystoreKv());
final biometricsProvider = Provider<BiometricAuth>((_) => DeviceBiometrics());

final appLockProvider = StateNotifierProvider<AppLockController, AppLockState>(
  (ref) => AppLockController(kv: ref.watch(secureKvProvider), biometrics: ref.watch(biometricsProvider)),
);
