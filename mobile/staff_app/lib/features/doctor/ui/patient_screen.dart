import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../opd/registration_screen.dart';
import '../../staff/staff_actions.dart';

import '../../../core/providers.dart';
import '../../../core/ui/theme.dart';
import '../../../core/ui/widgets.dart';
import '../data/models.dart';
import '../specialty/care_tab.dart';
import 'billing_ui.dart';
import 'doctor_providers.dart';
import 'prescription_editor_screen.dart';
import 'prescription_view_screen.dart';
import 'reports_screen.dart' show openDoctorReportFile;
import 'rx_widgets.dart';

class PatientScreen extends ConsumerWidget {
  const PatientScreen({super.key, required this.patientId, this.heroTag});
  final int patientId;

  /// Matches the avatar on the screen we came from, for the shared-element transition.
  final Object? heroTag;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(patientDetailProvider(patientId));
    return detail.when(
      loading: () => Scaffold(appBar: AppBar(), body: const SkeletonList(count: 5)),
      error: (e, _) => Scaffold(
        appBar: AppBar(),
        body: EmptyState(icon: Icons.error_outline_rounded, title: 'Could not load patient', message: '$e'),
      ),
      data: (d) => d == null
          ? Scaffold(
              appBar: AppBar(),
              body: const EmptyState(icon: Icons.person_off_rounded, title: 'Patient not on this phone', message: 'They may no longer be assigned to you.'),
            )
          : _PatientView(d: d, heroTag: heroTag),
    );
  }
}

class _PatientView extends ConsumerWidget {
  const _PatientView({required this.d, this.heroTag});
  final PatientDetail d;
  final Object? heroTag;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final p = d.patient;
    final allergies = d.allergies;
    final topPad = MediaQuery.paddingOf(context).top;
    // toolbar 56 + header content ~126 + gaps + pinned tab strip 56. Excludes the status bar inset.
    final expanded = 268.0 + (allergies.isEmpty ? 0 : 64);

    return DefaultTabController(
      length: 7,
      child: Scaffold(
        floatingActionButton: FadeSlideIn(
          offset: const Offset(0, 60),
          child: ref.watch(staffProvider).modules.contains('doctor')
              ? FloatingActionButton.extended(
                  onPressed: () {
                    HapticFeedback.lightImpact();
                    startPrescription(context, ref, d);
                  },
                  icon: const Icon(Icons.edit_note_rounded),
                  label: const Text('New prescription'),
                )
              : FloatingActionButton.extended(
                  onPressed: () => showStaffPatientActions(context, ref, d),
                  icon: const Icon(Icons.monitor_heart_rounded),
                  label: const Text('Vitals & notes'),
                ),
        ),
        body: NestedScrollView(
          headerSliverBuilder: (context, _) => [
            SliverAppBar(
              pinned: true,
              stretch: true,
              expandedHeight: expanded,
              backgroundColor: Theme.of(context).colorScheme.primary,
              foregroundColor: Colors.white,
              systemOverlayStyle: SystemUiOverlayStyle.light,
              actions: [
                IconButton(
                  tooltip: 'New OPD visit',
                  icon: const Icon(Icons.add_task_rounded),
                  onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => RegisterPatientScreen(existing: p))),
                ),
                IconButton(
                  tooltip: p.pinned ? 'Stop keeping offline' : 'Keep on this phone',
                  icon: AnimatedSwitcher(
                    duration: Motion.medium,
                    transitionBuilder: (c, a) => RotationTransition(
                      turns: Tween(begin: 0.8, end: 1.0).animate(a),
                      child: ScaleTransition(scale: a, child: c),
                    ),
                    child: Icon(p.pinned ? Icons.push_pin_rounded : Icons.push_pin_outlined, key: ValueKey(p.pinned)),
                  ),
                  onPressed: () async {
                    HapticFeedback.selectionClick();
                    await ref.read(doctorRepoProvider).setPinned(p.id, !p.pinned);
                    afterLocalWrite(ref);
                    if (context.mounted) {
                      showSnack(
                        context,
                        p.pinned ? 'Will be removed when no longer assigned to you' : 'Kept on this phone for offline use',
                        icon: Icons.push_pin_rounded,
                      );
                    }
                  },
                ),
              ],
              flexibleSpace: LayoutBuilder(
                builder: (context, c) {
                  final minH = kToolbarHeight + topPad + 56;
                  final maxH = expanded + topPad;
                  final t = ((c.maxHeight - minH) / (maxH - minH)).clamp(0.0, 1.0);
                  return Stack(
                    fit: StackFit.expand,
                    children: [
                      DecoratedBox(decoration: BoxDecoration(gradient: Theme.of(context).colorScheme.heroGradient)),
                      Positioned(right: -50, top: -40, child: _circle(180, 0.10)),
                      Positioned(left: -40, top: 90, child: _circle(120, 0.06)),
                      // Collapsed title
                      Positioned(
                        left: 56,
                        right: 100,
                        top: topPad,
                        height: kToolbarHeight,
                        child: Opacity(
                          opacity: (1 - t * 2.2).clamp(0.0, 1.0),
                          child: Align(
                            alignment: Alignment.centerLeft,
                            child: Text(
                              p.name,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: Theme.of(context).textTheme.titleLarge?.copyWith(color: Colors.white),
                            ),
                          ),
                        ),
                      ),
                      // Expanded header
                      Positioned(
                        left: 20,
                        right: 20,
                        bottom: 56 + 16,
                        child: Opacity(
                          opacity: ((t - 0.25) / 0.75).clamp(0.0, 1.0),
                          child: Transform.translate(
                            offset: Offset(0, 16 * (1 - t)),
                            child: _HeaderBody(d: d, heroTag: heroTag),
                          ),
                        ),
                      ),
                    ],
                  );
                },
              ),
              bottom: PreferredSize(
                preferredSize: const Size.fromHeight(56),
                child: Container(
                  height: 56,
                  decoration: BoxDecoration(
                    color: Theme.of(context).scaffoldBackgroundColor,
                    borderRadius: const BorderRadius.vertical(top: Radius.circular(Radii.xl)),
                  ),
                  padding: const EdgeInsets.fromLTRB(8, 8, 8, 4),
                  child: const TabBar(
                    isScrollable: true,
                    tabAlignment: TabAlignment.start,
                    tabs: [
                      Tab(text: 'Summary'),
                      Tab(text: 'Care'),
                      Tab(text: 'Prescriptions'),
                      Tab(text: 'Visits'),
                      Tab(text: 'Labs'),
                      Tab(text: 'Billing'),
                      Tab(text: 'Timeline'),
                    ],
                  ),
                ),
              ),
            ),
          ],
          body: TabBarView(
            children: [
              _SummaryTab(d: d),
              CareTab(d: d),
              _PrescriptionsTab(d: d),
              _VisitsTab(d: d),
              _LabsTab(labs: d.labs),
              BillingTab(d: d),
              _TimelineTab(entries: d.timeline),
            ],
          ),
        ),
      ),
    );
  }

  Widget _circle(double s, double a) => Container(
    width: s,
    height: s,
    decoration: BoxDecoration(
      shape: BoxShape.circle,
      color: Colors.white.withValues(alpha: a),
    ),
  );
}

class _HeaderBody extends StatelessWidget {
  const _HeaderBody({required this.d, this.heroTag});
  final PatientDetail d;
  final Object? heroTag;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final p = d.patient;
    final facts = <(IconData, String)>[
      if (p.ageLabel != null) (Icons.cake_outlined, p.ageLabel!),
      if (p.gender != null) (Icons.wc_rounded, p.gender!),
      if (p.bloodGroup != null) (Icons.bloodtype_outlined, p.bloodGroup!),
      if (p.mobile != null) (Icons.phone_outlined, p.mobile!),
    ];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Row(
          children: [
            Container(
              padding: const EdgeInsets.all(3),
              decoration: BoxDecoration(shape: BoxShape.circle, color: Colors.white.withValues(alpha: 0.25)),
              child: PatientAvatar(name: p.name, radius: 32, heroTag: heroTag),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    p.name,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: t.textTheme.headlineSmall?.copyWith(color: Colors.white),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    ['ID ${p.id}', if (p.isDead) 'Deceased', if (p.pinned) 'Kept offline'].join(' · '),
                    style: t.textTheme.bodySmall?.copyWith(color: Colors.white.withValues(alpha: 0.8)),
                  ),
                ],
              ),
            ),
          ],
        ),
        const SizedBox(height: 14),
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Row(
            children: [
              for (final f in facts)
                Container(
                  margin: const EdgeInsets.only(right: 8),
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: 0.16),
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: Colors.white.withValues(alpha: 0.18)),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(f.$1, size: 14, color: Colors.white),
                      const SizedBox(width: 6),
                      Text(f.$2, style: t.textTheme.labelMedium?.copyWith(color: Colors.white)),
                    ],
                  ),
                ),
            ],
          ),
        ),
        if (d.allergies.isNotEmpty) ...[const SizedBox(height: 12), AllergyBanner(allergies: d.allergies, dense: true)],
      ],
    );
  }
}

/// Pick the visit/admission (if there is more than one open) and open the editor.
Future<void> startPrescription(BuildContext context, WidgetRef ref, PatientDetail d, {Prescription? copyFrom}) async {
  final options = d.openEncounters.isNotEmpty ? d.openEncounters : d.encounters.take(1).toList();
  if (options.isEmpty) {
    showSnack(context, 'No OPD visit or admission on record. Register the visit at the front desk first.', error: true);
    return;
  }
  Encounter? e = options.length == 1 ? options.first : null;
  e ??= await showModalBottomSheet<Encounter>(
    context: context,
    builder: (ctx) => SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
            child: Text('Prescribe for which visit?', style: Theme.of(ctx).textTheme.titleMedium),
          ),
          for (final (i, o) in options.indexed)
            FadeSlideIn(
              index: i,
              child: ListTile(
                leading: IconBadge(o.isIpd ? Icons.bed_rounded : Icons.medical_services_rounded),
                title: Text('${o.label} · ${fmtDateTime(o.date)}'),
                subtitle: Text([o.doctorName, o.bed].whereType<String>().where((s) => s.isNotEmpty).join(' · ')),
                onTap: () => Navigator.pop(ctx, o),
              ),
            ),
          const SizedBox(height: 8),
        ],
      ),
    ),
  );
  if (e == null || !context.mounted) return;
  if (!e.isOpen) {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        icon: const Icon(Icons.event_busy_rounded),
        title: const Text('Visit is closed'),
        content: Text('The latest ${e!.label.toLowerCase()} (${fmtDate(e.date)}) is discharged. The server may refuse a new prescription for it. Continue?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Continue')),
        ],
      ),
    );
    if (ok != true || !context.mounted) return;
  }
  await Navigator.push(
    context,
    MaterialPageRoute(
      builder: (_) => PrescriptionEditorScreen.create(patientId: d.patient.id, encounter: e!, copyFrom: copyFrom),
    ),
  );
}

/// Padding for tab content: room for the FAB at the bottom.
const _tabPad = EdgeInsets.fromLTRB(16, 12, 16, 110);

// ================================================================== summary
class _SummaryTab extends ConsumerWidget {
  const _SummaryTab({required this.d});
  final PatientDetail d;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = Theme.of(context);
    final p = d.patient;
    final last = d.prescriptions.where((x) => x.items.isNotEmpty).firstOrNull;
    final reported = d.labs.where((l) => l.isReported).take(3).toList();
    final latestVitals = <String, VitalReading>{};
    for (final v in d.vitals) {
      latestVitals.putIfAbsent(v.name, () => v);
    }
    var i = 0;
    Widget anim(Widget w) => FadeSlideIn(index: i++, child: w);
    const hp = EdgeInsets.fromLTRB(4, 18, 0, 10);

    return ListView(
      padding: _tabPad,
      children: [
        for (final e in d.openEncounters) ...[
          SectionHeader(e.isIpd ? 'Current admission' : 'Current visit', padding: hp),
          anim(EncounterCard(encounter: e, diagnoses: d.diagnoses[e.key] ?? const [])),
        ],

        SectionHeader(
          'Last prescription',
          padding: hp,
          trailing: last == null
              ? null
              : FilledButton.tonalIcon(
                  style: FilledButton.styleFrom(minimumSize: const Size(0, 36), visualDensity: VisualDensity.compact),
                  onPressed: () => startPrescription(context, ref, d, copyFrom: last),
                  icon: const Icon(Icons.replay_rounded, size: 18),
                  label: const Text('Repeat'),
                ),
        ),
        if (last == null)
          Padding(
            padding: const EdgeInsets.only(left: 4),
            child: Text('No prescriptions yet.', style: t.textTheme.bodyMedium?.copyWith(color: t.colorScheme.onSurfaceVariant)),
          )
        else
          anim(PrescriptionCard(rx: last, encounter: d.encounterFor(last), onTap: () => _openRx(context, last))),

        if (latestVitals.isNotEmpty) ...[
          const SectionHeader('Latest vitals', padding: hp),
          anim(
            SizedBox(
              height: 92,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                itemCount: latestVitals.length,
                separatorBuilder: (_, _) => const SizedBox(width: 10),
                itemBuilder: (_, j) {
                  final v = latestVitals.values.elementAt(j);
                  return Container(
                    width: 128,
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: t.cardTheme.color,
                      borderRadius: BorderRadius.circular(Radii.md),
                      border: Border.all(color: t.colorScheme.outlineVariant.withValues(alpha: 0.55)),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          v.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: t.textTheme.labelSmall?.copyWith(color: t.colorScheme.onSurfaceVariant),
                        ),
                        const Spacer(),
                        Text.rich(
                          TextSpan(
                            children: [
                              TextSpan(text: v.value, style: t.textTheme.titleLarge),
                              if (v.unit != null) TextSpan(text: ' ${v.unit}', style: t.textTheme.labelSmall),
                            ],
                          ),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                        Text(fmtDateShort(v.measuredAt), style: t.textTheme.labelSmall?.copyWith(color: t.colorScheme.onSurfaceVariant)),
                      ],
                    ),
                  );
                },
              ),
            ),
          ),
        ],

        if (reported.isNotEmpty) ...[
          const SectionHeader('Recent lab results', padding: hp),
          for (final l in reported)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: anim(LabReportCard(report: l, onOpenFile: () => openDoctorReportFile(context, ref, l))),
            ),
        ],

        if (p.guardian != null || p.address != null || p.note != null) ...[
          const SectionHeader('Details', padding: hp),
          anim(
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  children: [
                    if (p.guardian != null) _kv(context, Icons.family_restroom_rounded, 'Guardian', p.guardian!),
                    if (p.address != null) _kv(context, Icons.home_outlined, 'Address', p.address!),
                    if (p.note != null) _kv(context, Icons.sticky_note_2_outlined, 'Note', p.note!),
                  ],
                ),
              ),
            ),
          ),
        ],
      ],
    );
  }

  Widget _kv(BuildContext context, IconData icon, String k, String v) {
    final t = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          IconBadge(icon, size: 32),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(k, style: t.textTheme.labelSmall?.copyWith(color: t.colorScheme.onSurfaceVariant)),
                Text(v),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

void _openRx(BuildContext context, Prescription rx) => Navigator.push(context, MaterialPageRoute(builder: (_) => PrescriptionViewScreen(localId: rx.localId)));

// ================================================================== prescriptions
class _PrescriptionsTab extends StatelessWidget {
  const _PrescriptionsTab({required this.d});
  final PatientDetail d;

  @override
  Widget build(BuildContext context) {
    if (d.prescriptions.isEmpty) {
      return const EmptyState(icon: Icons.receipt_long_rounded, title: 'No prescriptions yet');
    }
    return ListView.separated(
      padding: _tabPad,
      itemCount: d.prescriptions.length,
      separatorBuilder: (_, _) => const SizedBox(height: 10),
      itemBuilder: (_, i) {
        final rx = d.prescriptions[i];
        return FadeSlideIn(
          index: i,
          child: PrescriptionCard(rx: rx, encounter: d.encounterFor(rx), onTap: () => _openRx(context, rx)),
        );
      },
    );
  }
}

// ================================================================== visits
class _VisitsTab extends StatelessWidget {
  const _VisitsTab({required this.d});
  final PatientDetail d;

  @override
  Widget build(BuildContext context) {
    if (d.encounters.isEmpty) return const EmptyState(icon: Icons.event_note_rounded, title: 'No visits on record');
    return ListView.separated(
      padding: _tabPad,
      itemCount: d.encounters.length,
      separatorBuilder: (_, _) => const SizedBox(height: 10),
      itemBuilder: (_, i) {
        final e = d.encounters[i];
        final rxCount = d.prescriptions.where((p) => p.kind == e.kind && p.caseRefId == e.refId).length;
        return FadeSlideIn(
          index: i,
          child: EncounterCard(encounter: e, diagnoses: d.diagnoses[e.key] ?? const [], prescriptionCount: rxCount),
        );
      },
    );
  }
}

// ================================================================== labs
class _LabsTab extends ConsumerWidget {
  const _LabsTab({required this.labs});
  final List<LabReport> labs;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (labs.isEmpty) return const EmptyState(icon: Icons.biotech_rounded, title: 'No lab or imaging reports');
    return ListView.separated(
      padding: _tabPad,
      itemCount: labs.length,
      separatorBuilder: (_, _) => const SizedBox(height: 10),
      itemBuilder: (_, i) => FadeSlideIn(
        index: i,
        child: LabReportCard(report: labs[i], onOpenFile: () => openDoctorReportFile(context, ref, labs[i])),
      ),
    );
  }
}

// ================================================================== timeline
class _TimelineTab extends StatelessWidget {
  const _TimelineTab({required this.entries});
  final List<TimelineEntry> entries;

  @override
  Widget build(BuildContext context) {
    if (entries.isEmpty) return const EmptyState(icon: Icons.timeline_rounded, title: 'No timeline entries');
    final t = Theme.of(context);
    final cs = t.colorScheme;
    return ListView.builder(
      padding: _tabPad,
      itemCount: entries.length,
      itemBuilder: (_, i) {
        final e = entries[i];
        return FadeSlideIn(
          index: i,
          offset: const Offset(-20, 0),
          child: IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                SizedBox(
                  width: 24,
                  child: Column(
                    children: [
                      Container(
                        width: 14,
                        height: 14,
                        margin: const EdgeInsets.only(top: 4),
                        decoration: BoxDecoration(
                          color: cs.primary,
                          shape: BoxShape.circle,
                          border: Border.all(color: cs.primaryContainer, width: 3),
                        ),
                      ),
                      if (i < entries.length - 1)
                        Expanded(
                          child: Container(width: 2, margin: const EdgeInsets.symmetric(vertical: 2), color: cs.outlineVariant),
                        ),
                    ],
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.only(bottom: 14),
                    child: Card(
                      child: Padding(
                        padding: const EdgeInsets.all(14),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              fmtDateTime(e.date),
                              style: t.textTheme.labelSmall?.copyWith(color: cs.primary, fontWeight: FontWeight.w700),
                            ),
                            const SizedBox(height: 2),
                            Text(e.title, style: t.textTheme.titleSmall),
                            if (e.description != null) ...[const SizedBox(height: 4), Text(e.description!, style: t.textTheme.bodySmall)],
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}
