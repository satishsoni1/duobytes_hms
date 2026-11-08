import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../core/auth/patient_account.dart';
import '../../../core/providers.dart';
import '../../../core/ui/theme.dart';
import '../../../core/ui/widgets.dart';
import '../../doctor/data/clinical.dart';
import '../../doctor/data/models.dart';
import '../../doctor/specialty/followups.dart' show followUpWhen;
import '../../doctor/specialty/obstetrics.dart' show PregnancyDating;
import '../../doctor/specialty/vaccine_schedule.dart';
import '../../doctor/ui/rx_widgets.dart';
import '../data/patient_records.dart';
import '../../../core/ui/report_files.dart';
import 'booking.dart';
import 'my_prescription_screen.dart';
import 'patient_providers.dart';

/// Home for a signed-in patient: their own records, read-only, available offline.
class PatientHome extends ConsumerStatefulWidget {
  const PatientHome({super.key});

  @override
  ConsumerState<PatientHome> createState() => _PatientHomeState();
}

class _PatientHomeState extends ConsumerState<PatientHome> {
  int _tab = 0;

  @override
  Widget build(BuildContext context) {
    final expired = ref.watch(tokenExpiredProvider);
    return Scaffold(
      body: FadeIndexedStack(index: _tab, children: const [_HomeTab(), _PrescriptionsTab(), _ReportsTab(), _ProfileTab()]),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (i) {
          if (i != _tab) HapticFeedback.selectionClick();
          setState(() => _tab = i);
        },
        destinations: [
          const NavigationDestination(icon: Icon(Icons.home_outlined), selectedIcon: Icon(Icons.home_rounded), label: 'Home'),
          const NavigationDestination(icon: Icon(Icons.receipt_long_outlined), selectedIcon: Icon(Icons.receipt_long_rounded), label: 'Prescriptions'),
          const NavigationDestination(icon: Icon(Icons.biotech_outlined), selectedIcon: Icon(Icons.biotech_rounded), label: 'Reports'),
          NavigationDestination(
            icon: Badge(isLabelVisible: expired, label: const Text('!'), child: const Icon(Icons.person_outline_rounded)),
            selectedIcon: const Icon(Icons.person_rounded),
            label: 'Profile',
          ),
        ],
      ),
    );
  }
}

/// Loads the records and shows loading / first-download / data states consistently on every tab.
class _RecordsView extends ConsumerWidget {
  const _RecordsView({required this.builder});
  final Widget Function(BuildContext, PatientRecords) builder;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final records = ref.watch(patientRecordsProvider);
    final sync = ref.watch(patientSyncProvider);
    return records.when(
      loading: () => const SkeletonList(count: 4),
      error: (e, _) => EmptyState(icon: Icons.error_outline_rounded, title: 'Could not load your records', message: '$e'),
      data: (r) => r == null
          ? EmptyState(
              icon: sync.phase == PatientSyncPhase.offline ? Icons.cloud_off_rounded : Icons.downloading_rounded,
              title: sync.phase == PatientSyncPhase.offline ? 'You are offline' : 'Getting your records…',
              message: sync.phase == PatientSyncPhase.offline
                  ? 'Connect to the internet once to download your records. After that they are available offline.'
                  : 'This takes a moment the first time.',
              action: sync.phase == PatientSyncPhase.syncing
                  ? const CircularProgressIndicator()
                  : FilledButton.tonal(onPressed: () => ref.read(patientSyncProvider.notifier).refresh(), child: const Text('Try again')),
            )
          : builder(context, r),
    );
  }
}

class _PatientSyncBanner extends ConsumerWidget {
  const _PatientSyncBanner();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = ref.watch(patientSyncProvider);
    final expired = ref.watch(tokenExpiredProvider);
    final cs = Theme.of(context).colorScheme;
    final (IconData, String, Color, Color, VoidCallback?)? spec = expired
        ? (Icons.lock_clock_rounded, 'Signed out of the hospital server. Tap to sign in and update your records.', cs.errorContainer, cs.onErrorContainer,
            () => showReauthDialog(context, ref))
        : s.phase == PatientSyncPhase.offline
            ? (Icons.cloud_off_rounded, 'Offline · showing records from ${fmtAgo(s.lastSyncAt)}', cs.secondaryContainer, cs.onSecondaryContainer, null)
            : s.phase == PatientSyncPhase.error
                ? (Icons.sync_problem_rounded, s.message ?? 'Could not update your records', cs.tertiaryContainer, cs.onTertiaryContainer,
                    () => ref.read(patientSyncProvider.notifier).refresh())
                : null;
    return AnimatedSize(
      duration: Motion.medium,
      curve: Motion.emphasized,
      child: spec == null
          ? const SizedBox(width: double.infinity)
          : Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
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
                    ]),
                  ),
                ),
              ),
            ),
    );
  }
}

void _openFile(BuildContext context, WidgetRef ref, LabReport r) =>
    ReportFiles.open(context, ref.read(patientApiProvider), 'patient/report_file', kind: r.kind, id: r.id, name: r.fileName);

void _openRx(BuildContext context, PatientRecords r, Prescription rx) {
  final e = r.encounterFor(rx);
  Navigator.push(context, MaterialPageRoute(builder: (_) => MyPrescriptionScreen(rx: rx, encounter: e, diagnoses: e == null ? const [] : r.diagnoses[e.key] ?? const [])));
}

// ================================================================== home
class _HomeTab extends ConsumerWidget {
  const _HomeTab();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final session = ref.watch(patientSessionProvider);
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: () => ref.read(patientSyncProvider.notifier).refresh(),
          child: _RecordsView(builder: (context, r) => _homeList(context, ref, session, r)),
        ),
      ),
    );
  }

  Widget _homeList(BuildContext context, WidgetRef ref, PatientSession session, PatientRecords r) {
    final t = Theme.of(context);
    final sync = ref.watch(patientSyncProvider);
    final latest = r.prescriptions.where((p) => p.items.isNotEmpty).firstOrNull;
    final next = r.has('my_appointments') ? r.nextAppointment : null;
    final admitted = r.encounters.where((e) => e.isIpd && e.isOpen).firstOrNull;
    final reports = r.labs.where((l) => l.isReported).take(2).toList();
    var i = 0;
    Widget anim(Widget w) => FadeSlideIn(index: i++, child: w);
    const hp = EdgeInsets.fromLTRB(20, 22, 16, 10);

    return ListView(physics: const AlwaysScrollableScrollPhysics(), padding: const EdgeInsets.only(bottom: 24), children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
        child: anim(HeroPanel(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(
                child: Text(DateFormat('EEEE, d MMMM').format(DateTime.now()).toUpperCase(),
                    style: t.textTheme.labelSmall?.copyWith(color: Colors.white.withValues(alpha: 0.85), letterSpacing: 1.1, fontWeight: FontWeight.w600)),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: BoxDecoration(color: Colors.white.withValues(alpha: 0.16), borderRadius: BorderRadius.circular(20)),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  PulseDot(color: sync.phase == PatientSyncPhase.offline ? const Color(0xFFFFC97A) : const Color(0xFF7CF0B5), active: sync.phase == PatientSyncPhase.syncing),
                  const SizedBox(width: 6),
                  Text(sync.phase == PatientSyncPhase.syncing ? 'Updating' : 'Updated ${fmtAgo(sync.lastSyncAt)}',
                      style: t.textTheme.labelSmall?.copyWith(color: Colors.white, fontWeight: FontWeight.w600)),
                ]),
              ),
            ]),
            const SizedBox(height: 14),
            Text('${greeting()},', style: t.textTheme.titleMedium?.copyWith(color: Colors.white.withValues(alpha: 0.85), fontWeight: FontWeight.w500)),
            Text(session.firstName, style: t.textTheme.headlineSmall?.copyWith(color: Colors.white)),
            if (session.hospital.name.isNotEmpty)
              Text(session.hospital.name, style: t.textTheme.bodySmall?.copyWith(color: Colors.white.withValues(alpha: 0.8))),
            const SizedBox(height: 18),
            Row(children: [
              Expanded(child: StatTile(value: r.prescriptions.length, label: 'Rx', icon: Icons.receipt_long_rounded)),
              const SizedBox(width: 10),
              Expanded(child: StatTile(value: r.labs.length, label: 'Reports', icon: Icons.biotech_rounded)),
              const SizedBox(width: 10),
              Expanded(child: StatTile(value: r.encounters.length, label: 'Visits', icon: Icons.local_hospital_rounded)),
            ]),
          ]),
        )),
      ),
      const _PatientSyncBanner(),
      if (r.has('my_appointments')) ...[
        const LiveQueueCard(),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
          child: FilledButton.tonalIcon(
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
            onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const BookAppointmentScreen())),
            icon: const Icon(Icons.event_available_rounded),
            label: const Text('Book appointment'),
          ),
        ),
      ],
      if (r.allergies.isNotEmpty) Padding(padding: const EdgeInsets.fromLTRB(16, 12, 16, 0), child: anim(AllergyBanner(allergies: r.allergies))),

      if (next != null) ...[
        const SectionHeader('Next appointment', padding: hp),
        Padding(padding: const EdgeInsets.symmetric(horizontal: 16), child: anim(_AppointmentCard(a: next, highlight: true))),
      ],
      if (r.nextFollowUp != null) ...[
        const SectionHeader('Next review', padding: hp),
        Padding(padding: const EdgeInsets.symmetric(horizontal: 16), child: anim(_FollowUpTile(f: r.nextFollowUp!))),
      ],
      if (r.activePregnancy != null && PregnancyDating.of(r.activePregnancy!) != null) ...[
        const SectionHeader('Pregnancy', padding: hp),
        Padding(padding: const EdgeInsets.symmetric(horizontal: 16), child: anim(_PregnancyTile(dating: PregnancyDating.of(r.activePregnancy!)!))),
      ],
      if (admitted != null) ...[
        const SectionHeader('Current admission', padding: hp),
        Padding(padding: const EdgeInsets.symmetric(horizontal: 16), child: anim(EncounterCard(encounter: admitted, diagnoses: r.diagnoses[admitted.key] ?? const []))),
      ],
      if (latest != null) ...[
        const SectionHeader('Latest prescription', padding: hp),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: anim(PrescriptionCard(rx: latest, encounter: r.encounterFor(latest), onTap: () => _openRx(context, r, latest))),
        ),
      ],
      if (reports.isNotEmpty) ...[
        const SectionHeader('Recent reports', padding: hp),
        for (final l in reports) Padding(padding: const EdgeInsets.fromLTRB(16, 0, 16, 10), child: anim(LabReportCard(report: l, onOpenFile: () => _openFile(context, ref, l)))),
      ],
      const SectionHeader('My records', padding: hp),
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: anim(Card(
          child: Column(children: [
            if (r.has('opd') || r.has('ipd'))
              _NavRow(
                icon: Icons.local_hospital_rounded,
                title: 'Visits & admissions',
                subtitle: '${r.encounters.length} on record',
                onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => _VisitsScreen(records: r))),
              ),
            if (r.has('my_appointments'))
              _NavRow(
                icon: Icons.event_rounded,
                title: 'Appointments',
                subtitle: '${r.appointments.length} in the last 2 years',
                onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => _AppointmentsScreen(records: r))),
              ),
            if (r.ofType(RecordType.vaccination).isNotEmpty || ((r.patient?.dob != null) && DateTime.now().difference(r.patient!.dob!).inDays < 18 * 365))
              _NavRow(
                icon: Icons.vaccines_rounded,
                title: 'Vaccinations',
                subtitle: () {
                  final st = vaccineStatuses(r.patient?.dob, r.ofType(RecordType.vaccination));
                  final due = st.where((s) => s.state == VaccineState.due || s.state == VaccineState.overdue).length;
                  return due > 0 ? '$due due now' : '${st.where((s) => s.state == VaccineState.given).length} recorded';
                }(),
                onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => _VaccinationsScreen(records: r))),
              ),
            if (r.vitals.isNotEmpty)
              _NavRow(
                icon: Icons.monitor_heart_rounded,
                title: 'Vitals',
                subtitle: 'Latest ${fmtDateShort(r.vitals.first.measuredAt)}',
                onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => _VitalsScreen(records: r))),
              ),
            if (r.timeline.isNotEmpty)
              _NavRow(
                icon: Icons.timeline_rounded,
                title: 'Timeline',
                subtitle: '${r.timeline.length} entries',
                onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => _TimelineScreen(records: r))),
              ),
          ]),
        )),
      ),
    ]);
  }
}

class _NavRow extends StatelessWidget {
  const _NavRow({required this.icon, required this.title, required this.subtitle, required this.onTap});
  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => ListTile(
        contentPadding: const EdgeInsets.fromLTRB(14, 4, 10, 4),
        leading: IconBadge(icon),
        title: Text(title, style: Theme.of(context).textTheme.titleSmall),
        subtitle: Text(subtitle),
        trailing: const Icon(Icons.chevron_right_rounded),
        onTap: onTap,
      );
}

class _AppointmentCard extends StatelessWidget {
  const _AppointmentCard({required this.a, this.highlight = false, this.onCancel});
  final VoidCallback? onCancel;
  final MyAppointment a;
  final bool highlight;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final d = a.date;
    final statusColors = switch (a.status.toLowerCase()) {
      'approved' => (cs.tertiaryContainer, cs.onTertiaryContainer),
      'cancel' || 'cancelled' || 'reject' || 'rejected' => (cs.errorContainer, cs.onErrorContainer),
      _ => (cs.secondaryContainer, cs.onSecondaryContainer),
    };
    return Card(
      color: highlight ? cs.primaryContainer.withValues(alpha: 0.55) : null,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(children: [
          Container(
            width: 56,
            padding: const EdgeInsets.symmetric(vertical: 8),
            decoration: BoxDecoration(color: cs.surface, borderRadius: BorderRadius.circular(Radii.md), border: Border.all(color: cs.outlineVariant)),
            child: Column(children: [
              Text(d == null ? '—' : DateFormat('MMM').format(d).toUpperCase(), style: t.textTheme.labelSmall?.copyWith(color: cs.primary, fontWeight: FontWeight.w700)),
              Text(d == null ? '' : '${d.day}', style: t.textTheme.titleLarge),
            ]),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(a.doctorName == null ? 'Appointment' : 'Dr. ${a.doctorName}', style: t.textTheme.titleSmall),
              Text([if (d != null) DateFormat('EEE, h:mm a').format(d), a.department].whereType<String>().where((s) => s.isNotEmpty).join(' · '),
                  style: t.textTheme.bodySmall?.copyWith(color: cs.onSurfaceVariant)),
              if (a.message != null) Text(a.message!, maxLines: 2, overflow: TextOverflow.ellipsis, style: t.textTheme.bodySmall),
            ]),
          ),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            StatusPill(a.status[0].toUpperCase() + a.status.substring(1), bg: statusColors.$1, fg: statusColors.$2),
            if (onCancel != null && !a.isCancelled)
              TextButton(
                style: TextButton.styleFrom(visualDensity: VisualDensity.compact, foregroundColor: cs.error),
                onPressed: onCancel,
                child: const Text('Cancel'),
              ),
          ]),
        ]),
      ),
    );
  }
}

// ================================================================== prescriptions
class _PrescriptionsTab extends ConsumerWidget {
  const _PrescriptionsTab();

  @override
  Widget build(BuildContext context, WidgetRef ref) => Scaffold(
        appBar: AppBar(title: const Text('My prescriptions'), toolbarHeight: 64),
        body: RefreshIndicator(
          onRefresh: () => ref.read(patientSyncProvider.notifier).refresh(),
          child: _RecordsView(
            builder: (context, r) => r.prescriptions.isEmpty
                ? ListView(children: const [SizedBox(height: 80), EmptyState(icon: Icons.receipt_long_rounded, title: 'No prescriptions yet')])
                : ListView.separated(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                    itemCount: r.prescriptions.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 10),
                    itemBuilder: (_, i) {
                      final rx = r.prescriptions[i];
                      return FadeSlideIn(index: i, child: PrescriptionCard(rx: rx, encounter: r.encounterFor(rx), onTap: () => _openRx(context, r, rx)));
                    },
                  ),
          ),
        ),
      );
}

// ================================================================== reports
class _ReportsTab extends ConsumerStatefulWidget {
  const _ReportsTab();
  @override
  ConsumerState<_ReportsTab> createState() => _ReportsTabState();
}

class _ReportsTabState extends ConsumerState<_ReportsTab> {
  String _kind = 'all';

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Lab & imaging reports'), toolbarHeight: 64),
        body: RefreshIndicator(
          onRefresh: () => ref.read(patientSyncProvider.notifier).refresh(),
          child: _RecordsView(builder: (context, r) {
            final list = _kind == 'all' ? r.labs : r.labs.where((l) => l.kind == _kind).toList();
            return ListView(physics: const AlwaysScrollableScrollPhysics(), padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
              if (r.has('pathology') && r.has('radiology'))
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: SegmentedButton<String>(
                    showSelectedIcon: false,
                    segments: const [
                      ButtonSegment(value: 'all', label: Text('All')),
                      ButtonSegment(value: 'pathology', label: Text('Lab')),
                      ButtonSegment(value: 'radiology', label: Text('Imaging')),
                    ],
                    selected: {_kind},
                    onSelectionChanged: (s) => setState(() => _kind = s.first),
                  ),
                ),
              if (list.isEmpty) const EmptyState(icon: Icons.biotech_rounded, title: 'No reports yet'),
              for (final (i, l) in list.indexed) Padding(padding: const EdgeInsets.only(bottom: 10), child: FadeSlideIn(index: i, child: LabReportCard(report: l, onOpenFile: () => _openFile(context, ref, l)))),
            ]);
          }),
        ),
      );
}

// ================================================================== profile
class _ProfileTab extends ConsumerWidget {
  const _ProfileTab();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = Theme.of(context);
    final session = ref.watch(patientSessionProvider);
    final records = ref.watch(patientRecordsProvider).valueOrNull;
    final sync = ref.watch(patientSyncProvider);
    final expired = ref.watch(tokenExpiredProvider);
    final p = records?.patient ?? Patient(session.patient);
    final rows = <(IconData, String, String?)>[
      (Icons.badge_outlined, 'Patient ID', '${session.patientId}'),
      (Icons.person_outline_rounded, 'Username', session.username),
      (Icons.cake_outlined, 'Date of birth', p.dob == null ? p.ageLabel : '${fmtDate(p.dob)}${p.ageLabel != null ? ' (${p.ageLabel})' : ''}'),
      (Icons.wc_rounded, 'Gender', p.gender),
      (Icons.bloodtype_outlined, 'Blood group', p.bloodGroup),
      (Icons.phone_outlined, 'Phone', p.mobile),
      (Icons.alternate_email_rounded, 'Email', p.email),
      (Icons.family_restroom_rounded, 'Guardian', p.guardian),
      (Icons.home_outlined, 'Address', p.address),
    ].where((r) => r.$3 != null && r.$3!.isNotEmpty).toList();

    return Scaffold(
      appBar: AppBar(title: const Text('Profile'), toolbarHeight: 64),
      body: ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 32), children: [
        FadeSlideIn(
          child: HeroPanel(
            child: Row(children: [
              Container(
                padding: const EdgeInsets.all(3),
                decoration: BoxDecoration(shape: BoxShape.circle, color: Colors.white.withValues(alpha: 0.25)),
                child: PatientAvatar(name: p.name, radius: 30),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(p.name, style: t.textTheme.titleLarge?.copyWith(color: Colors.white)),
                  Text(session.hospital.name, style: t.textTheme.bodySmall?.copyWith(color: Colors.white.withValues(alpha: 0.85))),
                ]),
              ),
            ]),
          ),
        ),
        if (p.allergies.isNotEmpty) ...[const SizedBox(height: 12), AllergyBanner(allergies: p.allergies)],
        const SectionHeader('Details', padding: EdgeInsets.fromLTRB(4, 20, 0, 8)),
        FadeSlideIn(
          index: 1,
          child: Card(
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Column(children: [
                for (final r in rows)
                  ListTile(
                    dense: true,
                    leading: IconBadge(r.$1, size: 32),
                    title: Text(r.$2, style: t.textTheme.labelSmall?.copyWith(color: t.colorScheme.onSurfaceVariant)),
                    subtitle: Text(r.$3!, style: t.textTheme.bodyMedium),
                  ),
              ]),
            ),
          ),
        ),
        const SectionHeader('Your records on this phone', padding: EdgeInsets.fromLTRB(4, 20, 0, 8)),
        FadeSlideIn(
          index: 2,
          child: Card(
            child: ListTile(
              contentPadding: const EdgeInsets.fromLTRB(14, 8, 12, 8),
              leading: IconBadge(expired ? Icons.lock_clock_rounded : Icons.cloud_done_rounded),
              title: Text(expired ? 'Sign in again to update' : sync.phase == PatientSyncPhase.syncing ? 'Updating…' : 'Updated ${fmtAgo(sync.lastSyncAt)}'),
              subtitle: const Text('Saved encrypted so you can see them without internet.'),
              trailing: expired
                  ? FilledButton(onPressed: () => showReauthDialog(context, ref), child: const Text('Sign in'))
                  : IconButton(
                      tooltip: 'Update now',
                      onPressed: sync.phase == PatientSyncPhase.syncing ? null : () => ref.read(patientSyncProvider.notifier).refresh(),
                      icon: const Icon(Icons.refresh_rounded),
                    ),
            ),
          ),
        ),
        const SizedBox(height: 20),
        OutlinedButton.icon(
          style: OutlinedButton.styleFrom(foregroundColor: t.colorScheme.error, minimumSize: const Size.fromHeight(48)),
          onPressed: () async {
            final ok = await showDialog<bool>(
              context: context,
              builder: (ctx) => AlertDialog(
                title: const Text('Sign out?'),
                content: const Text('Your records will be removed from this phone. They stay safe at the hospital.'),
                actions: [
                  TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
                  FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Sign out')),
                ],
              ),
            );
            if (ok == true) await ref.read(authControllerProvider.notifier).signOut();
          },
          icon: const Icon(Icons.logout_rounded),
          label: const Text('Sign out'),
        ),
      ]),
    );
  }
}

// ================================================================== secondary pages
class _VisitsScreen extends StatelessWidget {
  const _VisitsScreen({required this.records});
  final PatientRecords records;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Visits & admissions')),
        body: records.encounters.isEmpty
            ? const EmptyState(icon: Icons.event_note_rounded, title: 'No visits on record')
            : ListView.separated(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                itemCount: records.encounters.length,
                separatorBuilder: (_, _) => const SizedBox(height: 10),
                itemBuilder: (_, i) {
                  final e = records.encounters[i];
                  final n = records.prescriptions.where((p) => p.kind == e.kind && p.caseRefId == e.refId).length;
                  return FadeSlideIn(index: i, child: EncounterCard(encounter: e, diagnoses: records.diagnoses[e.key] ?? const [], prescriptionCount: n));
                },
              ),
      );
}

class _AppointmentsScreen extends ConsumerWidget {
  const _AppointmentsScreen({required this.records});
  final PatientRecords records;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Follow updates after booking/cancelling.
    final records = ref.watch(patientRecordsProvider).valueOrNull ?? this.records;
    final up = records.appointments.where((a) => a.isUpcoming).toList()..sort((a, b) => a.date!.compareTo(b.date!));
    final past = records.appointments.where((a) => !a.isUpcoming).toList();
    var i = 0;
    return Scaffold(
      appBar: AppBar(title: const Text('Appointments')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const BookAppointmentScreen())),
        icon: const Icon(Icons.add_rounded),
        label: const Text('Book'),
      ),
      body: records.appointments.isEmpty
          ? const EmptyState(icon: Icons.event_busy_rounded, title: 'No appointments', message: 'Tap Book to choose a doctor and a time.')
          : ListView(padding: const EdgeInsets.fromLTRB(16, 0, 16, 24), children: [
              if (up.isNotEmpty) ...[
                SectionHeader('Upcoming', count: up.length, padding: const EdgeInsets.fromLTRB(4, 12, 0, 8)),
                for (final a in up) Padding(padding: const EdgeInsets.only(bottom: 10), child: FadeSlideIn(
                      index: i++,
                      child: GestureDetector(
                        onLongPress: () => cancelAppointment(context, ref, a.id),
                        child: _AppointmentCard(a: a, highlight: true, onCancel: () => cancelAppointment(context, ref, a.id)),
                      ),
                    )),
              ],
              if (past.isNotEmpty) ...[
                SectionHeader('Past', count: past.length, padding: const EdgeInsets.fromLTRB(4, 12, 0, 8)),
                for (final a in past) Padding(padding: const EdgeInsets.only(bottom: 10), child: FadeSlideIn(index: i++, child: _AppointmentCard(a: a))),
              ],
            ]),
    );
  }
}

class _VitalsScreen extends StatelessWidget {
  const _VitalsScreen({required this.records});
  final PatientRecords records;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Vitals')),
      body: ListView.separated(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        itemCount: records.vitals.length,
        separatorBuilder: (_, _) => const SizedBox(height: 8),
        itemBuilder: (_, i) {
          final v = records.vitals[i];
          return FadeSlideIn(
            index: i,
            child: Card(
              child: ListTile(
                leading: const IconBadge(Icons.monitor_heart_rounded),
                title: Text(v.name),
                subtitle: Text(fmtDateTime(v.measuredAt)),
                trailing: Text('${v.value}${v.unit != null ? ' ${v.unit}' : ''}', style: t.textTheme.titleMedium),
              ),
            ),
          );
        },
      ),
    );
  }
}

class _TimelineScreen extends StatelessWidget {
  const _TimelineScreen({required this.records});
  final PatientRecords records;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Timeline')),
      body: ListView.separated(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        itemCount: records.timeline.length,
        separatorBuilder: (_, _) => const SizedBox(height: 8),
        itemBuilder: (_, i) {
          final e = records.timeline[i];
          return FadeSlideIn(
            index: i,
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(fmtDateTime(e.date), style: t.textTheme.labelSmall?.copyWith(color: t.colorScheme.primary, fontWeight: FontWeight.w700)),
                  Text(e.title, style: t.textTheme.titleSmall),
                  if (e.description != null) Text(e.description!, style: t.textTheme.bodySmall),
                ]),
              ),
            ),
          );
        },
      ),
    );
  }
}

class _FollowUpTile extends StatelessWidget {
  const _FollowUpTile({required this.f});
  final ClinicalRecord f;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final (label, urgent) = followUpWhen(f.eventDate);
    return Card(
      child: ListTile(
        contentPadding: const EdgeInsets.fromLTRB(14, 6, 14, 6),
        leading: IconBadge(Icons.event_repeat_rounded, color: urgent ? cs.error : cs.primary),
        title: Text(f.str('reason') ?? 'Review with your doctor', style: t.textTheme.titleSmall),
        subtitle: Text('${fmtDate(f.eventDate)} · $label${f.updatedByName != null && f.updatedByName!.isNotEmpty ? ' · Dr. ${f.updatedByName}' : ''}'),
      ),
    );
  }
}

class _PregnancyTile extends StatelessWidget {
  const _PregnancyTile({required this.dating});
  final PregnancyDating dating;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            IconBadge(Icons.pregnant_woman_rounded, color: cs.secondary),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${dating.weeks} weeks ${dating.days} days', style: t.textTheme.titleSmall),
                Text('${dating.trimesterLabel} trimester · due ${fmtDate(dating.edd)}', style: t.textTheme.bodySmall?.copyWith(color: cs.onSurfaceVariant)),
              ]),
            ),
          ]),
          const SizedBox(height: 10),
          ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: LinearProgressIndicator(value: dating.progress, minHeight: 8, color: cs.secondary, backgroundColor: cs.surfaceContainerHighest),
          ),
        ]),
      ),
    );
  }
}

class _VaccinationsScreen extends StatelessWidget {
  const _VaccinationsScreen({required this.records});
  final PatientRecords records;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final st = vaccineStatuses(records.patient?.dob, records.ofType(RecordType.vaccination));
    final others = otherVaccinations(records.ofType(RecordType.vaccination));
    (IconData, Color, String) look(VaccineState s) => switch (s) {
          VaccineState.given => (Icons.check_circle_rounded, cs.tertiary, 'Given'),
          VaccineState.overdue => (Icons.error_rounded, cs.error, 'Overdue'),
          VaccineState.due => (Icons.notifications_active_rounded, cs.secondary, 'Due now'),
          VaccineState.upcoming => (Icons.radio_button_unchecked_rounded, cs.outline, 'Upcoming'),
        };
    final attention = st.where((s) => s.state == VaccineState.due || s.state == VaccineState.overdue).toList();
    final rest = st.where((s) => s.state != VaccineState.due && s.state != VaccineState.overdue).toList();
    Widget tile(VaccineStatus s) {
      final (icon, color, label) = look(s.state);
      return ListTile(
        leading: Icon(icon, color: color),
        title: Text('${s.dose.vaccine} · ${s.dose.dose}'),
        subtitle: Text(s.record != null ? 'Given ${fmtDate(s.record!.eventDate)}' : s.dueDate != null ? '$label · ${fmtDate(s.dueDate)}' : 'At ${s.dose.ageLabel}'),
      );
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Vaccinations')),
      body: ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
        Text('Based on the IAP schedule. Your doctor will advise which vaccines to take and when.', style: t.textTheme.bodySmall?.copyWith(color: cs.onSurfaceVariant)),
        if (attention.isNotEmpty) ...[
          SectionHeader('Due now', count: attention.length, padding: const EdgeInsets.fromLTRB(4, 16, 0, 8)),
          Card(child: Column(children: [for (final s in attention) tile(s)])),
        ],
        SectionHeader('Schedule', padding: const EdgeInsets.fromLTRB(4, 16, 0, 8)),
        Card(child: Column(children: [for (final s in rest) tile(s)])),
        if (others.isNotEmpty) ...[
          const SectionHeader('Other vaccines', padding: EdgeInsets.fromLTRB(4, 16, 0, 8)),
          Card(
            child: Column(children: [
              for (final r in others)
                ListTile(leading: Icon(Icons.check_circle_rounded, color: cs.tertiary), title: Text('${r.str('vaccine')} ${r.str('dose') ?? ''}'), subtitle: Text('Given ${fmtDate(r.eventDate)}')),
            ]),
          ),
        ],
      ]),
    );
  }
}
