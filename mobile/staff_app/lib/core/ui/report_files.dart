import 'dart:io';

import 'package:flutter/material.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

import '../api/api_client.dart';
import 'widgets.dart';

/// Report documents (PDF/images) are downloaded with the user's token into the app's private cache,
/// opened in the phone's viewer, and kept for offline viewing until sign-out.
class ReportFiles {
  static Future<Directory> _dir() async {
    final d = Directory(p.join((await getApplicationCacheDirectory()).path, 'reports'));
    if (!d.existsSync()) d.createSync(recursive: true);
    return d;
  }

  static String _safe(String s) => s.replaceAll(RegExp(r'[^A-Za-z0-9._-]'), '_');

  /// [endpoint] is `patients/report_file` (doctor) or `patient/report_file` (patient).
  static Future<void> open(BuildContext context, ApiClient api, String endpoint, {required String kind, required int id, String? name}) async {
    final messenger = ScaffoldMessenger.of(context);
    try {
      final dir = await _dir();
      final ext = p.extension(name ?? '').isEmpty ? '' : p.extension(name!);
      final file = File(p.join(dir.path, _safe('$kind-$id$ext')));
      if (!file.existsSync() || file.lengthSync() == 0) {
        messenger.showSnackBar(const SnackBar(content: Text('Downloading report…'), duration: Duration(seconds: 30)));
        await api.download(endpoint, file.path, query: {'kind': kind, 'id': id});
        messenger.hideCurrentSnackBar();
      }
      final res = await OpenFilex.open(file.path);
      if (res.type != ResultType.done && context.mounted) {
        showSnack(context, res.type == ResultType.noAppToOpen ? 'No app on this phone can open this file type.' : 'Could not open the report: ${res.message}', error: true);
      }
    } on ApiException catch (e) {
      messenger.hideCurrentSnackBar();
      if (context.mounted) showSnack(context, e.isOffline ? 'You are offline. Report files need internet the first time.' : e.message, error: true);
    }
  }

  /// Remove all downloaded report files (sign-out).
  static Future<void> clear() async {
    try {
      final d = await _dir();
      if (d.existsSync()) d.deleteSync(recursive: true);
    } catch (_) {}
  }
}
