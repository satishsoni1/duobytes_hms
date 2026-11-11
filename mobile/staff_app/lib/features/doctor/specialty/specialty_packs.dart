import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';

import '../../../core/providers.dart';
import '../../../core/ui/line_chart.dart';
import '../../../core/ui/widgets.dart';
import '../data/clinical.dart';
import '../data/models.dart';
import '../ui/doctor_providers.dart';
import 'followups.dart';
import 'forms/form_spec.dart';
import 'forms/record_form_sheet.dart';
import 'forms/specialty_forms.dart';
import 'specialty.dart';

// ====================================================================== dental
/// FDI notation. Rows as the dentist faces the patient: upper right → upper left, lower right → lower left.
const _adultUpper = [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28];
const _adultLower = [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38];
const _childUpper = [55, 54, 53, 52, 51, 61, 62, 63, 64, 65];
const _childLower = [85, 84, 83, 82, 81, 71, 72, 73, 74, 75];

enum ToothState { none, healthy, finding, planned, done, missing }

/// Latest state of each tooth from its records (missing > planned > finding > done > healthy).
Map<String, ToothState> toothStates(List<ClinicalRecord> records) {
  final out = <String, ToothState>{};
  int rank(ToothState s) => switch (s) {
        ToothState.missing => 5,
        ToothState.planned => 4,
        ToothState.finding => 3,
        ToothState.done => 2,
        ToothState.healthy => 1,
        ToothState.none => 0,
      };
  for (final r in records.where((r) => r.type == SpForms.dental.type)) {
    for (final tooth in (r.str('tooth') ?? '').split(RegExp(r'[,\s]+')).where((s) => s.isNotEmpty)) {
      final finding = r.str('finding');
      final s = finding == 'Missing' || (r.str('procedure') == 'Extraction' && r.status == 'done')
          ? ToothState.missing
          : r.status == 'done'
              ? ToothState.done
              : r.str('procedure') != null && r.str('procedure') != 'Observation'
                  ? ToothState.planned
                  : finding == 'Healthy'
                      ? ToothState.healthy
                      : ToothState.finding;
      if (rank(s) > rank(out[tooth] ?? ToothState.none)) out[tooth] = s;
    }
  }
  return out;
}

class DentalChartCard extends ConsumerWidget {
  const DentalChartCard({super.key, required this.d, required this.records});
  final PatientDetail d;
  final List<ClinicalRecord> records;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final mine = records.where((r) => r.type == SpForms.dental.type).toList();
    final planned = mine.where((r) => r.status == 'active' && r.str('procedure') != null).length;
    final due = mine.fold<double>(0, (s, r) => s + (numberOf(r.data['charge']) ?? 0) - (numberOf(r.data['paid']) ?? 0));
    return CareCard(
      icon: Icons.medical_information_rounded,
      title: 'Dental chart',
      subtitle: [
        mine.isEmpty ? 'No teeth charted yet' : '${mine.length} record${mine.length == 1 ? '' : 's'}',
        if (planned > 0) '$planned planned',
        if (due > 0) '₹${due.round()} balance',
      ].join(' · '),
      onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => DentalChartScreen(patientId: d.patient.id, child: (d.patient.ageYears ?? 30) < 12))),
      child: ToothChart(states: toothStates(records), child: (d.patient.ageYears ?? 30) < 12, compact: true, onTap: (tooth) => _toothSheet(context, ref, d.patient.id, tooth)),
    );
  }
}

class DentalChartScreen extends ConsumerStatefulWidget {
  const DentalChartScreen({super.key, required this.patientId, required this.child});
  final int patientId;
  final bool child;

  @override
  ConsumerState<DentalChartScreen> createState() => _DentalChartScreenState();
}

class _DentalChartScreenState extends ConsumerState<DentalChartScreen> {
  late bool _child = widget.child;

  @override
  Widget build(BuildContext context) {
    final records = ref.watch(clinicalRecordsProvider(widget.patientId)).valueOrNull;
    final t = Theme.of(context);
    if (records == null) return Scaffold(appBar: AppBar(), body: const SkeletonList());
    final mine = records.where((r) => r.type == SpForms.dental.type).toList();
    final charge = mine.fold<double>(0, (s, r) => s + (numberOf(r.data['charge']) ?? 0));
    final paid = mine.fold<double>(0, (s, r) => s + (numberOf(r.data['paid']) ?? 0));
    return Scaffold(
      appBar: AppBar(title: const Text('Dental chart')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => editSpecialtyRecord(context, ref, patientId: widget.patientId, form: SpForms.dental),
        icon: const Icon(Icons.add_rounded),
        label: const Text('Add tooth record'),
      ),
      body: ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 100), children: [
        SegmentedButton<bool>(
          segments: const [ButtonSegment(value: false, label: Text('Permanent')), ButtonSegment(value: true, label: Text('Milk teeth'))],
          selected: {_child},
          onSelectionChanged: (s) => setState(() => _child = s.first),
        ),
        const SizedBox(height: 12),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: ToothChart(states: toothStates(records), child: _child, onTap: (tooth) => _toothSheet(context, ref, widget.patientId, tooth)),
          ),
        ),
        const SizedBox(height: 6),
        const _ToothLegend(),
        if (charge > 0) ...[
          const SizedBox(height: 12),
          Card(
            child: ListTile(
              leading: const Icon(Icons.receipt_long_rounded),
              title: Text('Treatment total ₹${charge.round()}'),
              subtitle: Text('Paid ₹${paid.round()}'),
              trailing: Text('Balance ₹${(charge - paid).round()}', style: t.textTheme.titleSmall?.copyWith(color: charge > paid ? t.colorScheme.error : null)),
            ),
          ),
        ],
        SectionHeader('Treatment plan & history', padding: const EdgeInsets.fromLTRB(4, 20, 0, 8)),
        if (mine.isEmpty) Text('Tap a tooth to record a finding or plan a treatment.', style: t.textTheme.bodyMedium),
        for (final r in mine) SpecialtyRecordTile(form: SpForms.dental, record: r, patientId: widget.patientId),
      ]),
    );
  }
}

Future<void> _toothSheet(BuildContext context, WidgetRef ref, int patientId, int tooth) =>
    editSpecialtyRecord(context, ref, patientId: patientId, form: SpForms.dental, preset: {'tooth': '$tooth'});

class ToothChart extends StatelessWidget {
  const ToothChart({super.key, required this.states, required this.child, required this.onTap, this.compact = false});
  final Map<String, ToothState> states;
  final bool child;
  final bool compact;
  final void Function(int tooth) onTap;

  static Color colorOf(ColorScheme cs, ToothState s) => switch (s) {
        ToothState.finding => cs.error,
        ToothState.planned => Colors.orange.shade700,
        ToothState.done => Colors.green.shade600,
        ToothState.missing => cs.outline,
        ToothState.healthy => cs.primary,
        ToothState.none => cs.surfaceContainerHighest,
      };

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    Widget row(List<int> teeth) => Row(children: [
          for (final (i, n) in teeth.indexed) ...[
            if (i == teeth.length ~/ 2) Container(width: 2, height: compact ? 24 : 34, color: cs.outlineVariant),
            Expanded(
              child: Semantics(
                button: true,
                label: 'Tooth $n ${(states['$n'] ?? ToothState.none).name}',
                child: InkWell(
                  onTap: () => onTap(n),
                  borderRadius: BorderRadius.circular(6),
                  child: Container(
                    margin: const EdgeInsets.all(1.5),
                    height: compact ? 24 : 34,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: (states['$n'] ?? ToothState.none) == ToothState.none ? cs.surfaceContainerHighest : colorOf(cs, states['$n']!).withValues(alpha: 0.18),
                      border: Border.all(color: colorOf(cs, states['$n'] ?? ToothState.none), width: (states['$n'] ?? ToothState.none) == ToothState.none ? 0.5 : 1.6),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: FittedBox(child: Text('$n', style: TextStyle(fontSize: compact ? 9 : 11, fontWeight: FontWeight.w600, color: cs.onSurface))),
                  ),
                ),
              ),
            ),
          ],
        ]);
    return Column(children: [
      row(child ? _childUpper : _adultUpper),
      Divider(height: compact ? 6 : 10, color: cs.outlineVariant),
      row(child ? _childLower : _adultLower),
    ]);
  }
}

class _ToothLegend extends StatelessWidget {
  const _ToothLegend();

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Wrap(spacing: 12, runSpacing: 6, children: [
      for (final (s, l) in const [(ToothState.finding, 'Finding'), (ToothState.planned, 'Planned'), (ToothState.done, 'Done'), (ToothState.missing, 'Missing'), (ToothState.healthy, 'Healthy')])
        Row(mainAxisSize: MainAxisSize.min, children: [
          Container(width: 12, height: 12, decoration: BoxDecoration(color: ToothChart.colorOf(cs, s), borderRadius: BorderRadius.circular(3))),
          const SizedBox(width: 4),
          Text(l, style: Theme.of(context).textTheme.labelSmall),
        ]),
    ]);
  }
}

// ====================================================================== physiotherapy
ClinicalRecord? activePhysioPlan(List<ClinicalRecord> r) => r.where((x) => x.type == SpForms.physioPlan.type && x.status == 'active').firstOrNull;

List<ClinicalRecord> sessionsOf(List<ClinicalRecord> r, ClinicalRecord plan) =>
    r.where((x) => x.type == SpForms.physioSession.type && x.str('plan_uuid') == plan.uuid).toList();

class PhysioCard extends ConsumerWidget {
  const PhysioCard({super.key, required this.d, required this.records});
  final PatientDetail d;
  final List<ClinicalRecord> records;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final cs = Theme.of(context).colorScheme;
    final plan = activePhysioPlan(records);
    final done = plan == null ? 0 : sessionsOf(records, plan).length;
    final total = plan == null ? null : numberOf(plan.data['sessions_total'])?.round();
    return CareCard(
      icon: Icons.accessibility_new_rounded,
      title: 'Physiotherapy',
      subtitle: plan == null ? 'No ongoing therapy plan' : '${plan.str('condition')} · session $done${total == null ? '' : ' of $total'}',
      alert: total != null && done >= total,
      onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => PhysioScreen(patientId: d.patient.id))),
      action: TextButton.icon(
        onPressed: () => plan == null
            ? editSpecialtyRecord(context, ref, patientId: d.patient.id, form: SpForms.physioPlan)
            : editSpecialtyRecord(context, ref, patientId: d.patient.id, form: SpForms.physioSession, preset: {'plan_uuid': plan.uuid, 'session_no': done + 1}),
        icon: const Icon(Icons.add_rounded, size: 18),
        label: Text(plan == null ? 'Plan' : 'Session'),
      ),
      child: total == null || total == 0
          ? null
          : ClipRRect(
              borderRadius: BorderRadius.circular(8),
              child: LinearProgressIndicator(value: (done / total).clamp(0, 1), minHeight: 8, backgroundColor: cs.surfaceContainerHighest),
            ),
    );
  }
}

class PhysioScreen extends ConsumerWidget {
  const PhysioScreen({super.key, required this.patientId});
  final int patientId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final records = ref.watch(clinicalRecordsProvider(patientId)).valueOrNull;
    if (records == null) return Scaffold(appBar: AppBar(), body: const SkeletonList());
    final t = Theme.of(context);
    final plans = records.where((r) => r.type == SpForms.physioPlan.type).toList();
    final active = activePhysioPlan(records);
    String fx(double x) => fmtDateShort(DateTime.fromMillisecondsSinceEpoch(x.round()));
    return Scaffold(
      appBar: AppBar(title: const Text('Physiotherapy')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => active == null
            ? editSpecialtyRecord(context, ref, patientId: patientId, form: SpForms.physioPlan)
            : editSpecialtyRecord(context, ref, patientId: patientId, form: SpForms.physioSession,
                preset: {'plan_uuid': active.uuid, 'session_no': sessionsOf(records, active).length + 1}),
        icon: const Icon(Icons.add_rounded),
        label: Text(active == null ? 'New therapy plan' : 'Record session'),
      ),
      body: plans.isEmpty
          ? const EmptyState(icon: Icons.accessibility_new_rounded, title: 'No therapy plan', message: 'Start a plan with the condition and the number of sessions in the package.')
          : ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 100), children: [
              for (final plan in plans) ...[
                SpecialtyRecordTile(form: SpForms.physioPlan, record: plan, patientId: patientId),
                () {
                  final s = sessionsOf(records, plan);
                  final total = numberOf(plan.data['sessions_total'])?.round();
                  final amount = numberOf(plan.data['package_amount']);
                  final paid = numberOf(plan.data['paid']) ?? 0;
                  final pain = seriesOf(s, 'pain_after');
                  return Padding(
                    padding: const EdgeInsets.fromLTRB(4, 4, 4, 8),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(
                        [
                          '${s.length}${total == null ? '' : ' of $total'} sessions done',
                          if (total != null && s.length < total) '${total - s.length} left',
                          if (amount != null) 'balance ₹${(amount - paid).round()}',
                        ].join(' · '),
                        style: t.textTheme.titleSmall,
                      ),
                      if (pain.length >= 2) ...[
                        const SizedBox(height: 8),
                        Text('Pain after session', style: t.textTheme.labelMedium),
                        LineChart(height: 110, formatX: fx, series: [ChartSeries(label: 'Pain', points: pain)]),
                      ],
                    ]),
                  );
                }(),
                for (final r in sessionsOf(records, plan))
                  Padding(
                    padding: const EdgeInsets.only(left: 12),
                    child: SpecialtyRecordTile(
                      form: SpForms.physioSession,
                      record: r,
                      patientId: patientId,
                      trailing: Text('  #${r.str('session_no') ?? ''}', style: t.textTheme.labelLarge),
                    ),
                  ),
                const Divider(height: 28),
              ],
            ]),
    );
  }
}

// ====================================================================== ophthalmology
class EyeCard extends ConsumerWidget {
  const EyeCard({super.key, required this.d, required this.records});
  final PatientDetail d;
  final List<ClinicalRecord> records;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final latest = records.where((r) => r.type == SpForms.eye.type).firstOrNull;
    final iopHigh = latest != null && ['iop_re', 'iop_le'].any((k) => (numberOf(latest.data[k]) ?? 0) > 21);
    return CareCard(
      icon: Icons.visibility_rounded,
      title: 'Eye examination',
      alert: iopHigh,
      subtitle: latest == null ? 'Nothing recorded yet' : '${fmtDate(latest.eventDate)} · ${SpForms.eye.summaryOf(latest)}${iopHigh ? ' · IOP above 21' : ''}',
      onTap: () => Navigator.push(
        context,
        MaterialPageRoute(
          builder: (_) => SpecialtyHistoryScreen(
            patientId: d.patient.id,
            form: SpForms.eye,
            icon: Icons.visibility_rounded,
            header: (ctx, rs) => rs.isEmpty
                ? const SizedBox.shrink()
                : Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: FilledButton.tonalIcon(
                      onPressed: () => shareSpectacleRx(ctx, ref, d.patient, rs.first),
                      icon: const Icon(Icons.share_rounded),
                      label: const Text('Share spectacle prescription (PDF)'),
                    ),
                  ),
          ),
        ),
      ),
      action: TextButton.icon(
        onPressed: () => editSpecialtyRecord(context, ref, patientId: d.patient.id, form: SpForms.eye),
        icon: const Icon(Icons.add_rounded, size: 18),
        label: const Text('Add'),
      ),
      child: latest == null || (latest.str('sph_re') == null && latest.str('sph_le') == null) ? null : RefractionTable(r: latest),
    );
  }
}

class RefractionTable extends StatelessWidget {
  const RefractionTable({super.key, required this.r});
  final ClinicalRecord r;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    TableRow row(String eye, String s) => TableRow(children: [
          Padding(padding: const EdgeInsets.all(4), child: Text(eye, style: t.labelLarge)),
          for (final k in const ['sph', 'cyl', 'axis', 'add', 'va']) Padding(padding: const EdgeInsets.all(4), child: Text(r.str('${k}_$s') ?? '–', style: t.bodyMedium)),
        ]);
    return Table(
      border: TableBorder.all(color: Theme.of(context).colorScheme.outlineVariant, borderRadius: BorderRadius.circular(6)),
      children: [
        TableRow(children: [for (final h in const ['', 'SPH', 'CYL', 'AXIS', 'ADD', 'VA']) Padding(padding: const EdgeInsets.all(4), child: Text(h, style: t.labelSmall))]),
        row('RE', 're'),
        row('LE', 'le'),
      ],
    );
  }
}

/// A one-page spectacle prescription (A5) shared as PDF (WhatsApp, print, email).
Future<void> shareSpectacleRx(BuildContext context, WidgetRef ref, Patient p, ClinicalRecord r) async {
  final session = ref.read(sessionProvider);
  final doc = pw.Document(title: 'Spectacle prescription ${p.name}');
  String v(String k) => r.str(k) ?? '-';
  doc.addPage(pw.Page(
    pageFormat: PdfPageFormat.a5,
    margin: const pw.EdgeInsets.all(28),
    build: (_) => pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
      pw.Text(session.hospital.name, style: pw.TextStyle(fontSize: 16, fontWeight: pw.FontWeight.bold)),
      if (session.hospital.address != null) pw.Text(session.hospital.address!, style: const pw.TextStyle(fontSize: 9)),
      pw.Divider(),
      pw.Text('Spectacle prescription', style: pw.TextStyle(fontSize: 13, fontWeight: pw.FontWeight.bold)),
      pw.SizedBox(height: 6),
      pw.Text('${p.name}  ·  ${p.subtitle}'),
      pw.Text('Date: ${fmtDate(r.eventDate)}'),
      pw.SizedBox(height: 12),
      pw.TableHelper.fromTextArray(
        headers: const ['', 'SPH', 'CYL', 'AXIS', 'ADD', 'VA'],
        data: [
          ['Right (RE)', v('sph_re'), v('cyl_re'), v('axis_re'), v('add_re'), v('va_re')],
          ['Left (LE)', v('sph_le'), v('cyl_le'), v('axis_le'), v('add_le'), v('va_le')],
        ],
        cellAlignment: pw.Alignment.center,
      ),
      pw.SizedBox(height: 10),
      if (r.str('lens_type') != null) pw.Text('Lens: ${r.str('lens_type')}'),
      if (r.str('diagnosis') != null) pw.Text('Diagnosis: ${r.str('diagnosis')}'),
      if (r.str('advice') != null) pw.Text('Advice: ${r.str('advice')}'),
      pw.Spacer(),
      pw.Align(alignment: pw.Alignment.centerRight, child: pw.Text(session.staff.displayName, style: pw.TextStyle(fontWeight: pw.FontWeight.bold))),
    ]),
  ));
  try {
    await Printing.sharePdf(bytes: await doc.save(), filename: 'Spectacles_${p.name.replaceAll(RegExp(r'[^A-Za-z0-9]+'), '_')}_${r.eventYmd}.pdf');
  } catch (e) {
    if (context.mounted) showSnack(context, 'Could not share: $e', error: true);
  }
}

// ====================================================================== orthopaedics
class OrthoCard extends ConsumerWidget {
  const OrthoCard({super.key, required this.d, required this.records});
  final PatientDetail d;
  final List<ClinicalRecord> records;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final active = records.where((r) => r.type == SpForms.ortho.type && r.status == 'active').firstOrNull;
    final removal = active?.date('removal_on');
    final days = removal == null ? null : DateUtils.dateOnly(removal).difference(DateUtils.dateOnly(DateTime.now())).inDays;
    final hasPain = seriesOf(records.where((r) => r.type == SpForms.ortho.type).toList(), 'pain').length >= 2;
    return SpecialtyRecordCard(
      patientId: d.patient.id,
      records: records,
      form: SpForms.ortho,
      icon: Icons.personal_injury_rounded,
      child: days == null
          ? null
          : Row(children: [
              Icon(Icons.healing_rounded, size: 18, color: days <= 0 ? Theme.of(context).colorScheme.error : null),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  days < 0 ? '${active!.str('immobilisation') ?? 'Cast'} removal was due ${-days} days ago' : days == 0 ? 'Cast removal due today' : 'Cast removal in $days days (${fmtDate(removal)})',
                  style: TextStyle(fontWeight: FontWeight.w600, color: days <= 0 ? Theme.of(context).colorScheme.error : null),
                ),
              ),
              if (!hasPain && days > 0)
                TextButton(onPressed: () => showFollowUpSheet(context, ref, patientId: d.patient.id), child: const Text('Book review')),
            ]),
    );
  }
}

// ====================================================================== diabetes & lab trends
/// Lab parameters worth trending in chronic care, matched by name.
const labTrendMatchers = <(String, String)>[
  ('HbA1c', r'hba1c|glycated|glycosylated'),
  ('Fasting sugar', r'fasting|fbs|\bfbg\b'),
  ('Post-meal sugar', r'post ?prandial|ppbs|pp ?sugar|2 ?hr'),
  ('Total cholesterol', r'total cholesterol|^cholesterol'),
  ('LDL', r'\bldl\b'),
  ('HDL', r'\bhdl\b'),
  ('Triglycerides', r'triglycer'),
  ('Creatinine', r'creatinine'),
  ('TSH', r'\btsh\b'),
  ('Haemoglobin', r'^h(a)?emoglobin|^hb$'),
];

Map<String, List<(DateTime, double)>> labSeries(List<LabReport> labs) {
  final out = <String, List<(DateTime, double)>>{};
  for (final l in labs) {
    final at = l.reportingDate ?? l.collectionDate;
    if (at == null) continue;
    for (final p in l.parameters) {
      final v = double.tryParse(RegExp(r'-?[0-9]+(\.[0-9]+)?').firstMatch(p.value)?.group(0) ?? '');
      if (v == null) continue;
      for (final (label, re) in labTrendMatchers) {
        if (RegExp(re, caseSensitive: false).hasMatch(p.name.trim())) {
          out.putIfAbsent(label, () => []).add((at, v));
          break;
        }
      }
    }
  }
  for (final s in out.values) {
    s.sort((a, b) => a.$1.compareTo(b.$1));
  }
  return out;
}

/// Annual diabetes checks that are overdue (never done or more than a year ago).
List<String> diabetesChecksDue(ClinicalRecord? latest) {
  final yearAgo = DateTime.now().subtract(const Duration(days: 365));
  return [
    for (final (k, l) in const [('eye_check', 'eye'), ('foot_check', 'foot'), ('kidney_check', 'kidney')])
      if (latest?.date(k) == null || latest!.date(k)!.isBefore(yearAgo)) l,
  ];
}

class DiabetesCard extends ConsumerWidget {
  const DiabetesCard({super.key, required this.d, required this.records});
  final PatientDetail d;
  final List<ClinicalRecord> records;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final mine = records.where((r) => r.type == SpForms.diabetes.type).toList();
    final latest = mine.firstOrNull;
    final due = diabetesChecksDue(latest);
    final labs = labSeries(d.labs);
    final hba1c = [...?labs['HbA1c']?.map((p) => (p.$1.millisecondsSinceEpoch.toDouble(), p.$2)), ...seriesOf(mine, 'hba1c')]..sort((a, b) => a.$1.compareTo(b.$1));
    return CareCard(
      icon: Icons.bloodtype_rounded,
      title: 'Diabetes care',
      alert: latest != null && due.isNotEmpty,
      subtitle: [
        if (hba1c.isNotEmpty) 'HbA1c ${hba1c.last.$2}%',
        if (latest != null) SpForms.diabetes.summaryOf(latest) else 'No review recorded',
        if (latest != null && due.isNotEmpty) 'Due: ${due.join(', ')} check',
      ].join(' · '),
      onTap: () => Navigator.push(
        context,
        MaterialPageRoute(
          builder: (_) => SpecialtyHistoryScreen(patientId: d.patient.id, form: SpForms.diabetes, icon: Icons.bloodtype_rounded, header: (ctx, _) => LabTrendsPanel(labs: d.labs)),
        ),
      ),
      action: TextButton.icon(
        onPressed: () => editSpecialtyRecord(context, ref, patientId: d.patient.id, form: SpForms.diabetes),
        icon: const Icon(Icons.add_rounded, size: 18),
        label: const Text('Review'),
      ),
      child: hba1c.length >= 2 ? LineChart(height: 90, yUnit: '%', series: [ChartSeries(label: 'HbA1c', points: hba1c)]) : null,
    );
  }
}

/// Trend charts of key lab values from the patient's reports.
class LabTrendsPanel extends StatelessWidget {
  const LabTrendsPanel({super.key, required this.labs});
  final List<LabReport> labs;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final s = labSeries(labs).entries.where((e) => e.value.length >= 2).toList();
    String fx(double x) => fmtDateShort(DateTime.fromMillisecondsSinceEpoch(x.round()));
    if (s.isEmpty) return const SizedBox.shrink();
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      const SectionHeader('Lab trends', padding: EdgeInsets.fromLTRB(4, 4, 0, 8)),
      for (final e in s)
        Card(
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(child: Text(e.key, style: t.textTheme.titleSmall)),
                Text('${e.value.last.$2}', style: t.textTheme.titleMedium?.copyWith(color: t.colorScheme.primary)),
              ]),
              const SizedBox(height: 8),
              LineChart(height: 120, formatX: fx, series: [ChartSeries(label: e.key, points: [for (final p in e.value) (p.$1.millisecondsSinceEpoch.toDouble(), p.$2)])]),
            ]),
          ),
        ),
      const SectionHeader('Reviews', padding: EdgeInsets.fromLTRB(4, 12, 0, 8)),
    ]);
  }
}
