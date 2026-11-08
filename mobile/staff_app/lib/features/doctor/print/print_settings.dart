import 'dart:convert';

import 'package:uuid/uuid.dart';

import '../../../core/auth/staff.dart';
import '../../../core/db/app_database.dart';

enum PaperSize { a4, a5 }

/// Which part of the sheet to print on. Halves let one A4 hold two prescriptions, or use the
/// unused half of a page already printed on.
enum PrintArea { full, topHalf, bottomHalf }

/// What goes at the top of the prescription.
enum HeaderMode {
  /// Hospital and doctor details as text, in the chosen language.
  text,

  /// The hospital's letterhead image from the web print settings.
  image,

  /// Pre-printed letterhead paper: leave blank space at the top.
  blank,

  /// No header at all (e.g. printing on the back of a page).
  none,
}

/// Hospital/doctor header lines for one language. Empty fields fall back to English.
class HeaderText {
  HeaderText({this.hospital = '', this.address = '', this.phone = '', this.doctor = '', this.qualification = '', this.regNo = '', this.timings = ''});
  String hospital, address, phone, doctor, qualification, regNo, timings;

  factory HeaderText.fromJson(Map<String, dynamic> j) => HeaderText(
        hospital: '${j['hospital'] ?? ''}',
        address: '${j['address'] ?? ''}',
        phone: '${j['phone'] ?? ''}',
        doctor: '${j['doctor'] ?? ''}',
        qualification: '${j['qualification'] ?? ''}',
        regNo: '${j['reg_no'] ?? ''}',
        timings: '${j['timings'] ?? ''}',
      );

  Map<String, dynamic> toJson() =>
      {'hospital': hospital, 'address': address, 'phone': phone, 'doctor': doctor, 'qualification': qualification, 'reg_no': regNo, 'timings': timings};

  /// This language's text with gaps filled from [fallback].
  HeaderText merged(HeaderText fallback) {
    String f(String a, String b) => a.trim().isEmpty ? b : a;
    return HeaderText(
      hospital: f(hospital, fallback.hospital),
      address: f(address, fallback.address),
      phone: f(phone, fallback.phone),
      doctor: f(doctor, fallback.doctor),
      qualification: f(qualification, fallback.qualification),
      regNo: f(regNo, fallback.regNo),
      timings: f(timings, fallback.timings),
    );
  }
}

class PrintSettings {
  PrintSettings({
    this.paper = PaperSize.a4,
    this.area = PrintArea.full,
    this.header = HeaderMode.text,
    this.blankTopMm = 45,
    this.language = 'en',
    List<String>? languages,
    this.bilingual = false,
    this.showLogo = true,
    this.showVitals = true,
    this.showDiagnosis = true,
    this.footer = '',
    Map<String, HeaderText>? headers,
  })  : languages = languages ?? ['en', 'hi', 'mr'],
        headers = headers ?? {};

  PaperSize paper;
  PrintArea area;
  HeaderMode header;

  /// Space left blank for pre-printed letterhead (mm).
  double blankTopMm;

  /// Default print language.
  String language;

  /// Languages offered on the print screen.
  List<String> languages;
  bool bilingual;
  bool showLogo;
  bool showVitals;
  bool showDiagnosis;

  /// Free text printed at the bottom (e.g. "Emergency: 0724-xxxxxxx").
  String footer;
  Map<String, HeaderText> headers;

  HeaderText headerFor(String lang) => (headers[lang] ?? HeaderText()).merged(headers['en'] ?? HeaderText());

  factory PrintSettings.fromJson(Map<String, dynamic> j) {
    T pick<T extends Enum>(List<T> values, Object? name, T fallback) => values.firstWhere((v) => v.name == name, orElse: () => fallback);
    return PrintSettings(
      paper: pick(PaperSize.values, j['paper'], PaperSize.a4),
      area: pick(PrintArea.values, j['area'], PrintArea.full),
      header: pick(HeaderMode.values, j['header'], HeaderMode.text),
      blankTopMm: (j['blank_top_mm'] as num?)?.toDouble() ?? 45,
      language: (j['language'] as String?) ?? 'en',
      languages: ((j['languages'] as List?) ?? const ['en', 'hi', 'mr']).map((e) => '$e').toList(),
      bilingual: j['bilingual'] == true,
      showLogo: j['show_logo'] != false,
      showVitals: j['show_vitals'] != false,
      showDiagnosis: j['show_diagnosis'] != false,
      footer: '${j['footer'] ?? ''}',
      headers: {for (final e in ((j['headers'] as Map?) ?? const {}).entries) '${e.key}': HeaderText.fromJson(Map<String, dynamic>.from(e.value as Map))},
    );
  }

  Map<String, dynamic> toJson() => {
        'paper': paper.name,
        'area': area.name,
        'header': header.name,
        'blank_top_mm': blankTopMm,
        'language': language,
        'languages': languages,
        'bilingual': bilingual,
        'show_logo': showLogo,
        'show_vitals': showVitals,
        'show_diagnosis': showDiagnosis,
        'footer': footer,
        'headers': {for (final e in headers.entries) e.key: e.value.toJson()},
      };

  PrintSettings copy() => PrintSettings.fromJson(jsonDecode(jsonEncode(toJson())) as Map<String, dynamic>);

  /// English header pre-filled from the hospital and doctor profile.
  static PrintSettings defaults(Staff staff, Hospital hospital) => PrintSettings(headers: {
        'en': HeaderText(
          hospital: hospital.name,
          address: hospital.address ?? '',
          phone: hospital.phone ?? '',
          doctor: staff.displayName,
          qualification: [staff.qualification, staff.specialization].whereType<String>().where((s) => s.trim().isNotEmpty).join(', '),
        ),
      });
}

/// Loads and saves the doctor's print settings. Saved on the phone and synced to the server
/// (op `settings.save`, key `print`) so they follow the doctor to another phone.
class PrintSettingsRepository {
  PrintSettingsRepository(this.adb);
  final AppDatabase adb;
  static const _metaKey = 'print_settings';

  Future<PrintSettings> load(Staff staff, Hospital hospital) async {
    final pendingUpload = (await adb.db.query('outbox', columns: ['seq'], where: "type = 'settings.save'", limit: 1)).isNotEmpty;
    final local = await adb.getMeta(_metaKey);
    if (!pendingUpload) {
      final server = await adb.lookup('server_settings');
      final s = (server?['staff_settings'] as Map?)?['print'];
      if (s is Map) return PrintSettings.fromJson(Map<String, dynamic>.from(s));
    }
    if (local != null) return PrintSettings.fromJson(jsonDecode(local) as Map<String, dynamic>);
    return PrintSettings.defaults(staff, hospital);
  }

  Future<void> save(PrintSettings s) async {
    final json = s.toJson();
    await adb.db.transaction((tx) async {
      await adb.setMeta(_metaKey, jsonEncode(json), tx);
      await tx.delete('outbox', where: "type = 'settings.save' AND attempts = 0");
      await tx.insert('outbox', {
        'op_id': const Uuid().v4(),
        'type': 'settings.save',
        'ref_local_id': 'print',
        'payload': jsonEncode({'key': 'print', 'value': json}),
        'attempts': 0,
        'created_at': DateTime.now().toIso8601String(),
      });
    });
  }

  Future<bool> hasLetterheadImage() async => (await adb.lookup('server_settings'))?['has_print_header'] == true;
}
