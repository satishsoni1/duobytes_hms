import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:staff_app/core/security/app_lock.dart';
import 'package:staff_app/core/security/lock_screen.dart';

class _Bio implements BiometricAuth {
  _Bio(this.ok);
  bool ok;
  int calls = 0;
  @override
  Future<bool> available() async => true;
  @override
  Future<bool> authenticate(String reason) async {
    calls++;
    return ok;
  }
}

void main() {
  late DateTime now;
  AppLockController make(MemoryKv kv, {bool bio = false}) => AppLockController(kv: kv, biometrics: _Bio(bio), clock: () => now);

  setUp(() => now = DateTime(2026, 11, 11, 10));

  test('PIN is stored only as a salted hash; cold start is locked', () async {
    final kv = MemoryKv();
    final a = make(kv);
    await a.ready();
    expect(a.state.enabled, isFalse);
    await a.setPin('2580');
    expect(kv.data.values.any((v) => v.contains('2580')), isFalse);
    expect(a.state.locked, isFalse);

    final b = make(kv); // app restarted
    await b.ready();
    expect(b.state.enabled && b.state.locked, isTrue);
    expect(await b.unlockWithPin('1111'), startsWith('Wrong PIN'));
    expect(b.state.locked, isTrue);
    expect(await b.unlockWithPin('2580'), isNull);
    expect(b.state.locked, isFalse);
    expect(AppLockController.validPin('12'), isFalse);
    expect(AppLockController.validPin('12a4'), isFalse);
  });

  test('repeated wrong PINs make the user wait, and the wait survives a restart', () async {
    final kv = MemoryKv();
    final a = make(kv);
    await a.ready();
    await a.setPin('2580');
    a.lockNow();
    for (var i = 0; i < 4; i++) {
      await a.unlockWithPin('0000');
    }
    expect(await a.unlockWithPin('0000'), contains('Try again in 30 s'));
    expect(await a.unlockWithPin('2580'), contains('Too many wrong PINs'), reason: 'even the right PIN waits');

    final b = make(kv);
    await b.ready();
    expect(await b.unlockWithPin('2580'), contains('Too many wrong PINs'));
    now = now.add(const Duration(seconds: 31));
    expect(await b.unlockWithPin('2580'), isNull);
    expect(AppLockController.penalty(6), const Duration(seconds: 60));
    expect(AppLockController.penalty(30), const Duration(minutes: 15));
  });

  test('locks again only after the chosen time in the background', () async {
    final a = make(MemoryKv());
    await a.ready();
    await a.setPin('2580');
    await a.setTimeout(const Duration(minutes: 5));
    a.onBackground();
    now = now.add(const Duration(minutes: 2));
    a.onForeground();
    expect(a.state.locked, isFalse);
    a.onBackground();
    now = now.add(const Duration(minutes: 6));
    a.onForeground();
    expect(a.state.locked, isTrue);
  });

  test('turning the lock off needs the current PIN; biometrics unlock', () async {
    final kv = MemoryKv();
    final a = make(kv, bio: true);
    await a.ready();
    await a.setPin('2580');
    expect(await a.disable('9999'), isFalse);
    await a.setBiometric(true);
    a.lockNow();
    expect(await a.unlockWithBiometrics(), isTrue);
    expect(a.state.locked, isFalse);
    expect(await a.disable('2580'), isTrue);
    expect(kv.data, isEmpty);
  });

  testWidgets('lock screen covers the app until the PIN is entered', (t) async {
    final kv = MemoryKv();
    final ctrl = AppLockController(kv: kv, biometrics: _Bio(false));
    await t.runAsync(() async {
      await ctrl.ready();
      await ctrl.setPin('1234');
    });
    ctrl.lockNow();
    await t.pumpWidget(ProviderScope(
      overrides: [appLockProvider.overrideWith((_) => ctrl)],
      child: const MaterialApp(home: AppLockGate(child: Scaffold(body: Text('Patient records')))),
    ));
    await t.pump();
    expect(find.text('Enter your PIN'), findsOneWidget);
    for (final d in ['1', '2', '3', '4']) {
      await t.tap(find.widgetWithText(FilledButton, d));
    }
    await t.pump();
    await t.tap(find.byTooltip('Unlock'));
    // Hashing runs in an isolate, outside the fake clock.
    for (var i = 0; i < 60 && find.text('Enter your PIN').evaluate().isNotEmpty; i++) {
      await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 50)));
      await t.pump();
    }
    await t.pumpAndSettle();
    expect(find.text('Enter your PIN'), findsNothing);
    expect(find.text('Patient records'), findsOneWidget);
  });
}
