import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:staff_app/core/api/api_client.dart';
import 'package:staff_app/core/db/app_database.dart';

typedef Handler = Future<(int, Object?)> Function(Map<String, dynamic>? body, RequestOptions o);

/// In-process stand-in for the hospital API (api/v1/*).
class FakeServer implements HttpClientAdapter {
  final routes = <String, Handler>{};
  final calls = <(String, Map<String, dynamic>?)>[];
  bool offline = false;
  String? etag;

  ApiClient client() {
    final dio = Dio();
    dio.httpClientAdapter = this;
    return ApiClient(baseUrl: 'https://hms.test', token: 'a' * 64, dio: dio);
  }

  List<Map<String, dynamic>?> bodiesFor(String path) => [for (final c in calls) if (c.$1 == path) c.$2];

  @override
  Future<ResponseBody> fetch(RequestOptions o, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    if (offline) throw DioException.connectionError(requestOptions: o, reason: 'offline');
    final path = o.uri.path.replaceFirst('/api/v1/', '');
    Map<String, dynamic>? body;
    if (requestStream != null) {
      final bytes = await requestStream.expand((x) => x).toList();
      if (bytes.isNotEmpty) body = jsonDecode(utf8.decode(bytes)) as Map<String, dynamic>;
    }
    calls.add((path, body));
    final h = routes[path];
    final (status, resp) = h == null ? (404, {'error': {'code': 'not_found', 'message': 'No route $path'}}) : await h(body, o);
    return ResponseBody.fromString(
      resp == null ? '' : jsonEncode(resp),
      status,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
        if (etag != null) 'etag': [etag!],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

Future<AppDatabase> openTestDb() async {
  sqfliteFfiInit();
  final db = await databaseFactoryFfi.openDatabase(
    inMemoryDatabasePath,
    options: OpenDatabaseOptions(version: AppDatabase.version, onCreate: AppDatabase.onCreate, singleInstance: false),
  );
  return AppDatabase(db);
}

// ------------------------------------------------------------------ fixtures
const doctorId = 7;

String today([int addDays = 0]) {
  final d = DateTime.now().add(Duration(days: addDays));
  return '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
}

Map<String, dynamic> patient(int id, {String name = 'Asha Patil', String allergies = 'Penicillin'}) => {
      'id': id,
      'name': name,
      'gender': 'Female',
      'dob': '1990-04-02',
      'age': 36,
      'month': 0,
      'day': 0,
      'mobileno': '98220$id',
      'known_allergies': allergies,
      'blood_group': 'B+',
      'is_dead': 'no',
      'updated_at': '2026-11-01 09:00:00',
    };

Map<String, dynamic> visit(int id, int patientId, {int opdId = 100, int doctor = doctorId, String? date}) => {
      'id': id,
      'opd_id': opdId,
      'patient_id': patientId,
      'doctor_id': doctor,
      'doctor_name': 'Mehta',
      'appointment_date': '${date ?? today()} 10:30:00',
      'case_type': '',
      'symptoms': 'Fever for 3 days',
      'bp': '120/80',
      'pulse': '88',
      'temperature': '101',
      'discharged': 'no',
      'updated_at': '2026-11-01 09:00:00',
    };

Map<String, dynamic> serverRx(int id, {int visitId = 10, int patientId = 1, String version = '2026-11-01 10:00:00', List<Map<String, dynamic>>? items}) => {
      'id': id,
      'visit_id': visitId,
      'ipd_id': null,
      'patient_id': patientId,
      'date': today(),
      'header_note': '',
      'footer_note': 'Plenty of fluids',
      'finding_description': '',
      'prescribe_by': doctorId,
      'prescribe_by_name': 'Mehta',
      'generated_by': doctorId,
      'version': version,
      'items': items ??
          [
            {'id': 900, 'medicine_id': 1, 'medicine_name': 'Paracetamol 650', 'composition': 'Paracetamol', 'category_id': 1, 'dosage_id': 11, 'dosage_label': '1 Tablet', 'interval_id': 21, 'interval_name': 'TDS', 'duration_id': 31, 'duration_name': '5 days', 'instruction': 'After food'},
          ],
      'pathology': [],
      'radiology': [],
    };

Map<String, dynamic> pullBody({
  List<Map<String, dynamic>> patients = const [],
  List<Map<String, dynamic>> visits = const [],
  List<Map<String, dynamic>> prescriptions = const [],
  required List<int> scope,
  required List<int> alive,
  String cursor = '2026-11-07 10:00:00',
}) =>
    {
      'cursor': cursor,
      'scope_patient_ids': scope,
      'prescription_ids': alive,
      'appointments': [],
      'data': {
        'patients': patients,
        'visits': visits,
        'admissions': [],
        'diagnoses': [
          {'kind': 'opd', 'case_id': 100, 'codes': [{'id': 5, 'code': 'A90', 'description': 'Dengue fever'}]},
        ],
        'prescriptions': prescriptions,
        'vitals': [],
        'lab_reports': [],
        'timeline': [],
      },
    };

final mastersBody = {
  'medicines': [
    {'id': 1, 'name': 'Paracetamol 650', 'composition': 'Paracetamol', 'company': 'Cipla', 'category_id': 1, 'category': 'Tablet'},
    {'id': 2, 'name': 'Amoxyclav 625', 'composition': 'Amoxicillin + Clavulanic acid', 'company': 'Alkem', 'category_id': 1, 'category': 'Tablet'},
    {'id': 3, 'name': 'Azithral 500', 'composition': 'Azithromycin', 'company': 'Alembic', 'category_id': 1, 'category': 'Tablet'},
  ],
  'dosages': [
    {'id': 11, 'category_id': 1, 'label': '1 Tablet'},
    {'id': 12, 'category_id': 1, 'label': '1/2 Tablet'},
  ],
  'intervals': [{'id': 21, 'name': 'TDS'}, {'id': 22, 'name': 'BD'}],
  'durations': [{'id': 31, 'name': '5 days'}],
  'pathology': [{'id': 41, 'name': 'CBC', 'short_name': 'CBC'}],
  'radiology': [{'id': 51, 'name': 'Chest X-Ray', 'short_name': 'CXR'}],
  'icd': [{'id': 5, 'code': 'A90', 'description': 'Dengue fever'}],
  'templates': [],
  'frequent_medicine_ids': [1],
};
