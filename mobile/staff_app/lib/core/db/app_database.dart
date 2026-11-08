import 'dart:convert';

import 'package:sqflite_common/sqlite_api.dart';

/// Local offline store.
///
/// Server records are kept as JSON in `json` columns, with the few fields we filter or sort on
/// pulled out into indexed columns. New server fields therefore reach the app without a migration.
///
/// Locally written prescriptions live in `prescriptions` with status pending/conflict/rejected until
/// the server accepts them; `outbox` holds the queue of changes to push (see SyncEngine).
class AppDatabase {
  AppDatabase(this.db);

  final Database db;

  static const version = 3;

  static Future<void> onCreate(Database db, int _) async {
    final b = db.batch();
    b.execute('CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT)');

    b.execute('''CREATE TABLE patients (
      id INTEGER PRIMARY KEY, name TEXT NOT NULL, search TEXT NOT NULL, pinned INTEGER NOT NULL DEFAULT 0,
      updated_at TEXT, json TEXT NOT NULL)''');
    b.execute('CREATE INDEX patients_name ON patients(name)');

    b.execute('''CREATE TABLE visits (
      id INTEGER PRIMARY KEY, patient_id INTEGER NOT NULL, opd_id INTEGER, doctor_id INTEGER,
      appointment_date TEXT, json TEXT NOT NULL)''');
    b.execute('CREATE INDEX visits_patient ON visits(patient_id)');
    b.execute('CREATE INDEX visits_date ON visits(appointment_date)');

    b.execute('''CREATE TABLE admissions (
      id INTEGER PRIMARY KEY, patient_id INTEGER NOT NULL, doctor_id INTEGER, discharged TEXT, date TEXT,
      json TEXT NOT NULL)''');
    b.execute('CREATE INDEX admissions_patient ON admissions(patient_id)');

    b.execute('''CREATE TABLE diagnoses (
      kind TEXT NOT NULL, case_id INTEGER NOT NULL, json TEXT NOT NULL, PRIMARY KEY (kind, case_id))''');

    b.execute('''CREATE TABLE prescriptions (
      local_id TEXT PRIMARY KEY, server_id INTEGER UNIQUE, patient_id INTEGER NOT NULL,
      kind TEXT NOT NULL, case_ref_id INTEGER NOT NULL, date TEXT,
      status TEXT NOT NULL, version TEXT, local_rev INTEGER NOT NULL DEFAULT 0, force_save INTEGER NOT NULL DEFAULT 0,
      error TEXT, server_json TEXT, json TEXT NOT NULL, updated_local_at TEXT)''');
    b.execute('CREATE INDEX prescriptions_patient ON prescriptions(patient_id)');
    b.execute('CREATE INDEX prescriptions_case ON prescriptions(kind, case_ref_id)');

    b.execute('''CREATE TABLE vitals (
      id INTEGER PRIMARY KEY, patient_id INTEGER NOT NULL, measured_at TEXT, json TEXT NOT NULL)''');
    b.execute('CREATE INDEX vitals_patient ON vitals(patient_id)');

    b.execute('''CREATE TABLE lab_reports (
      kind TEXT NOT NULL, id INTEGER NOT NULL, patient_id INTEGER NOT NULL, reporting_date TEXT, json TEXT NOT NULL,
      PRIMARY KEY (kind, id))''');
    b.execute('CREATE INDEX lab_reports_patient ON lab_reports(patient_id)');

    b.execute('''CREATE TABLE timeline (
      id INTEGER PRIMARY KEY, patient_id INTEGER NOT NULL, timeline_date TEXT, json TEXT NOT NULL)''');
    b.execute('CREATE INDEX timeline_patient ON timeline(patient_id)');

    b.execute('''CREATE TABLE appointments (
      id INTEGER PRIMARY KEY, patient_id INTEGER NOT NULL, date TEXT, json TEXT NOT NULL)''');
    b.execute('CREATE INDEX appointments_date ON appointments(date)');

    // Masters
    b.execute('''CREATE TABLE medicines (
      id INTEGER PRIMARY KEY, name TEXT NOT NULL, composition TEXT, company TEXT, category_id INTEGER, category TEXT,
      search TEXT NOT NULL)''');
    b.execute('CREATE INDEX medicines_name ON medicines(name)');
    b.execute('CREATE TABLE icd (id INTEGER PRIMARY KEY, code TEXT NOT NULL, description TEXT NOT NULL, search TEXT NOT NULL)');
    b.execute('CREATE TABLE lookups (key TEXT PRIMARY KEY, json TEXT NOT NULL)');
    b.execute('''CREATE TABLE templates (
      local_id TEXT PRIMARY KEY, server_id INTEGER UNIQUE, name TEXT NOT NULL, shared INTEGER NOT NULL DEFAULT 0,
      mine INTEGER NOT NULL DEFAULT 1, status TEXT NOT NULL, json TEXT NOT NULL)''');

    b.execute('''CREATE TABLE outbox (
      seq INTEGER PRIMARY KEY AUTOINCREMENT, op_id TEXT NOT NULL UNIQUE, type TEXT NOT NULL,
      ref_local_id TEXT, payload TEXT, sent_rev INTEGER, attempts INTEGER NOT NULL DEFAULT 0,
      last_error TEXT, created_at TEXT NOT NULL)''');
    await b.commit(noResult: true);
    await _v2(db);
    await _v3(db);
  }

  static Future<void> onUpgrade(Database db, int from, int to) async {
    if (from < 2) await _v2(db);
    if (from < 3) await _v3(db);
  }

  /// v3: OPD bill per patient (charges, payments, services waiting to upload).
  static Future<void> _v3(Database db) async {
    await db.execute('CREATE TABLE IF NOT EXISTS patient_billing (patient_id INTEGER PRIMARY KEY, json TEXT NOT NULL)');
  }

  /// v2: specialty clinical records (follow-ups, vaccinations, growth, pregnancy, obstetric history).
  static Future<void> _v2(Database db) async {
    await db.execute('''CREATE TABLE IF NOT EXISTS clinical_records (
      uuid TEXT PRIMARY KEY, patient_id INTEGER NOT NULL, type TEXT NOT NULL, event_date TEXT, status TEXT NOT NULL,
      deleted INTEGER NOT NULL DEFAULT 0, read_only INTEGER NOT NULL DEFAULT 0, pending INTEGER NOT NULL DEFAULT 0,
      local_rev INTEGER NOT NULL DEFAULT 0, error TEXT, updated_at TEXT, updated_by_name TEXT, json TEXT NOT NULL)''');
    await db.execute('CREATE INDEX IF NOT EXISTS clinical_records_patient ON clinical_records(patient_id, type)');
    await db.execute('CREATE INDEX IF NOT EXISTS clinical_records_due ON clinical_records(type, status, event_date)');
  }

  // ------------------------------------------------------------------ meta
  Future<String?> getMeta(String key) async {
    final r = await db.query('meta', where: 'key = ?', whereArgs: [key]);
    return r.isEmpty ? null : r.first['value'] as String?;
  }

  Future<void> setMeta(String key, String? value, [DatabaseExecutor? ex]) =>
      (ex ?? db).insert('meta', {'key': key, 'value': value}, conflictAlgorithm: ConflictAlgorithm.replace);

  Future<Map<String, dynamic>?> lookup(String key) async {
    final r = await db.query('lookups', where: 'key = ?', whereArgs: [key]);
    return r.isEmpty ? null : jsonDecode(r.first['json'] as String) as Map<String, dynamic>;
  }

  /// Remove all patient data (sign-out or a different user signing in on this device).
  Future<void> wipe() async {
    await db.transaction((tx) async {
      for (final t in const [
        'meta', 'patients', 'visits', 'admissions', 'diagnoses', 'prescriptions', 'vitals', 'lab_reports',
        'timeline', 'appointments', 'medicines', 'icd', 'lookups', 'templates', 'outbox', 'clinical_records', 'patient_billing'
      ]) {
        await tx.delete(t);
      }
    });
  }
}

/// Lower-cased text used for local LIKE searches.
String searchKey(Iterable<Object?> parts) =>
    parts.where((p) => p != null && '$p'.isNotEmpty).map((p) => '$p'.toLowerCase()).join(' ');
