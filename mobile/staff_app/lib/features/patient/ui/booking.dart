import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../core/api/api_client.dart';
import '../../../core/ui/theme.dart';
import '../../../core/ui/widgets.dart';
import 'patient_providers.dart';

// ------------------------------------------------------------------ API (online only)
class BookingDoctor {
  BookingDoctor(this.j);
  final Map<String, dynamic> j;
  int get id => (j['id'] as num).toInt();
  String get name => '${j['name'] ?? ''}';
  String? get qualification => (j['qualification'] as String?)?.trim().isEmpty == true ? null : j['qualification'] as String?;
  String? get department => j['department'] as String?;
  List<String> get specialties => ((j['specialties'] as List?) ?? const []).map((e) => '$e').toList();
  double? get fee => (j['fee'] as num?)?.toDouble();
  int get minutes => (j['consult_minutes'] as num?)?.toInt() ?? 10;
  Set<String> get days => ((j['days'] as List?) ?? const []).map((e) => '$e').toSet();
  bool worksOn(DateTime d) => days.contains(DateFormat('EEEE').format(d));
}

class BookingShift {
  BookingShift(this.j);
  final Map<String, dynamic> j;
  int get id => (j['shift_id'] as num).toInt();
  String get name => '${j['name'] ?? 'Shift'}';
  String get start => '${j['start']}';
  String get end => '${j['end']}';
  List<(String, bool)> get slots => ((j['slots'] as List?) ?? const []).map((e) => ('${(e as Map)['time']}', e['available'] == true)).toList();
}

class QueueInfo {
  QueueInfo(this.j);
  final Map<String, dynamic> j;
  int get appointmentId => (j['appointment_id'] as num).toInt();
  String get doctor => '${j['doctor_name'] ?? ''}';
  DateTime? get time => DateTime.tryParse('${j['time']}'.replaceFirst(' ', 'T'));
  String get status => '${j['status']}';
  int? get token => (j['token'] as num?)?.toInt();
  bool get checkedIn => j['checked_in'] == true;
  bool get seen => j['seen'] == true;
  int get ahead => (j['ahead'] as num?)?.toInt() ?? 0;
  int? get nowServing => ((j['now_serving'] as Map?)?['token'] as num?)?.toInt();
  bool get doctorIsSeeing => j['now_serving'] != null;
  int get waitMinutes => (j['wait_minutes'] as num?)?.toInt() ?? 0;
}

class BookingApi {
  BookingApi(this.api);
  final ApiClient api;

  Future<List<BookingDoctor>> doctors() async =>
      (((await api.get('patient/doctors'))['doctors'] as List?) ?? const []).map((e) => BookingDoctor(Map<String, dynamic>.from(e as Map))).toList();

  Future<List<BookingShift>> slots(int doctorId, DateTime date) async =>
      (((await api.get('patient/slots', query: {'doctor_id': doctorId, 'date': _ymd(date)}))['shifts'] as List?) ?? const [])
          .map((e) => BookingShift(Map<String, dynamic>.from(e as Map)))
          .toList();

  Future<void> book({required int doctorId, required int shiftId, required DateTime date, required String time, String message = ''}) =>
      api.post('patient/book', {'doctor_id': doctorId, 'shift_id': shiftId, 'date': _ymd(date), 'time': time, 'message': message});

  Future<void> cancel(int appointmentId) => api.post('patient/cancel', {'appointment_id': appointmentId});

  Future<List<QueueInfo>> queue() async => (((await api.get('patient/queue'))['queue'] as List?) ?? const []).map((e) => QueueInfo(Map<String, dynamic>.from(e as Map))).toList();
}

String _ymd(DateTime d) => DateFormat('yyyy-MM-dd').format(d);
String slotLabel(String hhmm) => DateFormat('h:mm a').format(DateFormat('HH:mm').parse(hhmm));

final bookingApiProvider = Provider((ref) => BookingApi(ref.watch(patientApiProvider)));

// ------------------------------------------------------------------ live queue card
/// Today's queue position, refreshed every minute while visible. Hidden when there is nothing today or offline.
class LiveQueueCard extends ConsumerStatefulWidget {
  const LiveQueueCard({super.key});

  @override
  ConsumerState<LiveQueueCard> createState() => _LiveQueueCardState();
}

class _LiveQueueCardState extends ConsumerState<LiveQueueCard> {
  List<QueueInfo>? _q;
  DateTime? _at;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _load();
    _timer = Timer.periodic(const Duration(seconds: 60), (_) => _load());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final q = await ref.read(bookingApiProvider).queue();
      if (mounted) {
        setState(() {
          _q = q;
          _at = DateTime.now();
        });
      }
    } on ApiException {
      // Offline or appointments disabled: keep the last value (or stay hidden).
    }
  }

  @override
  Widget build(BuildContext context) {
    final q = _q?.where((e) => !e.seen).toList() ?? const <QueueInfo>[];
    if (q.isEmpty) return const SizedBox.shrink();
    final e = q.first;
    final t = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
      child: FadeSlideIn(
        child: HeroPanel(
          padding: const EdgeInsets.fromLTRB(18, 14, 18, 16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              const Icon(Icons.groups_rounded, color: Colors.white, size: 20),
              const SizedBox(width: 8),
              Expanded(child: Text('Your queue today · ${e.doctor}', style: t.textTheme.titleSmall?.copyWith(color: Colors.white), overflow: TextOverflow.ellipsis)),
              PulseDot(color: const Color(0xFF7CF0B5), active: e.doctorIsSeeing),
            ]),
            const SizedBox(height: 12),
            Row(children: [
              Expanded(child: StatTile(value: e.token ?? 0, label: e.token == null ? 'Token not yet given' : 'Your token', icon: Icons.confirmation_number_rounded, highlight: true)),
              const SizedBox(width: 10),
              Expanded(child: StatTile(value: e.ahead, label: 'Ahead of you', icon: Icons.people_alt_rounded)),
              const SizedBox(width: 10),
              Expanded(child: StatTile(value: e.waitMinutes, label: 'Est. minutes', icon: Icons.timer_rounded)),
            ]),
            const SizedBox(height: 10),
            Text(
              [
                if (e.time != null) 'Slot ${DateFormat('h:mm a').format(e.time!)}',
                if (e.nowServing != null) 'Now seeing token ${e.nowServing}' else if (e.doctorIsSeeing) 'Doctor is seeing patients',
                if (!e.checkedIn) 'Please check in at the reception',
                if (e.status == 'pending') 'Awaiting confirmation',
              ].join(' · '),
              style: t.textTheme.bodySmall?.copyWith(color: Colors.white.withValues(alpha: 0.9)),
            ),
            if (_at != null)
              Text('Updated ${DateFormat('h:mm a').format(_at!)}', style: t.textTheme.labelSmall?.copyWith(color: Colors.white.withValues(alpha: 0.7))),
          ]),
        ),
      ),
    );
  }
}

// ------------------------------------------------------------------ booking flow
class BookAppointmentScreen extends ConsumerStatefulWidget {
  const BookAppointmentScreen({super.key});

  @override
  ConsumerState<BookAppointmentScreen> createState() => _BookAppointmentScreenState();
}

class _BookAppointmentScreenState extends ConsumerState<BookAppointmentScreen> {
  List<BookingDoctor>? _doctors;
  String? _error;
  String _q = '';
  String? _specialty;
  BookingDoctor? _doctor;

  @override
  void initState() {
    super.initState();
    _loadDoctors();
  }

  Future<void> _loadDoctors() async {
    setState(() => _error = null);
    try {
      final d = await ref.read(bookingApiProvider).doctors();
      if (mounted) setState(() => _doctors = d);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.isOffline ? 'Booking needs internet. Please connect and try again.' : e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_doctor != null) {
      return _SlotPicker(doctor: _doctor!, onBack: () => setState(() => _doctor = null));
    }
    final t = Theme.of(context);
    final all = _doctors;
    final specialties = {for (final d in all ?? const <BookingDoctor>[]) ...d.specialties}.toList()..sort();
    final list = (all ?? const <BookingDoctor>[]).where((d) {
      if (_specialty != null && !d.specialties.contains(_specialty)) return false;
      final hay = '${d.name} ${d.specialties.join(' ')} ${d.department ?? ''}'.toLowerCase();
      return _q.isEmpty || _q.toLowerCase().split(RegExp(r'\s+')).every(hay.contains);
    }).toList();

    return Scaffold(
      appBar: AppBar(title: const Text('Book appointment')),
      body: _error != null
          ? EmptyState(icon: Icons.cloud_off_rounded, title: 'Cannot load doctors', message: _error, action: FilledButton.tonal(onPressed: _loadDoctors, child: const Text('Try again')))
          : all == null
              ? const SkeletonList()
              : Column(children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
                    child: TextField(
                      onChanged: (v) => setState(() => _q = v.trim()),
                      decoration: const InputDecoration(hintText: 'Search doctor or speciality', prefixIcon: Icon(Icons.search_rounded)),
                    ),
                  ),
                  if (specialties.isNotEmpty)
                    SizedBox(
                      height: 46,
                      child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6), children: [
                        Padding(
                          padding: const EdgeInsets.only(right: 8),
                          child: FilterChip(label: const Text('All'), selected: _specialty == null, showCheckmark: false, onSelected: (_) => setState(() => _specialty = null)),
                        ),
                        for (final s in specialties)
                          Padding(
                            padding: const EdgeInsets.only(right: 8),
                            child: FilterChip(label: Text(s), selected: _specialty == s, showCheckmark: false, onSelected: (_) => setState(() => _specialty = s)),
                          ),
                      ]),
                    ),
                  Expanded(
                    child: list.isEmpty
                        ? const EmptyState(icon: Icons.person_search_rounded, title: 'No doctors found')
                        : ListView.separated(
                            padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                            itemCount: list.length,
                            separatorBuilder: (_, _) => const SizedBox(height: 10),
                            itemBuilder: (_, i) {
                              final d = list[i];
                              return FadeSlideIn(
                                index: i,
                                child: Card(
                                  child: Pressable(
                                    haptic: true,
                                    onTap: () => setState(() => _doctor = d),
                                    child: Padding(
                                      padding: const EdgeInsets.all(14),
                                      child: Row(children: [
                                        PatientAvatar(name: d.name.replaceFirst('Dr. ', ''), radius: 26),
                                        const SizedBox(width: 12),
                                        Expanded(
                                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                            Text(d.name, style: t.textTheme.titleSmall),
                                            Text([...d.specialties, ?d.qualification].join(' · '), maxLines: 2, overflow: TextOverflow.ellipsis, style: t.textTheme.bodySmall),
                                            const SizedBox(height: 4),
                                            Text(
                                              [
                                                if (d.fee != null) 'Fee ₹${d.fee!.toStringAsFixed(0)}',
                                                if (d.days.isNotEmpty) d.days.map((x) => x.substring(0, 3)).join(', '),
                                              ].join(' · '),
                                              style: t.textTheme.labelSmall?.copyWith(color: t.colorScheme.primary),
                                            ),
                                          ]),
                                        ),
                                        const Icon(Icons.chevron_right_rounded),
                                      ]),
                                    ),
                                  ),
                                ),
                              );
                            },
                          ),
                  ),
                ]),
    );
  }
}

class _SlotPicker extends ConsumerStatefulWidget {
  const _SlotPicker({required this.doctor, required this.onBack});
  final BookingDoctor doctor;
  final VoidCallback onBack;

  @override
  ConsumerState<_SlotPicker> createState() => _SlotPickerState();
}

class _SlotPickerState extends ConsumerState<_SlotPicker> {
  late DateTime _date;
  List<BookingShift>? _shifts;
  String? _error;
  int? _shiftId;
  String? _time;
  bool _booking = false;
  final _msg = TextEditingController();

  List<DateTime> get _days {
    final today = DateTime.now();
    return [for (var i = 0; i < 30; i++) DateTime(today.year, today.month, today.day + i)];
  }

  @override
  void initState() {
    super.initState();
    _date = _days.firstWhere(widget.doctor.worksOn, orElse: () => _days.first);
    _load();
  }

  @override
  void dispose() {
    _msg.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _shifts = null;
      _error = null;
      _time = null;
      _shiftId = null;
    });
    try {
      final s = await ref.read(bookingApiProvider).slots(widget.doctor.id, _date);
      if (mounted) setState(() => _shifts = s);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.isOffline ? 'You are offline.' : e.message);
    }
  }

  Future<void> _book() async {
    setState(() => _booking = true);
    try {
      await ref.read(bookingApiProvider).book(doctorId: widget.doctor.id, shiftId: _shiftId!, date: _date, time: _time!, message: _msg.text.trim());
      HapticFeedback.heavyImpact();
      await ref.read(patientSyncProvider.notifier).refresh();
      if (!mounted) return;
      setState(() => _booking = false);
      await showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          icon: Icon(Icons.event_available_rounded, color: Theme.of(ctx).colorScheme.tertiary, size: 40),
          title: const Text('Appointment requested'),
          content: Text('${widget.doctor.name}\n${DateFormat('EEEE, d MMMM').format(_date)} at ${slotLabel(_time!)}\n\n'
              'The hospital will confirm it.${widget.doctor.fee != null ? ' Consultation fee ₹${widget.doctor.fee!.toStringAsFixed(0)} is paid at the hospital.' : ''}'),
          actions: [FilledButton(onPressed: () => Navigator.pop(ctx), child: const Text('Done'))],
        ),
      );
      if (mounted) Navigator.pop(context);
    } on ApiException catch (e) {
      HapticFeedback.heavyImpact();
      if (mounted) showSnack(context, e.isOffline ? 'You are offline. Connect to book.' : e.message, error: true);
      if (e.kind != ApiErrorKind.offline) _load(); // slot may have been taken: refresh availability
    } finally {
      if (mounted) setState(() => _booking = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final d = widget.doctor;
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) widget.onBack();
      },
      child: Scaffold(
        appBar: AppBar(leading: BackButton(onPressed: widget.onBack), title: Text(d.name)),
        body: ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
          Text([...d.specialties, if (d.fee != null) 'Fee ₹${d.fee!.toStringAsFixed(0)}', '${d.minutes} min per patient'].join(' · '), style: t.textTheme.bodySmall),
          const SectionHeader('Choose a date', padding: EdgeInsets.fromLTRB(4, 16, 0, 8)),
          SizedBox(
            height: 76,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              itemCount: _days.length,
              separatorBuilder: (_, _) => const SizedBox(width: 8),
              itemBuilder: (_, i) {
                final day = _days[i];
                final works = d.worksOn(day);
                final selected = DateUtils.isSameDay(day, _date);
                return AnimatedContainer(
                  duration: Motion.fast,
                  width: 58,
                  decoration: BoxDecoration(
                    color: selected ? cs.primary : (works ? cs.surfaceContainerLowest : cs.surfaceContainerHighest),
                    borderRadius: BorderRadius.circular(Radii.md),
                    border: Border.all(color: selected ? cs.primary : cs.outlineVariant),
                  ),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(Radii.md),
                    onTap: works
                        ? () {
                            HapticFeedback.selectionClick();
                            setState(() => _date = day);
                            _load();
                          }
                        : null,
                    child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                      Text(DateFormat('EEE').format(day), style: t.textTheme.labelSmall?.copyWith(color: selected ? cs.onPrimary : (works ? cs.onSurfaceVariant : cs.outline))),
                      Text('${day.day}', style: t.textTheme.titleLarge?.copyWith(color: selected ? cs.onPrimary : (works ? cs.onSurface : cs.outline))),
                      Text(DateFormat('MMM').format(day), style: t.textTheme.labelSmall?.copyWith(color: selected ? cs.onPrimary : cs.outline)),
                    ]),
                  ),
                );
              },
            ),
          ),
          const SectionHeader('Choose a time', padding: EdgeInsets.fromLTRB(4, 18, 0, 8)),
          if (_error != null)
            EmptyState(icon: Icons.cloud_off_rounded, title: 'Cannot load times', message: _error, action: FilledButton.tonal(onPressed: _load, child: const Text('Try again')))
          else if (_shifts == null)
            const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator()))
          else if (_shifts!.isEmpty || _shifts!.every((s) => s.slots.every((x) => !x.$2)))
            const EmptyState(icon: Icons.event_busy_rounded, title: 'No free slots on this day', message: 'Try another date.')
          else
            for (final s in _shifts!) ...[
              Padding(
                padding: const EdgeInsets.only(bottom: 6, left: 4),
                child: Text('${s.name} · ${slotLabel(s.start)} – ${slotLabel(s.end)}', style: t.textTheme.labelLarge),
              ),
              Wrap(spacing: 8, runSpacing: 8, children: [
                for (final (time, free) in s.slots)
                  ChoiceChip(
                    label: Text(slotLabel(time)),
                    selected: _shiftId == s.id && _time == time,
                    onSelected: free
                        ? (_) {
                            HapticFeedback.selectionClick();
                            setState(() {
                              _shiftId = s.id;
                              _time = time;
                            });
                          }
                        : null,
                  ),
              ]),
              const SizedBox(height: 14),
            ],
          TextField(controller: _msg, maxLength: 300, maxLines: 2, decoration: const InputDecoration(labelText: 'Reason for visit (optional)', counterText: '')),
        ]),
        bottomNavigationBar: SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
            child: FilledButton.icon(
              style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
              onPressed: _time == null || _booking ? null : _book,
              icon: _booking ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.event_available_rounded),
              label: Text(_time == null ? 'Pick a time' : 'Book ${DateFormat('d MMM').format(_date)}, ${slotLabel(_time!)}'),
            ),
          ),
        ),
      ),
    );
  }
}

/// Ask and cancel an upcoming appointment.
Future<bool> cancelAppointment(BuildContext context, WidgetRef ref, int appointmentId) async {
  final ok = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: const Text('Cancel appointment?'),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Keep')),
        FilledButton(style: FilledButton.styleFrom(backgroundColor: Theme.of(ctx).colorScheme.error), onPressed: () => Navigator.pop(ctx, true), child: const Text('Cancel it')),
      ],
    ),
  );
  if (ok != true) return false;
  try {
    await ref.read(bookingApiProvider).cancel(appointmentId);
    await ref.read(patientSyncProvider.notifier).refresh();
    if (context.mounted) showSnack(context, 'Appointment cancelled');
    return true;
  } on ApiException catch (e) {
    if (context.mounted) showSnack(context, e.isOffline ? 'You are offline. Connect to cancel.' : e.message, error: true);
    return false;
  }
}
