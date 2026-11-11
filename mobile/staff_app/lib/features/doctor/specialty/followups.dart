import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/ui/widgets.dart';
import '../data/clinical.dart';
import '../data/models.dart';
import '../ui/doctor_providers.dart';
import 'specialty.dart';
import '../ui/whatsapp_actions.dart';

/// "Due today" / "Overdue by 3 days" / "In 5 days".
(String, bool) followUpWhen(DateTime? due, {DateTime? today}) {
  if (due == null) return ('No date', false);
  final now = today ?? DateTime.now();
  final d = DateTime(due.year, due.month, due.day).difference(DateTime(now.year, now.month, now.day)).inDays;
  if (d == 0) return ('Due today', true);
  if (d < 0) return ('Overdue by ${-d} day${d == -1 ? '' : 's'}', true);
  if (d == 1) return ('Tomorrow', false);
  return ('In $d days · ${fmtDateShort(due)}', false);
}

const followUpPresets = <(String, int)>[('3 days', 3), ('1 week', 7), ('2 weeks', 14), ('1 month', 30), ('3 months', 90)];

class FollowUpsCard extends ConsumerWidget {
  const FollowUpsCard({super.key, required this.d, required this.records});
  final PatientDetail d;
  final List<ClinicalRecord> records;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final list = records.where((r) => r.type == RecordType.followup).toList()
      ..sort((a, b) {
        final sa = a.status == 'active' ? 0 : 1, sb = b.status == 'active' ? 0 : 1;
        if (sa != sb) return sa - sb;
        return (a.eventDate ?? DateTime(2999)).compareTo(b.eventDate ?? DateTime(2999));
      });
    final active = list.where((r) => r.status == 'active').toList();

    return CareCard(
      icon: Icons.event_repeat_rounded,
      title: 'Follow-ups',
      subtitle: active.isEmpty ? 'None scheduled' : followUpWhen(active.first.eventDate).$1,
      alert: active.isNotEmpty && followUpWhen(active.first.eventDate).$2,
      action: TextButton.icon(
        onPressed: () => showFollowUpSheet(context, ref, patientId: d.patient.id, encounter: d.openEncounters.firstOrNull ?? d.encounters.firstOrNull),
        icon: const Icon(Icons.add_rounded, size: 18),
        label: const Text('Schedule'),
      ),
      child: list.isEmpty
          ? null
          : Column(children: [
              for (final r in list.take(4))
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  dense: true,
                  leading: Icon(
                    r.status == 'done' ? Icons.check_circle_rounded : r.status == 'cancelled' ? Icons.cancel_rounded : Icons.schedule_rounded,
                    color: r.status == 'active' ? (followUpWhen(r.eventDate).$2 ? cs.error : cs.primary) : cs.outline,
                  ),
                  title: Text(r.str('reason') ?? 'Review', style: r.status == 'active' ? null : TextStyle(color: cs.onSurfaceVariant, decoration: TextDecoration.lineThrough)),
                  subtitle: Text(r.status == 'active' ? followUpWhen(r.eventDate).$1 : '${r.status == 'done' ? 'Done' : 'Cancelled'} · ${fmtDate(r.eventDate)}'),
                  trailing: r.pending ? Icon(Icons.cloud_upload_outlined, size: 16, color: cs.outline) : null,
                  onTap: r.status == 'active' ? () => _actions(context, ref, r) : null,
                ),
            ]),
    );
  }

  Future<void> _actions(BuildContext context, WidgetRef ref, ClinicalRecord r) async {
    final choice = await showModalBottomSheet<String>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          ListTile(leading: const WhatsAppIcon(), title: const Text('Send reminder on WhatsApp'), onTap: () => Navigator.pop(ctx, 'whatsapp')),
          ListTile(leading: const Icon(Icons.check_circle_outline_rounded), title: const Text('Mark as done'), onTap: () => Navigator.pop(ctx, 'done')),
          ListTile(leading: const Icon(Icons.edit_calendar_rounded), title: const Text('Reschedule'), onTap: () => Navigator.pop(ctx, 'edit')),
          ListTile(leading: const Icon(Icons.cancel_outlined), title: const Text('Cancel follow-up'), onTap: () => Navigator.pop(ctx, 'cancel')),
        ]),
      ),
    );
    if (choice == null || !context.mounted) return;
    if (choice == 'whatsapp') {
      await sendFollowUpReminder(context, ref, r, d.patient);
      return;
    }
    if (choice == 'edit') {
      await showFollowUpSheet(context, ref, patientId: r.patientId, existing: r);
      return;
    }
    r.status = choice == 'done' ? 'done' : 'cancelled';
    if (choice == 'done') r.data['done_on'] = ymd(DateTime.now());
    HapticFeedback.lightImpact();
    await saveClinicalRecord(ref, r);
  }
}

Future<void> showFollowUpSheet(BuildContext context, WidgetRef ref, {required int patientId, Encounter? encounter, ClinicalRecord? existing}) async {
  var date = existing?.eventDate ?? DateTime.now().add(const Duration(days: 7));
  final reason = TextEditingController(text: existing?.str('reason') ?? 'Review');
  final saved = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    builder: (ctx) => StatefulBuilder(
      builder: (ctx, setState) => Padding(
        padding: EdgeInsets.fromLTRB(20, 0, 20, MediaQuery.viewInsetsOf(ctx).bottom + 20),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(existing == null ? 'Schedule follow-up' : 'Reschedule follow-up', style: Theme.of(ctx).textTheme.titleLarge),
          const SizedBox(height: 14),
          Wrap(spacing: 8, runSpacing: 8, children: [
            for (final p in followUpPresets)
              ChoiceChip(
                label: Text(p.$1),
                selected: ymd(date) == ymd(DateTime.now().add(Duration(days: p.$2))),
                onSelected: (_) => setState(() => date = DateTime.now().add(Duration(days: p.$2))),
              ),
            ActionChip(
              avatar: const Icon(Icons.calendar_month_rounded, size: 18),
              label: Text(fmtDate(date)),
              onPressed: () async {
                final picked = await showDatePicker(context: ctx, initialDate: date, firstDate: DateTime.now().subtract(const Duration(days: 1)), lastDate: DateTime.now().add(const Duration(days: 730)));
                if (picked != null) setState(() => date = picked);
              },
            ),
          ]),
          const SizedBox(height: 14),
          TextField(controller: reason, maxLength: 200, decoration: const InputDecoration(labelText: 'Reason', hintText: 'e.g. Review with CBC report')),
          const SizedBox(height: 8),
          FilledButton.icon(
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
            onPressed: () => Navigator.pop(ctx, true),
            icon: const Icon(Icons.check_rounded),
            label: Text('Follow-up on ${fmtDate(date)}'),
          ),
        ]),
      ),
    ),
  );
  if (saved != true) return;
  final r = existing ??
      ClinicalRecord.create(patientId: patientId, type: RecordType.followup, data: {
        if (encounter != null) 'visit_kind': encounter.kind,
        if (encounter != null) 'visit_ref_id': encounter.refId,
      });
  r.eventDate = date;
  r.status = 'active';
  r.data['reason'] = reason.text.trim().isEmpty ? 'Review' : reason.text.trim();
  await saveClinicalRecord(ref, r);
  if (context.mounted) showSnack(context, 'Follow-up set for ${fmtDate(date)}', icon: Icons.event_available_rounded);
}

/// Card shell used by every specialty tool on the patient's Care tab.
class CareCard extends StatelessWidget {
  const CareCard({super.key, required this.icon, required this.title, this.subtitle, this.action, this.child, this.onTap, this.alert = false, this.color});
  final IconData icon;
  final String title;
  final String? subtitle;
  final Widget? action;
  final Widget? child;
  final VoidCallback? onTap;
  final bool alert;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    return Card(
      child: Pressable(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 8, 12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              IconBadge(icon, color: alert ? cs.error : (color ?? cs.primary)),
              const SizedBox(width: 12),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(title, style: t.textTheme.titleSmall),
                  if (subtitle != null) Text(subtitle!, style: t.textTheme.bodySmall?.copyWith(color: alert ? cs.error : cs.onSurfaceVariant, fontWeight: alert ? FontWeight.w600 : null)),
                ]),
              ),
              ?action,
              if (action == null && onTap != null) Icon(Icons.chevron_right_rounded, color: cs.outline),
            ]),
            if (child != null) Padding(padding: const EdgeInsets.only(top: 8, right: 6), child: child),
          ]),
        ),
      ),
    );
  }
}
