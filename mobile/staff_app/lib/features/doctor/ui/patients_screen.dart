import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../opd/registration_screen.dart';

import '../../../core/api/api_client.dart';
import '../../../core/providers.dart';
import '../../../core/ui/widgets.dart';
import '../data/doctor_remote.dart';
import '../data/models.dart';
import 'doctor_providers.dart';
import 'patient_screen.dart';

final doctorRemoteProvider = Provider<DoctorRemote>((ref) => DoctorRemote(ref.watch(apiProvider), ref.watch(databaseProvider)));

class PatientsScreen extends ConsumerStatefulWidget {
  const PatientsScreen({super.key, required this.onOpenSyncDetails});
  final VoidCallback onOpenSyncDetails;

  @override
  ConsumerState<PatientsScreen> createState() => _PatientsScreenState();
}

class _PatientsScreenState extends ConsumerState<PatientsScreen> {
  final _search = TextEditingController();
  Timer? _debounce;
  String _query = '';

  @override
  void dispose() {
    _search.dispose();
    _debounce?.cancel();
    super.dispose();
  }

  void _onChanged(String v) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 200), () => setState(() => _query = v.trim()));
  }

  @override
  Widget build(BuildContext context) {
    final results = ref.watch(patientSearchProvider(_query));
    return Scaffold(
      appBar: AppBar(title: const Text('Patients'), toolbarHeight: 64),
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'register',
        onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const RegisterPatientScreen())),
        icon: const Icon(Icons.person_add_alt_1_rounded),
        label: const Text('Register'),
      ),
      body: Column(children: [
        SyncBanner(onOpenDetails: widget.onOpenSyncDetails),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          child: TextField(
            controller: _search,
            onChanged: _onChanged,
            textInputAction: TextInputAction.search,
            decoration: InputDecoration(
              hintText: 'Search name, phone or patient ID',
              prefixIcon: const Icon(Icons.search_rounded),
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(40), borderSide: BorderSide.none),
              enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(40), borderSide: BorderSide.none),
              focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(40), borderSide: BorderSide(color: Theme.of(context).colorScheme.primary, width: 1.6)),
              suffixIcon: _query.isEmpty
                  ? null
                  : IconButton(
                      tooltip: 'Clear',
                      icon: const Icon(Icons.close),
                      onPressed: () {
                        _search.clear();
                        setState(() => _query = '');
                      }),
            ),
          ),
        ),
        Expanded(
          child: results.when(
            loading: () => const SkeletonList(),
            error: (e, _) => EmptyState(icon: Icons.error_outline_rounded, title: 'Could not search', message: '$e'),
            data: (list) => ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 24), children: [
              if (list.isEmpty)
                EmptyState(
                  icon: Icons.person_search_outlined,
                  title: _query.isEmpty ? 'No patients on this phone yet' : 'No match on this phone',
                  message: _query.isEmpty ? 'Your patients download automatically when online.' : 'Search the whole hospital to find other patients.',
                ),
              for (final (i, p) in list.indexed)
                Padding(padding: const EdgeInsets.only(bottom: 10), child: FadeSlideIn(index: i, child: _PatientTile(patient: p))),
              if (_query.length >= 2)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: FilledButton.tonalIcon(
                    onPressed: () => showModalBottomSheet<void>(
                      context: context,
                      isScrollControlled: true,
                      useSafeArea: true,
                      builder: (_) => _HospitalSearchSheet(initial: _query),
                    ),
                    icon: const Icon(Icons.travel_explore_rounded),
                    label: Text('Search all hospital patients for "$_query"', overflow: TextOverflow.ellipsis),
                  ),
                ),
            ]),
          ),
        ),
      ]),
    );
  }
}

class _PatientTile extends StatelessWidget {
  const _PatientTile({required this.patient});
  final Patient patient;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final tag = 'list-${patient.id}';
    return Card(
      child: Pressable(
        haptic: true,
        onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => PatientScreen(patientId: patient.id, heroTag: tag))),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            PatientAvatar(name: patient.name, radius: 23, heroTag: tag),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Flexible(child: Text(patient.name, style: t.textTheme.titleSmall, overflow: TextOverflow.ellipsis)),
                  if (patient.allergies.isNotEmpty) ...[const SizedBox(width: 6), Icon(Icons.warning_amber_rounded, size: 16, color: cs.error)],
                ]),
                const SizedBox(height: 2),
                Text(['ID ${patient.id}', if (patient.subtitle.isNotEmpty) patient.subtitle, ?patient.mobile].join(' · '),
                    maxLines: 1, overflow: TextOverflow.ellipsis, style: t.textTheme.bodySmall?.copyWith(color: cs.onSurfaceVariant)),
              ]),
            ),
            if (patient.pinned) Padding(padding: const EdgeInsets.only(left: 6), child: Icon(Icons.push_pin_rounded, size: 16, color: cs.primary)),
            Icon(Icons.chevron_right_rounded, color: cs.outline),
          ]),
        ),
      ),
    );
  }
}

class _HospitalSearchSheet extends ConsumerStatefulWidget {
  const _HospitalSearchSheet({required this.initial});
  final String initial;

  @override
  ConsumerState<_HospitalSearchSheet> createState() => _HospitalSearchSheetState();
}

class _HospitalSearchSheetState extends ConsumerState<_HospitalSearchSheet> {
  late final _q = TextEditingController(text: widget.initial);
  List<Map<String, dynamic>>? _results;
  String? _error;
  bool _busy = false;
  int? _downloading;

  @override
  void initState() {
    super.initState();
    _run();
  }

  @override
  void dispose() {
    _q.dispose();
    super.dispose();
  }

  Future<void> _run() async {
    if (_q.text.trim().length < 2) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final r = await ref.read(doctorRemoteProvider).searchHospital(_q.text.trim());
      setState(() => _results = r);
    } on ApiException catch (e) {
      setState(() => _error = e.isOffline ? 'You are offline. Hospital-wide search needs internet; patients already on this phone can still be searched.' : e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _open(Map<String, dynamic> p) async {
    final id = (p['id'] as num).toInt();
    setState(() => _downloading = id);
    try {
      await ref.read(doctorRemoteProvider).downloadPatient(id);
      ref.read(dataRevisionProvider.notifier).state++;
      if (!mounted) return;
      final nav = Navigator.of(context);
      nav.pop();
      nav.push(MaterialPageRoute(builder: (_) => PatientScreen(patientId: id)));
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _downloading = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SizedBox(
        height: MediaQuery.sizeOf(context).height * 0.85,
        child: Column(children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
            child: TextField(
              controller: _q,
              autofocus: false,
              textInputAction: TextInputAction.search,
              onSubmitted: (_) => _run(),
              decoration: InputDecoration(
                labelText: 'Search hospital patients',
                prefixIcon: const Icon(Icons.travel_explore),
                suffixIcon: IconButton(tooltip: 'Search', icon: const Icon(Icons.arrow_forward), onPressed: _run),
              ),
            ),
          ),
          if (_busy) const LinearProgressIndicator(minHeight: 2),
          Expanded(
            child: _error != null
                ? EmptyState(icon: Icons.cloud_off_outlined, title: 'Search unavailable', message: _error)
                : _results == null
                    ? const SizedBox.shrink()
                    : _results!.isEmpty
                        ? const EmptyState(icon: Icons.person_off_outlined, title: 'No patients found')
                        : ListView(children: [
                            for (final p in _results!)
                              ListTile(
                                leading: PatientAvatar(name: '${p['name'] ?? '?'}'),
                                title: Text('${p['name'] ?? ''}'),
                                subtitle: Text(['ID ${p['id']}', p['gender'], p['mobileno']].where((s) => s != null && '$s'.isNotEmpty).join(' · ')),
                                trailing: _downloading == p['id']
                                    ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
                                    : const Icon(Icons.download_for_offline_outlined),
                                onTap: _downloading == null ? () => _open(p) : null,
                              ),
                            const Padding(
                              padding: EdgeInsets.all(16),
                              child: Text('Opening a patient saves their history on this phone for offline use.', textAlign: TextAlign.center),
                            ),
                          ]),
          ),
        ]),
      ),
    );
  }
}
