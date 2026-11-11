import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../providers.dart';
import '../ui/widgets.dart';
import 'app_lock.dart';

/// Shows the lock screen over the signed-in app while it is locked, and re-locks after the app
/// has been in the background longer than the chosen time.
class AppLockGate extends ConsumerStatefulWidget {
  const AppLockGate({super.key, required this.child});
  final Widget child;

  @override
  ConsumerState<AppLockGate> createState() => _AppLockGateState();
}

class _AppLockGateState extends ConsumerState<AppLockGate> with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState s) {
    final lock = ref.read(appLockProvider.notifier);
    if (s == AppLifecycleState.paused || s == AppLifecycleState.hidden) lock.onBackground();
    if (s == AppLifecycleState.resumed) lock.onForeground();
  }

  @override
  Widget build(BuildContext context) {
    final st = ref.watch(appLockProvider);
    if (!st.loaded) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    return Stack(children: [
      // Keep the app underneath alive (and hidden from screen readers) so unlocking returns to the same screen.
      ExcludeSemantics(excluding: st.locked, child: TickerMode(enabled: !st.locked, child: widget.child)),
      if (st.locked) const Positioned.fill(child: LockScreen()),
    ]);
  }
}

class LockScreen extends ConsumerStatefulWidget {
  const LockScreen({super.key});

  @override
  ConsumerState<LockScreen> createState() => _LockScreenState();
}

class _LockScreenState extends ConsumerState<LockScreen> {
  String _pin = '';
  String? _message;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _bio());
  }

  Future<void> _bio() async {
    if (!ref.read(appLockProvider).biometric) return;
    await ref.read(appLockProvider.notifier).unlockWithBiometrics();
  }

  Future<void> _digit(String d) async {
    if (_busy || _pin.length >= AppLockController.maxPin) return;
    HapticFeedback.selectionClick();
    setState(() {
      _pin += d;
      _message = null;
    });
  }

  Future<void> _submit() async {
    if (_pin.length < AppLockController.minPin) return;
    setState(() => _busy = true);
    final err = await ref.read(appLockProvider.notifier).unlockWithPin(_pin);
    if (!mounted) return;
    if (err != null) HapticFeedback.heavyImpact();
    setState(() {
      _busy = false;
      _pin = '';
      _message = err;
    });
  }

  Future<void> _forgot() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Forgot your PIN?'),
        content: const Text('Sign out to remove the PIN. Patient data on this device is deleted when you sign out. Changes not yet uploaded will be lost, so sync first if you can.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(style: FilledButton.styleFrom(backgroundColor: Theme.of(ctx).colorScheme.error), onPressed: () => Navigator.pop(ctx, true), child: const Text('Sign out')),
        ],
      ),
    );
    if (ok != true) return;
    await ref.read(appLockProvider.notifier).clear();
    await ref.read(authControllerProvider.notifier).signOut();
  }

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final st = ref.watch(appLockProvider);
    return Material(
      color: cs.surface,
      child: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 380),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 24),
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                Icon(Icons.lock_rounded, size: 40, color: cs.primary),
                const SizedBox(height: 12),
                Text('Enter your PIN', style: t.textTheme.titleLarge),
                const SizedBox(height: 16),
                PinDots(length: _pin.length, max: AppLockController.maxPin),
                SizedBox(
                  height: 40,
                  child: Center(
                    child: Text(_message ?? '', textAlign: TextAlign.center, style: TextStyle(color: cs.error, fontWeight: FontWeight.w600)),
                  ),
                ),
                PinPad(
                  onDigit: _digit,
                  onBackspace: () => setState(() => _pin = _pin.isEmpty ? '' : _pin.substring(0, _pin.length - 1)),
                  onSubmit: _pin.length >= AppLockController.minPin && !_busy ? _submit : null,
                  extra: st.biometric ? IconButton(tooltip: 'Use fingerprint', iconSize: 30, onPressed: _bio, icon: const Icon(Icons.fingerprint_rounded)) : null,
                ),
                const SizedBox(height: 12),
                TextButton(onPressed: _forgot, child: const Text('Forgot PIN?')),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}

class PinDots extends StatelessWidget {
  const PinDots({super.key, required this.length, required this.max});
  final int length;
  final int max;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Semantics(
      label: '$length digits entered',
      child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
        for (var i = 0; i < max; i++)
          AnimatedContainer(
            duration: const Duration(milliseconds: 120),
            margin: const EdgeInsets.symmetric(horizontal: 7),
            width: 14,
            height: 14,
            decoration: BoxDecoration(shape: BoxShape.circle, color: i < length ? cs.primary : Colors.transparent, border: Border.all(color: cs.outline, width: 1.5)),
          ),
      ]),
    );
  }
}

class PinPad extends StatelessWidget {
  const PinPad({super.key, required this.onDigit, required this.onBackspace, required this.onSubmit, this.extra});
  final void Function(String) onDigit;
  final VoidCallback onBackspace;
  final VoidCallback? onSubmit;
  final Widget? extra;

  @override
  Widget build(BuildContext context) {
    Widget key(String d) => Expanded(
          child: Padding(
            padding: const EdgeInsets.all(6),
            child: AspectRatio(
              aspectRatio: 1.5,
              child: FilledButton.tonal(
                style: FilledButton.styleFrom(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)), textStyle: Theme.of(context).textTheme.headlineSmall),
                onPressed: () => onDigit(d),
                child: Text(d),
              ),
            ),
          ),
        );
    Widget cell(Widget w) => Expanded(child: Padding(padding: const EdgeInsets.all(6), child: AspectRatio(aspectRatio: 1.5, child: Center(child: w))));
    return Column(children: [
      for (final row in const [['1', '2', '3'], ['4', '5', '6'], ['7', '8', '9']]) Row(children: [for (final d in row) key(d)]),
      Row(children: [
        cell(extra ?? IconButton(tooltip: 'Delete', iconSize: 28, onPressed: onBackspace, icon: const Icon(Icons.backspace_outlined))),
        key('0'),
        cell(extra == null
            ? IconButton.filled(tooltip: 'Unlock', iconSize: 28, onPressed: onSubmit, icon: const Icon(Icons.arrow_forward_rounded))
            : Row(mainAxisAlignment: MainAxisAlignment.spaceEvenly, children: [
                IconButton(tooltip: 'Delete', onPressed: onBackspace, icon: const Icon(Icons.backspace_outlined)),
                IconButton.filled(tooltip: 'Unlock', onPressed: onSubmit, icon: const Icon(Icons.arrow_forward_rounded)),
              ])),
      ]),
    ]);
  }
}

/// Ask for a new PIN twice. Returns the PIN, or null when cancelled.
Future<String?> askNewPin(BuildContext context) => Navigator.push<String>(context, MaterialPageRoute(builder: (_) => const _NewPinScreen()));

class _NewPinScreen extends StatefulWidget {
  const _NewPinScreen();
  @override
  State<_NewPinScreen> createState() => _NewPinScreenState();
}

class _NewPinScreenState extends State<_NewPinScreen> {
  String _pin = '';
  String? _first;
  String? _error;

  void _next() {
    if (_first == null) {
      setState(() {
        _first = _pin;
        _pin = '';
        _error = null;
      });
    } else if (_first == _pin) {
      Navigator.pop(context, _pin);
    } else {
      setState(() {
        _first = null;
        _pin = '';
        _error = 'The PINs did not match. Start again.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Set app PIN')),
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 380),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 24),
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                Text(_first == null ? 'Choose a 4 to 6 digit PIN' : 'Enter the same PIN again', style: t.textTheme.titleMedium),
                const SizedBox(height: 16),
                PinDots(length: _pin.length, max: AppLockController.maxPin),
                SizedBox(height: 40, child: Center(child: Text(_error ?? '', style: TextStyle(color: t.colorScheme.error, fontWeight: FontWeight.w600)))),
                PinPad(
                  onDigit: (d) => setState(() => _pin = _pin.length < AppLockController.maxPin ? _pin + d : _pin),
                  onBackspace: () => setState(() => _pin = _pin.isEmpty ? '' : _pin.substring(0, _pin.length - 1)),
                  onSubmit: _pin.length >= AppLockController.minPin ? _next : null,
                ),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}

/// Account → App lock.
class AppLockSettingsScreen extends ConsumerWidget {
  const AppLockSettingsScreen({super.key});

  static const timeouts = [(Duration.zero, 'Immediately'), (Duration(minutes: 1), 'After 1 minute'), (Duration(minutes: 5), 'After 5 minutes'), (Duration(minutes: 15), 'After 15 minutes')];

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final st = ref.watch(appLockProvider);
    final lock = ref.read(appLockProvider.notifier);
    final bioAvailable = ref.watch(_bioAvailableProvider).valueOrNull ?? false;
    return Scaffold(
      appBar: AppBar(title: const Text('App lock')),
      body: ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 24), children: [
        Card(
          child: SwitchListTile(
            secondary: const IconBadge(Icons.lock_rounded),
            title: const Text('Lock with a PIN'),
            subtitle: const Text('Ask for a PIN when the app opens, so a shared or lost tablet does not show patient records.'),
            value: st.enabled,
            onChanged: (on) async {
              if (on) {
                final pin = await askNewPin(context);
                if (pin != null) {
                  await lock.setPin(pin);
                  if (context.mounted) showSnack(context, 'App lock is on', icon: Icons.lock_rounded);
                }
              } else {
                final pin = await _askCurrentPin(context);
                if (pin == null) return;
                final ok = await lock.disable(pin);
                if (context.mounted) showSnack(context, ok ? 'App lock is off' : 'Wrong PIN', error: !ok);
              }
            },
          ),
        ),
        if (st.enabled) ...[
          Card(
            child: SwitchListTile(
              secondary: const IconBadge(Icons.fingerprint_rounded),
              title: const Text('Unlock with fingerprint or face'),
              subtitle: Text(bioAvailable ? 'Your PIN still works as a backup.' : 'No fingerprint or face is set up on this device.'),
              value: st.biometric && bioAvailable,
              onChanged: bioAvailable
                  ? (on) async {
                      if (on && !await ref.read(biometricsProvider).authenticate('Confirm to use fingerprint for DuoBytes HMS')) return;
                      await lock.setBiometric(on);
                    }
                  : null,
            ),
          ),
          Card(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const ListTile(leading: IconBadge(Icons.timer_outlined), title: Text('Lock again when away'), subtitle: Text('Time the app can stay in the background before it asks for the PIN.')),
              RadioGroup<int>(
                groupValue: st.timeout.inSeconds,
                onChanged: (v) => v == null ? null : lock.setTimeout(Duration(seconds: v)),
                child: Column(children: [for (final (d, l) in timeouts) RadioListTile<int>(value: d.inSeconds, title: Text(l))]),
              ),
            ]),
          ),
          Card(
            child: ListTile(
              leading: const IconBadge(Icons.password_rounded),
              title: const Text('Change PIN'),
              trailing: const Icon(Icons.chevron_right_rounded),
              onTap: () async {
                final cur = await _askCurrentPin(context);
                if (cur == null || !context.mounted) return;
                if (await lock.unlockWithPin(cur) != null) {
                  if (context.mounted) showSnack(context, 'Wrong PIN', error: true);
                  return;
                }
                if (!context.mounted) return;
                final pin = await askNewPin(context);
                if (pin != null) {
                  await lock.setPin(pin);
                  if (context.mounted) showSnack(context, 'PIN changed');
                }
              },
            ),
          ),
        ],
      ]),
    );
  }

  Future<String?> _askCurrentPin(BuildContext context) {
    final c = TextEditingController();
    return showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Current PIN'),
        content: TextField(
          controller: c,
          autofocus: true,
          obscureText: true,
          keyboardType: TextInputType.number,
          maxLength: AppLockController.maxPin,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(ctx, c.text), child: const Text('OK')),
        ],
      ),
    );
  }
}

final _bioAvailableProvider = FutureProvider.autoDispose<bool>((ref) => ref.watch(biometricsProvider).available());
