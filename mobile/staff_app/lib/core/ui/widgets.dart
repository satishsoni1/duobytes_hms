import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../api/api_client.dart';
import '../auth/auth_controller.dart';
import '../providers.dart';
import '../sync/sync_engine.dart';
import 'motion.dart';
import 'theme.dart';

export 'motion.dart';

final _date = DateFormat('d MMM yyyy');
final _dateShort = DateFormat('d MMM');
final _time = DateFormat('h:mm a');

String fmtDate(DateTime? d) => d == null ? '—' : _date.format(d);
String fmtDateShort(DateTime? d) {
  if (d == null) return '—';
  return d.year == DateTime.now().year ? _dateShort.format(d) : _date.format(d);
}

String fmtTime(DateTime? d) => d == null ? '' : _time.format(d);
String fmtDateTime(DateTime? d) {
  if (d == null) return '—';
  final hasTime = d.hour != 0 || d.minute != 0;
  return hasTime ? '${fmtDateShort(d)}, ${fmtTime(d)}' : fmtDateShort(d);
}

String fmtAgo(DateTime? d) {
  if (d == null) return 'never';
  final diff = DateTime.now().difference(d);
  if (diff.inSeconds < 60) return 'just now';
  if (diff.inMinutes < 60) return '${diff.inMinutes} min ago';
  if (diff.inHours < 24) return '${diff.inHours} h ago';
  return fmtDateTime(d);
}

String greeting([DateTime? now]) {
  final h = (now ?? DateTime.now()).hour;
  if (h < 12) return 'Good morning';
  if (h < 17) return 'Good afternoon';
  return 'Good evening';
}

/// Sync state card: offline, uploading, needs sign-in, conflicts. Animates in and out.
class SyncBanner extends ConsumerWidget {
  const SyncBanner({super.key, this.onOpenDetails, this.margin = const EdgeInsets.fromLTRB(16, 12, 16, 0)});
  final VoidCallback? onOpenDetails;
  final EdgeInsets margin;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = ref.watch(syncEngineProvider);
    final expired = ref.watch(tokenExpiredProvider);
    final cs = Theme.of(context).colorScheme;

    (IconData, String, Color, Color, VoidCallback?)? spec;
    if (expired) {
      spec = (Icons.lock_clock_rounded, 'Signed out of the server. Your data is safe here — tap to sign in and sync.', cs.errorContainer, cs.onErrorContainer,
          () => showReauthDialog(context, ref));
    } else if (s.needsAttention) {
      final parts = [if (s.conflicts > 0) '${s.conflicts} conflict${s.conflicts == 1 ? '' : 's'}', if (s.rejected > 0) '${s.rejected} not accepted'];
      spec = (Icons.error_rounded, 'Prescriptions need your review: ${parts.join(', ')}', cs.errorContainer, cs.onErrorContainer, onOpenDetails);
    } else if (s.phase == SyncPhase.offline) {
      spec = (Icons.cloud_off_rounded,
          s.pending > 0 ? 'Offline · ${s.pending} change${s.pending == 1 ? '' : 's'} will upload when connected' : 'Offline · showing data from ${fmtAgo(s.lastSyncAt)}',
          cs.secondaryContainer, cs.onSecondaryContainer, onOpenDetails);
    } else if (s.phase == SyncPhase.error) {
      spec = (Icons.sync_problem_rounded, s.message ?? 'Sync failed', cs.tertiaryContainer, cs.onTertiaryContainer, onOpenDetails);
    } else if (s.phase == SyncPhase.syncing && !s.hasSyncedOnce) {
      spec = (Icons.downloading_rounded, 'Downloading your patients for offline use…', cs.primaryContainer, cs.onPrimaryContainer, null);
    } else if (s.pending > 0) {
      spec = (Icons.cloud_upload_rounded, '${s.pending} change${s.pending == 1 ? '' : 's'} waiting to upload', cs.secondaryContainer, cs.onSecondaryContainer, onOpenDetails);
    }

    final child = spec == null
        ? const SizedBox(key: ValueKey('none'), width: double.infinity)
        : Padding(
            key: ValueKey(spec.$2),
            padding: margin,
            child: Material(
              color: spec.$3,
              borderRadius: BorderRadius.circular(Radii.md),
              child: InkWell(
                borderRadius: BorderRadius.circular(Radii.md),
                onTap: spec.$5,
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                  child: Row(children: [
                    Icon(spec.$1, size: 20, color: spec.$4),
                    const SizedBox(width: 12),
                    Expanded(child: Text(spec.$2, style: TextStyle(color: spec.$4, fontSize: 13, fontWeight: FontWeight.w500))),
                    if (s.phase == SyncPhase.syncing)
                      SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: spec.$4))
                    else if (spec.$5 != null)
                      Icon(Icons.chevron_right_rounded, size: 20, color: spec.$4),
                  ]),
                ),
              ),
            ),
          );

    return AnimatedSize(
      duration: Motion.medium,
      curve: Motion.emphasized,
      alignment: Alignment.topCenter,
      child: AnimatedSwitcher(
        duration: Motion.medium,
        switchInCurve: Motion.emphasized,
        transitionBuilder: (c, a) => FadeTransition(opacity: a, child: SizeTransition(sizeFactor: a, axisAlignment: -1, child: c)),
        child: child,
      ),
    );
  }
}

Future<void> showReauthDialog(BuildContext context, WidgetRef ref) async {
  final auth = ref.read(authControllerProvider);
  if (auth is! SignedInBase) return;
  final login = switch (auth) {
    SignedIn() => auth.session.staff.email ?? '',
    PatientSignedIn() => auth.session.username ?? '',
  };
  final pw = TextEditingController();
  String? error;
  var busy = false;
  await showDialog<void>(
    context: context,
    builder: (ctx) => StatefulBuilder(builder: (ctx, setState) {
      Future<void> submit() async {
        setState(() {
          busy = true;
          error = null;
        });
        try {
          final c = ref.read(authControllerProvider.notifier);
          if (auth is PatientSignedIn) {
            await c.signInPatient(serverUrl: auth.serverUrl, username: login, password: pw.text);
          } else {
            await c.signIn(serverUrl: auth.serverUrl, email: login, password: pw.text);
          }
          if (ctx.mounted) Navigator.pop(ctx);
          if (auth is SignedIn) ref.read(syncEngineProvider.notifier).sync();
        } on ApiException catch (e) {
          setState(() {
            busy = false;
            error = e.message;
          });
        }
      }

      return AlertDialog(
        icon: const Icon(Icons.lock_reset_rounded),
        title: const Text('Sign in to sync'),
        content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(login),
          const SizedBox(height: 12),
          TextField(
            controller: pw,
            obscureText: true,
            autofocus: true,
            decoration: InputDecoration(labelText: 'Password', errorText: error),
            onSubmitted: (_) => busy ? null : submit(),
          ),
        ]),
        actions: [
          TextButton(onPressed: busy ? null : () => Navigator.pop(ctx), child: const Text('Later')),
          FilledButton(onPressed: busy ? null : submit, child: busy ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : const Text('Sign in')),
        ],
      );
    }),
  );
}

class EmptyState extends StatelessWidget {
  const EmptyState({super.key, required this.icon, required this.title, this.message, this.action});
  final IconData icon;
  final String title;
  final String? message;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: FadeSlideIn(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Container(
              width: 84,
              height: 84,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: LinearGradient(colors: [cs.primaryContainer, cs.secondaryContainer], begin: Alignment.topLeft, end: Alignment.bottomRight),
              ),
              child: Icon(icon, size: 38, color: cs.onPrimaryContainer),
            ),
            const SizedBox(height: 18),
            Text(title, style: t.textTheme.titleMedium, textAlign: TextAlign.center),
            if (message != null) ...[
              const SizedBox(height: 6),
              Text(message!, style: t.textTheme.bodyMedium?.copyWith(color: cs.onSurfaceVariant), textAlign: TextAlign.center),
            ],
            if (action != null) ...[const SizedBox(height: 18), action!],
          ]),
        ),
      ),
    );
  }
}

class SectionHeader extends StatelessWidget {
  const SectionHeader(this.title, {super.key, this.trailing, this.count, this.padding = const EdgeInsets.fromLTRB(20, 22, 16, 10)});
  final String title;
  final int? count;
  final Widget? trailing;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    return Padding(
      padding: padding,
      child: Row(children: [
        Expanded(
          child: Row(children: [
            Flexible(
              child: Text(title,
                  maxLines: 1, overflow: TextOverflow.ellipsis, style: t.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700, letterSpacing: -0.1)),
            ),
            if (count != null) ...[
              const SizedBox(width: 8),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                decoration: BoxDecoration(color: t.colorScheme.primaryContainer, borderRadius: BorderRadius.circular(20)),
                child: Text('$count', style: t.textTheme.labelSmall?.copyWith(color: t.colorScheme.onPrimaryContainer, fontWeight: FontWeight.w700)),
              ),
            ],
          ]),
        ),
        if (trailing != null) ...[const SizedBox(width: 8), trailing!],
      ]),
    );
  }
}

/// Red card listing allergies. Shown on every patient and prescription screen.
class AllergyBanner extends StatelessWidget {
  const AllergyBanner({super.key, required this.allergies, this.dense = false});
  final List<String> allergies;
  final bool dense;

  @override
  Widget build(BuildContext context) {
    if (allergies.isEmpty) return const SizedBox.shrink();
    final cs = Theme.of(context).colorScheme;
    return Container(
      width: double.infinity,
      padding: EdgeInsets.symmetric(horizontal: 12, vertical: dense ? 8 : 12),
      decoration: BoxDecoration(
        color: cs.allergyBg,
        borderRadius: BorderRadius.circular(Radii.md),
        border: Border.all(color: cs.error.withValues(alpha: 0.25)),
      ),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Container(
          padding: const EdgeInsets.all(4),
          decoration: BoxDecoration(color: cs.error, shape: BoxShape.circle),
          child: Icon(Icons.priority_high_rounded, size: 14, color: cs.onError),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Padding(
            padding: const EdgeInsets.only(top: 1),
            child: Text.rich(TextSpan(children: [
              TextSpan(text: 'Allergies  ', style: TextStyle(fontWeight: FontWeight.w800, color: cs.allergyFg)),
              TextSpan(text: allergies.join(', '), style: TextStyle(color: cs.allergyFg, fontWeight: FontWeight.w500)),
            ])),
          ),
        ),
      ]),
    );
  }
}

class StatusPill extends StatelessWidget {
  const StatusPill(this.label, {super.key, required this.bg, required this.fg, this.icon});
  final String label;
  final Color bg;
  final Color fg;
  final IconData? icon;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(20)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          if (icon != null) ...[Icon(icon, size: 13, color: fg), const SizedBox(width: 4)],
          Flexible(child: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: fg, fontSize: 11.5, fontWeight: FontWeight.w700))),
        ]),
      );
}

/// Initials on a soft gradient whose hue is derived from the name, so patients are easy to tell apart.
class PatientAvatar extends StatelessWidget {
  const PatientAvatar({super.key, required this.name, this.radius = 22, this.heroTag});
  final String name;
  final double radius;
  final Object? heroTag;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
    final initials = parts.isEmpty ? '?' : (parts.first[0] + (parts.length > 1 ? parts.last[0] : '')).toUpperCase();
    final hue = (name.codeUnits.fold<int>(7, (h, c) => (h * 31 + c) & 0x7fffffff) % 360).toDouble();
    final c1 = HSLColor.fromAHSL(1, hue, 0.55, dark ? 0.32 : 0.86).toColor();
    final c2 = HSLColor.fromAHSL(1, (hue + 40) % 360, 0.6, dark ? 0.26 : 0.78).toColor();
    final fg = HSLColor.fromAHSL(1, hue, 0.6, dark ? 0.86 : 0.26).toColor();
    final avatar = Container(
      width: radius * 2,
      height: radius * 2,
      alignment: Alignment.center,
      decoration: BoxDecoration(shape: BoxShape.circle, gradient: LinearGradient(colors: [c1, c2], begin: Alignment.topLeft, end: Alignment.bottomRight)),
      child: Text(initials, style: TextStyle(color: fg, fontWeight: FontWeight.w700, fontSize: radius * 0.72, letterSpacing: 0.5)),
    );
    return heroTag == null ? avatar : Hero(tag: heroTag!, child: avatar);
  }
}

/// Brand gradient panel with soft decorative circles; used at the top of main screens.
class HeroPanel extends StatelessWidget {
  const HeroPanel({super.key, required this.child, this.padding = const EdgeInsets.fromLTRB(20, 16, 20, 20), this.radius = Radii.xl});
  final Widget child;
  final EdgeInsets padding;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return ClipRRect(
      borderRadius: BorderRadius.circular(radius),
      child: DecoratedBox(
        decoration: BoxDecoration(gradient: cs.heroGradient),
        child: Stack(children: [
          Positioned(right: -40, top: -50, child: _Bubble(size: 170, alpha: 0.10)),
          Positioned(right: 60, bottom: -70, child: _Bubble(size: 140, alpha: 0.07)),
          Positioned(left: -30, bottom: -40, child: _Bubble(size: 100, alpha: 0.06)),
          Padding(padding: padding, child: DefaultTextStyle.merge(style: const TextStyle(color: Colors.white), child: child)),
        ]),
      ),
    );
  }
}

class _Bubble extends StatelessWidget {
  const _Bubble({required this.size, required this.alpha});
  final double size;
  final double alpha;
  @override
  Widget build(BuildContext context) =>
      Container(width: size, height: size, decoration: BoxDecoration(shape: BoxShape.circle, color: Colors.white.withValues(alpha: alpha)));
}

/// Frosted number tile for use on a [HeroPanel].
class StatTile extends StatelessWidget {
  const StatTile({super.key, required this.value, required this.label, required this.icon, this.highlight = false});
  final int value;
  final String label;
  final IconData icon;
  final bool highlight;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 10),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: highlight ? 0.24 : 0.14),
        borderRadius: BorderRadius.circular(Radii.md),
        border: Border.all(color: Colors.white.withValues(alpha: 0.18)),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(icon, size: 18, color: Colors.white.withValues(alpha: 0.9)),
        const SizedBox(height: 8),
        AnimatedCount(value, style: t.textTheme.headlineSmall?.copyWith(color: Colors.white, height: 1)),
        const SizedBox(height: 2),
        Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: t.textTheme.labelSmall?.copyWith(color: Colors.white.withValues(alpha: 0.85))),
      ]),
    );
  }
}

/// Tinted round icon used as a leading badge on cards.
class IconBadge extends StatelessWidget {
  const IconBadge(this.icon, {super.key, this.color, this.size = 36});
  final IconData icon;
  final Color? color;
  final double size;

  @override
  Widget build(BuildContext context) {
    final c = color ?? Theme.of(context).colorScheme.primary;
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(color: c.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(size * 0.34)),
      child: Icon(icon, size: size * 0.52, color: c),
    );
  }
}

/// Small pulsing dot (e.g. "live" / syncing). Static when reduce-motion is on.
class PulseDot extends StatefulWidget {
  const PulseDot({super.key, required this.color, this.active = true});
  final Color color;
  final bool active;
  @override
  State<PulseDot> createState() => _PulseDotState();
}

class _PulseDotState extends State<PulseDot> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1400));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _sync();
  }

  @override
  void didUpdateWidget(PulseDot old) {
    super.didUpdateWidget(old);
    _sync();
  }

  void _sync() {
    if (widget.active && Motion.enabled(context)) {
      if (!_c.isAnimating) _c.repeat();
    } else {
      _c.stop();
      _c.value = 0;
    }
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => SizedBox(
        width: 14,
        height: 14,
        child: AnimatedBuilder(
          animation: _c,
          builder: (_, _) => Stack(alignment: Alignment.center, children: [
            Container(
              width: 6 + 8 * _c.value,
              height: 6 + 8 * _c.value,
              decoration: BoxDecoration(shape: BoxShape.circle, color: widget.color.withValues(alpha: 0.45 * (1 - _c.value))),
            ),
            Container(width: 7, height: 7, decoration: BoxDecoration(shape: BoxShape.circle, color: widget.color)),
          ]),
        ),
      );
}

void showSnack(BuildContext context, String message, {bool error = false, IconData? icon}) {
  final cs = Theme.of(context).colorScheme;
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(
      content: Row(children: [
        Icon(icon ?? (error ? Icons.error_outline_rounded : Icons.check_circle_rounded), color: error ? cs.onError : cs.inversePrimary, size: 20),
        const SizedBox(width: 12),
        Expanded(child: Text(message)),
      ]),
      backgroundColor: error ? cs.error : null,
    ));
}
