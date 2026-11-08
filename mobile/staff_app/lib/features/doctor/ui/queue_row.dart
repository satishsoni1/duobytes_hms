import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/ui/widgets.dart';
import '../data/clinical.dart';
import '../data/models.dart';
import '../data/queue.dart';
import '../print/print_screen.dart';
import '../specialty/specialty.dart';
import 'billing_ui.dart';
import 'doctor_providers.dart';
import 'patient_screen.dart';
import 'prescription_editor_screen.dart';

/// One patient in today's OPD queue: token, status, quick prescribe, and an actions menu.
class QueueRow extends ConsumerWidget {
  const QueueRow({super.key, required this.entry, this.statusRecord});
  final QueueEntry entry;
  final ClinicalRecord? statusRecord;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final e = entry.encounter;
    final p = entry.patient;
    final allergies = p == null ? const <String>[] : splitAllergies([...p.allergies, ...e.allergies]);
    final (stateLabel, stateBg, stateFg) = switch (entry.state) {
      QueueState.waiting => ('Waiting', cs.surfaceContainerHighest, cs.onSurfaceVariant),
      QueueState.withDoctor => ('With doctor', cs.secondaryContainer, cs.onSecondaryContainer),
      QueueState.seen => ('Seen', cs.tertiaryContainer, cs.onTertiaryContainer),
      QueueState.skipped => ('Skipped', cs.errorContainer, cs.onErrorContainer),
    };
    final time = e.date != null && (e.date!.hour != 0 || e.date!.minute != 0) ? fmtTime(e.date) : null;
    final active = entry.state == QueueState.withDoctor;

    return Card(
      color: active ? cs.secondaryContainer.withValues(alpha: 0.35) : null,
      child: Pressable(
        haptic: true,
        onTap: p == null ? null : () => Navigator.push(context, MaterialPageRoute(builder: (_) => PatientScreen(patientId: p.id))),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 12, 4, 12),
          child: Row(children: [
            // Token
            Container(
              width: 44,
              height: 44,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: active ? cs.secondary : cs.primaryContainer,
                borderRadius: BorderRadius.circular(14),
              ),
              child: Text(
                entry.token != null ? '${entry.token}' : '—',
                style: t.textTheme.titleMedium?.copyWith(color: active ? cs.onSecondary : cs.onPrimaryContainer, fontWeight: FontWeight.w800),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Flexible(child: Text(p?.name ?? 'Patient #${e.patientId}', style: t.textTheme.titleSmall, overflow: TextOverflow.ellipsis)),
                  if (allergies.isNotEmpty) ...[
                    const SizedBox(width: 6),
                    Tooltip(message: 'Allergies: ${allergies.join(', ')}', child: Icon(Icons.warning_amber_rounded, size: 16, color: cs.error)),
                  ],
                ]),
                const SizedBox(height: 2),
                Text([?time, if (p != null && p.subtitle.isNotEmpty) p.subtitle].join(' · '),
                    maxLines: 1, overflow: TextOverflow.ellipsis, style: t.textTheme.bodySmall?.copyWith(color: cs.onSurfaceVariant)),
                if (e.symptoms != null) Text(e.symptoms!, maxLines: 1, overflow: TextOverflow.ellipsis, style: t.textTheme.bodySmall),
                const SizedBox(height: 6),
                Wrap(spacing: 6, runSpacing: 4, children: [
                  StatusPill(stateLabel, bg: stateBg, fg: stateFg),
                  if (entry.hasRx) StatusPill('Rx done', bg: cs.tertiaryContainer, fg: cs.onTertiaryContainer, icon: Icons.check_circle_rounded),
                ]),
              ]),
            ),
            if (!entry.hasRx && p != null)
              FilledButton.tonal(
                style: FilledButton.styleFrom(minimumSize: const Size(0, 38), padding: const EdgeInsets.symmetric(horizontal: 12), visualDensity: VisualDensity.compact),
                onPressed: () async {
                  HapticFeedback.lightImpact();
                  if (entry.state == QueueState.waiting) {
                    await saveClinicalRecord(ref, queueRecord(e, QueueState.withDoctor, existing: statusRecord));
                  }
                  if (context.mounted) await Navigator.push(context, MaterialPageRoute(builder: (_) => PrescriptionEditorScreen.create(patientId: p.id, encounter: e)));
                },
                child: const Text('Write Rx'),
              ),
            IconButton(tooltip: 'More', icon: const Icon(Icons.more_vert_rounded), onPressed: () => _actions(context, ref)),
          ]),
        ),
      ),
    );
  }

  Future<void> _actions(BuildContext context, WidgetRef ref) async {
    final e = entry.encounter;
    final p = entry.patient;
    final rxForVisit = p == null ? const <Prescription>[] : (await ref.read(doctorRepoProvider).prescriptions(p.id)).where((r) => r.kind == e.kind && r.caseRefId == e.refId).toList();
    if (!context.mounted) return;
    final choice = await showModalBottomSheet<String>(
      context: context,
      builder: (ctx) => SafeArea(
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            ListTile(title: Text(p?.name ?? 'Patient', style: Theme.of(ctx).textTheme.titleMedium), subtitle: Text(entry.token != null ? 'Token ${entry.token}' : 'OPD visit')),
            if (entry.state != QueueState.withDoctor) _tile(ctx, 'with_doctor', Icons.campaign_rounded, 'Call in / start consultation'),
            if (entry.state != QueueState.seen) _tile(ctx, 'seen', Icons.how_to_reg_rounded, 'Mark as seen'),
            if (entry.state != QueueState.skipped) _tile(ctx, 'skipped', Icons.person_off_rounded, 'Skip (not present)'),
            if (entry.state != QueueState.waiting) _tile(ctx, 'waiting', Icons.hourglass_top_rounded, 'Back to waiting'),
            const Divider(),
            if (p != null) _tile(ctx, 'rx', Icons.edit_note_rounded, entry.hasRx ? 'Write another prescription' : 'Write prescription'),
            if (p != null) _tile(ctx, 'patient', Icons.history_rounded, 'History, reports & old prescriptions'),
            if (p != null) _tile(ctx, 'service', Icons.add_card_rounded, 'Add service / charge'),
            if (rxForVisit.isNotEmpty) _tile(ctx, 'print', Icons.print_rounded, 'Print prescription'),
          ]),
        ),
      ),
    );
    if (choice == null || !context.mounted) return;
    switch (choice) {
      case 'with_doctor' || 'seen' || 'skipped' || 'waiting':
        HapticFeedback.selectionClick();
        await saveClinicalRecord(ref, queueRecord(e, queueStateFrom(choice), existing: statusRecord));
      case 'rx':
        await Navigator.push(context, MaterialPageRoute(builder: (_) => PrescriptionEditorScreen.create(patientId: p!.id, encounter: e)));
      case 'patient':
        await Navigator.push(context, MaterialPageRoute(builder: (_) => PatientScreen(patientId: p!.id)));
      case 'service':
        await showAddServiceSheet(context, ref, e);
      case 'print':
        await openPrintScreen(context, ref, rxForVisit.first.localId);
    }
  }

  Widget _tile(BuildContext ctx, String value, IconData icon, String label) =>
      ListTile(leading: Icon(icon), title: Text(label), onTap: () => Navigator.pop(ctx, value));
}
