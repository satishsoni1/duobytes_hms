import 'package:flutter/material.dart';

import '../../../core/ui/widgets.dart';
import '../data/lab_flags.dart';
import '../data/models.dart';

class RxStatusPill extends StatelessWidget {
  const RxStatusPill(this.status, {super.key});
  final RxStatus status;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return switch (status) {
      RxStatus.synced => const SizedBox.shrink(),
      RxStatus.pending => StatusPill('Waiting to sync', bg: cs.secondaryContainer, fg: cs.onSecondaryContainer, icon: Icons.cloud_upload_outlined),
      RxStatus.conflict => StatusPill('Conflict', bg: cs.errorContainer, fg: cs.onErrorContainer, icon: Icons.call_split),
      RxStatus.rejected => StatusPill('Not accepted', bg: cs.errorContainer, fg: cs.onErrorContainer, icon: Icons.block),
    };
  }
}

class PrescriptionCard extends StatelessWidget {
  const PrescriptionCard({super.key, required this.rx, this.encounter, this.onTap});
  final Prescription rx;
  final Encounter? encounter;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final items = rx.items;
    final tests = [...rx.pathology, ...rx.radiology];
    final cs = t.colorScheme;
    return Card(
      child: Pressable(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              const IconBadge(Icons.receipt_long_rounded),
              const SizedBox(width: 12),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text([fmtDate(rx.date), if (encounter != null) encounter!.isIpd ? 'IPD' : 'OPD'].join(' · '), style: t.textTheme.titleSmall),
                  if (rx.prescribedBy != null && rx.prescribedBy!.isNotEmpty)
                    Text('Dr. ${rx.prescribedBy}', style: t.textTheme.bodySmall?.copyWith(color: cs.onSurfaceVariant)),
                ]),
              ),
              RxStatusPill(rx.status),
            ]),
            if (items.isNotEmpty) const SizedBox(height: 12),
            for (final (i, it) in items.take(4).indexed)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Row(children: [
                  Container(
                    width: 22,
                    height: 22,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(color: cs.secondaryContainer, shape: BoxShape.circle),
                    child: Text('${i + 1}', style: t.textTheme.labelSmall?.copyWith(color: cs.onSecondaryContainer, fontWeight: FontWeight.w700)),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text.rich(TextSpan(children: [
                      TextSpan(text: it.medicineName, style: const TextStyle(fontWeight: FontWeight.w600)),
                      if (it.doseLine.isNotEmpty) TextSpan(text: '  ${it.doseLine}', style: TextStyle(color: cs.onSurfaceVariant)),
                    ]), maxLines: 1, overflow: TextOverflow.ellipsis),
                  ),
                ]),
              ),
            if (items.length > 4)
              Padding(padding: const EdgeInsets.only(left: 32), child: Text('+${items.length - 4} more', style: t.textTheme.labelMedium?.copyWith(color: cs.primary))),
            if (tests.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Wrap(spacing: 6, runSpacing: 6, children: [
                  for (final x in tests.take(4))
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(color: cs.tertiaryContainer.withValues(alpha: 0.6), borderRadius: BorderRadius.circular(8)),
                      child: Row(mainAxisSize: MainAxisSize.min, children: [
                        Icon(Icons.biotech_rounded, size: 12, color: cs.onTertiaryContainer),
                        const SizedBox(width: 4),
                        Text(x.name, style: t.textTheme.labelSmall?.copyWith(color: cs.onTertiaryContainer)),
                      ]),
                    ),
                  if (tests.length > 4) Text('+${tests.length - 4}', style: t.textTheme.labelSmall),
                ]),
              ),
            if (rx.error != null && rx.status != RxStatus.synced)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(rx.error!, style: t.textTheme.bodySmall?.copyWith(color: cs.error)),
              ),
          ]),
        ),
      ),
    );
  }
}

class EncounterCard extends StatelessWidget {
  const EncounterCard({super.key, required this.encounter, this.diagnoses = const [], this.prescriptionCount});
  final Encounter encounter;
  final List<IcdCode> diagnoses;
  final int? prescriptionCount;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final e = encounter;
    final vitals = e.vitals.present;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            IconBadge(e.isIpd ? Icons.bed_rounded : Icons.medical_services_rounded, color: e.isIpd ? t.colorScheme.tertiary : t.colorScheme.primary),
            const SizedBox(width: 12),
            Expanded(child: Text('${e.label} · ${fmtDateTime(e.date)}', style: t.textTheme.titleSmall)),
            if (e.isOpen)
              StatusPill(e.isIpd ? 'Admitted' : 'Open', bg: t.colorScheme.primaryContainer, fg: t.colorScheme.onPrimaryContainer)
            else
              StatusPill('Discharged', bg: t.colorScheme.surfaceContainerHighest, fg: t.colorScheme.onSurfaceVariant),
          ]),
          const SizedBox(height: 6),
          _line(context, [if (e.doctorName != null && e.doctorName!.isNotEmpty) 'Dr. ${e.doctorName}', e.bed, e.caseType].whereType<String>().where((s) => s.isNotEmpty).join(' · ')),
          if (e.symptoms != null) ...[
            const SizedBox(height: 6),
            Text('Symptoms', style: t.textTheme.labelSmall?.copyWith(color: t.colorScheme.onSurfaceVariant)),
            Text(e.symptoms!),
          ],
          if (vitals.isNotEmpty) ...[
            const SizedBox(height: 8),
            Wrap(spacing: 6, runSpacing: 6, children: [
              for (final v in vitals.entries) _chip(context, '${v.key} ${v.value}'),
            ]),
          ],
          if (diagnoses.isNotEmpty) ...[
            const SizedBox(height: 8),
            Text('Diagnosis', style: t.textTheme.labelSmall?.copyWith(color: t.colorScheme.onSurfaceVariant)),
            for (final c in diagnoses) Text('${c.code}  ${c.description}', maxLines: 2, overflow: TextOverflow.ellipsis),
          ],
          if (e.note != null) ...[
            const SizedBox(height: 6),
            Text(e.note!, style: t.textTheme.bodySmall),
          ],
          if (prescriptionCount != null && prescriptionCount! > 0) ...[
            const SizedBox(height: 6),
            Text('$prescriptionCount prescription${prescriptionCount == 1 ? '' : 's'}', style: t.textTheme.bodySmall?.copyWith(color: t.colorScheme.primary)),
          ],
        ]),
      ),
    );
  }

  Widget _line(BuildContext context, String s) =>
      s.isEmpty ? const SizedBox.shrink() : Text(s, style: Theme.of(context).textTheme.bodySmall?.copyWith(color: Theme.of(context).colorScheme.onSurfaceVariant));

  Widget _chip(BuildContext context, String s) {
    final cs = Theme.of(context).colorScheme;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(color: cs.surfaceContainerHigh, borderRadius: BorderRadius.circular(10), border: Border.all(color: cs.outlineVariant.withValues(alpha: 0.5))),
      child: Text(s, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: cs.onSurface)),
    );
  }
}

class LabReportCard extends StatelessWidget {
  const LabReportCard({super.key, required this.report, this.initiallyExpanded = false, this.onOpenFile, this.isNew = false});
  final LabReport report;
  final bool initiallyExpanded;

  /// Opens the uploaded report document; shown only when the lab attached one.
  final VoidCallback? onOpenFile;

  /// Highlight as not yet viewed (doctor reports inbox).
  final bool isNew;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final r = report;
    final params = r.parameters.where((p) => p.value.isNotEmpty).toList();
    final abnormal = r.abnormal.length;
    return Card(
      clipBehavior: Clip.antiAlias,
      child: ExpansionTile(
        initiallyExpanded: initiallyExpanded,
        shape: const Border(),
        leading: IconBadge(r.kind == 'radiology' ? Icons.monitor_heart_rounded : Icons.biotech_rounded, color: abnormal > 0 ? cs.error : r.kind == 'radiology' ? cs.tertiary : cs.primary),
        title: Row(children: [
          Flexible(child: Text(r.testName, overflow: TextOverflow.ellipsis)),
          if (isNew) ...[const SizedBox(width: 6), StatusPill('New', bg: cs.primary, fg: cs.onPrimary)],
        ]),
        subtitle: Text.rich(TextSpan(children: [
          TextSpan(text: r.isReported ? 'Reported ${fmtDate(r.reportingDate)}' : 'Awaiting report${r.collectionDate != null ? ' · collected ${fmtDate(r.collectionDate)}' : ''}'),
          if (abnormal > 0) TextSpan(text: ' · $abnormal abnormal', style: TextStyle(color: cs.error, fontWeight: FontWeight.w700)),
          if (r.hasFile) const TextSpan(text: ' · file attached'),
        ])),
        childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
        expandedCrossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (params.isNotEmpty)
            Table(
              columnWidths: const {0: FlexColumnWidth(3), 1: FlexColumnWidth(2.4), 2: FlexColumnWidth(2)},
              defaultVerticalAlignment: TableCellVerticalAlignment.middle,
              children: [
                TableRow(children: [
                  for (final h in const ['Parameter', 'Result', 'Range'])
                    Padding(padding: const EdgeInsets.symmetric(vertical: 4), child: Text(h, style: t.textTheme.labelSmall?.copyWith(color: cs.onSurfaceVariant))),
                ]),
                for (final p in params)
                  () {
                    final f = flagFor(p.value, p.referenceRange);
                    final color = f == LabFlag.high ? cs.error : f == LabFlag.low ? cs.secondary : null;
                    return TableRow(
                      decoration: color == null ? null : BoxDecoration(color: color.withValues(alpha: 0.07)),
                      children: [
                        Padding(padding: const EdgeInsets.symmetric(vertical: 4), child: Text(p.name)),
                        Text.rich(TextSpan(children: [
                          TextSpan(text: '${p.value}${p.unit != null ? ' ${p.unit}' : ''}', style: TextStyle(fontWeight: FontWeight.w700, color: color)),
                          if (f == LabFlag.high || f == LabFlag.low) ...[
                            WidgetSpan(
                              alignment: PlaceholderAlignment.middle,
                              child: Padding(
                                padding: const EdgeInsets.only(left: 4),
                                child: Icon(f == LabFlag.high ? Icons.arrow_upward_rounded : Icons.arrow_downward_rounded, size: 14, color: color),
                              ),
                            ),
                            TextSpan(text: f == LabFlag.high ? 'High' : 'Low', style: TextStyle(color: color, fontWeight: FontWeight.w700, fontSize: 11)),
                          ],
                        ])),
                        Text(p.referenceRange ?? '', style: t.textTheme.bodySmall),
                      ],
                    );
                  }(),
              ],
            ),
          if (r.result != null) ...[const SizedBox(height: 6), Text(r.result!)],
          if (r.description != null) ...[const SizedBox(height: 6), Text(r.description!, style: t.textTheme.bodySmall)],
          if (params.isEmpty && r.result == null && r.description == null && !r.hasFile) const Text('No results entered yet.'),
          if (r.hasFile && onOpenFile != null) ...[
            const SizedBox(height: 10),
            FilledButton.tonalIcon(
              onPressed: onOpenFile,
              icon: const Icon(Icons.picture_as_pdf_rounded),
              label: Text(r.fileName == null ? 'Open report file' : 'Open ${r.fileName}', overflow: TextOverflow.ellipsis),
            ),
          ],
        ],
      ),
    );
  }
}
