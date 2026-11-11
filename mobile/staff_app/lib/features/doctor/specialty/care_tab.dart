import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/ui/widgets.dart';
import '../ui/doctor_providers.dart';
import 'specialty.dart';

/// The patient screen's "Care" tab: cards from every specialty toolkit that is switched on for this
/// doctor and relevant for this patient.
class CareTab extends ConsumerWidget {
  const CareTab({super.key, required this.d, this.padding = const EdgeInsets.fromLTRB(16, 12, 16, 110)});
  final PatientDetail d;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final settings = ref.watch(specialtySettingsProvider).valueOrNull;
    final records = ref.watch(clinicalRecordsProvider(d.patient.id)).valueOrNull;
    if (settings == null || records == null) return const SkeletonList(count: 3);
    final t = Theme.of(context);
    final kits = specialtyToolkits.where((k) => (settings.enabled.contains(k.id) && k.appliesTo(d, records)) || k.hasRecords(records)).toList();
    final cards = [for (final k in kits) ...k.buildCards(context, d, records)];
    return ListView(padding: padding, children: [
      Wrap(spacing: 6, runSpacing: 6, children: [
        for (final k in kits)
          Chip(
            avatar: Icon(k.icon, size: 16, color: t.colorScheme.primary),
            label: Text(k.name),
            visualDensity: VisualDensity.compact,
          ),
      ]),
      const SizedBox(height: 10),
      for (final (i, c) in cards.indexed) Padding(padding: const EdgeInsets.only(bottom: 10), child: FadeSlideIn(index: i, child: c)),
      Padding(
        padding: const EdgeInsets.only(top: 4),
        child: Text('Specialty tools follow your specialty on the hospital system. Change them under Account, Specialty tools.',
            textAlign: TextAlign.center, style: t.textTheme.labelSmall?.copyWith(color: t.colorScheme.onSurfaceVariant)),
      ),
    ]);
  }
}
