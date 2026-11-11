import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../core/auth/staff.dart';
import '../../../core/providers.dart';
import '../../../core/sync/sync_engine.dart';
import '../../../core/ui/widgets.dart';
import '../data/doctor_repository.dart';
import '../data/models.dart';
import '../data/clinical.dart';
import '../data/queue.dart';
import '../specialty/specialty.dart';
import 'queue_row.dart';
import '../specialty/followups.dart';
import 'doctor_providers.dart';
import 'patient_screen.dart';
import 'whatsapp_actions.dart';
import 'prescription_editor_screen.dart';

enum _QueueFilter { all, waiting, withDoctor, seen }

class TodayScreen extends ConsumerStatefulWidget {
  const TodayScreen({super.key, required this.onOpenSyncDetails});
  final VoidCallback onOpenSyncDetails;

  @override
  ConsumerState<TodayScreen> createState() => _TodayScreenState();
}

class _TodayScreenState extends ConsumerState<TodayScreen> {
  _QueueFilter _filter = _QueueFilter.all;

  @override
  Widget build(BuildContext context) {
    final today = ref.watch(todayProvider);
    final session = ref.watch(sessionProvider);
    final sync = ref.watch(syncEngineProvider);
    final data = today.valueOrNull;

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          edgeOffset: 8,
          onRefresh: () => ref.read(syncEngineProvider.notifier).sync(),
          child: CustomScrollView(
            physics: const AlwaysScrollableScrollPhysics(parent: BouncingScrollPhysics()),
            slivers: [
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
                  child: FadeSlideIn(
                    child: _Hero(
                      staff: session.staff,
                      hospital: session.hospital.name,
                      sync: sync,
                      data: data,
                      onSync: () => ref.read(syncEngineProvider.notifier).sync(),
                      onCallNext: data == null ? null : () => callNextPatient(context, ref, data),
                    ),
                  ),
                ),
              ),
              SliverToBoxAdapter(child: SyncBanner(onOpenDetails: widget.onOpenSyncDetails)),
              ...today.when(
                loading: () => [const SliverFillRemaining(hasScrollBody: true, child: SkeletonList(count: 4))],
                error: (e, _) => [SliverFillRemaining(hasScrollBody: false, child: EmptyState(icon: Icons.error_outline_rounded, title: 'Could not load', message: '$e'))],
                data: (d) => d.isEmpty
                    ? [
                        SliverFillRemaining(
                          hasScrollBody: false,
                          child: EmptyState(
                            icon: Icons.event_available_rounded,
                            title: sync.hasSyncedOnce ? 'No patients for today' : 'Getting your patients…',
                            message: sync.hasSyncedOnce
                                ? 'OPD visits booked with you today and your admitted patients appear here.'
                                : 'The first download needs internet. After that the app works offline.',
                          ),
                        ),
                      ]
                    : _sections(context, d),
              ),
              const SliverToBoxAdapter(child: SizedBox(height: 24)),
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> _sections(BuildContext context, TodayData d) {
    var i = 0;
    Widget item(Widget w) => Padding(padding: const EdgeInsets.fromLTRB(16, 0, 16, 10), child: FadeSlideIn(index: i++, child: w));
    final q = d.queue;
    int n(QueueState s) => q.where((e) => e.state == s).length;
    final shown = q.where((e) => switch (_filter) {
          _QueueFilter.all => true,
          _QueueFilter.waiting => e.state == QueueState.waiting || e.state == QueueState.skipped,
          _QueueFilter.withDoctor => e.state == QueueState.withDoctor,
          _QueueFilter.seen => e.state == QueueState.seen,
        });
    return [
      if (q.isNotEmpty) ...[
        SliverToBoxAdapter(child: SectionHeader('OPD queue', count: q.length)),
        SliverToBoxAdapter(
          child: SizedBox(
            height: 44,
            child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.fromLTRB(16, 0, 16, 6), children: [
              for (final (f, label) in [
                (_QueueFilter.all, 'All ${q.length}'),
                (_QueueFilter.waiting, 'Waiting ${n(QueueState.waiting) + n(QueueState.skipped)}'),
                (_QueueFilter.withDoctor, 'With doctor ${n(QueueState.withDoctor)}'),
                (_QueueFilter.seen, 'Seen ${n(QueueState.seen)}'),
              ])
                Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: FilterChip(
                    showCheckmark: false,
                    label: Text(label),
                    selected: _filter == f,
                    onSelected: (_) {
                      HapticFeedback.selectionClick();
                      setState(() => _filter = f);
                    },
                  ),
                ),
            ]),
          ),
        ),
        if (shown.isEmpty)
          const SliverToBoxAdapter(child: Padding(padding: EdgeInsets.all(20), child: Center(child: Text('Nobody here right now.'))))
        else
          SliverList.list(children: [for (final e in shown) item(QueueRow(entry: e, statusRecord: d.queueStatus[e.encounter.refId]))]),
      ],
      if (d.ipd.isNotEmpty) ...[
        SliverToBoxAdapter(child: SectionHeader('Admitted', count: d.ipd.length)),
        SliverList.list(children: [for (final e in d.ipd) item(_EncounterCard(encounter: e, patient: d.patients[e.patientId], hasRx: d.hasRx(e)))]),
      ],
      if (d.followUps.isNotEmpty) ...[
        SliverToBoxAdapter(child: SectionHeader('Follow-ups due', count: d.followUps.length)),
        SliverList.list(children: [for (final f in d.followUps) item(_FollowUpCard(record: f, patient: d.patients[f.patientId]))]),
      ],
      if (d.appointments.isNotEmpty) ...[
        SliverToBoxAdapter(child: SectionHeader('Not checked in yet', count: d.appointments.length)),
        SliverList.list(children: [for (final a in d.appointments) item(_AppointmentCard(appointment: a, patient: d.patients[a.patientId]))]),
      ],
    ];
  }
}

/// Move the next waiting patient to "with doctor" and open them.
Future<void> callNextPatient(BuildContext context, WidgetRef ref, TodayData d) async {
  final next = d.queue.where((e) => e.state == QueueState.waiting).firstOrNull;
  if (next == null) {
    showSnack(context, 'No one is waiting.');
    return;
  }
  HapticFeedback.mediumImpact();
  await saveClinicalRecord(ref, queueRecord(next.encounter, QueueState.withDoctor, existing: d.queueStatus[next.encounter.refId]));
  if (!context.mounted) return;
  showSnack(context, 'Calling ${next.token != null ? 'token ${next.token} · ' : ''}${next.patient?.name ?? 'next patient'}', icon: Icons.campaign_rounded);
  if (next.patient != null) {
    await Navigator.push(context, MaterialPageRoute(builder: (_) => PatientScreen(patientId: next.patient!.id)));
  }
}

class _Hero extends StatelessWidget {
  const _Hero({required this.staff, required this.hospital, required this.sync, required this.data, required this.onSync, this.onCallNext});
  final Staff staff;
  final String hospital;
  final SyncStatus sync;
  final TodayData? data;
  final VoidCallback onSync;
  final VoidCallback? onCallNext;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final d = data;
    final toPrescribe = d == null ? 0 : [...d.opd, ...d.ipd].where((e) => !d.hasRx(e)).length;
    final waiting = d?.queue.where((e) => e.state == QueueState.waiting).length ?? 0;
    final seen = d?.queue.where((e) => e.state == QueueState.seen).length ?? 0;
    final next = d?.queue.where((e) => e.state == QueueState.waiting).firstOrNull;
    final nextLabel = next == null ? 'Call next' : 'Call next · ${next.token != null ? 'Token ${next.token}' : (next.patient?.name ?? '')}';
    final online = sync.phase == SyncPhase.idle || sync.phase == SyncPhase.syncing;
    final syncLabel = switch (sync.phase) {
      SyncPhase.syncing => 'Syncing',
      SyncPhase.offline => 'Offline',
      SyncPhase.needsSignIn => 'Sign in',
      SyncPhase.error => 'Sync issue',
      SyncPhase.idle => sync.lastSyncAt == null ? 'Not synced' : 'Synced ${fmtAgo(sync.lastSyncAt)}',
    };

    return HeroPanel(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(
            child: Text(DateFormat('EEEE, d MMMM').format(DateTime.now()).toUpperCase(),
                style: t.textTheme.labelSmall?.copyWith(color: Colors.white.withValues(alpha: 0.8), letterSpacing: 1.1, fontWeight: FontWeight.w600)),
          ),
          Material(
            color: Colors.white.withValues(alpha: 0.16),
            shape: const StadiumBorder(),
            child: InkWell(
              customBorder: const StadiumBorder(),
              onTap: onSync,
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  PulseDot(color: online ? const Color(0xFF7CF0B5) : const Color(0xFFFFC97A), active: sync.phase == SyncPhase.syncing),
                  const SizedBox(width: 6),
                  Text(syncLabel, style: t.textTheme.labelSmall?.copyWith(color: Colors.white, fontWeight: FontWeight.w600)),
                ]),
              ),
            ),
          ),
        ]),
        const SizedBox(height: 14),
        Text('${greeting()},', style: t.textTheme.titleMedium?.copyWith(color: Colors.white.withValues(alpha: 0.85), fontWeight: FontWeight.w500)),
        Text(staff.displayName, style: t.textTheme.headlineSmall?.copyWith(color: Colors.white), maxLines: 1, overflow: TextOverflow.ellipsis),
        if (hospital.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 2),
            child: Text(hospital, style: t.textTheme.bodySmall?.copyWith(color: Colors.white.withValues(alpha: 0.75))),
          ),
        const SizedBox(height: 18),
        Row(children: [
          Expanded(child: StatTile(value: waiting, label: 'Waiting', icon: Icons.hourglass_top_rounded, highlight: waiting > 0)),
          const SizedBox(width: 10),
          Expanded(child: StatTile(value: seen, label: 'Seen', icon: Icons.how_to_reg_rounded)),
          const SizedBox(width: 10),
          Expanded(child: StatTile(value: toPrescribe, label: 'Pending Rx', icon: Icons.edit_note_rounded, highlight: toPrescribe > 0)),
        ]),
        if (waiting > 0 && onCallNext != null) ...[
          const SizedBox(height: 14),
          FilledButton.icon(
            style: FilledButton.styleFrom(
              backgroundColor: Colors.white,
              foregroundColor: Theme.of(context).colorScheme.primary,
              minimumSize: const Size.fromHeight(46),
            ),
            onPressed: onCallNext,
            icon: const Icon(Icons.campaign_rounded),
            label: Text(nextLabel),
          ),
        ],
      ]),
    );
  }
}

class _EncounterCard extends StatelessWidget {
  const _EncounterCard({required this.encounter, required this.patient, required this.hasRx});
  final Encounter encounter;
  final Patient? patient;
  final bool hasRx;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final p = patient;
    final allergies = p == null ? const <String>[] : splitAllergies([...p.allergies, ...encounter.allergies]);
    final time = encounter.isIpd
        ? (encounter.bed?.isNotEmpty == true ? encounter.bed : 'IPD')
        : (encounter.date != null && (encounter.date!.hour != 0 || encounter.date!.minute != 0) ? fmtTime(encounter.date) : null);

    return Card(
      child: Pressable(
        haptic: true,
        onTap: p == null ? null : () => Navigator.push(context, MaterialPageRoute(builder: (_) => PatientScreen(patientId: p.id, heroTag: 'today-${encounter.key}'))),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 14, 12, 14),
          child: Row(children: [
            PatientAvatar(name: p?.name ?? '?', radius: 23, heroTag: p == null ? null : 'today-${encounter.key}'),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Flexible(child: Text(p?.name ?? 'Patient #${encounter.patientId}', style: t.textTheme.titleSmall, overflow: TextOverflow.ellipsis)),
                  if (allergies.isNotEmpty) ...[
                    const SizedBox(width: 6),
                    Tooltip(message: 'Allergies: ${allergies.join(', ')}', child: Icon(Icons.warning_amber_rounded, size: 16, color: cs.error)),
                  ],
                ]),
                const SizedBox(height: 2),
                Text([?time, if (p != null && p.subtitle.isNotEmpty) p.subtitle].join(' · '),
                    style: t.textTheme.bodySmall?.copyWith(color: cs.onSurfaceVariant), maxLines: 1, overflow: TextOverflow.ellipsis),
                if (encounter.symptoms != null) ...[
                  const SizedBox(height: 4),
                  Text(encounter.symptoms!, maxLines: 1, overflow: TextOverflow.ellipsis, style: t.textTheme.bodySmall),
                ],
              ]),
            ),
            const SizedBox(width: 8),
            AnimatedSwitcher(
              duration: Motion.medium,
              transitionBuilder: (c, a) => ScaleTransition(scale: a, child: FadeTransition(opacity: a, child: c)),
              child: hasRx
                  ? StatusPill('Rx done', key: const ValueKey('done'), bg: cs.tertiaryContainer, fg: cs.onTertiaryContainer, icon: Icons.check_circle_rounded)
                  : (p == null
                      ? const SizedBox.shrink()
                      : FilledButton.tonal(
                          key: const ValueKey('write'),
                          style: FilledButton.styleFrom(minimumSize: const Size(0, 38), padding: const EdgeInsets.symmetric(horizontal: 14), visualDensity: VisualDensity.compact),
                          onPressed: () {
                            HapticFeedback.lightImpact();
                            Navigator.push(context, MaterialPageRoute(builder: (_) => PrescriptionEditorScreen.create(patientId: p.id, encounter: encounter)));
                          },
                          child: const Text('Write Rx'),
                        )),
            ),
          ]),
        ),
      ),
    );
  }
}

class _AppointmentCard extends StatelessWidget {
  const _AppointmentCard({required this.appointment, required this.patient});
  final Appointment appointment;
  final Patient? patient;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    return Card(
      child: Pressable(
        onTap: patient == null ? null : () => Navigator.push(context, MaterialPageRoute(builder: (_) => PatientScreen(patientId: patient!.id))),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            PatientAvatar(name: patient?.name ?? '?', radius: 23),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(patient?.name ?? 'Patient #${appointment.patientId}', style: t.textTheme.titleSmall),
                const SizedBox(height: 2),
                Text([fmtTime(appointment.date), appointment.priority, appointment.message].whereType<String>().where((s) => s.isNotEmpty).join(' · '),
                    maxLines: 2, overflow: TextOverflow.ellipsis, style: t.textTheme.bodySmall?.copyWith(color: cs.onSurfaceVariant)),
              ]),
            ),
            StatusPill(appointment.status, bg: cs.surfaceContainerHighest, fg: cs.onSurfaceVariant, icon: Icons.schedule_rounded),
          ]),
        ),
      ),
    );
  }
}

class _FollowUpCard extends ConsumerWidget {
  const _FollowUpCard({required this.record, required this.patient});
  final ClinicalRecord record;
  final Patient? patient;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final (label, urgent) = followUpWhen(record.eventDate);
    final overdue = urgent && label != 'Due today';
    return Card(
      child: Pressable(
        onTap: patient == null ? null : () => Navigator.push(context, MaterialPageRoute(builder: (_) => PatientScreen(patientId: patient!.id))),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            PatientAvatar(name: patient?.name ?? '?', radius: 23),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(patient?.name ?? 'Patient #${record.patientId}', style: t.textTheme.titleSmall),
                const SizedBox(height: 2),
                Text(
                  [record.str('reason') ?? 'Review', if (record.str('reminded_on') != null) 'reminded ${fmtDateShort(record.date('reminded_on'))}'].join(' · '),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: t.textTheme.bodySmall?.copyWith(color: cs.onSurfaceVariant),
                ),
              ]),
            ),
            IconButton(
              tooltip: 'Send reminder on WhatsApp',
              onPressed: () => sendFollowUpReminder(context, ref, record, patient),
              icon: const WhatsAppIcon(),
            ),
            Flexible(
              child: StatusPill(label,
                  bg: overdue ? cs.errorContainer : cs.secondaryContainer, fg: overdue ? cs.onErrorContainer : cs.onSecondaryContainer, icon: Icons.event_repeat_rounded),
            ),
          ]),
        ),
      ),
    );
  }
}
