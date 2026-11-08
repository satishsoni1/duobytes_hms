import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/ui/line_chart.dart';
import '../../../core/ui/theme.dart';
import '../../../core/ui/widgets.dart';
import '../data/clinical.dart';
import '../ui/doctor_providers.dart';
import 'followups.dart';
import 'specialty.dart';
import 'vaccine_schedule.dart';

String ageText(int days) {
  if (days < 0) return '—';
  if (days < 7 * 8) return '${days ~/ 7} wk';
  final months = days / 30.44;
  if (months < 24) return '${months.floor()} mo';
  final y = days ~/ 365.25, m = ((days - y * 365.25) / 30.44).floor();
  return m == 0 ? '$y y' : '$y y $m mo';
}

// ================================================================== vaccination
class VaccinationCard extends StatelessWidget {
  const VaccinationCard({super.key, required this.d, required this.records});
  final PatientDetail d;
  final List<ClinicalRecord> records;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final st = vaccineStatuses(d.patient.dobOrEstimate, records);
    final given = st.where((s) => s.state == VaccineState.given).length;
    final overdue = st.where((s) => s.state == VaccineState.overdue).length;
    final due = st.where((s) => s.state == VaccineState.due).toList();
    return CareCard(
      icon: Icons.vaccines_rounded,
      title: 'Vaccination chart',
      subtitle: overdue > 0 ? '$overdue overdue · ${due.length} due now' : due.isNotEmpty ? '${due.length} due now' : 'Up to date',
      alert: overdue > 0,
      onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => VaccinationScreen(patientId: d.patient.id))),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(8),
          child: TweenAnimationBuilder<double>(
            tween: Tween(begin: 0, end: st.isEmpty ? 0 : given / st.length),
            duration: Motion.slow,
            curve: Motion.emphasized,
            builder: (_, v, _) => LinearProgressIndicator(value: v, minHeight: 8, backgroundColor: cs.surfaceContainerHighest, color: cs.tertiary),
          ),
        ),
        const SizedBox(height: 6),
        Text('$given of ${st.length} scheduled doses given', style: Theme.of(context).textTheme.labelSmall),
        if (due.isNotEmpty) ...[
          const SizedBox(height: 6),
          Wrap(spacing: 6, runSpacing: 6, children: [
            for (final s in due.take(6)) StatusPill('${s.dose.vaccine} ${s.dose.dose}', bg: cs.secondaryContainer, fg: cs.onSecondaryContainer),
          ]),
        ],
      ]),
    );
  }
}

class VaccinationScreen extends ConsumerWidget {
  const VaccinationScreen({super.key, required this.patientId});
  final int patientId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(patientDetailProvider(patientId)).valueOrNull;
    final records = ref.watch(clinicalRecordsProvider(patientId)).valueOrNull;
    if (detail == null || records == null) return Scaffold(appBar: AppBar(), body: const SkeletonList());
    final dob = detail.patient.dobOrEstimate;
    final st = vaccineStatuses(dob, records);
    final others = otherVaccinations(records);
    final groups = <String, List<VaccineStatus>>{};
    for (final s in st) {
      groups.putIfAbsent(s.dose.ageLabel, () => []).add(s);
    }
    final t = Theme.of(context);
    var i = 0;

    return Scaffold(
      appBar: AppBar(title: const Text('Vaccination chart')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _recordDose(context, ref, detail, null),
        icon: const Icon(Icons.add_rounded),
        label: const Text('Add other vaccine'),
      ),
      body: ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 100), children: [
        Text('${detail.patient.name} · ${detail.patient.dob != null ? 'born ${fmtDate(detail.patient.dob)}' : 'date of birth not recorded — due dates estimated from age'}',
            style: t.textTheme.bodySmall?.copyWith(color: t.colorScheme.onSurfaceVariant)),
        const SizedBox(height: 4),
        Text('Schedule based on IAP recommendations. Check against your hospital protocol.', style: t.textTheme.labelSmall?.copyWith(color: t.colorScheme.onSurfaceVariant)),
        const SizedBox(height: 8),
        Wrap(spacing: 8, runSpacing: 6, children: [
          for (final s in VaccineState.values) _legend(context, s),
        ]),
        for (final g in groups.entries) ...[
          SectionHeader(g.key, padding: const EdgeInsets.fromLTRB(4, 18, 0, 8)),
          FadeSlideIn(
            index: i++,
            child: Card(
              child: Column(children: [
                for (final s in g.value)
                  ListTile(
                    leading: _stateIcon(context, s.state),
                    title: Text('${s.dose.vaccine} · ${s.dose.dose}'),
                    subtitle: Text(s.record != null
                        ? 'Given ${fmtDate(s.record!.eventDate)}${s.record!.str('batch_no') != null ? ' · batch ${s.record!.str('batch_no')}' : ''}'
                        : s.dueDate == null
                            ? 'Due at ${s.dose.ageLabel}'
                            : 'Due ${fmtDate(s.dueDate)}'),
                    trailing: s.record?.pending == true ? Icon(Icons.cloud_upload_outlined, size: 16, color: t.colorScheme.outline) : null,
                    onTap: () => _recordDose(context, ref, detail, s.dose, existing: s.record),
                  ),
              ]),
            ),
          ),
        ],
        if (others.isNotEmpty) ...[
          const SectionHeader('Other vaccines', padding: EdgeInsets.fromLTRB(4, 18, 0, 8)),
          Card(
            child: Column(children: [
              for (final r in others)
                ListTile(
                  leading: _stateIcon(context, VaccineState.given),
                  title: Text('${r.str('vaccine')}${r.str('dose') != null ? ' · ${r.str('dose')}' : ''}'),
                  subtitle: Text('Given ${fmtDate(r.eventDate)}'),
                  onTap: () => _recordDose(context, ref, detail, null, existing: r),
                ),
            ]),
          ),
        ],
      ]),
    );
  }

  Widget _legend(BuildContext context, VaccineState s) => Row(mainAxisSize: MainAxisSize.min, children: [
        SizedBox(width: 18, height: 18, child: FittedBox(child: _stateIcon(context, s))),
        const SizedBox(width: 4),
        Text(_stateName(s), style: Theme.of(context).textTheme.labelSmall),
      ]);

  static String _stateName(VaccineState s) => switch (s) {
        VaccineState.given => 'Given',
        VaccineState.overdue => 'Overdue',
        VaccineState.due => 'Due now',
        VaccineState.upcoming => 'Upcoming',
      };

  static Widget _stateIcon(BuildContext context, VaccineState s) {
    final cs = Theme.of(context).colorScheme;
    final (icon, color) = switch (s) {
      VaccineState.given => (Icons.check_circle_rounded, cs.tertiary),
      VaccineState.overdue => (Icons.error_rounded, cs.error),
      VaccineState.due => (Icons.notifications_active_rounded, cs.secondary),
      VaccineState.upcoming => (Icons.radio_button_unchecked_rounded, cs.outline),
    };
    return Icon(icon, color: color);
  }

  Future<void> _recordDose(BuildContext context, WidgetRef ref, PatientDetail d, VaccineDose? dose, {ClinicalRecord? existing}) async {
    final vaccine = TextEditingController(text: existing?.str('vaccine') ?? dose?.vaccine ?? '');
    final doseNo = TextEditingController(text: existing?.str('dose') ?? dose?.dose ?? '');
    final batch = TextEditingController(text: existing?.str('batch_no') ?? '');
    final note = TextEditingController(text: existing?.str('note') ?? '');
    var given = existing?.eventDate ?? DateTime.now();
    var external = existing?.data['external'] == true;
    final result = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setState) => Padding(
          padding: EdgeInsets.fromLTRB(20, 0, 20, MediaQuery.viewInsetsOf(ctx).bottom + 20),
          child: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Text(existing == null ? 'Record vaccination' : 'Vaccination', style: Theme.of(ctx).textTheme.titleLarge),
              const SizedBox(height: 14),
              Row(children: [
                Expanded(flex: 3, child: TextField(controller: vaccine, enabled: dose == null, decoration: const InputDecoration(labelText: 'Vaccine'))),
                const SizedBox(width: 10),
                Expanded(flex: 2, child: TextField(controller: doseNo, enabled: dose == null, decoration: const InputDecoration(labelText: 'Dose'))),
              ]),
              const SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: () async {
                  final p = await showDatePicker(context: ctx, initialDate: given, firstDate: d.patient.dob ?? DateTime(1990), lastDate: DateTime.now());
                  if (p != null) setState(() => given = p);
                },
                icon: const Icon(Icons.event_rounded),
                label: Text('Given on ${fmtDate(given)}'),
              ),
              const SizedBox(height: 12),
              TextField(controller: batch, decoration: const InputDecoration(labelText: 'Batch / lot number (optional)')),
              const SizedBox(height: 12),
              TextField(controller: note, decoration: const InputDecoration(labelText: 'Note (optional)')),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                value: external,
                onChanged: (v) => setState(() => external = v),
                title: const Text('Given elsewhere'),
                subtitle: const Text('Recorded from the vaccination card'),
              ),
              const SizedBox(height: 8),
              FilledButton.icon(
                style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
                onPressed: () => vaccine.text.trim().isEmpty ? null : Navigator.pop(ctx, 'save'),
                icon: const Icon(Icons.check_rounded),
                label: const Text('Save'),
              ),
              if (existing != null)
                TextButton(onPressed: () => Navigator.pop(ctx, 'delete'), child: Text('Remove this record', style: TextStyle(color: Theme.of(ctx).colorScheme.error))),
            ]),
          ),
        ),
      ),
    );
    if (result == null) return;
    if (result == 'delete' && existing != null) {
      await deleteClinicalRecord(ref, existing);
      return;
    }
    final r = existing ?? ClinicalRecord.create(patientId: d.patient.id, type: RecordType.vaccination);
    r.eventDate = given;
    r.status = 'given';
    r.data
      ..['vaccine'] = vaccine.text.trim()
      ..['dose'] = doseNo.text.trim()
      ..['given_on'] = ymd(given)
      ..['batch_no'] = batch.text.trim()
      ..['note'] = note.text.trim()
      ..['external'] = external;
    HapticFeedback.lightImpact();
    await saveClinicalRecord(ref, r);
  }
}

// ================================================================== growth
class GrowthCard extends StatelessWidget {
  const GrowthCard({super.key, required this.d, required this.records});
  final PatientDetail d;
  final List<ClinicalRecord> records;

  @override
  Widget build(BuildContext context) {
    final g = records.where((r) => r.type == RecordType.growth).toList()..sort((a, b) => (a.eventDate ?? DateTime(0)).compareTo(b.eventDate ?? DateTime(0)));
    final last = g.lastOrNull;
    final dob = d.patient.dobOrEstimate;
    final weights = [
      for (final r in g)
        if (r.num_('weight_kg') != null && r.eventDate != null && dob != null) (r.eventDate!.difference(dob).inDays / 30.44, r.num_('weight_kg')!),
    ];
    return CareCard(
      icon: Icons.child_care_rounded,
      title: 'Growth',
      subtitle: last == null
          ? 'No measurements yet'
          : [
              if (last.num_('weight_kg') != null) '${_n(last.num_('weight_kg')!)} kg',
              if (last.num_('height_cm') != null) '${_n(last.num_('height_cm')!)} cm',
              if (last.num_('head_cm') != null) 'HC ${_n(last.num_('head_cm')!)} cm',
              fmtDateShort(last.eventDate),
            ].join(' · '),
      onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => GrowthScreen(patientId: d.patient.id))),
      child: weights.length >= 2 ? LineChart(series: [ChartSeries(label: 'Weight', points: weights)], height: 100, yUnit: 'kg') : null,
    );
  }
}

String _n(double v) => v == v.roundToDouble() ? v.toStringAsFixed(0) : v.toStringAsFixed(1);

class GrowthScreen extends ConsumerWidget {
  const GrowthScreen({super.key, required this.patientId});
  final int patientId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(patientDetailProvider(patientId)).valueOrNull;
    final records = ref.watch(clinicalRecordsProvider(patientId)).valueOrNull;
    if (detail == null || records == null) return Scaffold(appBar: AppBar(), body: const SkeletonList());
    final t = Theme.of(context);
    final dob = detail.patient.dobOrEstimate;
    final g = records.where((r) => r.type == RecordType.growth && r.eventDate != null).toList()..sort((a, b) => a.eventDate!.compareTo(b.eventDate!));
    List<(double, double)> series(String k) => [
          for (final r in g)
            if (r.num_(k) != null && dob != null) (r.eventDate!.difference(dob).inDays / 30.44, r.num_(k)!),
        ];
    String months(double m) => m < 24 ? '${m.round()} mo' : '${(m / 12).toStringAsFixed(1)} y';
    final charts = [('Weight', 'weight_kg', 'kg'), ('Height / length', 'height_cm', 'cm'), ('Head circumference', 'head_cm', 'cm')];

    return Scaffold(
      appBar: AppBar(title: const Text('Growth')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _addMeasurement(context, ref, detail.patient.id, null),
        icon: const Icon(Icons.straighten_rounded),
        label: const Text('Add measurement'),
      ),
      body: ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 100), children: [
        if (g.isEmpty) const EmptyState(icon: Icons.child_care_rounded, title: 'No measurements yet', message: 'Add weight, length/height and head circumference at each visit.'),
        for (final (i, c) in charts.indexed)
          if (series(c.$2).length >= 2)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: FadeSlideIn(
                index: i,
                child: Card(
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text('${c.$1} (${c.$3})', style: t.textTheme.titleSmall),
                      const SizedBox(height: 10),
                      LineChart(series: [ChartSeries(label: c.$1, points: series(c.$2))], height: 150, xLabel: 'Age', formatX: months),
                    ]),
                  ),
                ),
              ),
            ),
        if (g.isNotEmpty) ...[
          const SectionHeader('Measurements', padding: EdgeInsets.fromLTRB(4, 8, 0, 8)),
          Text('Reference percentile curves (WHO/IAP) are not drawn; compare against your growth charts.', style: t.textTheme.labelSmall?.copyWith(color: t.colorScheme.onSurfaceVariant)),
          const SizedBox(height: 8),
          Card(
            child: Column(children: [
              for (final r in g.reversed)
                ListTile(
                  leading: const IconBadge(Icons.straighten_rounded, size: 32),
                  title: Text([
                    if (r.num_('weight_kg') != null) '${_n(r.num_('weight_kg')!)} kg',
                    if (r.num_('height_cm') != null) '${_n(r.num_('height_cm')!)} cm',
                    if (r.num_('head_cm') != null) 'HC ${_n(r.num_('head_cm')!)}',
                    if (r.num_('muac_cm') != null) 'MUAC ${_n(r.num_('muac_cm')!)}',
                  ].join(' · ')),
                  subtitle: Text('${fmtDate(r.eventDate)}${dob != null ? ' · age ${ageText(r.eventDate!.difference(dob).inDays)}' : ''}${_bmi(r)}'),
                  onTap: () => _addMeasurement(context, ref, detail.patient.id, r),
                ),
            ]),
          ),
        ],
      ]),
    );
  }

  String _bmi(ClinicalRecord r) {
    final w = r.num_('weight_kg'), h = r.num_('height_cm');
    if (w == null || h == null || h <= 0) return '';
    return ' · BMI ${(w / ((h / 100) * (h / 100))).toStringAsFixed(1)}';
  }

  Future<void> _addMeasurement(BuildContext context, WidgetRef ref, int patientId, ClinicalRecord? existing) async {
    final c = {for (final k in const ['weight_kg', 'height_cm', 'head_cm', 'muac_cm']) k: TextEditingController(text: existing?.str(k) ?? '')};
    var date = existing?.eventDate ?? DateTime.now();
    final res = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setState) {
          Widget f(String k, String label) => Expanded(
                child: TextField(
                  controller: c[k],
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
                  decoration: InputDecoration(labelText: label),
                ),
              );
          return Padding(
            padding: EdgeInsets.fromLTRB(20, 0, 20, MediaQuery.viewInsetsOf(ctx).bottom + 20),
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Text('Growth measurement', style: Theme.of(ctx).textTheme.titleLarge),
              const SizedBox(height: 14),
              Row(children: [f('weight_kg', 'Weight (kg)'), const SizedBox(width: 10), f('height_cm', 'Length/height (cm)')]),
              const SizedBox(height: 12),
              Row(children: [f('head_cm', 'Head circ. (cm)'), const SizedBox(width: 10), f('muac_cm', 'MUAC (cm)')]),
              const SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: () async {
                  final p = await showDatePicker(context: ctx, initialDate: date, firstDate: DateTime(1990), lastDate: DateTime.now());
                  if (p != null) setState(() => date = p);
                },
                icon: const Icon(Icons.event_rounded),
                label: Text('Measured on ${fmtDate(date)}'),
              ),
              const SizedBox(height: 12),
              FilledButton(style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)), onPressed: () => Navigator.pop(ctx, 'save'), child: const Text('Save')),
              if (existing != null)
                TextButton(onPressed: () => Navigator.pop(ctx, 'delete'), child: Text('Remove', style: TextStyle(color: Theme.of(ctx).colorScheme.error))),
            ]),
          );
        },
      ),
    );
    if (res == null) return;
    if (res == 'delete' && existing != null) {
      await deleteClinicalRecord(ref, existing);
      return;
    }
    final values = {for (final e in c.entries) e.key: double.tryParse(e.value.text.trim())};
    if (values.values.every((v) => v == null)) return;
    final r = existing ?? ClinicalRecord.create(patientId: patientId, type: RecordType.growth);
    r.eventDate = date;
    for (final e in values.entries) {
      r.data[e.key] = e.value;
    }
    await saveClinicalRecord(ref, r);
  }
}

// ================================================================== dose calculator
class DoseCalculatorCard extends StatelessWidget {
  const DoseCalculatorCard({super.key, required this.d, required this.records});
  final PatientDetail d;
  final List<ClinicalRecord> records;

  /// Latest weight: growth record, else the most recent visit/admission weight.
  static double? latestWeight(PatientDetail d, List<ClinicalRecord> records) {
    final g = records.where((r) => r.type == RecordType.growth && r.num_('weight_kg') != null).toList()
      ..sort((a, b) => (b.eventDate ?? DateTime(0)).compareTo(a.eventDate ?? DateTime(0)));
    if (g.isNotEmpty) return g.first.num_('weight_kg');
    for (final e in d.encounters) {
      final w = double.tryParse(RegExp(r'[0-9.]+').firstMatch(e.vitals.weight ?? '')?.group(0) ?? '');
      if (w != null && w > 0) return w;
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    final w = latestWeight(d, records);
    return CareCard(
      icon: Icons.calculate_rounded,
      title: 'Weight-based dose calculator',
      subtitle: w == null ? 'Enter weight to calculate' : 'Using ${_n(w)} kg',
      onTap: () => showModalBottomSheet<void>(context: context, isScrollControlled: true, builder: (_) => _DoseCalculator(weight: w)),
    );
  }
}

class _DoseCalculator extends StatefulWidget {
  const _DoseCalculator({this.weight});
  final double? weight;
  @override
  State<_DoseCalculator> createState() => _DoseCalculatorState();
}

class _DoseCalculatorState extends State<_DoseCalculator> {
  late final _w = TextEditingController(text: widget.weight == null ? '' : _n(widget.weight!));
  final _mgkg = TextEditingController();
  final _max = TextEditingController();
  final _conc = TextEditingController();
  int _perDay = 3;

  double? _v(TextEditingController c) => double.tryParse(c.text.trim());

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final w = _v(_w), mgkg = _v(_mgkg), max = _v(_max), conc = _v(_conc);
    double? dose = (w != null && mgkg != null) ? w * mgkg : null;
    final capped = dose != null && max != null && dose > max;
    if (capped) dose = max;
    Widget field(TextEditingController c, String label, {String? suffix}) => TextField(
          controller: c,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
          onChanged: (_) => setState(() {}),
          decoration: InputDecoration(labelText: label, suffixText: suffix),
        );
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 0, 20, MediaQuery.viewInsetsOf(context).bottom + 20),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text('Dose calculator', style: t.textTheme.titleLarge),
        const SizedBox(height: 4),
        Text('Arithmetic only. Enter the dose from your formulary and verify before prescribing.', style: t.textTheme.bodySmall?.copyWith(color: t.colorScheme.onSurfaceVariant)),
        const SizedBox(height: 14),
        Row(children: [Expanded(child: field(_w, 'Weight', suffix: 'kg')), const SizedBox(width: 10), Expanded(child: field(_mgkg, 'Dose per kg', suffix: 'mg/kg'))]),
        const SizedBox(height: 12),
        Row(children: [Expanded(child: field(_max, 'Max single dose', suffix: 'mg')), const SizedBox(width: 10), Expanded(child: field(_conc, 'Strength', suffix: 'mg/ml'))]),
        const SizedBox(height: 12),
        SegmentedButton<int>(
          showSelectedIcon: false,
          segments: const [ButtonSegment(value: 1, label: Text('OD')), ButtonSegment(value: 2, label: Text('BD')), ButtonSegment(value: 3, label: Text('TDS')), ButtonSegment(value: 4, label: Text('QID'))],
          selected: {_perDay},
          onSelectionChanged: (s) => setState(() => _perDay = s.first),
        ),
        const SizedBox(height: 16),
        AnimatedSwitcher(
          duration: Motion.fast,
          child: dose == null
              ? const SizedBox(height: 60, key: ValueKey('none'))
              : Container(
                  key: ValueKey(dose),
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(color: t.colorScheme.primaryContainer, borderRadius: BorderRadius.circular(Radii.md)),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text('${_n(dose)} mg per dose${conc != null && conc > 0 ? '  =  ${(dose / conc).toStringAsFixed(1)} ml' : ''}',
                        style: t.textTheme.titleLarge?.copyWith(color: t.colorScheme.onPrimaryContainer)),
                    Text('${_n(dose * _perDay)} mg per day ($_perDay × daily)', style: TextStyle(color: t.colorScheme.onPrimaryContainer)),
                    if (capped) Text('Capped at the maximum single dose.', style: TextStyle(color: t.colorScheme.error, fontWeight: FontWeight.w600)),
                  ]),
                ),
        ),
      ]),
    );
  }
}
