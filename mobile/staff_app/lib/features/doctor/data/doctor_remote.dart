import '../../../core/api/api_client.dart';
import '../../../core/db/app_database.dart';
import 'doctor_sync.dart';
import 'models.dart';

/// Online-only lookups. Everything else in the doctor module reads the local database.
class DoctorRemote {
  DoctorRemote(this.api, this.adb);
  final ApiClient api;
  final AppDatabase adb;

  /// Hospital-wide search (respects the hospital's "doctor restriction" setting).
  Future<List<Map<String, dynamic>>> searchHospital(String q) async {
    final r = await api.get('patients/search', query: {'q': q});
    return ((r['patients'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
  }

  /// Download a patient's full history and keep it on the device (pinned) for offline use.
  Future<void> downloadPatient(int patientId) async {
    final r = await api.get('patients/bundle', query: {'id': patientId});
    final data = Map<String, dynamic>.from(r['data'] as Map);
    await adb.db.transaction((tx) => DoctorSyncModule.applyPatientData(tx, data, pin: true));
  }

  Future<List<IcdCode>> searchIcd(String q) async {
    final r = await api.get('patients/icd', query: {'q': q});
    return ((r['codes'] as List?) ?? const []).map((e) => IcdCode.fromJson(Map<String, dynamic>.from(e as Map))).toList();
  }
}
