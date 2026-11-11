import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../core/ui/widgets.dart';
import '../doctor/data/models.dart';
import '../doctor/ui/doctor_providers.dart';
import '../opd/registration_screen.dart';
import 'staff_data.dart';

final staffRepoProvider = Provider<StaffRepository>((ref) => StaffRepository(ref.watch(databaseProvider)));

final vitalTypesProvider = FutureProvider.autoDispose<List<VitalType>>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(staffRepoProvider).vitalTypes();
});

final pendingNotesProvider = FutureProvider.autoDispose.family<List<PendingNote>, int>((ref, patientId) {
  ref.watch(dataRevisionProvider);
  return ref.watch(staffRepoProvider).pendingNotes(patientId);
});

/// Actions for reception / nursing on a patient (the doctor's equivalent is "New prescription").
Future<void> showStaffPatientActions(BuildContext context, WidgetRef ref, PatientDetail d) async {
  final admission = d.encounters.where((e) => e.isIpd && e.isOpen).firstOrNull;
  final choice = await showModalBottomSheet<String>(
    context: context,
    builder: (ctx) => SafeArea(
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        ListTile(leading: const Icon(Icons.monitor_heart_rounded), title: const Text('Record vitals'), onTap: () => Navigator.pop(ctx, 'vitals')),
        ListTile(
          leading: const Icon(Icons.edit_note_rounded),
          title: const Text('Nursing note'),
          subtitle: Text(admission == null ? 'Only for admitted patients' : 'On the current admission'),
          enabled: admission != null,
          onTap: () => Navigator.pop(ctx, 'note'),
        ),
        ListTile(leading: const Icon(Icons.add_task_rounded), title: const Text('New OPD visit'), onTap: () => Navigator.pop(ctx, 'visit')),
      ]),
    ),
  );
  if (choice == null || !context.mounted) return;
  switch (choice) {
    case 'vitals':
      await showVitalsSheet(context, ref, d.patient);
    case 'note':
      await showNurseNoteSheet(context, ref, d.patient, admission!);
    case 'visit':
      await Navigator.push(context, MaterialPageRoute(builder: (_) => RegisterPatientScreen(existing: d.patient)));
  }
}

/// Enter readings for the hospital's vital sign types (from the web vitals setup).
Future<void> showVitalsSheet(BuildContext context, WidgetRef ref, Patient patient) async {
  final types = await ref.read(staffRepoProvider).vitalTypes();
  if (!context.mounted) return;
  if (types.isEmpty) {
    showSnack(context, 'No vital sign types on this device yet. Sync once with internet.', error: true);
    return;
  }
  final controllers = {for (final t in types) t: TextEditingController()};
  final ok = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (ctx) => Padding(
      padding: EdgeInsets.fromLTRB(20, 0, 20, MediaQuery.viewInsetsOf(ctx).bottom + 16),
      child: SingleChildScrollView(
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text('Vitals · ${patient.name}', style: Theme.of(ctx).textTheme.titleLarge),
          const SizedBox(height: 12),
          for (final t in types)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: TextField(
                controller: controllers[t],
                keyboardType: t.name.toLowerCase() == 'bp' ? TextInputType.text : const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9./ ]'))],
                decoration: InputDecoration(labelText: t.name, suffixText: t.unit, helperText: t.range == null ? null : 'Normal ${t.range}'),
              ),
            ),
          FilledButton(style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)), onPressed: () => Navigator.pop(ctx, true), child: const Text('Save vitals')),
        ]),
      ),
    ),
  );
  if (ok != true) return;
  try {
    await ref.read(staffRepoProvider).recordVitals(patient.id, {for (final e in controllers.entries) e.key: e.value.text});
    afterLocalWrite(ref);
    if (context.mounted) showSnack(context, 'Vitals saved', icon: Icons.monitor_heart_rounded);
  } on ArgumentError catch (e) {
    if (context.mounted) showSnack(context, '${e.message}', error: true);
  }
}

Future<void> showNurseNoteSheet(BuildContext context, WidgetRef ref, Patient patient, Encounter admission) async {
  final note = TextEditingController();
  final comment = TextEditingController();
  final ok = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    builder: (ctx) => Padding(
      padding: EdgeInsets.fromLTRB(20, 0, 20, MediaQuery.viewInsetsOf(ctx).bottom + 16),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text('Nursing note · ${patient.name}', style: Theme.of(ctx).textTheme.titleLarge),
        const SizedBox(height: 12),
        Wrap(spacing: 6, runSpacing: 6, children: [
          for (final q in const ['Medication given as charted', 'IV fluids running', 'Patient resting comfortably', 'Dressing changed', 'Vomiting once', 'Doctor informed'])
            ActionChip(label: Text(q), onPressed: () => note.text = note.text.isEmpty ? q : '${note.text}. $q'),
        ]),
        const SizedBox(height: 10),
        TextField(controller: note, autofocus: true, maxLines: 4, textCapitalization: TextCapitalization.sentences, decoration: const InputDecoration(labelText: 'Note *')),
        const SizedBox(height: 10),
        TextField(controller: comment, maxLines: 2, decoration: const InputDecoration(labelText: 'Comment (optional)')),
        const SizedBox(height: 12),
        FilledButton(style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)), onPressed: () => Navigator.pop(ctx, true), child: const Text('Save note')),
      ]),
    ),
  );
  if (ok != true) return;
  try {
    await ref.read(staffRepoProvider).addNurseNote(patientId: patient.id, ipdId: admission.refId, note: note.text, comment: comment.text);
    afterLocalWrite(ref);
    if (context.mounted) showSnack(context, 'Note saved; it uploads on the next sync', icon: Icons.edit_note_rounded);
  } on ArgumentError catch (e) {
    if (context.mounted) showSnack(context, '${e.message}', error: true);
  }
}
