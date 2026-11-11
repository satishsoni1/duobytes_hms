import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../core/ui/widgets.dart';
import '../doctor/data/billing.dart';
import '../doctor/data/models.dart';
import '../doctor/ui/billing_ui.dart';
import '../doctor/ui/doctor_providers.dart';
import '../doctor/ui/patient_screen.dart';
import 'registration.dart';

final registrationRepoProvider = Provider<RegistrationRepository>((ref) {
  final s = ref.watch(staffProvider);
  return RegistrationRepository(ref.watch(databaseProvider), staffId: s.id, staffName: s.displayName);
});

final opdDoctorsProvider = FutureProvider.autoDispose<List<OpdDoctor>>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(registrationRepoProvider).doctors();
});

/// Consultation charge to suggest: the doctor's own (from the web doctor shift), else one named "consult".
ChargeOption? defaultConsultation(List<ChargeOption> charges, OpdDoctor? doctor) {
  if (doctor?.chargeId != null) {
    final own = charges.where((c) => c.id == doctor!.chargeId).firstOrNull;
    if (own != null) return own;
  }
  return charges.where((c) => RegExp('consult|opd', caseSensitive: false).hasMatch('${c.name} ${c.category ?? ''} ${c.type ?? ''}')).firstOrNull;
}

/// Register a new patient and open their OPD visit, or (with [existing]) open a new visit for a known patient.
/// Works fully offline; both are uploaded on the next sync.
class RegisterPatientScreen extends ConsumerStatefulWidget {
  const RegisterPatientScreen({super.key, this.existing});
  final Patient? existing;

  @override
  ConsumerState<RegisterPatientScreen> createState() => _RegisterPatientScreenState();
}

class _RegisterPatientScreenState extends ConsumerState<RegisterPatientScreen> {
  final _name = TextEditingController();
  final _mobile = TextEditingController();
  final _years = TextEditingController();
  final _months = TextEditingController();
  final _guardian = TextEditingController();
  final _address = TextEditingController();
  final _allergies = TextEditingController();
  final _symptoms = TextEditingController();
  final _bp = TextEditingController();
  final _pulse = TextEditingController();
  final _temp = TextEditingController();
  final _weight = TextEditingController();
  final _paid = TextEditingController();
  final _discount = TextEditingController(text: '0');
  String? _gender;
  String _blood = '';
  DateTime? _dob;
  OpdDoctor? _doctor;
  ChargeOption? _charge;
  String _mode = 'Cash';
  bool _casualty = false;
  bool _paidTouched = false;
  bool _saving = false;
  String? _error;
  List<Patient> _sameMobile = const [];

  bool get _isNew => widget.existing == null;

  @override
  void dispose() {
    for (final c in [_name, _mobile, _years, _months, _guardian, _address, _allergies, _symptoms, _bp, _pulse, _temp, _weight, _paid, _discount]) {
      c.dispose();
    }
    super.dispose();
  }

  double get _discountPct => (double.tryParse(_discount.text) ?? 0).clamp(0, 100).toDouble();
  double get _total => _charge?.price(1, _discountPct).total ?? 0;

  void _syncPaid() {
    if (!_paidTouched) _paid.text = _total == 0 ? '' : _total.toStringAsFixed(_total % 1 == 0 ? 0 : 2);
  }

  Future<void> _checkMobile(String v) async {
    final list = await ref.read(registrationRepoProvider).withMobile(v);
    if (mounted) setState(() => _sameMobile = list);
  }

  Future<void> _save() async {
    final doctor = _doctor;
    if (doctor == null) {
      setState(() => _error = 'Pick the consultant doctor');
      return;
    }
    final reg = ref.read(registrationRepoProvider);
    final patient = NewPatient(
      name: _name.text,
      gender: _gender,
      ageYears: int.tryParse(_years.text) ?? 0,
      ageMonths: int.tryParse(_months.text) ?? 0,
      dob: _dob,
      mobile: _mobile.text,
      guardian: _guardian.text,
      address: _address.text,
      allergies: _allergies.text,
      bloodGroup: _blood,
    );
    final err = (_isNew ? patient.validate() : null) ??
        NewVisit(patientId: 0, doctor: doctor, charge: _charge, discount: _discountPct, paid: double.tryParse(_paid.text) ?? 0).validate();
    if (err != null) {
      setState(() => _error = err);
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final pid = _isNew ? await reg.registerPatient(patient) : widget.existing!.id;
      final enc = await reg.createVisit(NewVisit(
        patientId: pid,
        doctor: doctor,
        charge: _charge,
        discount: _discountPct,
        paid: double.tryParse(_paid.text) ?? 0,
        paymentMode: _mode,
        symptoms: _symptoms.text,
        bp: _bp.text,
        pulse: _pulse.text,
        temperature: _temp.text,
        weight: _weight.text,
        casualty: _casualty,
      ));
      afterLocalWrite(ref);
      if (!mounted) return;
      HapticFeedback.mediumImpact();
      final me = ref.read(staffProvider).id == doctor.id;
      await showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          icon: const Icon(Icons.confirmation_number_rounded, size: 36),
          title: Text('Token ${enc.j['token']}'),
          content: Text('${_isNew ? _name.text.trim() : widget.existing!.name} is in the queue for ${doctor.name}.\n'
              'Token and patient ID are confirmed when this device next syncs.'),
          actions: [FilledButton(onPressed: () => Navigator.pop(ctx), child: const Text('Done'))],
        ),
      );
      if (!mounted) return;
      if (me) {
        Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => PatientScreen(patientId: pid)));
      } else {
        Navigator.pop(context, pid);
      }
    } catch (e) {
      setState(() => _error = '$e');
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final doctors = ref.watch(opdDoctorsProvider).valueOrNull;
    final charges = ref.watch(chargeOptionsProvider).valueOrNull ?? const <ChargeOption>[];
    final me = ref.watch(staffProvider).id;
    if (doctors != null && _doctor == null && doctors.isNotEmpty) {
      _doctor = doctors.where((d) => d.id == me).firstOrNull ?? (doctors.length == 1 ? doctors.first : null);
    }
    if (_charge == null && charges.isNotEmpty) {
      _charge = defaultConsultation(charges, _doctor);
      WidgetsBinding.instance.addPostFrameCallback((_) => setState(_syncPaid));
    }

    return Scaffold(
      appBar: AppBar(title: Text(_isNew ? 'Register patient' : 'New OPD visit')),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            if (_error != null) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(_error!, style: TextStyle(color: t.colorScheme.error, fontWeight: FontWeight.w600))),
            FilledButton.icon(
              style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
              onPressed: _saving ? null : _save,
              icon: const Icon(Icons.how_to_reg_rounded),
              label: Text(_isNew ? 'Register & add to queue' : 'Add to queue'),
            ),
          ]),
        ),
      ),
      body: ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 24), children: [
        if (_isNew) ...[
          const SectionHeader('Patient', padding: EdgeInsets.fromLTRB(4, 4, 0, 8)),
          TextField(controller: _name, textCapitalization: TextCapitalization.words, decoration: const InputDecoration(labelText: 'Full name *')),
          const SizedBox(height: 12),
          TextField(
            controller: _mobile,
            keyboardType: TextInputType.phone,
            inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9+ ]'))],
            onChanged: _checkMobile,
            decoration: const InputDecoration(labelText: 'Mobile number', prefixIcon: Icon(Icons.phone_rounded)),
          ),
          if (_sameMobile.isNotEmpty)
            Card(
              color: t.colorScheme.tertiaryContainer,
              margin: const EdgeInsets.only(top: 8),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(14, 10, 14, 0),
                  child: Text('Already registered with this number. Tap to open a visit for them instead:', style: TextStyle(color: t.colorScheme.onTertiaryContainer)),
                ),
                for (final p in _sameMobile)
                  ListTile(
                    dense: true,
                    leading: PatientAvatar(name: p.name, radius: 16),
                    title: Text(p.name),
                    subtitle: Text(p.subtitle),
                    onTap: () => Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => RegisterPatientScreen(existing: p))),
                  ),
              ]),
            ),
          const SizedBox(height: 12),
          Wrap(spacing: 8, children: [
            for (final g in const ['Male', 'Female', 'Other']) ChoiceChip(label: Text(g), selected: _gender == g, onSelected: (on) => setState(() => _gender = on ? g : null)),
          ]),
          const SizedBox(height: 12),
          Row(children: [
            Expanded(child: TextField(controller: _years, enabled: _dob == null, keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.digitsOnly], decoration: const InputDecoration(labelText: 'Age (years)'))),
            const SizedBox(width: 10),
            Expanded(child: TextField(controller: _months, enabled: _dob == null, keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.digitsOnly], decoration: const InputDecoration(labelText: 'Months'))),
            const SizedBox(width: 10),
            Expanded(
              child: OutlinedButton(
                onPressed: () async {
                  final d = await showDatePicker(context: context, initialDate: _dob ?? DateTime(DateTime.now().year - 30), firstDate: DateTime(1900), lastDate: DateTime.now());
                  setState(() => _dob = d);
                },
                child: Text(_dob == null ? 'Date of birth' : fmtDate(_dob), textAlign: TextAlign.center),
              ),
            ),
          ]),
          const SizedBox(height: 12),
          TextField(controller: _guardian, textCapitalization: TextCapitalization.words, decoration: const InputDecoration(labelText: 'Guardian / relative (optional)')),
          const SizedBox(height: 12),
          TextField(controller: _address, textCapitalization: TextCapitalization.sentences, decoration: const InputDecoration(labelText: 'Address / village (optional)')),
          const SizedBox(height: 12),
          TextField(controller: _allergies, decoration: const InputDecoration(labelText: 'Known allergies', hintText: 'e.g. Penicillin, or None')),
          const SizedBox(height: 12),
          Wrap(spacing: 6, runSpacing: 6, children: [
            for (final b in const ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'])
              ChoiceChip(label: Text(b), visualDensity: VisualDensity.compact, selected: _blood == b, onSelected: (on) => setState(() => _blood = on ? b : '')),
          ]),
        ] else
          Card(child: ListTile(leading: PatientAvatar(name: widget.existing!.name), title: Text(widget.existing!.name), subtitle: Text(widget.existing!.subtitle))),

        const SectionHeader('Visit', padding: EdgeInsets.fromLTRB(4, 20, 0, 8)),
        if (doctors == null)
          const LinearProgressIndicator()
        else if (doctors.isEmpty)
          const Text('No doctors on this device yet. Sync once with internet to download the doctor list.')
        else
          DropdownButtonFormField<int>(
            initialValue: _doctor?.id,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Consultant doctor *'),
            items: [for (final d in doctors) DropdownMenuItem(value: d.id, child: Text([d.name, ?d.department].join(' · '), overflow: TextOverflow.ellipsis))],
            onChanged: (id) => setState(() {
              _doctor = doctors.firstWhere((d) => d.id == id);
              _charge = defaultConsultation(charges, _doctor) ?? _charge;
              _syncPaid();
            }),
          ),
        const SizedBox(height: 12),
        DropdownButtonFormField<int>(
          initialValue: _charge?.id,
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'Consultation charge *'),
          items: [for (final c in charges) DropdownMenuItem(value: c.id, child: Text('${c.name} · ₹${c.standardCharge.toStringAsFixed(0)}', overflow: TextOverflow.ellipsis))],
          onChanged: (id) => setState(() {
            _charge = charges.firstWhere((c) => c.id == id);
            _syncPaid();
          }),
        ),
        const SizedBox(height: 12),
        Row(children: [
          Expanded(
            child: TextField(
              controller: _discount,
              keyboardType: TextInputType.number,
              onChanged: (_) => setState(_syncPaid),
              decoration: const InputDecoration(labelText: 'Discount', suffixText: '%'),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: TextField(
              controller: _paid,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              onChanged: (_) => _paidTouched = true,
              decoration: InputDecoration(labelText: 'Paid now', prefixText: '₹ ', helperText: 'Total ₹${_total.toStringAsFixed(_total % 1 == 0 ? 0 : 2)}'),
            ),
          ),
        ]),
        const SizedBox(height: 8),
        Wrap(spacing: 8, children: [for (final m in paymentModes) ChoiceChip(label: Text(m), selected: _mode == m, onSelected: (_) => setState(() => _mode = m))]),
        const SizedBox(height: 12),
        TextField(controller: _symptoms, maxLines: 2, textCapitalization: TextCapitalization.sentences, decoration: const InputDecoration(labelText: 'Complaint / symptoms')),
        const SizedBox(height: 12),
        Row(children: [
          Expanded(child: TextField(controller: _bp, decoration: const InputDecoration(labelText: 'BP', hintText: '120/80'))),
          const SizedBox(width: 8),
          Expanded(child: TextField(controller: _pulse, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Pulse'))),
          const SizedBox(width: 8),
          Expanded(child: TextField(controller: _temp, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Temp °F'))),
          const SizedBox(width: 8),
          Expanded(child: TextField(controller: _weight, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Wt kg'))),
        ]),
        SwitchListTile(contentPadding: EdgeInsets.zero, title: const Text('Emergency / casualty'), value: _casualty, onChanged: (v) => setState(() => _casualty = v)),
      ]),
    );
  }
}
