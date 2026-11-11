import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/ui/line_chart.dart';
import '../../../../core/ui/widgets.dart';
import '../../data/clinical.dart';
import '../followups.dart';
import '../specialty.dart';
import 'form_spec.dart';

/// Open the editor for [form]. Returns the saved record, or null when cancelled.
Future<ClinicalRecord?> editSpecialtyRecord(
  BuildContext context,
  WidgetRef ref, {
  required int patientId,
  required RecordForm form,
  ClinicalRecord? existing,
  Map<String, dynamic> preset = const {},
}) async {
  final r = existing ?? ClinicalRecord.create(patientId: patientId, type: form.type, eventDate: DateTime.now(), status: form.statuses?.first.$1 ?? 'active');
  if (existing == null) r.data.addAll(preset);
  final saved = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (ctx) => _RecordFormSheet(form: form, record: r, isNew: existing == null),
  );
  if (saved != true) return null;
  await saveClinicalRecord(ref, r);
  return r;
}

class _RecordFormSheet extends StatefulWidget {
  const _RecordFormSheet({required this.form, required this.record, required this.isNew});
  final RecordForm form;
  final ClinicalRecord record;
  final bool isNew;

  @override
  State<_RecordFormSheet> createState() => _RecordFormSheetState();
}

class _RecordFormSheetState extends State<_RecordFormSheet> {
  late final Map<String, dynamic> _data = Map<String, dynamic>.from(widget.record.data);
  late DateTime _date = widget.record.eventDate ?? DateTime.now();
  late String _status = widget.record.status;
  final _controllers = <String, TextEditingController>{};
  String? _error;

  TextEditingController _c(String key) => _controllers.putIfAbsent(key, () => TextEditingController(text: _data[key] == null ? '' : '${_data[key]}'));

  @override
  void dispose() {
    for (final c in _controllers.values) {
      c.dispose();
    }
    super.dispose();
  }

  void _save() {
    for (final e in _controllers.entries) {
      final v = e.value.text.trim();
      _data[e.key] = v.isEmpty ? null : v;
    }
    final err = widget.form.validate(_data);
    if (err != null) {
      setState(() => _error = err);
      return;
    }
    widget.record.data
      ..clear()
      ..addAll({for (final e in _data.entries) if (e.value != null) e.key: e.value});
    widget.record.eventDate = _date;
    widget.record.status = _status;
    Navigator.pop(context, true);
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: DraggableScrollableSheet(
        expand: false,
        initialChildSize: 0.9,
        maxChildSize: 0.95,
        builder: (ctx, scroll) => Column(children: [
          Expanded(child: _list(scroll)),
          SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
              child: FilledButton(style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)), onPressed: _save, child: const Text('Save')),
            ),
          ),
        ]),
      ),
    );
  }

  Widget _list(ScrollController scroll) {
    final t = Theme.of(context);
    final f = widget.form;
    return ListView(controller: scroll, padding: const EdgeInsets.fromLTRB(20, 0, 20, 12), children: [
          Text(widget.isNew ? f.addLabel : 'Edit ${f.title.toLowerCase()}', style: t.textTheme.titleLarge),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            onPressed: () async {
              final d = await showDatePicker(context: context, initialDate: _date, firstDate: DateTime(2000), lastDate: DateTime.now().add(const Duration(days: 365)));
              if (d != null) setState(() => _date = d);
            },
            icon: const Icon(Icons.event_rounded),
            label: Text('${f.datedLabel}: ${fmtDate(_date)}'),
          ),
          if (f.statuses != null) ...[
            const SizedBox(height: 10),
            SegmentedButton<String>(
              segments: [for (final (v, l) in f.statuses!) ButtonSegment(value: v, label: Text(l))],
              selected: {_status},
              onSelectionChanged: (s) => setState(() => _status = s.first),
            ),
          ],
          for (final field in f.fields) Padding(padding: const EdgeInsets.only(top: 14), child: _field(field)),
          if (_error != null)
            Padding(padding: const EdgeInsets.only(top: 12), child: Text(_error!, style: TextStyle(color: t.colorScheme.error, fontWeight: FontWeight.w600))),
        ]);
  }

  Widget _label(FieldSpec f) => Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Text('${f.label}${f.required ? ' *' : ''}', style: Theme.of(context).textTheme.labelLarge),
      );

  Widget _field(FieldSpec f) {
    switch (f.kind) {
      case FieldKind.text:
      case FieldKind.multiline:
        return TextField(
          controller: _c(f.key),
          maxLines: f.kind == FieldKind.multiline ? 3 : 1,
          textCapitalization: TextCapitalization.sentences,
          decoration: InputDecoration(labelText: f.label + (f.required ? ' *' : ''), hintText: f.hint),
        );
      case FieldKind.number:
        return TextField(
          controller: _c(f.key),
          keyboardType: const TextInputType.numberWithOptions(decimal: true, signed: true),
          inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[-+0-9.]'))],
          decoration: InputDecoration(labelText: f.label + (f.required ? ' *' : ''), suffixText: f.unit, hintText: f.hint),
        );
      case FieldKind.pair:
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          _label(f),
          Row(children: [
            Expanded(child: TextField(controller: _c('${f.key}_re'), decoration: InputDecoration(labelText: 'Right', suffixText: f.unit, hintText: f.hint))),
            const SizedBox(width: 10),
            Expanded(child: TextField(controller: _c('${f.key}_le'), decoration: InputDecoration(labelText: 'Left', suffixText: f.unit, hintText: f.hint))),
          ]),
        ]);
      case FieldKind.choice:
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          _label(f),
          Wrap(spacing: 8, runSpacing: 8, children: [
            for (final o in f.options)
              ChoiceChip(label: Text(o), selected: _data[f.key] == o, onSelected: (on) => setState(() => _data[f.key] = on ? o : null)),
          ]),
        ]);
      case FieldKind.multi:
        final chosen = ('${_data[f.key] ?? ''}').split(', ').where((s) => s.isNotEmpty).toSet();
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          _label(f),
          Wrap(spacing: 8, runSpacing: 8, children: [
            for (final o in f.options)
              FilterChip(
                label: Text(o),
                selected: chosen.contains(o),
                onSelected: (on) => setState(() {
                  on ? chosen.add(o) : chosen.remove(o);
                  _data[f.key] = chosen.isEmpty ? null : f.options.where(chosen.contains).join(', ');
                }),
              ),
          ]),
        ]);
      case FieldKind.scale:
        final v = numberOf(_data[f.key]);
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: _label(f)),
            Text(v == null ? 'Not scored' : '${v.round()} / 10', style: Theme.of(context).textTheme.titleSmall),
          ]),
          Slider(
            value: v ?? 0,
            max: 10,
            divisions: 10,
            label: v == null ? null : '${v.round()}',
            onChanged: (x) => setState(() => _data[f.key] = x.round()),
          ),
        ]);
      case FieldKind.date:
        final d = parseYmd(_data[f.key]);
        return OutlinedButton.icon(
          onPressed: () async {
            final p = await showDatePicker(context: context, initialDate: d ?? DateTime.now(), firstDate: DateTime(2000), lastDate: DateTime.now().add(const Duration(days: 730)));
            if (p != null) setState(() => _data[f.key] = ymd(p));
          },
          icon: const Icon(Icons.event_rounded),
          label: Text(d == null ? f.label : '${f.label}: ${fmtDate(d)}'),
        );
    }
  }
}

DateTime? parseYmd(Object? v) => v == null ? null : DateTime.tryParse('$v');

/// Card on the Care tab: latest record of [form] with a short summary and an Add button.
class SpecialtyRecordCard extends ConsumerWidget {
  const SpecialtyRecordCard({super.key, required this.patientId, required this.records, required this.form, required this.icon, this.child});
  final int patientId;
  final List<ClinicalRecord> records;
  final RecordForm form;
  final IconData icon;
  final Widget? child;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final mine = records.where((r) => r.type == form.type).toList();
    final latest = mine.firstOrNull;
    final trend = form.trendFields.firstOrNull;
    final pts = trend == null ? const <(double, double)>[] : seriesOf(mine, trend.key);
    return CareCard(
      icon: icon,
      title: form.title,
      subtitle: latest == null ? 'Nothing recorded yet' : '${fmtDate(latest.eventDate)} · ${form.summaryOf(latest)}',
      onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => SpecialtyHistoryScreen(patientId: patientId, form: form, icon: icon))),
      action: TextButton.icon(
        onPressed: () => editSpecialtyRecord(context, ref, patientId: patientId, form: form),
        icon: const Icon(Icons.add_rounded, size: 18),
        label: const Text('Add'),
      ),
      child: child ?? (pts.length >= 2 ? LineChart(height: 90, series: [ChartSeries(label: trend!.label, points: pts)]) : null),
    );
  }
}

/// Points (time, value) of a numeric field across records, oldest first.
List<(double, double)> seriesOf(List<ClinicalRecord> records, String key) {
  final out = <(double, double)>[];
  for (final r in records) {
    final v = numberOf(r.data[key]);
    if (v != null && r.eventDate != null) out.add((r.eventDate!.millisecondsSinceEpoch.toDouble(), v));
  }
  out.sort((a, b) => a.$1.compareTo(b.$1));
  return out;
}

/// All records of one form for a patient, with trend charts for numeric fields.
class SpecialtyHistoryScreen extends ConsumerWidget {
  const SpecialtyHistoryScreen({super.key, required this.patientId, required this.form, required this.icon, this.header});
  final int patientId;
  final RecordForm form;
  final IconData icon;
  final Widget Function(BuildContext context, List<ClinicalRecord> records)? header;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final all = ref.watch(clinicalRecordsProvider(patientId)).valueOrNull;
    final t = Theme.of(context);
    final records = all?.where((r) => r.type == form.type).toList();
    String fx(double x) => fmtDateShort(DateTime.fromMillisecondsSinceEpoch(x.round()));
    return Scaffold(
      appBar: AppBar(title: Text(form.title)),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => editSpecialtyRecord(context, ref, patientId: patientId, form: form),
        icon: const Icon(Icons.add_rounded),
        label: Text(form.addLabel),
      ),
      body: records == null
          ? const SkeletonList()
          : ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 100), children: [
              if (header != null) header!(context, records),
              for (final f in form.trendFields)
                if (seriesOf(records, f.key).length >= 2)
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text('${f.label}${f.unit == null ? '' : ' (${f.unit})'}', style: t.textTheme.titleSmall),
                        const SizedBox(height: 8),
                        LineChart(height: 130, formatX: fx, series: [ChartSeries(label: f.label, points: seriesOf(records, f.key))]),
                      ]),
                    ),
                  ),
              if (records.isEmpty)
                EmptyState(icon: icon, title: 'Nothing recorded yet', message: 'Tap "${form.addLabel}" to record the first one. It is saved on this device and uploaded on the next sync.'),
              for (final (i, r) in records.indexed)
                FadeSlideIn(index: i, child: SpecialtyRecordTile(form: form, record: r, patientId: patientId)),
            ]),
    );
  }
}

class SpecialtyRecordTile extends ConsumerWidget {
  const SpecialtyRecordTile({super.key, required this.form, required this.record, required this.patientId, this.trailing});
  final RecordForm form;
  final ClinicalRecord record;
  final int patientId;
  final Widget? trailing;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final status = form.statuses?.where((s) => s.$1 == record.status).firstOrNull?.$2;
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: () => editSpecialtyRecord(context, ref, patientId: patientId, form: form, existing: record),
        onLongPress: () async {
          final ok = await showDialog<bool>(
            context: context,
            builder: (ctx) => AlertDialog(
              title: const Text('Delete this record?'),
              content: Text('${form.title} of ${fmtDate(record.eventDate)} will be removed on all devices after sync.'),
              actions: [
                TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
                FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Delete')),
              ],
            ),
          );
          if (ok == true) await deleteClinicalRecord(ref, record);
        },
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(fmtDate(record.eventDate), style: t.textTheme.titleSmall)),
              if (status != null)
                record.status == form.statuses!.last.$1
                    ? StatusPill(status, bg: cs.tertiaryContainer, fg: cs.onTertiaryContainer)
                    : StatusPill(status, bg: cs.secondaryContainer, fg: cs.onSecondaryContainer),
              if (record.pending) Padding(padding: const EdgeInsets.only(left: 6), child: Icon(Icons.cloud_upload_outlined, size: 16, color: cs.outline)),
              ?trailing,
            ]),
            if (record.error != null) Text(record.error!, style: TextStyle(color: cs.error)),
            const SizedBox(height: 6),
            for (final f in form.fields)
              if (f.display(record) case final v?)
                Padding(
                  padding: const EdgeInsets.only(top: 3),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    SizedBox(width: 130, child: Text(f.label, style: t.textTheme.bodySmall?.copyWith(color: cs.onSurfaceVariant))),
                    Expanded(child: Text(v, style: t.textTheme.bodyMedium)),
                  ]),
                ),
          ]),
        ),
      ),
    );
  }
}
