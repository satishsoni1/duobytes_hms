import 'package:flutter/material.dart';

class AppConfig {
  static const appName = 'DuoBytes HMS';

  /// Pre-filled on the sign-in screen; can be changed there (e.g. for a test server).
  static const defaultServerUrl = 'https://hrms.duobytes.in';

  static const brandColor = Color(0xFF1A7A7E);

  /// While the app is open and online, sync at least this often.
  static const periodicSync = Duration(minutes: 5);

  /// After a local save, wait briefly so several quick edits go up in one push.
  static const pushDebounce = Duration(seconds: 2);

  static const connectTimeout = Duration(seconds: 15);
  static const receiveTimeout = Duration(seconds: 90); // first full pull can be large
}
