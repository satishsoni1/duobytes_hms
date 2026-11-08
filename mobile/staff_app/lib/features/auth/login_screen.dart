import 'dart:math' as math;
import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/auth/session_store.dart';
import '../../core/config.dart';
import '../../core/providers.dart';
import '../../core/ui/theme.dart';
import '../../core/ui/widgets.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _form = GlobalKey<FormState>();
  final _server = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  bool _showPassword = false;
  bool _editServer = false;
  String? _error;
  AccountKind _kind = AccountKind.staff;

  bool get _patient => _kind == AccountKind.patient;

  @override
  void initState() {
    super.initState();
    final s = ref.read(authControllerProvider);
    _server.text = (s is SignedOut ? s.serverUrl : null) ?? AppConfig.defaultServerUrl;
    _error = s is SignedOut ? s.message : null;
    if (s is SignedOut && s.lastKind != null) _kind = s.lastKind!;
  }

  @override
  void dispose() {
    _server.dispose();
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) {
      HapticFeedback.heavyImpact();
      return;
    }
    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final c = ref.read(authControllerProvider.notifier);
      if (_patient) {
        await c.signInPatient(serverUrl: _server.text, username: _email.text, password: _password.text);
      } else {
        await c.signIn(serverUrl: _server.text, email: _email.text, password: _password.text);
      }
      HapticFeedback.mediumImpact();
    } on ApiException catch (e) {
      HapticFeedback.heavyImpact();
      setState(() => _error = e.isOffline ? 'Cannot reach ${ApiClient.normalizeServerUrl(_server.text)}. Check the internet connection and the hospital address.' : e.message);
    } catch (e) {
      setState(() => _error = 'Sign-in failed: $e');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final dark = t.brightness == Brightness.dark;
    var i = 0;
    Widget anim(Widget w) => FadeSlideIn(index: i++, child: w);

    return Scaffold(
      body: Stack(children: [
        const Positioned.fill(child: _Aurora()),
        SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(20, 24, 20, 24),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 420),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  anim(Center(
                    child: Container(
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(28),
                        boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: 0.12), blurRadius: 30, offset: const Offset(0, 12))],
                      ),
                      child: Image.asset('assets/brand/duobytes_hms_logo.png', height: 120, semanticLabel: 'DuoBytes HMS'),
                    ),
                  )),
                  const SizedBox(height: 20),
                  anim(Text('Welcome back', textAlign: TextAlign.center, style: t.textTheme.headlineMedium?.copyWith(color: Colors.white))),
                  const SizedBox(height: 4),
                  anim(Text(_patient ? 'See your prescriptions, reports and visits' : 'Sign in with your hospital staff account',
                      textAlign: TextAlign.center, style: t.textTheme.bodyMedium?.copyWith(color: Colors.white.withValues(alpha: 0.85)))),
                  const SizedBox(height: 28),
                  anim(ClipRRect(
                    borderRadius: BorderRadius.circular(Radii.xl),
                    child: BackdropFilter(
                      filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
                      child: Container(
                        padding: const EdgeInsets.fromLTRB(20, 22, 20, 14),
                        decoration: BoxDecoration(
                          color: (dark ? cs.surfaceContainer : cs.surface).withValues(alpha: dark ? 0.82 : 0.9),
                          borderRadius: BorderRadius.circular(Radii.xl),
                          border: Border.all(color: Colors.white.withValues(alpha: 0.35)),
                        ),
                        child: Form(
                          key: _form,
                          child: AutofillGroup(
                            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                              SegmentedButton<AccountKind>(
                                showSelectedIcon: false,
                                segments: const [
                                  ButtonSegment(value: AccountKind.staff, icon: Icon(Icons.medical_services_rounded), label: Text('Staff')),
                                  ButtonSegment(value: AccountKind.patient, icon: Icon(Icons.person_rounded), label: Text('Patient')),
                                ],
                                selected: {_kind},
                                onSelectionChanged: _busy
                                    ? null
                                    : (k) {
                                        HapticFeedback.selectionClick();
                                        setState(() {
                                          _kind = k.first;
                                          _error = null;
                                        });
                                      },
                              ),
                              const SizedBox(height: 18),
                              AnimatedSwitcher(
                                duration: Motion.fast,
                                child: TextFormField(
                                  key: ValueKey(_kind),
                                  controller: _email,
                                  keyboardType: _patient ? TextInputType.text : TextInputType.emailAddress,
                                  autofillHints: _patient ? const [AutofillHints.username] : const [AutofillHints.username, AutofillHints.email],
                                  textInputAction: TextInputAction.next,
                                  decoration: InputDecoration(
                                    labelText: _patient ? 'Username' : 'Email',
                                    prefixIcon: Icon(_patient ? Icons.person_outline_rounded : Icons.alternate_email_rounded),
                                    helperText: _patient ? 'Same login as the hospital patient portal' : null,
                                  ),
                                  validator: (v) => (v == null || v.trim().isEmpty) ? (_patient ? 'Enter your username' : 'Enter your email') : null,
                                ),
                              ),
                              const SizedBox(height: 14),
                              TextFormField(
                                controller: _password,
                                obscureText: !_showPassword,
                                autofillHints: const [AutofillHints.password],
                                textInputAction: TextInputAction.done,
                                onFieldSubmitted: (_) => _busy ? null : _submit(),
                                decoration: InputDecoration(
                                  labelText: 'Password',
                                  prefixIcon: const Icon(Icons.lock_outline_rounded),
                                  suffixIcon: IconButton(
                                    tooltip: _showPassword ? 'Hide password' : 'Show password',
                                    icon: AnimatedSwitcher(
                                      duration: Motion.fast,
                                      child: Icon(_showPassword ? Icons.visibility_off_rounded : Icons.visibility_rounded, key: ValueKey(_showPassword)),
                                    ),
                                    onPressed: () => setState(() => _showPassword = !_showPassword),
                                  ),
                                ),
                                validator: (v) => (v == null || v.isEmpty) ? 'Enter your password' : null,
                              ),
                              AnimatedSize(
                                duration: Motion.medium,
                                curve: Motion.emphasized,
                                child: _editServer
                                    ? Padding(
                                        padding: const EdgeInsets.only(top: 14),
                                        child: TextFormField(
                                          controller: _server,
                                          keyboardType: TextInputType.url,
                                          decoration: const InputDecoration(labelText: 'Hospital server address', prefixIcon: Icon(Icons.dns_rounded), hintText: 'https://hms.example.com'),
                                          validator: (v) => (v == null || v.trim().isEmpty) ? 'Enter the server address' : null,
                                        ),
                                      )
                                    : const SizedBox(width: double.infinity),
                              ),
                              AnimatedSwitcher(
                                duration: Motion.medium,
                                transitionBuilder: (c, a) => FadeTransition(opacity: a, child: SizeTransition(sizeFactor: a, axisAlignment: -1, child: c)),
                                child: _error == null
                                    ? const SizedBox(width: double.infinity, key: ValueKey('ok'))
                                    : Padding(
                                        key: ValueKey(_error),
                                        padding: const EdgeInsets.only(top: 16),
                                        child: Container(
                                          padding: const EdgeInsets.all(12),
                                          decoration: BoxDecoration(color: cs.errorContainer, borderRadius: BorderRadius.circular(Radii.md)),
                                          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                            Icon(Icons.error_outline_rounded, size: 20, color: cs.onErrorContainer),
                                            const SizedBox(width: 10),
                                            Expanded(child: Text(_error!, style: TextStyle(color: cs.onErrorContainer))),
                                          ]),
                                        ),
                                      ),
                              ),
                              const SizedBox(height: 20),
                              FilledButton(
                                onPressed: _busy ? null : _submit,
                                style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                                child: AnimatedSwitcher(
                                  duration: Motion.fast,
                                  child: _busy
                                      ? const SizedBox(key: ValueKey('busy'), width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.4))
                                      : const Row(key: ValueKey('idle'), mainAxisSize: MainAxisSize.min, children: [
                                          Text('Sign in'),
                                          SizedBox(width: 8),
                                          Icon(Icons.arrow_forward_rounded, size: 20),
                                        ]),
                                ),
                              ),
                              const SizedBox(height: 6),
                              TextButton.icon(
                                onPressed: () => setState(() => _editServer = !_editServer),
                                icon: Icon(_editServer ? Icons.expand_less_rounded : Icons.dns_outlined, size: 18),
                                label: Text(_editServer ? 'Hide server address' : ApiClient.normalizeServerUrl(_server.text), overflow: TextOverflow.ellipsis),
                              ),
                            ]),
                          ),
                        ),
                      ),
                    ),
                  )),
                  const SizedBox(height: 20),
                  anim(Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                    Icon(Icons.enhanced_encryption_rounded, size: 16, color: Colors.white.withValues(alpha: 0.85)),
                    const SizedBox(width: 6),
                    Flexible(
                      child: Text(
                        'Works offline · data encrypted on this phone',
                        style: t.textTheme.bodySmall?.copyWith(color: Colors.white.withValues(alpha: 0.85)),
                      ),
                    ),
                  ])),
                ]),
              ),
            ),
          ),
        ),
      ]),
    );
  }
}

/// Slowly drifting brand-colour blobs behind the sign-in card. Still when reduce-motion is on.
class _Aurora extends StatefulWidget {
  const _Aurora();
  @override
  State<_Aurora> createState() => _AuroraState();
}

class _AuroraState extends State<_Aurora> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: const Duration(seconds: 18));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (Motion.enabled(context)) {
      _c.repeat();
    } else {
      _c.stop();
    }
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return DecoratedBox(
      decoration: BoxDecoration(gradient: cs.heroGradient),
      child: AnimatedBuilder(
        animation: _c,
        builder: (context, _) {
          final a = _c.value * 2 * math.pi;
          return Stack(children: [
            _blob(Alignment(-0.9 + 0.3 * math.sin(a), -0.8 + 0.2 * math.cos(a)), 320, Colors.white.withValues(alpha: 0.22)),
            _blob(Alignment(0.95 + 0.25 * math.cos(a), -0.1 + 0.3 * math.sin(a)), 260, cs.brandGlow.withValues(alpha: 0.45)),
            _blob(Alignment(-0.3 + 0.35 * math.cos(a + 1), 1.0 + 0.15 * math.sin(a)), 360, cs.secondary.withValues(alpha: 0.35)),
          ]);
        },
      ),
    );
  }

  Widget _blob(Alignment at, double size, Color color) => Align(
        alignment: at,
        child: Container(
          width: size,
          height: size,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            gradient: RadialGradient(colors: [color, color.withValues(alpha: 0)]),
          ),
        ),
      );
}
