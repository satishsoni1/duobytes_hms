import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/ui/theme.dart';
import '../../../core/ui/widgets.dart';
import '../data/clinical.dart';
import '../ui/doctor_providers.dart';
import 'followups.dart';
import 'specialty.dart';

/// Dating of a pregnancy from LMP (Naegele: EDD = LMP + 280 days), or from an ultrasound EDD when recorded.
class PregnancyDating {
  PregnancyDating({required this.edd, required this.gaDays, required this.fromUsg});
  final DateTime edd;
  final int gaDays;
  final bool fromUsg;

  int get weeks => gaDays ~/ 7;
  int get days => gaDays % 7;
  String get gaLabel => '${weeks}w ${days}d';
  int get trimester => weeks < 14 ? 1 : weeks < 28 ? 2 : 3;
  String get trimesterLabel => const ['1st', '2nd', '3rd'][trimester - 1];
  double get progress => (gaDays / 280).clamp(0.0, 1.0);

  /// WHO 2016 antenatal care model: 8 contacts at these gestational weeks.
  static const ancWeeks = [12, 20, 26, 30, 34, 36, 38, 40];
  List<(int, DateTime)> get ancPlan => [for (final w in ancWeeks) (w, edd.subtract(Duration(days: 280 - w * 7)))];

  static PregnancyDating? of(ClinicalRecord r, {DateTime? today}) {
    final now = today ?? DateTime.now();
    final usg = r.date('edd_usg');
    final lmp = r.date('lmp');
    final edd = usg ?? lmp?.add(const Duration(days: 280));
    if (edd == null) return null;
    final ga = 280 - DateTime(edd.year, edd.month, edd.day).difference(DateTime(now.year, now.month, now.day)).inDays;
    return PregnancyDating(edd: edd, gaDays: ga, fromUsg: usg != null);
  }
}

ClinicalRecord? activePregnancy(List<ClinicalRecord> records) =>
    records.where((r) => r.type == RecordType.pregnancy && r.status == 'active' && !r.deleted).firstOrNull;

class PregnancyCard extends ConsumerWidget {
  const PregnancyCard({super.key, required this.d, required this.records});
  final PatientDetail d;
  final List<ClinicalRecord> records;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final p = activePregnancy(records);
    final dating = p == null ? null : PregnancyDating.of(p);
    final history = records.where((r) => r.type == RecordType.obstetricHistory || (r.type == RecordType.pregnancy && r.status == 'closed')).length;
    return CareCard(
      icon: Icons.pregnant_woman_rounded,
      title: 'Pregnancy',
      color: cs.secondary,
      subtitle: dating == null ? (history > 0 ? 'No active pregnancy · $history previous' : 'No active pregnancy') : '${dating.gaLabel} · ${dating.trimesterLabel} trimester · EDD ${fmtDate(dating.edd)}',
      alert: dating != null && dating.weeks >= 40,
      onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => PregnancyScreen(patientId: d.patient.id))),
      action: p == null ? TextButton.icon(onPressed: () => editPregnancy(context, ref, d.patient.id, null), icon: const Icon(Icons.add_rounded, size: 18), label: const Text('Start')) : null,
      child: dating == null
          ? null
          : Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: TweenAnimationBuilder<double>(
                  tween: Tween(begin: 0, end: dating.progress),
                  duration: Motion.slow,
                  curve: Motion.emphasized,
                  builder: (_, v, _) => LinearProgressIndicator(value: v, minHeight: 8, color: cs.secondary, backgroundColor: cs.surfaceContainerHighest),
                ),
              ),
              const SizedBox(height: 4),
              Row(children: [
                for (final l in const ['0', '13', '27', '40 wk']) Expanded(child: Text(l, style: t.textTheme.labelSmall?.copyWith(color: cs.onSurfaceVariant), textAlign: l == '0' ? TextAlign.left : l.startsWith('40') ? TextAlign.right : TextAlign.center)),
              ]),
            ]),
    );
  }
}

class PregnancyScreen extends ConsumerWidget {
  const PregnancyScreen({super.key, required this.patientId});
  final int patientId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(patientDetailProvider(patientId)).valueOrNull;
    final records = ref.watch(clinicalRecordsProvider(patientId)).valueOrNull;
    if (detail == null || records == null) return Scaffold(appBar: AppBar(), body: const SkeletonList());
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final p = activePregnancy(records);
    final dating = p == null ? null : PregnancyDating.of(p);
    final previous = records.where((r) => r.type == RecordType.pregnancy && r.status == 'closed').toList();
    final webHistory = records.where((r) => r.type == RecordType.obstetricHistory).toList();
    final now = DateTime.now();

    return Scaffold(
      appBar: AppBar(title: const Text('Pregnancy')),
      floatingActionButton: p == null
          ? FloatingActionButton.extended(onPressed: () => editPregnancy(context, ref, patientId, null), icon: const Icon(Icons.add_rounded), label: const Text('Start pregnancy record'))
          : null,
      body: ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 100), children: [
        if (p != null && dating != null) ...[
          FadeSlideIn(
            child: HeroPanel(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('Gestational age', style: t.textTheme.labelMedium?.copyWith(color: Colors.white.withValues(alpha: 0.85))),
                Text(dating.gaLabel, style: t.textTheme.displaySmall?.copyWith(color: Colors.white)),
                Text('${dating.trimesterLabel} trimester · EDD ${fmtDate(dating.edd)}${dating.fromUsg ? ' (USG)' : ' (LMP)'}',
                    style: t.textTheme.bodyMedium?.copyWith(color: Colors.white)),
                const SizedBox(height: 14),
                Row(children: [
                  for (final (l, k) in const [('G', 'gravida'), ('P', 'para'), ('A', 'abortions'), ('L', 'living')]) ...[
                    Expanded(
                      child: Container(
                        padding: const EdgeInsets.symmetric(vertical: 8),
                        decoration: BoxDecoration(color: Colors.white.withValues(alpha: 0.16), borderRadius: BorderRadius.circular(Radii.sm)),
                        child: Column(children: [
                          Text(p.str(k) ?? '–', style: t.textTheme.titleLarge?.copyWith(color: Colors.white)),
                          Text(l, style: t.textTheme.labelSmall?.copyWith(color: Colors.white.withValues(alpha: 0.85))),
                        ]),
                      ),
                    ),
                    if (k != 'living') const SizedBox(width: 8),
                  ],
                ]),
              ]),
            ),
          ),
          if (p.str('risk_factors') != null) ...[
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: cs.errorContainer, borderRadius: BorderRadius.circular(Radii.md)),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Icon(Icons.warning_amber_rounded, color: cs.onErrorContainer, size: 20),
                const SizedBox(width: 10),
                Expanded(child: Text('Risk factors: ${p.str('risk_factors')}', style: TextStyle(color: cs.onErrorContainer, fontWeight: FontWeight.w500))),
              ]),
            ),
          ],
          const SizedBox(height: 12),
          Row(children: [
            Expanded(child: OutlinedButton.icon(onPressed: () => editPregnancy(context, ref, patientId, p), icon: const Icon(Icons.edit_rounded), label: const Text('Edit'))),
            const SizedBox(width: 10),
            Expanded(child: FilledButton.tonalIcon(onPressed: () => _close(context, ref, p), icon: const Icon(Icons.task_alt_rounded), label: const Text('Record outcome'))),
          ]),
          const SectionHeader('Antenatal visits (WHO 8-contact plan)', padding: EdgeInsets.fromLTRB(4, 20, 0, 8)),
          Card(
            child: Column(children: [
              for (final (w, date) in dating.ancPlan)
                ListTile(
                  dense: true,
                  leading: Icon(
                    date.isBefore(now.subtract(const Duration(days: 3))) ? Icons.check_circle_outline_rounded : Icons.radio_button_unchecked_rounded,
                    color: date.isBefore(now.subtract(const Duration(days: 3))) ? cs.outline : cs.secondary,
                  ),
                  title: Text('$w weeks'),
                  trailing: Text(fmtDate(date), style: t.textTheme.labelLarge),
                  onLongPress: () => showFollowUpSheet(context, ref, patientId: patientId),
                ),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.only(top: 6, left: 4),
            child: Text('Schedule any of these as a follow-up from the Care tab.', style: t.textTheme.labelSmall?.copyWith(color: cs.onSurfaceVariant)),
          ),
        ] else
          const EmptyState(icon: Icons.pregnant_woman_rounded, title: 'No active pregnancy', message: 'Start a record with the last menstrual period (LMP) to track gestational age and EDD.'),
        if (previous.isNotEmpty || webHistory.isNotEmpty) ...[
          const SectionHeader('Obstetric history', padding: EdgeInsets.fromLTRB(4, 20, 0, 8)),
          Card(
            child: Column(children: [
              for (final r in previous)
                ListTile(
                  leading: const IconBadge(Icons.history_rounded, size: 32),
                  title: Text(r.str('outcome') ?? 'Pregnancy'),
                  subtitle: Text('${fmtDate(r.date('outcome_on') ?? r.eventDate)}${r.str('note') != null ? ' · ${r.str('note')}' : ''}'),
                ),
              for (final r in webHistory)
                ListTile(
                  leading: const IconBadge(Icons.history_edu_rounded, size: 32),
                  title: Text([r.str('alive_dead'), r.str('gender'), if (r.str('birth_weight') != null) '${r.str('birth_weight')} birth weight'].whereType<String>().join(' · ').ifEmpty('Previous pregnancy')),
                  subtitle: Text([
                    fmtDate(r.eventDate),
                    r.str('place_of_delivery'),
                    r.str('pregnancy_duration'),
                    r.str('pregnancy_complications'),
                  ].whereType<String>().where((s) => s != '—').join(' · ')),
                ),
            ]),
          ),
        ],
      ]),
    );
  }

  Future<void> _close(BuildContext context, WidgetRef ref, ClinicalRecord p) async {
    var outcome = 'Delivered';
    var on = DateTime.now();
    final ok = await showModalBottomSheet<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setState) => Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text('Pregnancy outcome', style: Theme.of(ctx).textTheme.titleLarge),
            const SizedBox(height: 12),
            Wrap(spacing: 8, runSpacing: 8, children: [
              for (final o in const ['Delivered', 'Miscarriage', 'Termination', 'Ectopic', 'Other'])
                ChoiceChip(label: Text(o), selected: outcome == o, onSelected: (_) => setState(() => outcome = o)),
            ]),
            const SizedBox(height: 12),
            OutlinedButton.icon(
              onPressed: () async {
                final d = await showDatePicker(context: ctx, initialDate: on, firstDate: DateTime.now().subtract(const Duration(days: 365)), lastDate: DateTime.now());
                if (d != null) setState(() => on = d);
              },
              icon: const Icon(Icons.event_rounded),
              label: Text('On ${fmtDate(on)}'),
            ),
            const SizedBox(height: 12),
            FilledButton(style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)), onPressed: () => Navigator.pop(ctx, true), child: const Text('Close pregnancy record')),
          ]),
        ),
      ),
    );
    if (ok != true) return;
    p.status = 'closed';
    p.data['outcome'] = outcome;
    p.data['outcome_on'] = ymd(on);
    await saveClinicalRecord(ref, p);
  }
}

Future<void> editPregnancy(BuildContext context, WidgetRef ref, int patientId, ClinicalRecord? existing) async {
  DateTime? lmp = existing?.date('lmp');
  DateTime? usg = existing?.date('edd_usg');
  final c = {for (final k in const ['gravida', 'para', 'abortions', 'living']) k: TextEditingController(text: existing?.str(k) ?? '')};
  final risk = TextEditingController(text: existing?.str('risk_factors') ?? '');
  final ok = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    builder: (ctx) => StatefulBuilder(builder: (ctx, setState) {
      final preview = lmp == null && usg == null ? null : PregnancyDating.of(ClinicalRecord.create(patientId: 0, type: 'x', data: {'lmp': lmp == null ? null : ymd(lmp!), 'edd_usg': usg == null ? null : ymd(usg!)}));
      return Padding(
        padding: EdgeInsets.fromLTRB(20, 0, 20, MediaQuery.viewInsetsOf(ctx).bottom + 20),
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(existing == null ? 'Start pregnancy record' : 'Edit pregnancy', style: Theme.of(ctx).textTheme.titleLarge),
            const SizedBox(height: 14),
            OutlinedButton.icon(
              onPressed: () async {
                final d = await showDatePicker(context: ctx, initialDate: lmp ?? DateTime.now().subtract(const Duration(days: 60)), firstDate: DateTime.now().subtract(const Duration(days: 310)), lastDate: DateTime.now());
                if (d != null) setState(() => lmp = d);
              },
              icon: const Icon(Icons.event_rounded),
              label: Text(lmp == null ? 'Last menstrual period (LMP)' : 'LMP ${fmtDate(lmp)}'),
            ),
            const SizedBox(height: 10),
            OutlinedButton.icon(
              onPressed: () async {
                final d = await showDatePicker(context: ctx, initialDate: usg ?? DateTime.now().add(const Duration(days: 180)), firstDate: DateTime.now().subtract(const Duration(days: 30)), lastDate: DateTime.now().add(const Duration(days: 300)));
                if (d != null) setState(() => usg = d);
              },
              icon: const Icon(Icons.monitor_heart_rounded),
              label: Text(usg == null ? 'EDD by ultrasound (optional)' : 'USG EDD ${fmtDate(usg)}'),
            ),
            if (preview != null)
              Padding(
                padding: const EdgeInsets.only(top: 10),
                child: Text('GA ${preview.gaLabel} · EDD ${fmtDate(preview.edd)}', textAlign: TextAlign.center, style: Theme.of(ctx).textTheme.titleSmall),
              ),
            const SizedBox(height: 12),
            Row(children: [
              for (final (k, l) in const [('gravida', 'G'), ('para', 'P'), ('abortions', 'A'), ('living', 'L')]) ...[
                Expanded(
                  child: TextField(
                    controller: c[k],
                    keyboardType: TextInputType.number,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    textAlign: TextAlign.center,
                    decoration: InputDecoration(labelText: l),
                  ),
                ),
                if (k != 'living') const SizedBox(width: 8),
              ],
            ]),
            const SizedBox(height: 12),
            TextField(controller: risk, maxLines: 2, decoration: const InputDecoration(labelText: 'Risk factors (optional)', hintText: 'e.g. previous LSCS, GDM, anaemia')),
            const SizedBox(height: 12),
            FilledButton(
              style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
              onPressed: lmp == null && usg == null ? null : () => Navigator.pop(ctx, true),
              child: const Text('Save'),
            ),
          ]),
        ),
      );
    }),
  );
  if (ok != true) return;
  final r = existing ?? ClinicalRecord.create(patientId: patientId, type: RecordType.pregnancy);
  r.eventDate = lmp ?? usg;
  r.status = 'active';
  r.data['lmp'] = lmp == null ? null : ymd(lmp!);
  r.data['edd_usg'] = usg == null ? null : ymd(usg!);
  for (final e in c.entries) {
    r.data[e.key] = int.tryParse(e.value.text.trim());
  }
  r.data['risk_factors'] = risk.text.trim();
  await saveClinicalRecord(ref, r);
}

extension on String {
  String ifEmpty(String other) => isEmpty ? other : this;
}
