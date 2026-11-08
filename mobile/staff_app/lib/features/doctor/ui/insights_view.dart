import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/providers.dart';
import '../../../core/ui/line_chart.dart';
import '../../../core/ui/widgets.dart';
import '../data/doctor_repository.dart';
import 'billing_ui.dart' show money;
import 'doctor_providers.dart';

final insightsProvider = FutureProvider.autoDispose.family<Insights, int>((ref, days) {
  ref.watch(dataRevisionProvider);
  return ref.watch(doctorRepoProvider).insights(days);
});

/// Key reports about the doctor's practice, from data on this phone.
class InsightsView extends ConsumerStatefulWidget {
  const InsightsView({super.key});

  @override
  ConsumerState<InsightsView> createState() => _InsightsViewState();
}

class _InsightsViewState extends ConsumerState<InsightsView> {
  int _days = 30;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final data = ref.watch(insightsProvider(_days));
    return ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 24), children: [
      SegmentedButton<int>(
        showSelectedIcon: false,
        segments: const [ButtonSegment(value: 7, label: Text('7 days')), ButtonSegment(value: 30, label: Text('30 days')), ButtonSegment(value: 90, label: Text('90 days'))],
        selected: {_days},
        onSelectionChanged: (s) => setState(() => _days = s.first),
      ),
      const SizedBox(height: 12),
      ...data.when(
        loading: () => [const SizedBox(height: 300, child: SkeletonList(count: 3))],
        error: (e, _) => [EmptyState(icon: Icons.error_outline_rounded, title: 'Could not calculate', message: '$e')],
        data: (d) {
          var i = 0;
          Widget a(Widget w) => FadeSlideIn(index: i++, child: w);
          return [
            a(GridView(
              gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount: 2,
                mainAxisSpacing: 10,
                crossAxisSpacing: 10,
                // Fixed height: fits real fonts and large text sizes on any screen width.
                mainAxisExtent: 112 * MediaQuery.textScalerOf(context).scale(1).clamp(1.0, 1.6),
              ),
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              children: [
                _Kpi(icon: Icons.medical_services_rounded, value: '${d.visits}', label: 'OPD visits'),
                _Kpi(icon: Icons.people_rounded, value: '${d.patients}', label: 'Patients · ${d.newPatients} new'),
                _Kpi(icon: Icons.receipt_long_rounded, value: '${d.prescriptions}', label: 'Prescriptions · ${d.avgMedicines.toStringAsFixed(1)} meds avg'),
                _Kpi(icon: Icons.add_card_rounded, value: money(d.billed), label: '${d.services} service${d.services == 1 ? '' : 's'} billed'),
                _Kpi(icon: Icons.event_repeat_rounded, value: '${d.followUpsDone}/${d.followUpsScheduled}', label: 'Follow-ups done', alert: d.followUpsOverdue > 0 ? '${d.followUpsOverdue} overdue' : null),
                _Kpi(icon: Icons.biotech_rounded, value: '${d.abnormalReports}/${d.reports}', label: 'Abnormal reports'),
              ],
            )),
            const SectionHeader('Visits per day', padding: EdgeInsets.fromLTRB(4, 20, 0, 8)),
            a(Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: d.visitsPerDay.every((p) => p.$2 == 0)
                    ? const Text('No OPD visits in this period.')
                    : LineChart(
                        height: 150,
                        series: [ChartSeries(label: 'Visits', points: [for (final p in d.visitsPerDay) (p.$1.millisecondsSinceEpoch.toDouble(), p.$2.toDouble())])],
                        formatX: (x) => fmtDateShort(DateTime.fromMillisecondsSinceEpoch(x.round())),
                      ),
              ),
            )),
            _TopList(title: 'Top diagnoses', items: d.topDiagnoses, icon: Icons.local_hospital_rounded),
            _TopList(title: 'Most prescribed medicines', items: d.topMedicines, icon: Icons.medication_rounded),
            _TopList(title: 'Most ordered tests', items: d.topTests, icon: Icons.biotech_rounded),
            const SectionHeader('Patients seen', padding: EdgeInsets.fromLTRB(4, 20, 0, 8)),
            a(Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('Male ${d.male} · Female ${d.female}', style: t.textTheme.titleSmall),
                  const SizedBox(height: 10),
                  for (final e in d.ageBands.entries)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 6),
                      child: Row(children: [
                        SizedBox(width: 52, child: Text(e.key, style: t.textTheme.labelMedium)),
                        Expanded(
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(6),
                            child: TweenAnimationBuilder<double>(
                              tween: Tween(begin: 0, end: d.patients == 0 ? 0 : e.value / d.patients),
                              duration: Motion.slow,
                              curve: Motion.emphasized,
                              builder: (_, v, _) => LinearProgressIndicator(value: v, minHeight: 10, color: cs.primary, backgroundColor: cs.surfaceContainerHighest),
                            ),
                          ),
                        ),
                        SizedBox(width: 32, child: Text('${e.value}', textAlign: TextAlign.right, style: t.textTheme.labelMedium)),
                      ]),
                    ),
                ]),
              ),
            )),
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Text('Calculated on this phone from your patients\' records. Hospital-wide figures are on the web reports.',
                  textAlign: TextAlign.center, style: t.textTheme.labelSmall?.copyWith(color: cs.onSurfaceVariant)),
            ),
          ];
        },
      ),
    ]);
  }
}

class _Kpi extends StatelessWidget {
  const _Kpi({required this.icon, required this.value, required this.label, this.alert});
  final IconData icon;
  final String value;
  final String label;
  final String? alert;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Row(children: [
            IconBadge(icon, size: 30),
            const Spacer(),
            if (alert != null) Flexible(child: StatusPill(alert!, bg: t.colorScheme.errorContainer, fg: t.colorScheme.onErrorContainer)),
          ]),
          FittedBox(alignment: Alignment.centerLeft, child: Text(value, style: t.textTheme.headlineSmall)),
          Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: t.textTheme.labelSmall?.copyWith(color: t.colorScheme.onSurfaceVariant)),
        ]),
      ),
    );
  }
}

class _TopList extends StatelessWidget {
  const _TopList({required this.title, required this.items, required this.icon});
  final String title;
  final List<(String, int)> items;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    if (items.isEmpty) return const SizedBox.shrink();
    final t = Theme.of(context);
    final max = items.first.$2;
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      SectionHeader(title, padding: const EdgeInsets.fromLTRB(4, 20, 0, 8)),
      Card(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(children: [
            for (final (name, n) in items)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    Expanded(child: Text(name, maxLines: 1, overflow: TextOverflow.ellipsis)),
                    Text('$n', style: t.textTheme.labelLarge),
                  ]),
                  const SizedBox(height: 3),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(6),
                    child: LinearProgressIndicator(value: n / max, minHeight: 6, color: t.colorScheme.secondary, backgroundColor: t.colorScheme.surfaceContainerHighest),
                  ),
                ]),
              ),
          ]),
        ),
      ),
    ]);
  }
}
