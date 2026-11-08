/// Signed-in staff member plus hospital branding, as returned by api/v1/auth/login and /me.
class StaffSession {
  StaffSession({required this.staff, required this.hospital});

  final Staff staff;
  final Hospital hospital;

  factory StaffSession.fromJson(Map<String, dynamic> j) => StaffSession(
        staff: Staff.fromJson(j['staff'] as Map<String, dynamic>),
        hospital: Hospital.fromJson(j['hospital'] as Map<String, dynamic>? ?? const {}),
      );

  Map<String, dynamic> toJson() => {'staff': staff.toJson(), 'hospital': hospital.toJson()};
}

class Staff {
  Staff(this._j);
  final Map<String, dynamic> _j;

  factory Staff.fromJson(Map<String, dynamic> j) => Staff(Map<String, dynamic>.from(j));
  Map<String, dynamic> toJson() => _j;

  int get id => (_j['id'] as num).toInt();
  String get name => '${_j['name'] ?? ''} ${_j['surname'] ?? ''}'.trim();
  String get displayName => role == 'Doctor' ? 'Dr. $name' : name;
  String get role => _j['role'] as String? ?? '';
  String? get employeeId => _j['employee_id'] as String?;
  String? get email => _j['email'] as String?;
  String? get department => _j['department'] as String?;
  String? get designation => _j['designation'] as String?;
  String? get specialization => _j['specialization'] as String?;
  String? get qualification => _j['qualification'] as String?;
  String? get imageUrl => _j['image'] as String?;
  List<String> get modules => (_j['modules'] as List? ?? const []).cast<String>();

  /// Hospital specialist names for this doctor (free text, e.g. "Paediatrics", "Cardiology").
  List<String> get specialties => (_j['specialties'] as List? ?? const []).map((e) => '$e').toList();
}

class Hospital {
  Hospital(this._j);
  final Map<String, dynamic> _j;

  factory Hospital.fromJson(Map<String, dynamic> j) => Hospital(Map<String, dynamic>.from(j));
  Map<String, dynamic> toJson() => _j;

  String get name => _j['hospital_name'] as String? ?? '';
  String? get address => _j['address'] as String?;
  String? get phone => _j['phone'] as String?;
  String? get logoUrl => _j['logo_url'] as String?;
  String? get primaryColor => _j['primary_color'] as String?;
}
