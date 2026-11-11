import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/ui/widgets.dart';
import '../data/models.dart';
import 'doctor_providers.dart';
import '../print/print_screen.dart';
import 'prescription_editor_screen.dart';
import 'rx_widgets.dart';
import 'whatsapp_actions.dart';

class PrescriptionViewScreen extends ConsumerWidget {
  const PrescriptionViewScreen({super.key, required this.localId});
  final String localId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final rxAsync = ref.watch(prescriptionProvider(localId));
    return rxAsync.when(
      loading: () => Scaffold(appBar: AppBar(), body: const Center(child: CircularProgressIndicator())),
      error: (e, _) => Scaffold(appBar: AppBar(), body: EmptyState(icon: Icons.error_outline, title: 'Could not load', message: '$e')),
      data: (rx) {
        if (rx == null) {
          return Scaffold(appBar: AppBar(), body: const EmptyState(icon: Icons.receipt_long_outlined, title: 'Prescription removed', message: 'It was deleted on the server or discarded.'));
        }
        final detail = ref.watch(patientDetailProvider(rx.patientId)).valueOrNull;
        final encounter = detail?.encounterFor(rx);
        return Scaffold(
          appBar: AppBar(
            title: Text('Prescription ${rx.displayNo}'),
            actions: [
              if (detail != null)
                IconButton(tooltip: 'Send on WhatsApp', icon: const WhatsAppIcon(), onPressed: () => sendPrescriptionWhatsApp(context, ref, rx, detail.patient)),
              IconButton(tooltip: 'Print', icon: const Icon(Icons.print_rounded), onPressed: () => openPrintScreen(context, ref, rx.localId)),
              if (encounter != null && rx.status != RxStatus.conflict)
                IconButton(
                  tooltip: 'Edit',
                  icon: const Icon(Icons.edit_outlined),
                  onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => PrescriptionEditorScreen.edit(rx: rx, encounter: encounter))),
                ),
              if (rx.status != RxStatus.synced)
                PopupMenuButton<String>(
                  onSelected: (v) async {
                    if (v == 'discard') await _discard(context, ref, rx);
                  },
                  itemBuilder: (_) => [
                    PopupMenuItem(value: 'discard', child: Text(rx.isLocalOnly ? 'Delete this unsent prescription' : 'Discard my changes')),
                  ],
                ),
            ],
          ),
          body: ListView(padding: const EdgeInsets.fromLTRB(16, 12, 16, 32), children: [
            if (rx.status == RxStatus.conflict) _ConflictPanel(rx: rx),
            if (rx.status == RxStatus.rejected) _RejectedPanel(rx: rx, canEdit: encounter != null),
            if (rx.status == RxStatus.pending)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Row(children: [
                  const RxStatusPill(RxStatus.pending),
                  const SizedBox(width: 8),
                  Expanded(child: Text('Saved on this phone. Uploads automatically when online.', style: Theme.of(context).textTheme.bodySmall)),
                ]),
              ),
            if (detail != null) ...[
              Text(detail.patient.name, style: Theme.of(context).textTheme.titleMedium),
              Text(['ID ${detail.patient.id}', detail.patient.subtitle].where((s) => s.isNotEmpty).join(' · ')),
              const SizedBox(height: 10),
              AllergyBanner(allergies: detail.allergies, dense: true),
            ],
            _RxBody(j: rx.j, encounter: encounter),
          ]),
        );
      },
    );
  }

  Future<void> _discard(BuildContext context, WidgetRef ref, Prescription rx) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(rx.isLocalOnly ? 'Delete prescription?' : 'Discard changes?'),
        content: Text(rx.isLocalOnly ? 'It has not reached the server and will be deleted from this phone.' : 'The server copy will be shown again.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(rx.isLocalOnly ? 'Delete' : 'Discard')),
        ],
      ),
    );
    if (ok != true) return;
    await ref.read(doctorRepoProvider).discardLocalChanges(rx.localId);
    afterLocalWrite(ref);
    if (context.mounted && rx.isLocalOnly) Navigator.pop(context);
  }
}

class _ConflictPanel extends ConsumerWidget {
  const _ConflictPanel({required this.rx});
  final Prescription rx;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final cs = Theme.of(context).colorScheme;
    final server = rx.serverJson;
    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: cs.errorContainer, borderRadius: BorderRadius.circular(12)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text('Changed by someone else', style: TextStyle(fontWeight: FontWeight.w700, color: cs.onErrorContainer)),
        const SizedBox(height: 4),
        Text('This prescription was edited on the server after you started your changes. Choose which version to keep.', style: TextStyle(color: cs.onErrorContainer)),
        if (server != null) ...[
          const SizedBox(height: 12),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('Server version', style: Theme.of(context).textTheme.labelLarge),
                _RxBody(j: server, encounter: null, compact: true),
              ]),
            ),
          ),
        ],
        const SizedBox(height: 12),
        Wrap(spacing: 8, runSpacing: 8, children: [
          FilledButton(
            onPressed: () async {
              await ref.read(doctorRepoProvider).keepMine(rx.localId);
              afterLocalWrite(ref);
            },
            child: const Text('Keep my version'),
          ),
          OutlinedButton(
            onPressed: () async {
              await ref.read(doctorRepoProvider).useServerVersion(rx.localId);
              afterLocalWrite(ref);
            },
            child: const Text('Use server version'),
          ),
        ]),
      ]),
    );
  }
}

class _RejectedPanel extends StatelessWidget {
  const _RejectedPanel({required this.rx, required this.canEdit});
  final Prescription rx;
  final bool canEdit;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: cs.errorContainer, borderRadius: BorderRadius.circular(12)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text('The server did not accept this prescription', style: TextStyle(fontWeight: FontWeight.w700, color: cs.onErrorContainer)),
        const SizedBox(height: 4),
        Text(rx.error ?? 'No reason given.', style: TextStyle(color: cs.onErrorContainer)),
        const SizedBox(height: 6),
        Text(canEdit ? 'Edit it to fix the problem and save again, or discard it from the menu.' : 'Discard it from the menu.',
            style: TextStyle(color: cs.onErrorContainer, fontSize: 12)),
      ]),
    );
  }
}

/// Medicines, tests and notes of a prescription (server JSON shape).
class _RxBody extends StatelessWidget {
  const _RxBody({required this.j, required this.encounter, this.compact = false});
  final Map<String, dynamic> j;
  final Encounter? encounter;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final rx = Prescription(localId: '', serverId: null, patientId: 0, kind: '', caseRefId: 0, status: RxStatus.synced, version: null, error: null, j: j);
    final tests = [...rx.pathology.map((x) => ('Pathology', x.name)), ...rx.radiology.map((x) => ('Radiology', x.name))];
    final pad = compact ? const EdgeInsets.only(top: 10, bottom: 4) : const EdgeInsets.fromLTRB(0, 20, 0, 8);

    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      if (!compact) ...[
        const SizedBox(height: 16),
        Text([fmtDate(rx.date), if (encounter != null) '${encounter!.label} of ${fmtDate(encounter!.date)}'].join(' · '), style: t.textTheme.bodyMedium),
        if (rx.prescribedBy != null && rx.prescribedBy!.isNotEmpty) Text('Prescribed by Dr. ${rx.prescribedBy}', style: t.textTheme.bodySmall),
      ],
      if (rx.headerNote != null) ...[SectionHeader('Notes', padding: pad), Text(rx.headerNote!)],
      if (rx.findings != null) ...[SectionHeader('Findings', padding: pad), Text(rx.findings!)],
      if (rx.newDiagnoses.isNotEmpty) ...[
        SectionHeader('Diagnosis to add', padding: pad),
        for (final c in rx.newDiagnoses) Text('${c.code}  ${c.description}'),
      ],
      SectionHeader('Medicines', padding: pad),
      if (rx.items.isEmpty) const Text('None'),
      for (var i = 0; i < rx.items.length; i++)
        Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            SizedBox(width: 24, child: Text('${i + 1}.', style: t.textTheme.bodyMedium)),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(rx.items[i].medicineName, style: const TextStyle(fontWeight: FontWeight.w600)),
                if (rx.items[i].composition != null) Text(rx.items[i].composition!, style: t.textTheme.bodySmall),
                if (rx.items[i].doseLine.isNotEmpty) Text(rx.items[i].doseLine),
                if (rx.items[i].instruction.isNotEmpty) Text(rx.items[i].instruction, style: t.textTheme.bodySmall?.copyWith(fontStyle: FontStyle.italic)),
              ]),
            ),
          ]),
        ),
      if (tests.isNotEmpty) ...[
        SectionHeader('Tests', padding: pad),
        for (final x in tests) Text('${x.$2}  ·  ${x.$1}'),
      ],
      if (rx.footerNote != null) ...[SectionHeader('Advice', padding: pad), Text(rx.footerNote!)],
    ]);
  }
}
