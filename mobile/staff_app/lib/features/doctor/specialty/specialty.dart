import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/providers.dart';
import '../data/clinical.dart';
import '../data/models.dart';
import '../ui/doctor_providers.dart';
import 'specialty_registry.dart';

/// A set of specialty tools (cards on the patient's Care tab plus their screens).
///
/// To add a specialty: create a toolkit with the specialist-name keywords it matches, which patients
/// it applies to, and the card it shows; then add it to [specialtyToolkits]. Records it saves use
/// [ClinicalRepository] with a record type the server accepts (Mobileclinical_model::TYPES).
class SpecialtyToolkit {
  const SpecialtyToolkit({
    required this.id,
    required this.name,
    required this.description,
    required this.icon,
    required this.keywords,
    required this.appliesTo,
    required this.buildCards,
    this.alwaysOn = false,
    this.optIn = false,
    this.recordTypes = const {},
  });

  final String id;
  final String name;
  final String description;
  final IconData icon;

  /// Matched (case-insensitive substring) against the hospital's specialist names for the doctor.
  final List<String> keywords;

  /// Tools every doctor gets (e.g. follow-ups).
  final bool alwaysOn;

  /// Only on when matched by specialty or switched on by the doctor (not offered to every doctor by default).
  final bool optIn;

  /// Record types this toolkit writes. A patient who already has such records shows the toolkit to every
  /// doctor (e.g. a GP sees the dentist's chart), even when it is not switched on.
  final Set<String> recordTypes;

  bool hasRecords(List<ClinicalRecord> records) => recordTypes.isNotEmpty && records.any((r) => recordTypes.contains(r.type));

  /// Whether this toolkit is relevant for a given patient (e.g. paediatrics: under 18).
  final bool Function(PatientDetail d, List<ClinicalRecord> records) appliesTo;

  final List<Widget> Function(BuildContext context, PatientDetail d, List<ClinicalRecord> records) buildCards;

  bool matches(Iterable<String> specialties) =>
      specialties.any((s) => keywords.any((k) => s.toLowerCase().contains(k)));
}

/// All toolkits (defined in specialty_registry.dart).
List<SpecialtyToolkit> get specialtyToolkits => allSpecialtyToolkits;

// ------------------------------------------------------------------ patient helpers
extension PatientAge on Patient {
  /// Age in whole days (from date of birth, else from the stored age fields).
  int? get ageDays {
    final d = dob;
    if (d != null) return DateTime.now().difference(d).inDays;
    final y = (j['age'] as num?)?.toInt() ?? 0, m = (j['month'] as num?)?.toInt() ?? 0, dd = (j['day'] as num?)?.toInt() ?? 0;
    if (y == 0 && m == 0 && dd == 0) return null;
    return y * 365 + m * 30 + dd;
  }

  double? get ageYears => ageDays == null ? null : ageDays! / 365.25;
  bool get isFemale => (gender ?? '').toLowerCase().startsWith('f');

  /// Date of birth, or an estimate from the stored age (used for vaccination due dates).
  DateTime? get dobOrEstimate => dob ?? (ageDays == null ? null : DateTime.now().subtract(Duration(days: ageDays!)));
}

// ------------------------------------------------------------------ which toolkits are on
class SpecialtySettings {
  const SpecialtySettings({required this.matched, required this.enabled, required this.overridden});

  /// Toolkits matched from the doctor's specialties on the server.
  final Set<String> matched;

  /// Toolkits switched on (matched, or the doctor's own choice).
  final Set<String> enabled;
  final bool overridden;
}

const _metaKey = 'specialty_tools';

final specialtySettingsProvider = FutureProvider.autoDispose<SpecialtySettings>((ref) async {
  ref.watch(dataRevisionProvider);
  final staff = ref.watch(staffProvider);
  final matched = {for (final t in specialtyToolkits) if (t.alwaysOn || t.matches(staff.specialties)) t.id};
  final raw = await ref.watch(databaseProvider).getMeta(_metaKey);
  if (raw != null) {
    final chosen = (jsonDecode(raw) as List).map((e) => '$e').toSet();
    return SpecialtySettings(matched: matched, enabled: {...chosen, for (final t in specialtyToolkits) if (t.alwaysOn) t.id}, overridden: true);
  }
  // No specialty on record: offer every toolkit (each still only shows for patients it applies to).
  final nothingSpecific = matched.every((id) => specialtyToolkits.firstWhere((t) => t.id == id).alwaysOn);
  return SpecialtySettings(matched: matched, enabled: nothingSpecific ? {for (final t in specialtyToolkits) if (!t.optIn) t.id} : matched, overridden: false);
});

Future<void> setSpecialtyTools(WidgetRef ref, Set<String>? ids) async {
  await ref.read(databaseProvider).setMeta(_metaKey, ids == null ? null : jsonEncode(ids.toList()));
  if (ids == null) await ref.read(databaseProvider).db.delete('meta', where: 'key = ?', whereArgs: [_metaKey]);
  ref.read(dataRevisionProvider.notifier).state++;
}

final clinicalRepoProvider = Provider<ClinicalRepository>((ref) => ClinicalRepository(ref.watch(databaseProvider)));

final clinicalRecordsProvider = FutureProvider.autoDispose.family<List<ClinicalRecord>, int>((ref, patientId) {
  ref.watch(dataRevisionProvider);
  return ref.watch(clinicalRepoProvider).forPatient(patientId);
});

/// Save a specialty record locally and schedule upload.
Future<void> saveClinicalRecord(WidgetRef ref, ClinicalRecord r) async {
  await ref.read(clinicalRepoProvider).save(r);
  afterLocalWrite(ref);
}

Future<void> deleteClinicalRecord(WidgetRef ref, ClinicalRecord r) async {
  await ref.read(clinicalRepoProvider).delete(r);
  afterLocalWrite(ref);
}
