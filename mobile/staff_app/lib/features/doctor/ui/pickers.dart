import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/ui/widgets.dart';
import '../data/allergy_check.dart';
import '../data/models.dart';
import 'doctor_providers.dart';
import 'patients_screen.dart' show doctorRemoteProvider;

Future<T?> _sheet<T>(BuildContext context, Widget child) => showModalBottomSheet<T>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (ctx) => Padding(
        padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(ctx).bottom),
        child: SizedBox(height: MediaQuery.sizeOf(ctx).height * 0.85, child: child),
      ),
    );

// ================================================================== medicines
Future<Medicine?> pickMedicine(BuildContext context, {required AllergyCheck allergies, required Set<int> alreadyAdded}) =>
    _sheet<Medicine>(context, _MedicinePicker(allergies: allergies, alreadyAdded: alreadyAdded));

class _MedicinePicker extends ConsumerStatefulWidget {
  const _MedicinePicker({required this.allergies, required this.alreadyAdded});
  final AllergyCheck allergies;
  final Set<int> alreadyAdded;

  @override
  ConsumerState<_MedicinePicker> createState() => _MedicinePickerState();
}

class _MedicinePickerState extends ConsumerState<_MedicinePicker> {
  final _q = TextEditingController();
  Timer? _debounce;
  List<Medicine> _results = [];
  bool _loaded = false;
  int _total = -1;

  @override
  void initState() {
    super.initState();
    _search('');
    ref.read(doctorRepoProvider).medicineCount().then((n) => mounted ? setState(() => _total = n) : null);
  }

  @override
  void dispose() {
    _q.dispose();
    _debounce?.cancel();
    super.dispose();
  }

  Future<void> _search(String q) async {
    final masters = await ref.read(mastersProvider.future);
    final r = await ref.read(doctorRepoProvider).searchMedicines(q, frequentIds: masters.frequentMedicineIds);
    if (mounted) {
      setState(() {
        _results = r;
        _loaded = true;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
        child: TextField(
          controller: _q,
          autofocus: true,
          textInputAction: TextInputAction.search,
          onChanged: (v) {
            _debounce?.cancel();
            _debounce = Timer(const Duration(milliseconds: 150), () => _search(v));
          },
          decoration: const InputDecoration(hintText: 'Medicine name, generic or company', prefixIcon: Icon(Icons.medication_outlined)),
        ),
      ),
      if (_q.text.isEmpty && _results.isNotEmpty) const SectionHeader('Your frequent medicines', padding: EdgeInsets.fromLTRB(16, 4, 16, 4)),
      Expanded(
        child: !_loaded
            ? const Center(child: CircularProgressIndicator())
            : _total == 0
                ? const EmptyState(icon: Icons.cloud_download_outlined, title: 'Medicine list not downloaded yet', message: 'Connect to the internet once so the pharmacy list can be saved on this phone.')
                : _results.isEmpty
                    ? EmptyState(icon: Icons.search_off, title: _q.text.isEmpty ? 'Type to search' : 'No medicine found', message: _q.text.isEmpty ? null : 'Only medicines in the hospital pharmacy list can be prescribed.')
                    : ListView.builder(
                        itemCount: _results.length,
                        itemBuilder: (_, i) {
                          final m = _results[i];
                          final allergy = widget.allergies.matchMedicine(m);
                          final added = widget.alreadyAdded.contains(m.id);
                          return ListTile(
                            enabled: !added,
                            title: Text(m.name),
                            subtitle: Text([m.composition, m.category, m.company].whereType<String>().where((s) => s.isNotEmpty).join(' · '), maxLines: 2, overflow: TextOverflow.ellipsis),
                            trailing: added
                                ? const Text('Added')
                                : allergy != null
                                    ? Tooltip(message: 'May match allergy: $allergy', child: Icon(Icons.warning_amber_rounded, color: t.colorScheme.error))
                                    : null,
                            onTap: () => Navigator.pop(context, m),
                          );
                        },
                      ),
      ),
    ]);
  }
}

// ================================================================== tests
class TestSelection {
  TestSelection(this.pathology, this.radiology);
  final List<TestRef> pathology;
  final List<TestRef> radiology;
}

Future<TestSelection?> pickTests(BuildContext context, {required Masters masters, required List<TestRef> pathology, required List<TestRef> radiology}) =>
    _sheet<TestSelection>(context, _TestPicker(masters: masters, pathology: pathology, radiology: radiology));

class _TestPicker extends StatefulWidget {
  const _TestPicker({required this.masters, required this.pathology, required this.radiology});
  final Masters masters;
  final List<TestRef> pathology;
  final List<TestRef> radiology;

  @override
  State<_TestPicker> createState() => _TestPickerState();
}

class _TestPickerState extends State<_TestPicker> {
  late final Set<int> _path = widget.pathology.map((e) => e.id).toSet();
  late final Set<int> _rad = widget.radiology.map((e) => e.id).toSet();
  String _q = '';
  int _tab = 0;

  @override
  Widget build(BuildContext context) {
    final source = _tab == 0 ? widget.masters.pathology : widget.masters.radiology;
    final selected = _tab == 0 ? _path : _rad;
    final q = _q.toLowerCase();
    final list = q.isEmpty ? source : source.where((t) => t.name.toLowerCase().contains(q)).toList();
    // Selected tests first so they are easy to review.
    list.sort((a, b) => (selected.contains(b.id) ? 1 : 0) - (selected.contains(a.id) ? 1 : 0));

    return Column(children: [
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: SegmentedButton<int>(
          segments: [
            ButtonSegment(value: 0, label: Text('Pathology${_path.isEmpty ? '' : ' (${_path.length})'}')),
            ButtonSegment(value: 1, label: Text('Radiology${_rad.isEmpty ? '' : ' (${_rad.length})'}')),
          ],
          selected: {_tab},
          onSelectionChanged: (s) => setState(() => _tab = s.first),
        ),
      ),
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 10, 16, 4),
        child: TextField(onChanged: (v) => setState(() => _q = v), decoration: const InputDecoration(hintText: 'Search tests', prefixIcon: Icon(Icons.search))),
      ),
      Expanded(
        child: source.isEmpty
            ? const EmptyState(icon: Icons.biotech_outlined, title: 'No tests configured')
            : ListView.builder(
                itemCount: list.length,
                itemBuilder: (_, i) => CheckboxListTile(
                  value: selected.contains(list[i].id),
                  title: Text(list[i].name),
                  dense: true,
                  onChanged: (v) => setState(() => v == true ? selected.add(list[i].id) : selected.remove(list[i].id)),
                ),
              ),
      ),
      SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: FilledButton(
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(46)),
            onPressed: () => Navigator.pop(
              context,
              TestSelection(
                widget.masters.pathology.where((t) => _path.contains(t.id)).toList(),
                widget.masters.radiology.where((t) => _rad.contains(t.id)).toList(),
              ),
            ),
            child: Text('Done · ${_path.length + _rad.length} selected'),
          ),
        ),
      ),
    ]);
  }
}

// ================================================================== ICD-10
Future<IcdCode?> pickIcd(BuildContext context) => _sheet<IcdCode>(context, const _IcdPicker());

class _IcdPicker extends ConsumerStatefulWidget {
  const _IcdPicker();
  @override
  ConsumerState<_IcdPicker> createState() => _IcdPickerState();
}

class _IcdPickerState extends ConsumerState<_IcdPicker> {
  Timer? _debounce;
  List<IcdCode> _results = [];
  bool _offline = false;
  bool _searchingOnline = false;
  int _seq = 0;

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  Future<void> _search(String q) async {
    final seq = ++_seq;
    final local = await ref.read(doctorRepoProvider).searchIcd(q);
    if (!mounted || seq != _seq) return;
    setState(() => _results = local);
    if (q.trim().length < 2) return;
    setState(() => _searchingOnline = true);
    try {
      final remote = await ref.read(doctorRemoteProvider).searchIcd(q).timeout(const Duration(seconds: 6));
      if (!mounted || seq != _seq) return;
      final ids = local.map((e) => e.id).toSet();
      setState(() {
        _results = [...local, ...remote.where((r) => !ids.contains(r.id))];
        _offline = false;
      });
    } on ApiException catch (_) {
      if (mounted && seq == _seq) setState(() => _offline = true);
    } on TimeoutException catch (_) {
      if (mounted && seq == _seq) setState(() => _offline = true);
    } finally {
      if (mounted && seq == _seq) setState(() => _searchingOnline = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
        child: TextField(
          autofocus: true,
          onChanged: (v) {
            _debounce?.cancel();
            _debounce = Timer(const Duration(milliseconds: 300), () => _search(v));
          },
          decoration: const InputDecoration(hintText: 'Diagnosis or ICD-10 code', prefixIcon: Icon(Icons.search)),
        ),
      ),
      if (_searchingOnline) const LinearProgressIndicator(minHeight: 2),
      if (_offline)
        const Padding(
          padding: EdgeInsets.symmetric(horizontal: 16, vertical: 4),
          child: Text('Offline: showing codes already used in this hospital. Full ICD-10 search needs internet.', style: TextStyle(fontSize: 12)),
        ),
      Expanded(
        child: ListView.builder(
          itemCount: _results.length,
          itemBuilder: (_, i) => ListTile(
            leading: Text(_results[i].code, style: const TextStyle(fontWeight: FontWeight.w700)),
            title: Text(_results[i].description, maxLines: 2, overflow: TextOverflow.ellipsis),
            onTap: () => Navigator.pop(context, _results[i]),
          ),
        ),
      ),
    ]);
  }
}

// ================================================================== templates
Future<RxTemplate?> pickTemplate(BuildContext context) => _sheet<RxTemplate>(context, const _TemplatePicker());

class _TemplatePicker extends ConsumerWidget {
  const _TemplatePicker();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final list = ref.watch(templatesProvider);
    return list.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (e, _) => EmptyState(icon: Icons.error_outline, title: 'Could not load templates', message: '$e'),
      data: (ts) => ts.isEmpty
          ? const EmptyState(
              icon: Icons.bookmarks_outlined,
              title: 'No templates yet',
              message: 'Save a prescription as a template from the editor menu. Templates made on the web Smart Prescription screen appear here too.')
          : ListView(children: [
              const SectionHeader('Prescription templates', padding: EdgeInsets.fromLTRB(16, 0, 16, 8)),
              for (final t in ts)
                ListTile(
                  leading: Icon(t.mine ? Icons.bookmark_outline : Icons.groups_outlined),
                  title: Text(t.name),
                  subtitle: Text(t.items.map((i) => i.medicineName).join(', '), maxLines: 2, overflow: TextOverflow.ellipsis),
                  trailing: t.mine
                      ? IconButton(
                          tooltip: 'Delete template',
                          icon: const Icon(Icons.delete_outline),
                          onPressed: () async {
                            await ref.read(doctorRepoProvider).deleteTemplate(t);
                            afterLocalWrite(ref);
                          },
                        )
                      : null,
                  onTap: () => Navigator.pop(context, t),
                ),
            ]),
    );
  }
}
