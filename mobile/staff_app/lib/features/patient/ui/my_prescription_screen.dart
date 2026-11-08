import 'package:flutter/material.dart';

import '../../../core/ui/theme.dart';
import '../../../core/ui/widgets.dart';
import '../../doctor/data/models.dart';

/// A prescription as the patient sees it: what to take, when, and for how long.
class MyPrescriptionScreen extends StatelessWidget {
  const MyPrescriptionScreen({super.key, required this.rx, this.encounter, this.diagnoses = const []});
  final Prescription rx;
  final Encounter? encounter;
  final List<IcdCode> diagnoses;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final items = rx.items;
    final tests = [...rx.pathology.map((x) => (Icons.biotech_rounded, x.name)), ...rx.radiology.map((x) => (Icons.monitor_heart_rounded, x.name))];
    var i = 0;
    Widget anim(Widget w) => FadeSlideIn(index: i++, child: w);

    return Scaffold(
      appBar: AppBar(title: const Text('Prescription')),
      body: ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 32), children: [
        anim(HeroPanel(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              const Icon(Icons.receipt_long_rounded, color: Colors.white, size: 22),
              const SizedBox(width: 10),
              Text(fmtDate(rx.date), style: t.textTheme.titleLarge?.copyWith(color: Colors.white)),
            ]),
            const SizedBox(height: 10),
            if (rx.prescribedBy != null && rx.prescribedBy!.isNotEmpty)
              Text('Dr. ${rx.prescribedBy}', style: t.textTheme.titleMedium?.copyWith(color: Colors.white)),
            if (encounter != null)
              Text('${encounter!.label} · ${fmtDate(encounter!.date)}', style: t.textTheme.bodySmall?.copyWith(color: Colors.white.withValues(alpha: 0.85))),
            const SizedBox(height: 14),
            Row(children: [
              Expanded(child: StatTile(value: items.length, label: 'Medicines', icon: Icons.medication_rounded)),
              const SizedBox(width: 10),
              Expanded(child: StatTile(value: tests.length, label: 'Tests', icon: Icons.biotech_rounded)),
            ]),
          ]),
        )),
        if (diagnoses.isNotEmpty) ...[
          const SectionHeader('Diagnosis', padding: EdgeInsets.fromLTRB(4, 20, 0, 8)),
          anim(Wrap(spacing: 8, runSpacing: 8, children: [for (final c in diagnoses) Chip(label: Text(c.description))])),
        ],
        if (rx.findings != null && rx.printFindings) ...[
          const SectionHeader('Doctor\'s findings', padding: EdgeInsets.fromLTRB(4, 20, 0, 8)),
          anim(Text(rx.findings!)),
        ],
        if (items.isNotEmpty) ...[
          SectionHeader('Your medicines', count: items.length, padding: const EdgeInsets.fromLTRB(4, 20, 0, 8)),
          for (final (n, it) in items.indexed)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: anim(Card(
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [
                      Container(
                        width: 30,
                        height: 30,
                        alignment: Alignment.center,
                        decoration: BoxDecoration(color: cs.primaryContainer, borderRadius: BorderRadius.circular(10)),
                        child: Text('${n + 1}', style: t.textTheme.labelLarge?.copyWith(color: cs.onPrimaryContainer)),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(it.medicineName, style: t.textTheme.titleMedium),
                          if (it.composition != null) Text(it.composition!, style: t.textTheme.bodySmall?.copyWith(color: cs.onSurfaceVariant)),
                        ]),
                      ),
                    ]),
                    const SizedBox(height: 12),
                    Row(children: [
                      Expanded(child: _Fact(icon: Icons.medication_liquid_rounded, label: 'Take', value: it.dosageLabel)),
                      Expanded(child: _Fact(icon: Icons.schedule_rounded, label: 'When', value: plainDosing(it.intervalName))),
                      Expanded(child: _Fact(icon: Icons.event_repeat_rounded, label: 'For', value: it.durationName)),
                    ]),
                    if (it.instruction.isNotEmpty) ...[
                      const SizedBox(height: 10),
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                        decoration: BoxDecoration(color: cs.secondaryContainer, borderRadius: BorderRadius.circular(Radii.sm)),
                        child: Row(children: [
                          Icon(Icons.info_outline_rounded, size: 16, color: cs.onSecondaryContainer),
                          const SizedBox(width: 8),
                          Expanded(child: Text(it.instruction, style: TextStyle(color: cs.onSecondaryContainer, fontWeight: FontWeight.w500))),
                        ]),
                      ),
                    ],
                  ]),
                ),
              )),
            ),
        ],
        if (tests.isNotEmpty) ...[
          SectionHeader('Tests to do', count: tests.length, padding: const EdgeInsets.fromLTRB(4, 20, 0, 8)),
          anim(Card(
            child: Column(children: [
              for (final x in tests) ListTile(leading: IconBadge(x.$1, color: cs.secondary), title: Text(x.$2)),
            ]),
          )),
        ],
        if (rx.footerNote != null) ...[
          const SectionHeader('Advice', padding: EdgeInsets.fromLTRB(4, 20, 0, 8)),
          anim(Card(
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                IconBadge(Icons.tips_and_updates_rounded, color: cs.tertiary),
                const SizedBox(width: 12),
                Expanded(child: Text(rx.footerNote!)),
              ]),
            ),
          )),
        ],
        const SizedBox(height: 18),
        Text('Take medicines exactly as prescribed. Contact the hospital if you have side effects.',
            textAlign: TextAlign.center, style: t.textTheme.bodySmall?.copyWith(color: cs.onSurfaceVariant)),
      ]),
    );
  }
}

class _Fact extends StatelessWidget {
  const _Fact({required this.icon, required this.label, required this.value});
  final IconData icon;
  final String label;
  final String? value;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    return Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Icon(icon, size: 16, color: t.colorScheme.primary),
      const SizedBox(width: 6),
      Expanded(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(label, style: t.textTheme.labelSmall?.copyWith(color: t.colorScheme.onSurfaceVariant)),
          Text(value == null || value!.isEmpty ? '—' : value!, style: t.textTheme.labelLarge),
        ]),
      ),
    ]);
  }
}

/// Clinical dosing shorthand in plain words for patients, keeping the code: "3 times a day (TDS)".
String? plainDosing(String? code) {
  if (code == null) return null;
  const words = {
    'OD': 'Once a day', 'QD': 'Once a day', 'BD': 'Twice a day', 'BID': 'Twice a day',
    'TDS': '3 times a day', 'TID': '3 times a day', 'QID': '4 times a day', 'QDS': '4 times a day',
    'HS': 'At bedtime', 'SOS': 'Only if needed', 'PRN': 'Only if needed', 'STAT': 'Immediately once',
    'AC': 'Before food', 'PC': 'After food', 'QOD': 'Every other day', 'WEEKLY': 'Once a week',
  };
  final key = code.trim().toUpperCase().replaceAll('.', '');
  final w = words[key];
  return w == null ? code : '$w ($code)';
}
