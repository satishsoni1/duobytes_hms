import 'dart:convert';

// Typed views over the JSON the server sends (api/v1/sync/pull). Kept as maps underneath so that
// fields added on the server later are preserved in the local store without a migration.

DateTime? parseServerDate(Object? v) {
  if (v == null) return null;
  final s = '$v'.trim();
  if (s.isEmpty || s.startsWith('0000')) return null;
  return DateTime.tryParse(s.replaceFirst(' ', 'T'));
}

String? _str(Object? v) {
  if (v == null) return null;
  final s = '$v'.trim();
  return s.isEmpty ? null : s;
}

int? _int(Object? v) => v == null ? null : (v is num ? v.toInt() : int.tryParse('$v'));

/// "Penicillin, sulfa; nuts" -> [Penicillin, sulfa, nuts]. "None"/"Nil"/"NA" count as no allergies.
List<String> splitAllergies(Iterable<String?> sources) {
  final out = <String>{};
  for (final s in sources) {
    if (s == null) continue;
    for (final part in s.split(RegExp(r'[,;\n/]+'))) {
      final t = part.trim();
      if (t.isEmpty) continue;
      if (RegExp(r'^(none|nil|no|na|n/a|nkda|not known|no known allergies?)\.?$', caseSensitive: false).hasMatch(t)) continue;
      out.add(t);
    }
  }
  return out.toList();
}

class Patient {
  Patient(this.j, {this.pinned = false});
  final Map<String, dynamic> j;
  final bool pinned;

  int get id => _int(j['id'])!;
  String get name => _str(j['name']) ?? 'Patient #$id';
  String? get gender => _str(j['gender']);
  DateTime? get dob => parseServerDate(j['dob']);
  String? get mobile => _str(j['mobileno']);
  String? get email => _str(j['email']);
  String? get bloodGroup => _str(j['blood_group']);
  String? get address => _str(j['address']);
  String? get guardian => _str(j['guardian_name']);
  String? get note => _str(j['note']);
  String? get identification => _str(j['identification_number']);
  String? get imageUrl => _str(j['image']);
  bool get isDead => j['is_dead'] == 'yes';
  List<String> get allergies => splitAllergies([_str(j['known_allergies'])]);

  /// "34y 2m" from dob when available, else from the stored age fields.
  String? get ageLabel {
    final d = dob;
    if (d != null) {
      final now = DateTime.now();
      var y = now.year - d.year;
      var m = now.month - d.month;
      if (now.day < d.day) m--;
      if (m < 0) {
        y--;
        m += 12;
      }
      if (y < 0) return null;
      return y >= 2 ? '${y}y' : '${y * 12 + m}m';
    }
    final y = _int(j['age']) ?? 0, m = _int(j['month']) ?? 0, dd = _int(j['day']) ?? 0;
    if (y == 0 && m == 0 && dd == 0) return null;
    if (y >= 2) return '${y}y';
    if (y > 0 || m > 0) return '${y * 12 + m}m';
    return '${dd}d';
  }

  String get subtitle => [ageLabel, gender, if (bloodGroup != null) bloodGroup].whereType<String>().join(' · ');
}

class Vitals {
  Vitals(this.j);
  final Map<String, dynamic> j;
  String? get bp => _str(j['bp']);
  String? get pulse => _str(j['pulse']);
  String? get temperature => _str(j['temperature']);
  String? get respiration => _str(j['respiration']);
  String? get height => _str(j['height']);
  String? get weight => _str(j['weight']);

  Map<String, String> get present => {
        'BP': ?bp,
        'Pulse': ?pulse,
        'Temp': ?temperature,
        'Resp': ?respiration,
        'Weight': ?weight,
        'Height': ?height,
      };
}

/// One OPD visit (visit_details) or one IPD admission (ipd_details) - what a prescription is written against.
class Encounter {
  Encounter._(this.kind, this.j);
  factory Encounter.visit(Map<String, dynamic> j) => Encounter._('opd', j);
  factory Encounter.admission(Map<String, dynamic> j) => Encounter._('ipd', j);

  final String kind; // 'opd' | 'ipd'
  final Map<String, dynamic> j;

  bool get isIpd => kind == 'ipd';

  /// visit_details.id for OPD, ipd_details.id for IPD. This is the prescription's case_ref_id.
  int get refId => _int(j['id'])!;

  /// opd_details.id / ipd_details.id: the case diagnoses are attached to.
  int get caseId => isIpd ? refId : (_int(j['opd_id']) ?? 0);
  int get patientId => _int(j['patient_id'])!;
  int? get doctorId => _int(j['doctor_id']);
  String? get doctorName => _str(j['doctor_name']);
  DateTime? get date => parseServerDate(isIpd ? j['date'] : j['appointment_date']);
  String? get symptoms => _str(j['symptoms']);
  String? get note => _str(j['note']);
  String? get caseType => _str(j['case_type']);
  bool get isOpen => j['discharged'] != 'yes';
  String? get bed => isIpd ? [_str(j['bed_group']), _str(j['bed_name'])].whereType<String>().join(' / ') : null;
  List<int> get assignedDoctorIds => isIpd ? ((j['assigned_doctor_ids'] as List?) ?? const []).map((e) => _int(e)!).toList() : const [];
  List<String> get allergies => splitAllergies([_str(j['known_allergies'])]);
  Vitals get vitals => Vitals(j);

  bool involvesDoctor(int staffId) => doctorId == staffId || assignedDoctorIds.contains(staffId);

  String get label => isIpd ? 'IPD admission' : 'OPD visit';
  String get key => '$kind:$refId';
}

class IcdCode {
  IcdCode({required this.id, required this.code, required this.description});
  final int id;
  final String code;
  final String description;

  factory IcdCode.fromJson(Map<String, dynamic> j) =>
      IcdCode(id: _int(j['id'])!, code: '${j['code'] ?? ''}', description: '${j['description'] ?? ''}');
  Map<String, dynamic> toJson() => {'id': id, 'code': code, 'description': description};

  @override
  bool operator ==(Object other) => other is IcdCode && other.id == id;
  @override
  int get hashCode => id;
}

class TestRef {
  TestRef(this.id, this.name);
  final int id;
  final String name;
  factory TestRef.fromJson(Map<String, dynamic> j) => TestRef(_int(j['id'])!, '${j['name'] ?? ''}');
  Map<String, dynamic> toJson() => {'id': id, 'name': name};
}

enum RxStatus { synced, pending, conflict, rejected }

RxStatus rxStatusFrom(String s) => RxStatus.values.firstWhere((e) => e.name == s, orElse: () => RxStatus.pending);

/// One medicine line. Mutable: the prescription editor edits these in place.
class RxItem {
  RxItem({
    this.id,
    required this.medicineId,
    required this.medicineName,
    this.composition,
    this.categoryId,
    this.dosageId,
    this.dosageLabel,
    this.intervalId,
    this.intervalName,
    this.durationId,
    this.durationName,
    this.instruction = '',
  });

  int? id; // server line id (ipd_prescription_details.id), null for new lines
  int medicineId;
  String medicineName;
  String? composition;
  int? categoryId;
  int? dosageId;
  String? dosageLabel;
  int? intervalId;
  String? intervalName;
  int? durationId;
  String? durationName;
  String instruction;

  factory RxItem.fromJson(Map<String, dynamic> j) => RxItem(
        id: _int(j['id']),
        medicineId: _int(j['medicine_id']) ?? 0,
        medicineName: _str(j['medicine_name']) ?? 'Unknown medicine',
        composition: _str(j['composition']),
        categoryId: _int(j['category_id']),
        dosageId: _int(j['dosage_id']),
        dosageLabel: _str(j['dosage_label']),
        intervalId: _int(j['interval_id']),
        intervalName: _str(j['interval_name']),
        durationId: _int(j['duration_id']),
        durationName: _str(j['duration_name']),
        instruction: _str(j['instruction']) ?? '',
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'medicine_id': medicineId,
        'medicine_name': medicineName,
        'composition': composition,
        'category_id': categoryId,
        'dosage_id': dosageId,
        'dosage_label': dosageLabel,
        'interval_id': intervalId,
        'interval_name': intervalName,
        'duration_id': durationId,
        'duration_name': durationName,
        'instruction': instruction,
      };

  RxItem copy({bool keepId = true}) => RxItem.fromJson(toJson()..['id'] = keepId ? id : null);

  String get doseLine => [dosageLabel, intervalName, durationName].whereType<String>().where((s) => s.isNotEmpty).join(' · ');
}

/// A prescription as stored locally: either from the server or written on this device.
/// The JSON shape is the server's, plus `icd_ids` / `print_findings` for unsent drafts.
class Prescription {
  Prescription({
    required this.localId,
    required this.serverId,
    required this.patientId,
    required this.kind,
    required this.caseRefId,
    required this.status,
    required this.version,
    required this.error,
    required this.j,
    this.serverJson,
    this.updatedLocalAt,
  });

  final String localId;
  final int? serverId;
  final int patientId;
  final String kind;
  final int caseRefId;
  final RxStatus status;
  final String? version;
  final String? error;
  final Map<String, dynamic> j;
  final Map<String, dynamic>? serverJson;
  final DateTime? updatedLocalAt;

  factory Prescription.fromRow(Map<String, Object?> r) => Prescription(
        localId: r['local_id'] as String,
        serverId: r['server_id'] as int?,
        patientId: r['patient_id'] as int,
        kind: r['kind'] as String,
        caseRefId: r['case_ref_id'] as int,
        status: rxStatusFrom(r['status'] as String),
        version: r['version'] as String?,
        error: r['error'] as String?,
        j: jsonDecode(r['json'] as String) as Map<String, dynamic>,
        serverJson: r['server_json'] == null ? null : jsonDecode(r['server_json'] as String) as Map<String, dynamic>,
        updatedLocalAt: parseServerDate(r['updated_local_at']),
      );

  DateTime? get date => parseServerDate(j['date']);
  String? get headerNote => _str(j['header_note']);
  String? get footerNote => _str(j['footer_note']);
  String? get findings => _str(j['finding_description']);
  bool get printFindings => j['print_findings'] == true || j['is_finding_print'] == 'yes';
  String? get prescribedBy => _str(j['prescribe_by_name']);
  int? get generatedBy => _int(j['generated_by']);
  List<RxItem> get items => ((j['items'] as List?) ?? const []).map((e) => RxItem.fromJson(Map<String, dynamic>.from(e as Map))).toList();
  List<TestRef> get pathology => ((j['pathology'] as List?) ?? const []).map((e) => TestRef.fromJson(Map<String, dynamic>.from(e as Map))).toList();
  List<TestRef> get radiology => ((j['radiology'] as List?) ?? const []).map((e) => TestRef.fromJson(Map<String, dynamic>.from(e as Map))).toList();
  List<IcdCode> get newDiagnoses => ((j['icd_codes'] as List?) ?? const []).map((e) => IcdCode.fromJson(Map<String, dynamic>.from(e as Map))).toList();

  bool get isLocalOnly => serverId == null;
  String get displayNo => serverId != null ? '#$serverId' : 'Not synced';
}

/// Mutable state of the prescription editor.
class PrescriptionDraft {
  PrescriptionDraft({
    this.localId,
    this.serverId,
    this.baseVersion,
    required this.patientId,
    required this.encounter,
    DateTime? date,
    List<RxItem>? items,
    List<TestRef>? pathology,
    List<TestRef>? radiology,
    List<IcdCode>? diagnoses,
    this.findings = '',
    this.headerNote = '',
    this.footerNote = '',
    this.printFindings = false,
  })  : date = date ?? DateTime.now(),
        items = items ?? [],
        pathology = pathology ?? [],
        radiology = radiology ?? [],
        diagnoses = diagnoses ?? [];

  String? localId;
  int? serverId;
  String? baseVersion;
  final int patientId;
  Encounter encounter;
  DateTime date;
  List<RxItem> items;
  List<TestRef> pathology;
  List<TestRef> radiology;

  /// New ICD-10 codes to add to the case together with this prescription.
  List<IcdCode> diagnoses;
  String findings;
  String headerNote;
  String footerNote;
  bool printFindings;

  factory PrescriptionDraft.fromPrescription(Prescription p, Encounter encounter) => PrescriptionDraft(
        localId: p.localId,
        serverId: p.serverId,
        baseVersion: p.version,
        patientId: p.patientId,
        encounter: encounter,
        date: p.date,
        items: p.items,
        pathology: p.pathology,
        radiology: p.radiology,
        diagnoses: p.newDiagnoses,
        findings: p.findings ?? '',
        headerNote: p.headerNote ?? '',
        footerNote: p.footerNote ?? '',
        printFindings: p.printFindings,
      );

  bool get isEmpty => items.isEmpty && pathology.isEmpty && radiology.isEmpty;

  /// Same rules the server applies, so the doctor sees problems before the prescription is queued.
  List<String> validate() {
    final errors = <String>[];
    if (isEmpty) errors.add('Add at least one medicine or test.');
    if (items.length > 40) errors.add('A prescription can have at most 40 medicines.');
    for (var i = 0; i < items.length; i++) {
      final it = items[i];
      if (it.dosageId == null) errors.add('${it.medicineName}: choose a dose.');
      if (it.intervalId == null) errors.add('${it.medicineName}: choose how often.');
    }
    return errors;
  }

  /// Local JSON (server shape) for storage and for building the push payload.
  Map<String, dynamic> toJson() => {
        'id': serverId,
        'visit_id': encounter.isIpd ? null : encounter.refId,
        'ipd_id': encounter.isIpd ? encounter.refId : null,
        'patient_id': patientId,
        'date': _ymd(date),
        'header_note': headerNote.trim(),
        'footer_note': footerNote.trim(),
        'finding_description': findings.trim(),
        'print_findings': printFindings,
        'items': items.map((e) => e.toJson()).toList(),
        'pathology': pathology.map((e) => e.toJson()).toList(),
        'radiology': radiology.map((e) => e.toJson()).toList(),
        'icd_codes': diagnoses.map((e) => e.toJson()).toList(),
      };
}

/// Push payload for op `prescription.save` built from a stored prescription row.
Map<String, dynamic> prescriptionPushPayload(Prescription p, {required bool force}) => {
      'kind': p.kind,
      'case_ref_id': p.caseRefId,
      'server_id': p.serverId,
      'base_version': p.version,
      'force': force,
      'date': p.j['date'],
      'header_note': p.j['header_note'] ?? '',
      'footer_note': p.j['footer_note'] ?? '',
      'finding_description': p.j['finding_description'] ?? '',
      'print_findings': p.printFindings,
      'items': p.items
          .map((it) => {
                'id': it.id,
                'medicine_id': it.medicineId,
                'dosage_id': it.dosageId,
                'interval_id': it.intervalId,
                'duration_id': it.durationId,
                'instruction': it.instruction,
              })
          .toList(),
      'pathology_ids': p.pathology.map((t) => t.id).toList(),
      'radiology_ids': p.radiology.map((t) => t.id).toList(),
      'icd_ids': p.newDiagnoses.map((c) => c.id).toList(),
    };

String _ymd(DateTime d) => '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

class VitalReading {
  VitalReading(this.j);
  final Map<String, dynamic> j;
  String get name => '${j['name'] ?? ''}';
  String get value => '${j['value'] ?? ''}';
  String? get unit => _str(j['unit']);
  String? get normalRange => _str(j['normal_range']);
  DateTime? get measuredAt => parseServerDate(j['measured_at']);
}

class LabParam {
  LabParam(this.j);
  final Map<String, dynamic> j;
  String get name => '${j['name'] ?? ''}';
  String get value => '${j['value'] ?? ''}';
  String? get unit => _str(j['unit']);
  String? get referenceRange => _str(j['reference_range']);
}

class LabReport {
  LabReport(this.j);
  final Map<String, dynamic> j;
  int get id => _int(j['id'])!;
  String get kind => '${j['kind']}';
  String get testName => _str(j['test_name']) ?? 'Test';
  String? get shortName => _str(j['short_name']);
  DateTime? get reportingDate => parseServerDate(j['reporting_date']);
  DateTime? get collectionDate => parseServerDate(j['collection_date']);
  String? get result => _str(j['result']);
  String? get description => _str(j['description']);
  List<LabParam> get parameters => ((j['parameters'] as List?) ?? const []).map((e) => LabParam(Map<String, dynamic>.from(e as Map))).toList();
  bool get isReported => reportingDate != null || result != null || parameters.any((p) => p.value.isNotEmpty);

  /// The lab uploaded a report document (PDF/image), downloadable through the API.
  bool get hasFile => j['has_file'] == true;
  String? get fileName => _str(j['file_name']);
  int? get patientId => _int(j['patient_id']);
  String get key => '$kind:$id';
}

class TimelineEntry {
  TimelineEntry(this.j);
  final Map<String, dynamic> j;
  String get title => '${j['title'] ?? ''}';
  String? get description => _str(j['description']);
  DateTime? get date => parseServerDate(j['timeline_date']);
}

class Appointment {
  Appointment(this.j);
  final Map<String, dynamic> j;
  int get id => _int(j['id'])!;
  int get patientId => _int(j['patient_id'])!;
  int? get visitId => _int(j['visit_id']);
  DateTime? get date => parseServerDate(j['date']);
  String get status => _str(j['status']) ?? 'pending';
  String? get priority => _str(j['priority']);
  String? get message => _str(j['message']);
}

// ------------------------------------------------------------------ masters
class Medicine {
  Medicine({required this.id, required this.name, this.composition, this.company, this.categoryId, this.category});
  final int id;
  final String name;
  final String? composition;
  final String? company;
  final int? categoryId;
  final String? category;

  factory Medicine.fromRow(Map<String, Object?> r) => Medicine(
        id: r['id'] as int,
        name: r['name'] as String,
        composition: _str(r['composition']),
        company: _str(r['company']),
        categoryId: r['category_id'] as int?,
        category: _str(r['category']),
      );
}

class Option {
  Option(this.id, this.label);
  final int id;
  final String label;
}

class Masters {
  Masters({
    required this.dosagesByCategory,
    required this.allDosages,
    required this.intervals,
    required this.durations,
    required this.pathology,
    required this.radiology,
    required this.frequentMedicineIds,
  });

  final Map<int, List<Option>> dosagesByCategory;
  final List<Option> allDosages;
  final List<Option> intervals;
  final List<Option> durations;
  final List<TestRef> pathology;
  final List<TestRef> radiology;
  final List<int> frequentMedicineIds;

  bool get isEmpty => intervals.isEmpty && allDosages.isEmpty;

  /// Dose options for a medicine's category; all doses when the category has none configured.
  List<Option> dosagesFor(int? categoryId) {
    final list = categoryId == null ? null : dosagesByCategory[categoryId];
    return (list == null || list.isEmpty) ? allDosages : list;
  }

  static Masters empty() => Masters(
      dosagesByCategory: const {}, allDosages: const [], intervals: const [], durations: const [],
      pathology: const [], radiology: const [], frequentMedicineIds: const []);
}

/// Template, in the same format as the web Smart Prescription screen (camelCase item keys).
class RxTemplate {
  RxTemplate({required this.localId, required this.serverId, required this.name, required this.shared, required this.mine, required this.payload});
  final String localId;
  final int? serverId;
  final String name;
  final bool shared;
  final bool mine;
  final Map<String, dynamic> payload;

  List<RxItem> get items => ((payload['items'] as List?) ?? const []).map((e) {
        final m = Map<String, dynamic>.from(e as Map);
        return RxItem(
          medicineId: _int(m['medicineId']) ?? 0,
          medicineName: _str(m['name']) ?? 'Medicine',
          composition: _str(m['composition']),
          categoryId: _int(m['categoryId']),
          dosageId: _int(m['dosageId']),
          intervalId: _int(m['intervalId']),
          durationId: _int(m['durationId']),
          instruction: _str(m['instruction']) ?? '',
        );
      }).where((i) => i.medicineId > 0).toList();

  String? get advice => _str(payload['advice']);
  List<int> get pathologyIds => (((payload['tests'] as Map?)?['pathology'] as List?) ?? const []).map((e) => _int(e)!).toList();
  List<int> get radiologyIds => (((payload['tests'] as Map?)?['radiology'] as List?) ?? const []).map((e) => _int(e)!).toList();
  List<int> get diagnosisIds => ((payload['diagnosis'] as List?) ?? const []).map((e) => _int(e)!).toList();

  static Map<String, dynamic> payloadFrom(PrescriptionDraft d) => {
        'items': d.items
            .map((i) => {
                  'medicineId': i.medicineId,
                  'name': i.medicineName,
                  'composition': i.composition ?? '',
                  'categoryId': i.categoryId ?? 0,
                  'dosageId': i.dosageId,
                  'intervalId': i.intervalId,
                  'durationId': i.durationId,
                  'instruction': i.instruction,
                })
            .toList(),
        'advice': d.footerNote,
        'tests': {
          'pathology': d.pathology.map((t) => t.id).toList(),
          'radiology': d.radiology.map((t) => t.id).toList(),
        },
        'diagnosis': d.diagnoses.map((c) => c.id).toList(),
      };
}
