import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../core/ui/widgets.dart';
import '../doctor/data/clinical.dart';
import '../doctor/data/lab_flags.dart';
import '../doctor/data/models.dart';
import '../doctor/data/queue.dart';
import '../doctor/specialty/specialty.dart' show clinicalRepoProvider, saveClinicalRecord;
import '../doctor/ui/doctor_providers.dart';
import '../doctor/ui/patient_screen.dart';
import '../opd/registration_screen.dart';
import 'staff_actions.dart';
import 'staff_data.dart';

final queueAllProvider = FutureProvider.autoDispose((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(staffRepoProvider).queueAll(DateTime.now());
});
final bedsProvider = FutureProvider.autoDispose<List<Bed>>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(staffRepoProvider).beds();
});
final admittedProvider = FutureProvider.autoDispose<List<Encounter>>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(staffRepoProvider).admitted();
});
final labWorklistProvider = FutureProvider.autoDispose<List<LabOrder>>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(staffRepoProvider).labWorklist();
});
final stockProvider = FutureProvider.autoDispose<List<StockItem>>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(staffRepoProvider).stock();
});

Future<void> _openPatient(BuildContext context, WidgetRef ref, int? patientId) async {
  if (patientId == null) return;
  if (await ref.read(doctorRepoProvider).patient(patientId) == null) {
    if (context.mounted) showSnack(context, 'This patient is not on this device yet. Sync to download.');
    return;
  }
  if (context.mounted) await Navigator.push(context, MaterialPageRoute(builder: (_) => PatientScreen(patientId: patientId)));
}

Future<void> _refresh(WidgetRef ref) => ref.read(syncEngineProvider.notifier).sync();

String queueLabel(QueueState s) => switch (s) {
      QueueState.waiting => 'Waiting',
      QueueState.withDoctor => 'With doctor',
      QueueState.seen => 'Seen',
      QueueState.skipped => 'Skipped',
    };

// ====================================================================== reception / triage queue
class QueueBoardScreen extends ConsumerStatefulWidget {
  const QueueBoardScreen({super.key, required this.title, this.triage = false});
  final String title;

  /// Nurse triage: tapping a waiting patient opens vitals entry.
  final bool triage;

  @override
  ConsumerState<QueueBoardScreen> createState() => _QueueBoardScreenState();
}

class _QueueBoardScreenState extends ConsumerState<QueueBoardScreen> {
  int? _doctor;
  bool _showSeen = false;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final data = ref.watch(queueAllProvider).valueOrNull;
    final all = data?.queue ?? const <QueueEntry>[];
    final shown = all.where((e) => (_doctor == null || e.encounter.doctorId == _doctor) && (_showSeen || e.state != QueueState.seen)).toList();
    final waiting = all.where((e) => e.state == QueueState.waiting).length;
    return Scaffold(
      appBar: AppBar(title: Text(widget.title), toolbarHeight: 64),
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'queue-register',
        onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const RegisterPatientScreen())),
        icon: const Icon(Icons.person_add_alt_1_rounded),
        label: const Text('Register patient'),
      ),
      body: RefreshIndicator(
        onRefresh: () => _refresh(ref),
        child: ListView(padding: const EdgeInsets.fromLTRB(16, 0, 16, 110), children: [
          const SyncBanner(margin: EdgeInsets.only(top: 12)),
          const SizedBox(height: 12),
          Text('${all.length} OPD today · $waiting waiting', style: t.textTheme.titleMedium),
          const SizedBox(height: 8),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(children: [
              ChoiceChip(label: const Text('All doctors'), selected: _doctor == null, onSelected: (_) => setState(() => _doctor = null)),
              for (final e in (data?.doctors ?? const <int, String>{}).entries) ...[
                const SizedBox(width: 6),
                ChoiceChip(
                  label: Text('${e.value} (${all.where((q) => q.encounter.doctorId == e.key && q.state == QueueState.waiting).length})'),
                  selected: _doctor == e.key,
                  onSelected: (_) => setState(() => _doctor = e.key),
                ),
              ],
              const SizedBox(width: 6),
              FilterChip(label: const Text('Show seen'), selected: _showSeen, onSelected: (v) => setState(() => _showSeen = v)),
            ]),
          ),
          const SizedBox(height: 8),
          if (data == null) const Padding(padding: EdgeInsets.symmetric(vertical: 24), child: LinearProgressIndicator()),
          if (data != null && shown.isEmpty)
            const EmptyState(icon: Icons.event_available_rounded, title: 'No one in the queue', message: 'Register a walk-in patient or wait for appointments to check in.'),
          for (final (i, q) in shown.indexed)
            FadeSlideIn(
              index: i,
              child: Card(
                child: ListTile(
                  onTap: () => widget.triage && q.patient != null ? showVitalsSheet(context, ref, q.patient!) : _openPatient(context, ref, q.patient?.id),
                  leading: CircleAvatar(
                    backgroundColor: q.state == QueueState.withDoctor ? cs.primary : cs.secondaryContainer,
                    foregroundColor: q.state == QueueState.withDoctor ? cs.onPrimary : cs.onSecondaryContainer,
                    child: Text('${q.token ?? '–'}', style: const TextStyle(fontWeight: FontWeight.w700)),
                  ),
                  title: Text(q.patient?.name ?? 'Patient #${q.encounter.patientId}'),
                  subtitle: Text([
                    q.encounter.doctorName ?? '',
                    fmtTime(q.encounter.date),
                    if (q.encounter.j['local'] == true) 'not uploaded yet',
                    if (q.encounter.j['upload_error'] != null) '${q.encounter.j['upload_error']}',
                  ].where((s) => s.isNotEmpty).join(' · ')),
                  trailing: PopupMenuButton<QueueState>(
                    tooltip: 'Change status',
                    onSelected: (s) async {
                      HapticFeedback.selectionClick();
                      final existing = (await ref.read(clinicalRepoProvider).forPatient(q.encounter.patientId, type: RecordType.opdStatus))
                          .where((r) => r.data['visit_id'] == q.encounter.refId)
                          .firstOrNull;
                      await saveClinicalRecord(ref, queueRecord(q.encounter, s, existing: existing));
                    },
                    itemBuilder: (_) => [for (final s in QueueState.values) PopupMenuItem(value: s, child: Text(queueLabel(s)))],
                    child: Chip(label: Text(queueLabel(q.state)), visualDensity: VisualDensity.compact),
                  ),
                ),
              ),
            ),
        ]),
      ),
    );
  }
}

// ====================================================================== bed board
class BedBoardScreen extends ConsumerWidget {
  const BedBoardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final beds = ref.watch(bedsProvider).valueOrNull;
    final wards = <String, List<Bed>>{};
    for (final b in beds ?? const <Bed>[]) {
      wards.putIfAbsent([b.floor, b.ward].whereType<String>().join(' · '), () => []).add(b);
    }
    final free = beds?.where((b) => !b.occupied).length ?? 0;
    return Scaffold(
      appBar: AppBar(title: const Text('Beds'), toolbarHeight: 64),
      body: RefreshIndicator(
        onRefresh: () => _refresh(ref),
        child: ListView(padding: const EdgeInsets.fromLTRB(16, 12, 16, 32), children: [
          if (beds == null) const Padding(padding: EdgeInsets.symmetric(vertical: 24), child: LinearProgressIndicator()),
          if (beds != null && beds.isEmpty) const EmptyState(icon: Icons.bed_rounded, title: 'No beds yet', message: 'Beds set up on the web appear here after a sync.'),
          if (beds != null && beds.isNotEmpty) Text('$free of ${beds.length} beds free', style: t.textTheme.titleMedium),
          for (final w in wards.entries) ...[
            SectionHeader(w.key, count: w.value.where((b) => !b.occupied).length, padding: const EdgeInsets.fromLTRB(4, 16, 0, 8)),
            GridView.extent(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              maxCrossAxisExtent: 170,
              mainAxisSpacing: 8,
              crossAxisSpacing: 8,
              childAspectRatio: 1.5,
              children: [
                for (final b in w.value)
                  Material(
                    color: b.occupied ? cs.primaryContainer : cs.surfaceContainerHighest,
                    borderRadius: BorderRadius.circular(12),
                    child: InkWell(
                      borderRadius: BorderRadius.circular(12),
                      onTap: b.occupied ? () => _openPatient(context, ref, b.patientId) : null,
                      child: Padding(
                        padding: const EdgeInsets.all(10),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Row(children: [
                            Icon(b.occupied ? Icons.bed_rounded : Icons.bed_outlined, size: 18, color: b.occupied ? cs.onPrimaryContainer : cs.outline),
                            const SizedBox(width: 6),
                            Expanded(child: Text(b.name, style: t.textTheme.titleSmall, overflow: TextOverflow.ellipsis)),
                          ]),
                          const Spacer(),
                          Text(b.occupied ? (b.patientName ?? 'Occupied') : 'Free', maxLines: 1, overflow: TextOverflow.ellipsis, style: t.textTheme.bodyMedium?.copyWith(fontWeight: b.occupied ? FontWeight.w600 : null)),
                          Text(b.occupied ? (b.doctorName ?? '') : (b.type ?? ''), maxLines: 1, overflow: TextOverflow.ellipsis, style: t.textTheme.labelSmall),
                        ]),
                      ),
                    ),
                  ),
              ],
            ),
          ],
        ]),
      ),
    );
  }
}

// ====================================================================== nurse ward
class WardScreen extends ConsumerWidget {
  const WardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final admitted = ref.watch(admittedProvider).valueOrNull;
    final beds = {for (final b in ref.watch(bedsProvider).valueOrNull ?? const <Bed>[]) if (b.ipdId != null) b.ipdId!: b};
    return Scaffold(
      appBar: AppBar(title: const Text('Ward'), toolbarHeight: 64),
      body: RefreshIndicator(
        onRefresh: () => _refresh(ref),
        child: ListView(padding: const EdgeInsets.fromLTRB(16, 0, 16, 32), children: [
          const SyncBanner(margin: EdgeInsets.only(top: 12)),
          const SizedBox(height: 8),
          if (admitted == null) const Padding(padding: EdgeInsets.symmetric(vertical: 24), child: LinearProgressIndicator()),
          if (admitted != null && admitted.isEmpty) const EmptyState(icon: Icons.local_hotel_rounded, title: 'No admitted patients', message: 'Admissions from the web show here after a sync.'),
          for (final (i, e) in (admitted ?? const <Encounter>[]).indexed)
            FadeSlideIn(index: i, child: _WardTile(e: e, bed: beds[e.refId])),
        ]),
      ),
    );
  }
}

class _WardTile extends ConsumerWidget {
  const _WardTile({required this.e, this.bed});
  final Encounter e;
  final Bed? bed;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final patient = ref.watch(patientDetailProvider(e.patientId)).valueOrNull?.patient;
    final t = Theme.of(context);
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 10, 8, 10),
        child: Row(children: [
          Expanded(
            child: InkWell(
              onTap: () => _openPatient(context, ref, e.patientId),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(patient?.name ?? bed?.patientName ?? 'Patient #${e.patientId}', style: t.textTheme.titleSmall),
                Text([if (bed != null) '${bed!.ward} · ${bed!.name}', e.doctorName, if (e.date != null) 'since ${fmtDateShort(e.date)}'].whereType<String>().join(' · '),
                    style: t.textTheme.bodySmall),
              ]),
            ),
          ),
          IconButton(tooltip: 'Record vitals', icon: const Icon(Icons.monitor_heart_rounded), onPressed: patient == null ? null : () => showVitalsSheet(context, ref, patient)),
          IconButton(tooltip: 'Nursing note', icon: const Icon(Icons.edit_note_rounded), onPressed: patient == null ? null : () => showNurseNoteSheet(context, ref, patient, e)),
        ]),
      ),
    );
  }
}

// ====================================================================== lab
class LabWorklistScreen extends ConsumerStatefulWidget {
  const LabWorklistScreen({super.key});
  @override
  ConsumerState<LabWorklistScreen> createState() => _LabWorklistScreenState();
}

class _LabWorklistScreenState extends ConsumerState<LabWorklistScreen> {
  bool _pending = true;
  String _q = '';

  @override
  Widget build(BuildContext context) {
    final list = ref.watch(labWorklistProvider).valueOrNull;
    final t = Theme.of(context);
    final shown = (list ?? const <LabOrder>[])
        .where((o) => o.pending == _pending)
        .where((o) => _q.isEmpty || '${o.patientName} ${o.testName} ${o.id}'.toLowerCase().contains(_q.toLowerCase()))
        .toList();
    return Scaffold(
      appBar: AppBar(title: const Text('Lab worklist'), toolbarHeight: 64),
      body: RefreshIndicator(
        onRefresh: () => _refresh(ref),
        child: ListView(padding: const EdgeInsets.fromLTRB(16, 0, 16, 32), children: [
          const SyncBanner(margin: EdgeInsets.only(top: 12)),
          const SizedBox(height: 12),
          TextField(onChanged: (v) => setState(() => _q = v), decoration: const InputDecoration(hintText: 'Search patient, test or report no.', prefixIcon: Icon(Icons.search_rounded))),
          const SizedBox(height: 10),
          SegmentedButton<bool>(
            segments: [
              ButtonSegment(value: true, label: Text('Pending (${list?.where((o) => o.pending).length ?? 0})')),
              ButtonSegment(value: false, label: Text('Reported (${list?.where((o) => !o.pending).length ?? 0})')),
            ],
            selected: {_pending},
            onSelectionChanged: (s) => setState(() => _pending = s.first),
          ),
          const SizedBox(height: 8),
          if (list == null) const Padding(padding: EdgeInsets.symmetric(vertical: 24), child: LinearProgressIndicator()),
          if (list != null && shown.isEmpty) EmptyState(icon: Icons.biotech_rounded, title: _pending ? 'Nothing pending' : 'No reports yet', message: 'Tests ordered on the web appear here after a sync.'),
          for (final o in shown)
            Card(
              child: ListTile(
                onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => LabEntryScreen(reportId: o.id))),
                leading: const IconBadge(Icons.science_rounded),
                title: Text('${o.testName} · ${o.patientName}'),
                subtitle: Text([
                  'No. ${o.id}',
                  if (o.collected != null) 'collected ${fmtDateShort(o.collected)}',
                  '${o.filled}/${o.parameters.length} values',
                  if (o.uploading) 'waiting to upload',
                  ?o.error,
                ].join(' · '), style: TextStyle(color: o.error != null ? t.colorScheme.error : null)),
                trailing: const Icon(Icons.chevron_right_rounded),
              ),
            ),
        ]),
      ),
    );
  }
}

class LabEntryScreen extends ConsumerStatefulWidget {
  const LabEntryScreen({super.key, required this.reportId});
  final int reportId;
  @override
  ConsumerState<LabEntryScreen> createState() => _LabEntryScreenState();
}

class _LabEntryScreenState extends ConsumerState<LabEntryScreen> {
  final _c = <int, TextEditingController>{};
  TextEditingController? _result;

  @override
  void dispose() {
    for (final c in _c.values) {
      c.dispose();
    }
    _result?.dispose();
    super.dispose();
  }

  Future<void> _save(LabOrder o, {required bool complete}) async {
    await ref.read(staffRepoProvider).saveLabResult(o, {for (final e in _c.entries) e.key: e.value.text.trim()}, result: _result!.text.trim(), complete: complete);
    afterLocalWrite(ref);
    if (!mounted) return;
    showSnack(context, complete ? 'Report marked as reported' : 'Draft saved', icon: Icons.check_rounded);
    Navigator.pop(context);
  }

  @override
  Widget build(BuildContext context) {
    final o = ref.watch(labWorklistProvider).valueOrNull?.where((x) => x.id == widget.reportId).firstOrNull;
    if (o == null) return Scaffold(appBar: AppBar(), body: const SkeletonList());
    _result ??= TextEditingController(text: o.result ?? '');
    final t = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(o.testName)),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
          child: Row(children: [
            Expanded(child: OutlinedButton(style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(50)), onPressed: () => _save(o, complete: false), child: const Text('Save draft'))),
            const SizedBox(width: 10),
            Expanded(child: FilledButton(style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)), onPressed: () => _save(o, complete: true), child: const Text('Mark reported'))),
          ]),
        ),
      ),
      body: ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 24), children: [
        Card(child: ListTile(title: Text(o.patientName), subtitle: Text('Report no. ${o.id}${o.collected != null ? ' · collected ${fmtDate(o.collected)}' : ''}'))),
        const SizedBox(height: 8),
        if (o.parameters.isEmpty) Text('This test has no parameters set up on the web. Enter the result below.', style: t.textTheme.bodyMedium),
        for (final p in o.parameters)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: StatefulBuilder(builder: (ctx, setLocal) {
              final c = _c.putIfAbsent(p.detailId, () => TextEditingController(text: p.value));
              final flag = flagFor(c.text, p.range);
              return TextField(
                controller: c,
                onChanged: (_) => setLocal(() {}),
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: InputDecoration(
                  labelText: p.name,
                  suffixText: p.unit,
                  helperText: p.range == null ? null : 'Range ${p.range}',
                  suffixIcon: switch (flag) {
                    LabFlag.high => Icon(Icons.arrow_upward_rounded, color: t.colorScheme.error),
                    LabFlag.low => Icon(Icons.arrow_downward_rounded, color: t.colorScheme.error),
                    _ => null,
                  },
                ),
              );
            }),
          ),
        TextField(controller: _result, maxLines: 3, decoration: const InputDecoration(labelText: 'Result / remarks')),
      ]),
    );
  }
}

// ====================================================================== pharmacy
enum StockFilter { all, low, out, expiring, expired }

class StockScreen extends ConsumerStatefulWidget {
  const StockScreen({super.key});
  @override
  ConsumerState<StockScreen> createState() => _StockScreenState();
}

class _StockScreenState extends ConsumerState<StockScreen> {
  StockFilter _f = StockFilter.all;
  String _q = '';

  bool _match(StockItem s) => switch (_f) {
        StockFilter.all => true,
        StockFilter.low => s.low,
        StockFilter.out => s.outOfStock,
        StockFilter.expiring => s.expiring > 0,
        StockFilter.expired => s.expired > 0,
      };

  @override
  Widget build(BuildContext context) {
    final list = ref.watch(stockProvider).valueOrNull;
    final t = Theme.of(context);
    final cs = t.colorScheme;
    int n(StockFilter f) => (list ?? const <StockItem>[]).where((s) => switch (f) {
          StockFilter.all => true,
          StockFilter.low => s.low,
          StockFilter.out => s.outOfStock,
          StockFilter.expiring => s.expiring > 0,
          StockFilter.expired => s.expired > 0,
        }).length;
    final shown = (list ?? const <StockItem>[]).where(_match).where((s) => _q.isEmpty || s.name.toLowerCase().contains(_q.toLowerCase())).toList();
    return Scaffold(
      appBar: AppBar(title: const Text('Medicine stock'), toolbarHeight: 64),
      body: RefreshIndicator(
        onRefresh: () => _refresh(ref),
        child: ListView(padding: const EdgeInsets.fromLTRB(16, 0, 16, 32), children: [
          const SyncBanner(margin: EdgeInsets.only(top: 12)),
          const SizedBox(height: 12),
          TextField(onChanged: (v) => setState(() => _q = v), decoration: const InputDecoration(hintText: 'Search medicine', prefixIcon: Icon(Icons.search_rounded))),
          const SizedBox(height: 8),
          Wrap(spacing: 6, runSpacing: 6, children: [
            for (final (f, l) in const [(StockFilter.all, 'All'), (StockFilter.low, 'Low'), (StockFilter.out, 'Out of stock'), (StockFilter.expiring, 'Expiring in 90 days'), (StockFilter.expired, 'Expired')])
              ChoiceChip(label: Text('$l (${n(f)})'), selected: _f == f, onSelected: (_) => setState(() => _f = f)),
          ]),
          const SizedBox(height: 8),
          if (list == null) const Padding(padding: EdgeInsets.symmetric(vertical: 24), child: LinearProgressIndicator()),
          if (list != null && shown.isEmpty) const EmptyState(icon: Icons.medication_rounded, title: 'Nothing to show', message: 'Stock from the web pharmacy appears here after a sync.'),
          for (final s in shown)
            Card(
              child: ListTile(
                leading: IconBadge(Icons.medication_rounded, color: s.outOfStock || s.expired > 0 ? cs.error : s.low ? Colors.orange.shade700 : null),
                title: Text(s.name),
                subtitle: Text([
                  ?s.category,
                  if (s.nextExpiry != null) 'next expiry ${fmtDate(s.nextExpiry)}',
                  if (s.expiring > 0) '${s.expiring} expiring soon',
                  if (s.expired > 0) '${s.expired} expired',
                ].join(' · ')),
                trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                  Text('${s.available}', style: t.textTheme.titleMedium?.copyWith(color: s.outOfStock ? cs.error : null)),
                  Text(s.outOfStock ? 'out' : s.low ? 'low' : 'in stock', style: t.textTheme.labelSmall),
                ]),
              ),
            ),
        ]),
      ),
    );
  }
}

/// Bottom-navigation shell shared by the staff roles.
class StaffShell extends ConsumerStatefulWidget {
  const StaffShell({super.key, required this.tabs});
  final List<(String, IconData, Widget)> tabs;
  @override
  ConsumerState<StaffShell> createState() => _StaffShellState();
}

class _StaffShellState extends ConsumerState<StaffShell> {
  int _i = 0;
  @override
  Widget build(BuildContext context) {
    final sync = ref.watch(syncEngineProvider);
    return Scaffold(
      body: FadeIndexedStack(index: _i, children: [for (final t in widget.tabs) t.$3]),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _i,
        onDestinationSelected: (i) => setState(() => _i = i),
        destinations: [
          for (final (i, t) in widget.tabs.indexed)
            NavigationDestination(
              icon: i == widget.tabs.length - 1 ? Badge(isLabelVisible: sync.pending > 0, label: Text('${sync.pending}'), child: Icon(t.$2)) : Icon(t.$2),
              label: t.$1,
            ),
        ],
      ),
    );
  }
}
