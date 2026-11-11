import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../core/lan/lan_screen.dart';
import '../../core/lan/lan_service.dart';
import '../../core/security/app_lock.dart';
import '../../core/security/lock_screen.dart';
import '../../core/sync/sync_engine.dart';
import '../../core/ui/widgets.dart';
import '../doctor/data/models.dart';
import '../doctor/ui/doctor_providers.dart';
import '../doctor/ui/prescription_view_screen.dart';
import '../doctor/print/print_settings_screen.dart';
import '../doctor/specialty/specialty.dart';
import '../doctor/ui/rx_widgets.dart';

class AccountScreen extends ConsumerWidget {
  const AccountScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final session = ref.watch(sessionProvider);
    final sync = ref.watch(syncEngineProvider);
    final expired = ref.watch(tokenExpiredProvider);
    final unsynced = ref.watch(unsyncedPrescriptionsProvider).valueOrNull ?? const <Prescription>[];
    final t = Theme.of(context);
    final s = session.staff;
    final isDoctor = s.modules.contains('doctor');

    return Scaffold(
      appBar: AppBar(title: const Text('Account'), toolbarHeight: 64),
      body: ListView(
        padding: const EdgeInsets.only(bottom: 32),
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: FadeSlideIn(
              child: HeroPanel(
                child: Row(
                  children: [
                    Container(
                      padding: const EdgeInsets.all(3),
                      decoration: BoxDecoration(shape: BoxShape.circle, color: Colors.white.withValues(alpha: 0.25)),
                      child: PatientAvatar(name: s.name, radius: 30),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(s.displayName, style: t.textTheme.titleLarge?.copyWith(color: Colors.white)),
                          Text(
                            [s.designation, s.department, s.specialization].whereType<String>().where((x) => x.isNotEmpty).join(' · '),
                            style: t.textTheme.bodySmall?.copyWith(color: Colors.white.withValues(alpha: 0.85)),
                          ),
                          const SizedBox(height: 8),
                          Row(
                            children: [
                              Icon(Icons.local_hospital_rounded, size: 14, color: Colors.white.withValues(alpha: 0.85)),
                              const SizedBox(width: 6),
                              Expanded(
                                child: Text(
                                  session.hospital.name.isEmpty ? (s.email ?? '') : '${session.hospital.name} · ${s.email ?? ''}',
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: t.textTheme.labelSmall?.copyWith(color: Colors.white.withValues(alpha: 0.85)),
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),

          const SectionHeader('Sync'),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: FadeSlideIn(
              index: 1,
              child: Card(
                child: ListTile(
                  contentPadding: const EdgeInsets.fromLTRB(16, 8, 12, 8),
                  leading: IconBadge(switch (sync.phase) {
                    SyncPhase.syncing => Icons.sync_rounded,
                    SyncPhase.offline => Icons.cloud_off_rounded,
                    SyncPhase.error => Icons.sync_problem_rounded,
                    SyncPhase.needsSignIn => Icons.lock_clock_rounded,
                    SyncPhase.idle => Icons.cloud_done_rounded,
                  }, color: sync.phase == SyncPhase.idle && !expired ? t.colorScheme.primary : t.colorScheme.tertiary),
                  title: Text(
                    expired
                        ? 'Sign in again to sync'
                        : switch (sync.phase) {
                            SyncPhase.syncing => 'Syncing…',
                            SyncPhase.offline => 'Offline',
                            SyncPhase.error => 'Last sync failed',
                            SyncPhase.needsSignIn => 'Sign in again to sync',
                            SyncPhase.idle => 'Up to date',
                          },
                  ),
                  subtitle: Text(
                    [
                      'Last synced ${fmtAgo(sync.lastSyncAt)}',
                      if (sync.pending > 0) '${sync.pending} change${sync.pending == 1 ? '' : 's'} waiting',
                      if (sync.message != null && sync.phase == SyncPhase.error) sync.message!,
                    ].join('\n'),
                  ),
                  isThreeLine: sync.pending > 0 || sync.phase == SyncPhase.error,
                  trailing: expired
                      ? FilledButton(onPressed: () => showReauthDialog(context, ref), child: const Text('Sign in'))
                      : OutlinedButton(
                          onPressed: sync.phase == SyncPhase.syncing ? null : () => ref.read(syncEngineProvider.notifier).sync(),
                          child: const Text('Sync now'),
                        ),
                ),
              ),
            ),
          ),

          if (unsynced.isNotEmpty) ...[
            const SectionHeader('Prescriptions not yet on the server'),
            for (final rx in unsynced)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
                child: _UnsyncedTile(rx: rx),
              ),
          ],

          const SectionHeader('Clinic devices'),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Card(
              child: ListTile(
                contentPadding: const EdgeInsets.fromLTRB(16, 8, 12, 8),
                leading: const IconBadge(Icons.hub_rounded),
                title: const Text('Share without internet'),
                subtitle: Text(switch (ref.watch(lanProvider).mode) {
                  LanMode.hub => 'This device is the hub · ${ref.watch(lanProvider).peers.length} connected',
                  LanMode.peer => 'Connected to ${ref.watch(lanProvider).host}',
                  LanMode.off => 'Share the queue and new patients over a hotspot',
                }),
                trailing: const Icon(Icons.chevron_right_rounded),
                onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const LanScreen())),
              ),
            ),
          ),

          const SectionHeader('Security'),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Card(
              child: ListTile(
                contentPadding: const EdgeInsets.fromLTRB(16, 8, 12, 8),
                leading: const IconBadge(Icons.lock_rounded),
                title: const Text('App lock'),
                subtitle: Text(ref.watch(appLockProvider).enabled ? 'On · PIN${ref.watch(appLockProvider).biometric ? ' and fingerprint' : ''}' : 'Off · anyone with this device can open the app'),
                trailing: const Icon(Icons.chevron_right_rounded),
                onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const AppLockSettingsScreen())),
              ),
            ),
          ),

          if (isDoctor) ...[
          const SectionHeader('Printing'),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Card(
              child: ListTile(
                contentPadding: const EdgeInsets.fromLTRB(16, 8, 12, 8),
                leading: const IconBadge(Icons.print_rounded),
                title: const Text('Print settings'),
                subtitle: const Text('Paper size, half-page printing, header and languages'),
                trailing: const Icon(Icons.chevron_right_rounded),
                onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const PrintSettingsScreen())),
              ),
            ),
          ),
          const SectionHeader('Specialty tools'),
        const Padding(padding: EdgeInsets.symmetric(horizontal: 16), child: _SpecialtyToolsCard()),
          ],

        const SectionHeader('This device'),
          const Padding(
            padding: EdgeInsets.symmetric(horizontal: 16),
            child: Card(
              child: ListTile(
                contentPadding: EdgeInsets.fromLTRB(16, 8, 16, 8),
                leading: IconBadge(Icons.enhanced_encryption_rounded),
                title: Text('Patient data is encrypted on this phone'),
                subtitle: Text('Your patients and the hospital medicine list are kept here so the app works without internet.'),
              ),
            ),
          ),
          const SizedBox(height: 8),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: OutlinedButton.icon(
              style: OutlinedButton.styleFrom(foregroundColor: t.colorScheme.error, minimumSize: const Size.fromHeight(46)),
              onPressed: () => _signOut(context, ref, sync.pending),
              icon: const Icon(Icons.logout),
              label: const Text('Sign out and remove data from this phone'),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _signOut(BuildContext context, WidgetRef ref, int pending) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Sign out?'),
        content: Text(
          pending > 0
              ? '$pending change${pending == 1 ? ' has' : 's have'} not reached the server yet and will be lost. Connect to the internet and sync first if you need them.'
              : 'All patient data will be removed from this phone. It stays safe on the hospital server.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(
            style: pending > 0 ? FilledButton.styleFrom(backgroundColor: Theme.of(ctx).colorScheme.error) : null,
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(pending > 0 ? 'Sign out and lose changes' : 'Sign out'),
          ),
        ],
      ),
    );
    if (ok == true) await ref.read(authControllerProvider.notifier).signOut();
  }
}

class _UnsyncedTile extends ConsumerWidget {
  const _UnsyncedTile({required this.rx});
  final Prescription rx;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final patient = ref.watch(patientDetailProvider(rx.patientId)).valueOrNull?.patient;
    return Card(
      child: ListTile(
        title: Text(patient?.name ?? 'Patient #${rx.patientId}'),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('${fmtDate(rx.date)} · ${rx.items.length} medicine${rx.items.length == 1 ? '' : 's'}'),
            if (rx.error != null) Text(rx.error!, style: TextStyle(color: Theme.of(context).colorScheme.error, fontSize: 12)),
          ],
        ),
        trailing: RxStatusPill(rx.status),
        onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => PrescriptionViewScreen(localId: rx.localId))),
      ),
    );
  }
}

class _SpecialtyToolsCard extends ConsumerWidget {
  const _SpecialtyToolsCard();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = Theme.of(context);
    final staff = ref.watch(staffProvider);
    final settings = ref.watch(specialtySettingsProvider).valueOrNull;
    if (settings == null) return const SizedBox(height: 80);
    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 4),
            child: Text(
              staff.specialties.isEmpty
                  ? 'No specialty is set for you on the hospital system, so all tools are available. Turn off the ones you do not need.'
                  : 'Your specialty: ${staff.specialties.join(', ')}${settings.overridden ? ' (customised below)' : ''}',
              style: t.textTheme.bodySmall?.copyWith(color: t.colorScheme.onSurfaceVariant),
            ),
          ),
          for (final k in specialtyToolkits)
            SwitchListTile(
              secondary: IconBadge(k.icon, size: 34),
              title: Text(k.name),
              subtitle: Text(k.alwaysOn ? '${k.description} · always on' : k.description),
              value: settings.enabled.contains(k.id),
              onChanged: k.alwaysOn
                  ? null
                  : (v) {
                      final next = {...settings.enabled};
                      v ? next.add(k.id) : next.remove(k.id);
                      setSpecialtyTools(ref, next);
                    },
            ),
          if (settings.overridden)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 8),
              child: TextButton.icon(onPressed: () => setSpecialtyTools(ref, null), icon: const Icon(Icons.restart_alt_rounded), label: const Text('Match my specialty again')),
            ),
        ]),
      ),
    );
  }
}
