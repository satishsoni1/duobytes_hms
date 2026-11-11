import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../features/doctor/data/models.dart';
import '../features/doctor/print/print_strings.dart';
import 'ui/widgets.dart';

/// WhatsApp messages to patients, opened in the doctor's own WhatsApp (no business API or internet
/// service of ours involved). The doctor reviews and presses send.

/// "098765 43210" / "+91-98765-43210" -> "919876543210". Null when it is not a usable mobile number.
String? whatsappNumber(String? raw, {String defaultCountry = '91'}) {
  if (raw == null) return null;
  var d = raw.replaceAll(RegExp(r'[^0-9+]'), '');
  if (d.startsWith('+')) {
    d = d.substring(1);
  } else if (d.startsWith('00')) {
    d = d.substring(2);
  } else {
    d = d.replaceFirst(RegExp(r'^0+'), '');
    if (d.length == 10) d = '$defaultCountry$d';
  }
  d = d.replaceAll('+', '');
  return RegExp(r'^[1-9][0-9]{9,14}$').hasMatch(d) ? d : null;
}

Uri whatsappUri(String number, String text) => Uri.parse('https://wa.me/$number?text=${Uri.encodeComponent(text)}');

/// Open a chat with [phone] and [text] filled in. Falls back to copying the text.
Future<bool> sendWhatsApp(BuildContext context, {required String? phone, required String text}) async {
  final n = whatsappNumber(phone);
  if (n == null) {
    await Clipboard.setData(ClipboardData(text: text));
    if (context.mounted) showSnack(context, 'No valid mobile number for this patient. The message is copied; paste it in WhatsApp.', icon: Icons.content_copy_rounded);
    return false;
  }
  try {
    if (await launchUrl(whatsappUri(n, text), mode: LaunchMode.externalApplication)) return true;
  } catch (_) {
    // fall through
  }
  await Clipboard.setData(ClipboardData(text: text));
  if (context.mounted) showSnack(context, 'Could not open WhatsApp. The message is copied.', icon: Icons.content_copy_rounded);
  return false;
}

// ------------------------------------------------------------------ message texts
const _reminder = {
  'en': 'Namaste {name},\nThis is a reminder of your follow-up visit with {doctor} at {hospital} on {date}{reason}.\nPlease reply to confirm, or call us to change the date.',
  'hi': 'नमस्ते {name},\n{hospital} में {doctor} के साथ आपकी फॉलो-अप मुलाक़ात {date} को है{reason}।\nकृपया जवाब देकर पुष्टि करें, या तारीख बदलने के लिए हमें फ़ोन करें।',
  'mr': 'नमस्कार {name},\n{hospital} येथे {doctor} यांच्याकडे तुमची फॉलो-अप भेट {date} रोजी आहे{reason}.\nकृपया उत्तर देऊन खात्री करा, किंवा तारीख बदलण्यासाठी आम्हाला फोन करा.',
};
const _reasonFmt = {'en': ' ({r})', 'hi': ' ({r})', 'mr': ' ({r})'};
const _rxIntro = {
  'en': 'Namaste {name}, your prescription from {doctor} ({hospital}), {date}:',
  'hi': 'नमस्ते {name}, {doctor} ({hospital}) का आपका पर्चा, {date}:',
  'mr': 'नमस्कार {name}, {doctor} ({hospital}) यांचे तुमचे प्रिस्क्रिप्शन, {date}:',
};
const _rxOutro = {
  'en': 'Take medicines as advised. Contact us if you feel worse.',
  'hi': 'दवाइयाँ सलाह अनुसार लें। तबीयत बिगड़े तो हमसे संपर्क करें।',
  'mr': 'औषधे सल्ल्याप्रमाणे घ्या. त्रास वाढल्यास आमच्याशी संपर्क करा.',
};

String _fill(String tpl, Map<String, String> v) => tpl.replaceAllMapped(RegExp(r'\{(\w+)\}'), (m) => v[m[1]] ?? '');

String followUpReminderText({required String lang, required String patientName, required String doctor, required String hospital, required String date, String? reason}) {
  final r = reason == null || reason.trim().isEmpty || reason.trim().toLowerCase() == 'review' ? '' : _fill(_reasonFmt[lang] ?? _reasonFmt['en']!, {'r': reason.trim()});
  return _fill(_reminder[lang] ?? _reminder['en']!, {'name': patientName, 'doctor': doctor, 'hospital': hospital, 'date': date, 'reason': r});
}

/// Plain-text prescription for WhatsApp (medicine names stay as written; dosing in the chosen language).
String prescriptionText({required String lang, required Patient patient, required Prescription rx, required String doctor, required String hospital, required String date, String? nextVisit}) {
  final s = PrintStrings(lang);
  final b = StringBuffer(_fill(_rxIntro[lang] ?? _rxIntro['en']!, {'name': patient.name, 'doctor': doctor, 'hospital': hospital, 'date': date}))..writeln();
  for (final (i, it) in rx.items.indexed) {
    final parts = [
      if ((it.dosageLabel ?? '').isNotEmpty) it.dosageLabel!,
      if ((it.intervalName ?? '').isNotEmpty) s.dosing(it.intervalName),
      if ((it.durationName ?? '').isNotEmpty) s.duration(it.durationName),
      if (it.instruction.trim().isNotEmpty) s.instruction(it.instruction.trim()),
    ];
    b.writeln('${i + 1}. ${it.medicineName}${parts.isEmpty ? '' : ' - ${parts.join(', ')}'}');
  }
  final tests = [...rx.pathology, ...rx.radiology].map((t) => t.name).where((n) => n.isNotEmpty).toList();
  if (tests.isNotEmpty) b.writeln('${s.t('tests')}: ${tests.join(', ')}');
  if (rx.footerNote != null) b.writeln('${s.t('advice')}: ${rx.footerNote}');
  if (nextVisit != null) b.writeln('${s.t('next_visit')}: $nextVisit');
  b.write(_rxOutro[lang] ?? _rxOutro['en']!);
  return b.toString();
}

/// Ask which language to send in (remembers nothing; the print default is pre-selected).
Future<String?> pickMessageLanguage(BuildContext context, {String initial = 'en'}) => showModalBottomSheet<String>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const ListTile(title: Text('Send message in')),
          for (final e in printLanguages.entries)
            ListTile(
              leading: Icon(e.key == initial ? Icons.radio_button_checked_rounded : Icons.radio_button_off_rounded),
              title: Text(e.value),
              onTap: () => Navigator.pop(ctx, e.key),
            ),
        ]),
      ),
    );
