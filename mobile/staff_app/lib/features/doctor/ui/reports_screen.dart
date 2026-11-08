import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../core/providers.dart';
import '../../../core/ui/report_files.dart';
import '../../../core/ui/widgets.dart';
import '../data/doctor_repository.dart';
import '../data/lab_flags.dart';
import '../data/models.dart';
import 'doctor_providers.dart';
import 'insights_view.dart';
import 'patient_screen.dart';
import 'rx_widgets.dart';

final reportsInboxProvider = FutureProvider.autoDispose<List<ReportEntry>>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(doctorRepoProvider).reportsInbox();
});

/// Number of new (unseen, recently reported) reports, for the navigation badge.
final newReportsCountProvider = Provider.autoDispose<int>((ref) => ref.watch(reportsInboxProvider).valueOrNull?.where((e) => e.isNew).length ?? 0);

/// Open a report's uploaded document through the doctor API.
void openDoctorReportFile(BuildContext context, WidgetRef ref, LabReport r) =>
    ReportFiles.open(context, ref.read(apiProvider), 'patients/report_file', kind: r.kind, id: r.id, name: r.fileName);

enum _Filter { all, newOnly, abnormal, awaiting, lab, imaging }

/// Medical reports inbox: lab and imaging results for all patients on this phone.
class ReportsScreen extends ConsumerStatefulWidget {
  const ReportsScreen({super.key, required this.onOpenSyncDetails});
  final VoidCallback onOpenSyncDetails;

  @override
  ConsumerState<ReportsScreen> createState() => _ReportsScreenState();
}

class _ReportsScreenState extends ConsumerState<ReportsScreen> {
  _Filter _filter = _Filter.all;
  bool _insights = false;
  String _q = '';
  Timer? _debounce;

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  bool _matches(ReportEntry e) {
    final r = e.report;
    final ok = switch (_filter) {
      _Filter.all => true,
      _Filter.newOnly => e.isNew,
      _Filter.abnormal => r.isAbnormal,
      _Filter.awaiting => !r.isReported,
      _Filter.lab => r.kind == 'pathology',
      _Filter.imaging => r.kind == 'radiology',
    };
    if (!ok) return false;
    if (_q.isEmpty) return true;
    final hay = '${e.patient?.name ?? ''} ${r.testName} ${r.shortName ?? ''} ${e.patient?.id ?? ''}'.toLowerCase();
    return _q.toLowerCase().split(RegExp(r'\s+')).every(hay.contains);
  }

  String _group(ReportEntry e) {
    final d = e.report.reportingDate;
    if (!e.report.isReported || d == null) return 'Awaiting results';
    final now = DateTime.now();
    final days = DateTime(now.year, now.month, now.day).difference(DateTime(d.year, d.month, d.day)).inDays;
    if (days <= 0) return 'Today';
    if (days == 1) return 'Yesterday';
    if (days < 7) return 'This week';
    return DateFormat('MMMM yyyy').format(d);
  }

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final inbox = ref.watch(reportsInboxProvider);
    final all = inbox.valueOrNull ?? const <ReportEntry>[];
    final counts = {
      _Filter.newOnly: all.where((e) => e.isNew).length,
      _Filter.abnormal: all.where((e) => e.report.isAbnormal).length,
      _Filter.awaiting: all.where((e) => !e.report.isReported).length,
    };
    String label(_Filter f) => switch (f) {
      _Filter.all => 'All',
      _Filter.newOnly => 'New',
      _Filter.abnormal => 'Abnormal',
      _Filter.awaiting => 'Awaiting',
      _Filter.lab => 'Lab',
      _Filter.imaging => 'Imaging',
    };

    return Scaffold(
      appBar: AppBar(
        title: Text(_insights ? 'Insights' : 'Medical reports'),
        toolbarHeight: 64,
        actions: [
          if (!_insights && (counts[_Filter.newOnly] ?? 0) > 0)
            TextButton(
              onPressed: () async {
                await ref.read(doctorRepoProvider).markReportsSeen(all.where((e) => e.isNew).map((e) => e.report.key));
                ref.read(dataRevisionProvider.notifier).state++;
              },
              child: const Text('Mark all seen'),
            ),
        ],
      ),
      body: Column(
        children: [
          SyncBanner(onOpenDetails: widget.onOpenSyncDetails),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 0),
            child: SegmentedButton<bool>(
              showSelectedIcon: false,
              segments: const [
                ButtonSegment(value: false, icon: Icon(Icons.biotech_rounded), label: Text('Results')),
                ButtonSegment(value: true, icon: Icon(Icons.insights_rounded), label: Text('Insights')),
              ],
              selected: {_insights},
              onSelectionChanged: (v) => setState(() => _insights = v.first),
            ),
          ),
          if (_insights)
            const Expanded(child: InsightsView())
          else ...[
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
              child: TextField(
                onChanged: (v) {
                  _debounce?.cancel();
                  _debounce = Timer(const Duration(milliseconds: 200), () => setState(() => _q = v.trim()));
                },
                decoration: InputDecoration(
                  hintText: 'Search patient or test',
                  prefixIcon: const Icon(Icons.search_rounded),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(40), borderSide: BorderSide.none),
                  enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(40), borderSide: BorderSide.none),
                ),
              ),
            ),
            SizedBox(
              height: 48,
              child: ListView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
                children: [
                  for (final f in _Filter.values)
                    Padding(
                      padding: const EdgeInsets.only(right: 8),
                      child: FilterChip(
                        label: Text(counts[f] == null || counts[f] == 0 ? label(f) : '${label(f)} ${counts[f]}'),
                        selected: _filter == f,
                        showCheckmark: false,
                        onSelected: (_) {
                          HapticFeedback.selectionClick();
                          setState(() => _filter = f);
                        },
                      ),
                    ),
                ],
              ),
            ),
            Expanded(
              child: RefreshIndicator(
                onRefresh: () => ref.read(syncEngineProvider.notifier).sync(),
                child: inbox.when(
                  loading: () => const SkeletonList(),
                  error: (e, _) => ListView(
                    children: [EmptyState(icon: Icons.error_outline_rounded, title: 'Could not load reports', message: '$e')],
                  ),
                  data: (_) {
                    final list = all.where(_matches).toList();
                    if (list.isEmpty) {
                      return ListView(
                        children: [
                          EmptyState(
                            icon: Icons.biotech_rounded,
                            title: all.isEmpty ? 'No reports yet' : 'Nothing matches',
                            message: all.isEmpty ? 'Lab and imaging results for your patients appear here as they are reported.' : null,
                          ),
                        ],
                      );
                    }
                    final children = <Widget>[];
                    String? group;
                    var i = 0;
                    for (final e in list) {
                      final g = _group(e);
                      if (g != group) {
                        group = g;
                        children.add(SectionHeader(g, padding: const EdgeInsets.fromLTRB(4, 14, 0, 8)));
                      }
                      children.add(
                        Padding(
                          padding: const EdgeInsets.only(bottom: 10),
                          child: FadeSlideIn(
                            index: i++,
                            child: _ReportRow(entry: e),
                          ),
                        ),
                      );
                    }
                    return ListView(physics: const AlwaysScrollableScrollPhysics(), padding: const EdgeInsets.fromLTRB(16, 0, 16, 24), children: children);
                  },
                ),
              ),
            ),
            if (inbox.hasValue)
              Padding(
                padding: const EdgeInsets.only(bottom: 4),
                child: Text(
                  'Values outside the reference range are flagged automatically.',
                  style: t.textTheme.labelSmall?.copyWith(color: t.colorScheme.onSurfaceVariant),
                ),
              ),
          ],
        ],
      ),
    );
  }
}

class _ReportRow extends ConsumerWidget {
  const _ReportRow({required this.entry});
  final ReportEntry entry;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final r = entry.report;
    final p = entry.patient;
    final abnormal = r.abnormal;
    return Card(
      child: Pressable(
        onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => ReportDetailScreen(entry: entry))),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Stack(
                clipBehavior: Clip.none,
                children: [
                  IconBadge(
                    r.kind == 'radiology' ? Icons.monitor_heart_rounded : Icons.biotech_rounded,
                    size: 42,
                    color: abnormal.isNotEmpty
                        ? cs.error
                        : r.kind == 'radiology'
                        ? cs.tertiary
                        : cs.primary,
                  ),
                  if (entry.isNew)
                    Positioned(
                      right: -2,
                      top: -2,
                      child: Container(
                        width: 12,
                        height: 12,
                        decoration: BoxDecoration(
                          color: cs.primary,
                          shape: BoxShape.circle,
                          border: Border.all(color: cs.surface, width: 2),
                        ),
                      ),
                    ),
                ],
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            r.testName,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: t.textTheme.titleSmall?.copyWith(fontWeight: entry.isNew ? FontWeight.w800 : null),
                          ),
                        ),
                        if (r.hasFile)
                          Padding(
                            padding: const EdgeInsets.only(left: 6),
                            child: Icon(Icons.attach_file_rounded, size: 16, color: cs.outline),
                          ),
                      ],
                    ),
                    Text(
                      [p?.name ?? 'Patient #${r.patientId}', if (p?.subtitle.isNotEmpty == true) p!.subtitle].join(' · '),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: t.textTheme.bodySmall?.copyWith(color: cs.onSurfaceVariant),
                    ),
                    const SizedBox(height: 6),
                    if (!r.isReported)
                      StatusPill(
                        r.collectionDate == null ? 'Awaiting result' : 'Collected ${fmtDateShort(r.collectionDate)}',
                        bg: cs.surfaceContainerHighest,
                        fg: cs.onSurfaceVariant,
                        icon: Icons.hourglass_top_rounded,
                      )
                    else if (abnormal.isEmpty)
                      StatusPill(
                        r.parameters.any((x) => flagFor(x.value, x.referenceRange) == LabFlag.normal)
                            ? 'Within range'
                            : 'Reported ${fmtDateShort(r.reportingDate)}',
                        bg: cs.tertiaryContainer,
                        fg: cs.onTertiaryContainer,
                        icon: Icons.check_rounded,
                      )
                    else
                      Wrap(
                        spacing: 6,
                        runSpacing: 6,
                        children: [
                          for (final (x, f) in abnormal.take(3))
                            StatusPill(
                              '${x.name} ${x.value} · ${f == LabFlag.high ? 'High' : 'Low'}',
                              bg: f == LabFlag.high ? cs.errorContainer : cs.secondaryContainer,
                              fg: f == LabFlag.high ? cs.onErrorContainer : cs.onSecondaryContainer,
                            ),
                          if (abnormal.length > 3) StatusPill('+${abnormal.length - 3}', bg: cs.errorContainer, fg: cs.onErrorContainer),
                        ],
                      ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// One report with its patient's context and their other reports.
class ReportDetailScreen extends ConsumerStatefulWidget {
  const ReportDetailScreen({super.key, required this.entry});
  final ReportEntry entry;

  @override
  ConsumerState<ReportDetailScreen> createState() => _ReportDetailScreenState();
}

class _ReportDetailScreenState extends ConsumerState<ReportDetailScreen> {
  @override
  void initState() {
    super.initState();
    if (widget.entry.isNew) {
      Future.microtask(() async {
        await ref.read(doctorRepoProvider).markReportsSeen([widget.entry.report.key]);
        if (mounted) ref.read(dataRevisionProvider.notifier).state++;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final r = widget.entry.report;
    final pid = r.patientId;
    final detail = pid == null ? null : ref.watch(patientDetailProvider(pid)).valueOrNull;
    final p = detail?.patient ?? widget.entry.patient;
    final others = detail?.labs.where((x) => x.key != r.key).take(6).toList() ?? const <LabReport>[];

    return Scaffold(
      appBar: AppBar(title: Text(r.kind == 'radiology' ? 'Imaging report' : 'Lab report')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
        children: [
          if (p != null)
            FadeSlideIn(
              child: Card(
                child: Pressable(
                  onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => PatientScreen(patientId: p.id))),
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(
                      children: [
                        Row(
                          children: [
                            PatientAvatar(name: p.name, radius: 22),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(p.name, style: t.textTheme.titleMedium),
                                  Text(['ID ${p.id}', if (p.subtitle.isNotEmpty) p.subtitle].join(' · '), style: t.textTheme.bodySmall),
                                ],
                              ),
                            ),
                            const Icon(Icons.chevron_right_rounded),
                          ],
                        ),
                        if (detail != null && detail.allergies.isNotEmpty) ...[
                          const SizedBox(height: 10),
                          AllergyBanner(allergies: detail.allergies, dense: true),
                        ],
                      ],
                    ),
                  ),
                ),
              ),
            ),
          const SizedBox(height: 12),
          FadeSlideIn(
            index: 1,
            child: LabReportCard(report: r, initiallyExpanded: true, onOpenFile: () => openDoctorReportFile(context, ref, r)),
          ),
          if (others.isNotEmpty) ...[
            const SectionHeader('Other reports for this patient', padding: EdgeInsets.fromLTRB(4, 20, 0, 8)),
            for (final (i, o) in others.indexed)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: FadeSlideIn(
                  index: i + 2,
                  child: LabReportCard(report: o, onOpenFile: () => openDoctorReportFile(context, ref, o)),
                ),
              ),
          ],
          if (p != null) ...[
            const SizedBox(height: 8),
            FilledButton.icon(
              style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
              onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => PatientScreen(patientId: p.id))),
              icon: const Icon(Icons.person_rounded),
              label: const Text('Open patient & prescribe'),
            ),
          ],
        ],
      ),
    );
  }
}
