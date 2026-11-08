import 'staff.dart';

/// Signed-in patient, as returned by api/v1/patient/login and /me.
class PatientSession {
  PatientSession({required this.patient, required this.modules, required this.hospital});

  /// Raw account JSON: user_id, patient_id, username, name, gender, dob, mobileno, known_allergies, ...
  final Map<String, dynamic> patient;

  /// Sections the hospital enabled for patients: opd, ipd, pathology, radiology, my_appointments.
  final Map<String, bool> modules;
  final Hospital hospital;

  factory PatientSession.fromJson(Map<String, dynamic> j) => PatientSession(
        patient: Map<String, dynamic>.from(j['patient'] as Map),
        modules: {for (final e in ((j['modules'] as Map?) ?? const {}).entries) '${e.key}': e.value == true},
        hospital: Hospital.fromJson(Map<String, dynamic>.from((j['hospital'] as Map?) ?? const {})),
      );

  Map<String, dynamic> toJson() => {'patient': patient, 'modules': modules, 'hospital': hospital.toJson()};

  int get userId => (patient['user_id'] as num).toInt();
  int get patientId => (patient['patient_id'] as num).toInt();
  String get name => '${patient['name'] ?? ''}'.trim();
  String get firstName => name.split(RegExp(r'\s+')).first;
  String? get username => patient['username'] as String?;

  bool has(String module) => modules[module] ?? true;
}
