import 'package:flutter/material.dart';

import '../../../core/ui/line_chart.dart';
import '../../../core/ui/widgets.dart';
import '../ui/doctor_providers.dart';
import 'followups.dart';

double? _num(String? s) => s == null ? null : double.tryParse(RegExp(r'-?[0-9]+(\.[0-9]+)?').firstMatch(s)?.group(0) ?? '');

/// Time series of numeric vitals for a patient, from visit/admission vitals and recorded vitals.
/// Blood pressure "120/80" is split into systolic and diastolic.
Map<String, List<(DateTime, double)>> vitalSeries(PatientDetail d) {
  final out = <String, List<(DateTime, double)>>{};
  void add(String k, DateTime? at, double? v) {
    if (at == null || v == null) return;
    out.putIfAbsent(k, () => []).add((at, v));
  }

  void bp(DateTime? at, String? s) {
    final m = RegExp(r'(\d{2,3})\s*/\s*(\d{2,3})').firstMatch(s ?? '');
    if (m == null) return;
    add('BP systolic', at, double.parse(m.group(1)!));
    add('BP diastolic', at, double.parse(m.group(2)!));
  }

  for (final e in d.encounters) {
    final v = e.vitals;
    bp(e.date, v.bp);
    add('Pulse', e.date, _num(v.pulse));
    add('Weight', e.date, _num(v.weight));
    add('Temperature', e.date, _num(v.temperature));
    add('Respiration', e.date, _num(v.respiration));
  }
  for (final v in d.vitals) {
    final name = v.name.trim();
    if (RegExp(r'\b(bp|blood pressure)\b', caseSensitive: false).hasMatch(name)) {
      bp(v.measuredAt, v.value);
    } else {
      add(name, v.measuredAt, _num(v.value));
    }
  }
  for (final s in out.values) {
    s.sort((a, b) => a.$1.compareTo(b.$1));
  }
  return out;
}

class VitalsTrendCard extends StatelessWidget {
  const VitalsTrendCard({super.key, required this.d});
  final PatientDetail d;

  @override
  Widget build(BuildContext context) {
    final s = vitalSeries(d);
    final sys = s['BP systolic'], dia = s['BP diastolic'];
    final latestBp = sys == null || dia == null ? null : '${sys.last.$2.round()}/${dia.last.$2.round()}';
    final trended = s.entries.where((e) => e.value.length >= 2).length;
    return CareCard(
      icon: Icons.monitor_heart_rounded,
      title: 'Vitals trends',
      subtitle: [if (latestBp != null) 'BP $latestBp', trended == 0 ? 'Not enough readings yet' : '$trended trend${trended == 1 ? '' : 's'}'].join(' · '),
      alert: sys != null && (sys.last.$2 >= 140 || (dia?.last.$2 ?? 0) >= 90),
      onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => VitalsTrendsScreen(d: d))),
      child: sys != null && sys.length >= 2
          ? LineChart(
              height: 100,
              series: [
                ChartSeries(label: 'Systolic', points: [for (final p in sys) (p.$1.millisecondsSinceEpoch.toDouble(), p.$2)]),
                if (dia != null) ChartSeries(label: 'Diastolic', points: [for (final p in dia) (p.$1.millisecondsSinceEpoch.toDouble(), p.$2)]),
              ],
            )
          : null,
    );
  }
}

class VitalsTrendsScreen extends StatelessWidget {
  const VitalsTrendsScreen({super.key, required this.d});
  final PatientDetail d;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final s = vitalSeries(d);
    String fx(double x) => fmtDateShort(DateTime.fromMillisecondsSinceEpoch(x.round()));
    List<(double, double)> pts(List<(DateTime, double)> l) => [for (final p in l) (p.$1.millisecondsSinceEpoch.toDouble(), p.$2)];

    final charts = <(String, List<ChartSeries>, String)>[];
    if ((s['BP systolic']?.length ?? 0) >= 2) {
      charts.add(('Blood pressure', [
        ChartSeries(label: 'Systolic', points: pts(s['BP systolic']!)),
        if (s['BP diastolic'] != null) ChartSeries(label: 'Diastolic', points: pts(s['BP diastolic']!)),
      ], 'mmHg'));
    }
    for (final e in s.entries) {
      if (e.key.startsWith('BP ') || e.value.length < 2) continue;
      charts.add((e.key, [ChartSeries(label: e.key, points: pts(e.value))], ''));
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Vitals trends')),
      body: charts.isEmpty
          ? const EmptyState(icon: Icons.monitor_heart_rounded, title: 'Not enough readings', message: 'Trends appear once a vital has been recorded at least twice.')
          : ListView.separated(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
              itemCount: charts.length,
              separatorBuilder: (_, _) => const SizedBox(height: 12),
              itemBuilder: (_, i) {
                final c = charts[i];
                final last = c.$2.first.points.last;
                return FadeSlideIn(
                  index: i,
                  child: Card(
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          Expanded(child: Text(c.$1, style: t.textTheme.titleSmall)),
                          Text(
                            c.$2.length == 2 ? '${c.$2[0].points.last.$2.round()}/${c.$2[1].points.last.$2.round()} ${c.$3}' : '${last.$2 % 1 == 0 ? last.$2.round() : last.$2} ${c.$3}',
                            style: t.textTheme.titleMedium?.copyWith(color: t.colorScheme.primary),
                          ),
                        ]),
                        const SizedBox(height: 10),
                        LineChart(series: c.$2, height: 150, formatX: fx),
                      ]),
                    ),
                  ),
                );
              },
            ),
    );
  }
}
