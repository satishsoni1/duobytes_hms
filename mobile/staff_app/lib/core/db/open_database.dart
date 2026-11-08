import 'package:path/path.dart' as p;
import 'package:sqflite_sqlcipher/sqflite.dart' as cipher;

import 'app_database.dart';

const _fileName = 'staff_app.db';

/// Opens the on-device database, encrypted with SQLCipher using a key from the platform keystore.
Future<AppDatabase> openAppDatabase(String key) async {
  final dir = await cipher.getDatabasesPath();
  final db = await cipher.openDatabase(
    p.join(dir, _fileName),
    password: key,
    version: AppDatabase.version,
    onCreate: AppDatabase.onCreate,
    onUpgrade: AppDatabase.onUpgrade,
  );
  return AppDatabase(db);
}

/// Removes the database file (used if the key was lost, e.g. after an OS keystore reset).
Future<void> deleteAppDatabase() async {
  final dir = await cipher.getDatabasesPath();
  await cipher.deleteDatabase(p.join(dir, _fileName));
}
