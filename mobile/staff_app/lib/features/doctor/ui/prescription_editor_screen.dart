import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/ui/widgets.dart';
import '../data/allergy_check.dart';
import '../data/models.dart';
import '../data/clinical.dart';
import '../specialty/followups.dart';
import '../print/print_screen.dart';
import '../specialty/specialty.dart';
import 'doctor_providers.dart';
import 'pickers.dart';

/// Write or edit a prescription. Works fully offline: Save stores it on the phone and queues it;
/// it reaches the hospital system on the next sync.
class PrescriptionEditorScreen extends ConsumerStatefulWidget {
  const PrescriptionEditorScreen._({required this.patientId, required this.encounter, this.existing, this.copyFrom});

  factory PrescriptionEditorScreen.create({required int patientId, required Encounter encounter, Prescription? copyFrom}) =>
      PrescriptionEditorScreen._(patientId: patientId, encounter: encounter, copyFrom: copyFrom);

  factory PrescriptionEditorScreen.edit({required Prescription rx, required Encounter encounter}) =>
      PrescriptionEditorScreen._(patientId: rx.patientId, encounter: encounter, existing: rx);

  final int patientId;
  final Encounter encounter;
  final Prescription? existing;
  final Prescription? copyFrom;

  @override
  ConsumerState<PrescriptionEditorScreen> createState() => _EditorState();
}

class _EditorState extends ConsumerState<PrescriptionEditorScreen> {
  late PrescriptionDraft _d;
  late final _findings = TextEditingController();
  late final _header = TextEditingController();
  late final _advice = TextEditingController();
  final _instructionCtrls = <RxItem, TextEditingController>{};
  bool _dirty = false;
  bool _saving = false;

  /// Optional follow-up created together with the prescription.
  DateTime? _followUp;
  final _followUpReason = TextEditingController(text: 'Review');

  @override
  void initState() {
    super.initState();
    final ex = widget.existing;
    if (ex != null) {
      _d = PrescriptionDraft.fromPrescription(ex, widget.encounter);
    } else {
      _d = PrescriptionDraft(patientId: widget.patientId, encounter: widget.encounter);
      final c = widget.copyFrom;
      if (c != null) {
        _d.items = c.items.map((i) => i.copy(keepId: false)).toList();
        _d.pathology = c.pathology;
        _d.radiology = c.radiology;
        _d.footerNote = c.footerNote ?? '';
        _dirty = true;
      }
    }
    _findings.text = _d.findings;
    _header.text = _d.headerNote;
    _advice.text = _d.footerNote;
    for (final c in [_findings, _header, _advice]) {
      c.addListener(() => _dirty = true);
    }
  }

  @override
  void dispose() {
    _findings.dispose();
    _header.dispose();
    _advice.dispose();
    _followUpReason.dispose();
    for (final c in _instructionCtrls.values) {
      c.dispose();
    }
    super.dispose();
  }

  TextEditingController _instructionCtrl(RxItem it) => _instructionCtrls.putIfAbsent(it, () {
    final c = TextEditingController(text: it.instruction);
    c.addListener(() {
      it.instruction = c.text;
      _dirty = true;
    });
    return c;
  });

  void _change(VoidCallback f) => setState(() {
    f();
    _dirty = true;
  });

  // ------------------------------------------------------------------ actions
  Future<void> _addMedicine(Masters masters, AllergyCheck allergies) async {
    final m = await pickMedicine(context, allergies: allergies, alreadyAdded: _d.items.map((e) => e.medicineId).toSet());
    if (m == null || !mounted) return;
    final allergy = allergies.matchMedicine(m);
    if (allergy != null && !await _confirmAllergy(m.name, allergy)) return;
    final doses = masters.dosagesFor(m.categoryId);
    _change(
      () => _d.items.add(
        RxItem(
          medicineId: m.id,
          medicineName: m.name,
          composition: m.composition,
          categoryId: m.categoryId,
          dosageId: doses.length == 1 ? doses.first.id : null,
          dosageLabel: doses.length == 1 ? doses.first.label : null,
        ),
      ),
    );
  }

  Future<bool> _confirmAllergy(String medicine, String allergy) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        icon: Icon(Icons.warning_amber_rounded, color: Theme.of(ctx).colorScheme.error),
        title: const Text('Possible allergy'),
        content: Text('$medicine may match the recorded allergy "$allergy".\n\nThis is a basic name check, not a full interaction check. Prescribe anyway?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Prescribe anyway')),
        ],
      ),
    );
    return ok == true;
  }

  Future<void> _copyLast(Masters masters) async {
    final last = await ref.read(doctorRepoProvider).lastPrescription(widget.patientId, excludeLocalId: _d.localId);
    if (!mounted) return;
    if (last == null) {
      showSnack(context, 'No earlier prescription with medicines for this patient.');
      return;
    }
    _mergeItems(last.items.map((i) => i.copy(keepId: false)).toList(), masters);
    showSnack(context, 'Copied ${last.items.length} medicine(s) from ${fmtDate(last.date)}. Review doses before saving.');
  }

  Future<void> _applyTemplate(Masters masters) async {
    final t = await pickTemplate(context);
    if (t == null || !mounted) return;
    _mergeItems(t.items, masters);
    final codes = await ref.read(doctorRepoProvider).icdByIds(t.diagnosisIds);
    _change(() {
      for (final id in t.pathologyIds) {
        final x = masters.pathology.where((p) => p.id == id).firstOrNull;
        if (x != null && !_d.pathology.any((p) => p.id == id)) _d.pathology.add(x);
      }
      for (final id in t.radiologyIds) {
        final x = masters.radiology.where((p) => p.id == id).firstOrNull;
        if (x != null && !_d.radiology.any((p) => p.id == id)) _d.radiology.add(x);
      }
      for (final c in codes) {
        if (!_d.diagnoses.contains(c)) _d.diagnoses.add(c);
      }
      if (_advice.text.trim().isEmpty && (t.advice ?? '').isNotEmpty) _advice.text = t.advice!;
    });
    if (mounted) showSnack(context, 'Applied template "${t.name}".');
  }

  /// Add medicines not already on the prescription, filling dose/frequency labels from the masters.
  void _mergeItems(List<RxItem> items, Masters masters) {
    final have = _d.items.map((e) => e.medicineId).toSet();
    _change(() {
      for (final it in items) {
        if (have.contains(it.medicineId)) continue;
        it.dosageLabel ??= _label(masters.dosagesFor(it.categoryId), it.dosageId) ?? _label(masters.allDosages, it.dosageId);
        it.intervalName ??= _label(masters.intervals, it.intervalId);
        it.durationName ??= _label(masters.durations, it.durationId);
        _d.items.add(it);
      }
    });
  }

  String? _label(List<Option> opts, int? id) => id == null ? null : opts.where((o) => o.id == id).firstOrNull?.label;

  Future<void> _save({bool print = false}) async {
    _d.findings = _findings.text;
    _d.headerNote = _header.text;
    _d.footerNote = _advice.text;
    final errors = _d.validate();
    if (errors.isNotEmpty) {
      await showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Almost done'),
          content: Text(errors.map((e) => '• $e').join('\n')),
          actions: [FilledButton(onPressed: () => Navigator.pop(ctx), child: const Text('OK'))],
        ),
      );
      return;
    }
    setState(() => _saving = true);
    try {
      final savedId = await ref.read(doctorRepoProvider).savePrescription(_d);
      if (_followUp != null) {
        final f = ClinicalRecord.create(
          patientId: widget.patientId,
          type: RecordType.followup,
          eventDate: _followUp,
          data: {
            'reason': _followUpReason.text.trim().isEmpty ? 'Review' : _followUpReason.text.trim(),
            'visit_kind': widget.encounter.kind,
            'visit_ref_id': widget.encounter.refId,
          },
        );
        await ref.read(clinicalRepoProvider).save(f);
      }
      HapticFeedback.mediumImpact();
      afterLocalWrite(ref);
      _dirty = false;
      if (!mounted) return;
      showSnack(context, 'Prescription saved. It will sync automatically.', icon: Icons.cloud_upload_rounded);
      if (print) {
        final job = await buildPrintJob(ref, savedId);
        if (!mounted) return;
        if (job != null) {
          await Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => PrintScreen(job: job)));
          return;
        }
      }
      if (mounted) Navigator.pop(context);
    } catch (e) {
      if (mounted) showSnack(context, 'Could not save: $e', error: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _saveAsTemplate() async {
    if (_d.items.isEmpty) {
      showSnack(context, 'Add at least one medicine first.');
      return;
    }
    _d.footerNote = _advice.text;
    final name = TextEditingController();
    var shared = false;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setState) => AlertDialog(
          title: const Text('Save as template'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: name,
                autofocus: true,
                maxLength: 100,
                decoration: const InputDecoration(labelText: 'Template name', hintText: 'e.g. Viral fever – adult'),
              ),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                value: shared,
                onChanged: (v) => setState(() => shared = v),
                title: const Text('Share with other doctors'),
              ),
            ],
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
            FilledButton(onPressed: () => Navigator.pop(ctx, name.text.trim().isNotEmpty), child: const Text('Save')),
          ],
        ),
      ),
    );
    if (ok != true) return;
    await ref.read(doctorRepoProvider).saveTemplate(name.text.trim(), shared, _d);
    afterLocalWrite(ref);
    if (mounted) showSnack(context, 'Template saved.');
  }

  Future<bool> _confirmLeave() async {
    if (!_dirty) return true;
    final leave = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Discard this prescription?'),
        content: const Text('Your changes have not been saved.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Keep editing')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Discard')),
        ],
      ),
    );
    return leave == true;
  }

  // ------------------------------------------------------------------ build
  @override
  Widget build(BuildContext context) {
    final masters = ref.watch(mastersProvider);
    final detail = ref.watch(patientDetailProvider(widget.patientId)).valueOrNull;
    final allergies = AllergyCheck(detail?.allergies ?? const []);

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) return;
        if (await _confirmLeave() && context.mounted) Navigator.pop(context);
      },
      child: Scaffold(
        appBar: AppBar(
          title: Text(widget.existing == null ? 'New prescription' : 'Edit prescription'),
          actions: [
            PopupMenuButton<String>(
              onSelected: (v) {
                final m = masters.valueOrNull;
                if (m == null) return;
                switch (v) {
                  case 'template':
                    _applyTemplate(m);
                  case 'copy':
                    _copyLast(m);
                  case 'save_template':
                    _saveAsTemplate();
                }
              },
              itemBuilder: (_) => const [
                PopupMenuItem(
                  value: 'template',
                  child: ListTile(leading: Icon(Icons.bookmarks_outlined), title: Text('Apply template')),
                ),
                PopupMenuItem(
                  value: 'copy',
                  child: ListTile(leading: Icon(Icons.replay), title: Text('Copy last prescription')),
                ),
                PopupMenuItem(
                  value: 'save_template',
                  child: ListTile(leading: Icon(Icons.bookmark_add_outlined), title: Text('Save as template')),
                ),
              ],
            ),
          ],
        ),
        body: masters.when(
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (e, _) => EmptyState(icon: Icons.error_outline, title: 'Could not load medicine lists', message: '$e'),
          data: (m) => m.isEmpty
              ? const EmptyState(
                  icon: Icons.cloud_download_outlined,
                  title: 'Medicine lists not downloaded yet',
                  message: 'Connect to the internet once so the pharmacy and dose lists can be saved on this phone. After that you can prescribe offline.',
                )
              : _form(context, m, detail, allergies),
        ),
        bottomNavigationBar: SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
            child: Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(50)),
                    onPressed: _saving || masters.valueOrNull?.isEmpty != false ? null : () => _save(print: true),
                    icon: const Icon(Icons.print_rounded),
                    label: const Text('Save & print'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  flex: 3,
                  child: FilledButton.icon(
                    style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)),
                    onPressed: _saving || masters.valueOrNull?.isEmpty != false ? null : _save,
                    icon: AnimatedSwitcher(
                      duration: Motion.fast,
                      child: _saving
                          ? const SizedBox(key: ValueKey('s'), width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                          : const Icon(Icons.check_rounded, key: ValueKey('i')),
                    ),
                    label: const Text('Save prescription'),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _form(BuildContext context, Masters m, PatientDetail? detail, AllergyCheck allergies) {
    final t = Theme.of(context);
    final e = widget.encounter;
    final existingDx = detail?.diagnoses[e.key] ?? const <IcdCode>[];

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
      children: [
        // Patient & case
        if (detail != null)
          FadeSlideIn(
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        PatientAvatar(name: detail.patient.name, radius: 22),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(detail.patient.name, style: t.textTheme.titleMedium),
                              Text(
                                [
                                  if (detail.patient.subtitle.isNotEmpty) detail.patient.subtitle,
                                  '${e.label} ${fmtDate(e.date)}',
                                  if (e.bed != null && e.bed!.isNotEmpty) e.bed!,
                                ].join(' · '),
                                style: t.textTheme.bodySmall?.copyWith(color: t.colorScheme.onSurfaceVariant),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    if (e.symptoms != null) ...[
                      const SizedBox(height: 10),
                      Text.rich(
                        TextSpan(
                          children: [
                            TextSpan(
                              text: 'Symptoms  ',
                              style: t.textTheme.labelMedium?.copyWith(color: t.colorScheme.primary),
                            ),
                            TextSpan(text: e.symptoms, style: t.textTheme.bodySmall),
                          ],
                        ),
                      ),
                    ],
                    if (detail.allergies.isNotEmpty) ...[const SizedBox(height: 10), AllergyBanner(allergies: detail.allergies, dense: true)],
                  ],
                ),
              ),
            ),
          ),
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            onPressed: () async {
              final now = DateTime.now();
              final picked = await showDatePicker(context: context, initialDate: _d.date, firstDate: now.subtract(const Duration(days: 60)), lastDate: now);
              if (picked != null) _change(() => _d.date = picked);
            },
            icon: const Icon(Icons.event, size: 18),
            label: Text('Date: ${fmtDate(_d.date)}'),
          ),
        ),

        // Diagnosis
        SectionHeader(
          'Diagnosis',
          padding: const EdgeInsets.fromLTRB(0, 8, 0, 6),
          trailing: TextButton.icon(
            onPressed: () async {
              final c = await pickIcd(context);
              if (c != null && !_d.diagnoses.contains(c) && !existingDx.contains(c)) _change(() => _d.diagnoses.add(c));
            },
            icon: const Icon(Icons.add, size: 18),
            label: const Text('Add'),
          ),
        ),
        if (existingDx.isEmpty && _d.diagnoses.isEmpty) Text('None recorded', style: t.textTheme.bodySmall),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final c in existingDx) Chip(label: Text('${c.code} ${c.description}', overflow: TextOverflow.ellipsis)),
            for (final c in _d.diagnoses)
              InputChip(
                label: Text('${c.code} ${c.description}', overflow: TextOverflow.ellipsis),
                onDeleted: () => _change(() => _d.diagnoses.remove(c)),
              ),
          ],
        ),

        // Medicines
        SectionHeader('Medicines · ${_d.items.length}', padding: const EdgeInsets.fromLTRB(0, 20, 0, 6)),
        for (var i = 0; i < _d.items.length; i++)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: FadeSlideIn(
              key: ObjectKey(_d.items[i]),
              child: _MedicineCard(
                item: _d.items[i],
                index: i,
                masters: m,
                allergy: allergies.matchItem(_d.items[i]),
                instruction: _instructionCtrl(_d.items[i]),
                onChanged: () => _change(() {}),
                onRemove: () {
                  HapticFeedback.selectionClick();
                  _change(() {
                    final it = _d.items.removeAt(i);
                    _instructionCtrls.remove(it)?.dispose();
                  });
                },
              ),
            ),
          ),
        Row(
          children: [
            Expanded(
              child: FilledButton.tonalIcon(
                onPressed: () => _addMedicine(m, allergies),
                icon: const Icon(Icons.add_rounded),
                label: const Text('Add medicine'),
              ),
            ),
            if (_d.items.isEmpty) ...[
              const SizedBox(width: 8),
              OutlinedButton.icon(onPressed: () => _copyLast(m), icon: const Icon(Icons.replay), label: const Text('Copy last')),
            ],
          ],
        ),

        // Tests
        SectionHeader(
          'Tests',
          padding: const EdgeInsets.fromLTRB(0, 20, 0, 6),
          trailing: TextButton.icon(
            onPressed: () async {
              final s = await pickTests(context, masters: m, pathology: _d.pathology, radiology: _d.radiology);
              if (s != null) {
                _change(() {
                  _d.pathology = s.pathology;
                  _d.radiology = s.radiology;
                });
              }
            },
            icon: const Icon(Icons.add, size: 18),
            label: Text(_d.pathology.isEmpty && _d.radiology.isEmpty ? 'Add' : 'Change'),
          ),
        ),
        if (_d.pathology.isEmpty && _d.radiology.isEmpty) Text('No tests ordered', style: t.textTheme.bodySmall),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final x in _d.pathology)
              InputChip(avatar: const Icon(Icons.biotech_outlined, size: 16), label: Text(x.name), onDeleted: () => _change(() => _d.pathology.remove(x))),
            for (final x in _d.radiology)
              InputChip(
                avatar: const Icon(Icons.monitor_heart_outlined, size: 16),
                label: Text(x.name),
                onDeleted: () => _change(() => _d.radiology.remove(x)),
              ),
          ],
        ),

        // Follow-up
        const SectionHeader('Follow-up', padding: EdgeInsets.fromLTRB(0, 20, 0, 6)),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            ChoiceChip(label: const Text('None'), selected: _followUp == null, onSelected: (_) => _change(() => _followUp = null)),
            for (final p in followUpPresets)
              ChoiceChip(
                label: Text(p.$1),
                selected: _followUp != null && ymd(_followUp!) == ymd(DateTime.now().add(Duration(days: p.$2))),
                onSelected: (_) => _change(() => _followUp = DateTime.now().add(Duration(days: p.$2))),
              ),
            ActionChip(
              avatar: const Icon(Icons.calendar_month_rounded, size: 18),
              label: Text(_followUp == null ? 'Pick date' : fmtDate(_followUp)),
              onPressed: () async {
                final picked = await showDatePicker(
                  context: context,
                  initialDate: _followUp ?? DateTime.now().add(const Duration(days: 7)),
                  firstDate: DateTime.now(),
                  lastDate: DateTime.now().add(const Duration(days: 730)),
                );
                if (picked != null) _change(() => _followUp = picked);
              },
            ),
          ],
        ),
        AnimatedSize(
          duration: Motion.medium,
          curve: Motion.emphasized,
          child: _followUp == null
              ? const SizedBox(width: double.infinity)
              : Padding(
                  padding: const EdgeInsets.only(top: 10),
                  child: TextField(
                    controller: _followUpReason,
                    maxLength: 200,
                    decoration: const InputDecoration(labelText: 'Follow-up reason', counterText: ''),
                  ),
                ),
        ),

        // Notes
        const SectionHeader('Notes', padding: EdgeInsets.fromLTRB(0, 20, 0, 6)),
        TextField(
          controller: _findings,
          minLines: 2,
          maxLines: 6,
          maxLength: 5000,
          decoration: const InputDecoration(labelText: 'Findings / examination'),
        ),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          dense: true,
          value: _d.printFindings,
          onChanged: (v) => _change(() => _d.printFindings = v),
          title: const Text('Print findings on the prescription'),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _header,
          minLines: 1,
          maxLines: 4,
          maxLength: 5000,
          decoration: const InputDecoration(labelText: 'Note (top of prescription)'),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _advice,
          minLines: 2,
          maxLines: 6,
          maxLength: 5000,
          decoration: const InputDecoration(labelText: 'Advice (diet, follow-up, precautions)'),
        ),
      ],
    );
  }
}

class _MedicineCard extends StatelessWidget {
  const _MedicineCard({
    required this.item,
    required this.index,
    required this.masters,
    required this.allergy,
    required this.instruction,
    required this.onChanged,
    required this.onRemove,
  });

  final RxItem item;
  final int index;
  final Masters masters;
  final String? allergy;
  final TextEditingController instruction;
  final VoidCallback onChanged;
  final VoidCallback onRemove;

  static const _quick = ['After food', 'Before food', 'Empty stomach', 'At bedtime', 'If needed (SOS)'];

  /// Options for a dropdown, always containing the current value (it may come from another category or the web).
  List<Option> _withCurrent(List<Option> opts, int? id, String? label) {
    if (id == null || opts.any((o) => o.id == id)) return opts;
    return [Option(id, label ?? 'Option #$id'), ...opts];
  }

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final doses = _withCurrent(masters.dosagesFor(item.categoryId), item.dosageId, item.dosageLabel);
    final intervals = _withCurrent(masters.intervals, item.intervalId, item.intervalName);
    final durations = _withCurrent(masters.durations, item.durationId, item.durationName);

    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 4, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  margin: const EdgeInsets.only(top: 8),
                  width: 28,
                  height: 28,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(color: t.colorScheme.primaryContainer, borderRadius: BorderRadius.circular(9)),
                  child: Text('${index + 1}', style: t.textTheme.labelLarge?.copyWith(color: t.colorScheme.onPrimaryContainer)),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.only(top: 10),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(item.medicineName, style: t.textTheme.titleSmall),
                        if (item.composition != null) Text(item.composition!, style: t.textTheme.bodySmall),
                      ],
                    ),
                  ),
                ),
                IconButton(tooltip: 'Remove', icon: const Icon(Icons.delete_outline_rounded), onPressed: onRemove),
              ],
            ),
            if (allergy != null)
              Padding(
                padding: const EdgeInsets.only(top: 4, right: 8),
                child: Row(
                  children: [
                    Icon(Icons.warning_amber_rounded, size: 16, color: t.colorScheme.error),
                    const SizedBox(width: 6),
                    Expanded(
                      child: Text('May match allergy: $allergy', style: TextStyle(color: t.colorScheme.error, fontSize: 12)),
                    ),
                  ],
                ),
              ),
            const SizedBox(height: 10),
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: Column(
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: _dropdown(context, 'Dose', doses, item.dosageId, (o) {
                          item.dosageId = o?.id;
                          item.dosageLabel = o?.label;
                        }),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: _dropdown(context, 'How often', intervals, item.intervalId, (o) {
                          item.intervalId = o?.id;
                          item.intervalName = o?.label;
                        }),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  _dropdown(context, 'For how long', durations, item.durationId, (o) {
                    item.durationId = o?.id;
                    item.durationName = o?.label;
                  }, optional: true),
                  const SizedBox(height: 8),
                  TextField(
                    controller: instruction,
                    maxLength: 300,
                    decoration: const InputDecoration(labelText: 'Instruction', counterText: ''),
                  ),
                  const SizedBox(height: 6),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: Wrap(
                      spacing: 6,
                      runSpacing: 0,
                      children: [
                        for (final q in _quick)
                          ActionChip(
                            label: Text(q, style: const TextStyle(fontSize: 12)),
                            visualDensity: VisualDensity.compact,
                            onPressed: () {
                              final cur = instruction.text.trim();
                              if (cur.toLowerCase().contains(q.toLowerCase())) return;
                              instruction.text = cur.isEmpty ? q : '$cur, $q';
                              onChanged();
                            },
                          ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// [onPick] receives null only for an optional field cleared with "—".
  Widget _dropdown(BuildContext context, String label, List<Option> opts, int? value, void Function(Option?) onPick, {bool optional = false}) {
    final missing = !optional && value == null;
    return DropdownButtonFormField<int?>(
      initialValue: value,
      isExpanded: true,
      decoration: InputDecoration(
        labelText: label,
        errorText: null,
        enabledBorder: missing
            ? OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: BorderSide(color: Theme.of(context).colorScheme.tertiary),
              )
            : null,
      ),
      items: [
        if (optional) const DropdownMenuItem<int?>(value: null, child: Text('—')),
        for (final o in opts)
          DropdownMenuItem<int?>(
            value: o.id,
            child: Text(o.label, overflow: TextOverflow.ellipsis),
          ),
      ],
      onChanged: (id) {
        onPick(id == null ? null : opts.firstWhere((o) => o.id == id));
        onChanged();
      },
    );
  }
}
