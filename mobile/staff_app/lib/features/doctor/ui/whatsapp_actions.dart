import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/providers.dart';
import '../../../core/ui/widgets.dart';
import '../../../core/whatsapp.dart';
import '../data/clinical.dart';
import '../data/models.dart';
import '../print/print_screen.dart';
import '../specialty/specialty.dart';

Future<String?> _language(BuildContext context, WidgetRef ref) async {
  final initial = (await ref.read(printSettingsProvider.future)).language;
  if (!context.mounted) return null;
  return pickMessageLanguage(context, initial: initial);
}

/// WhatsApp reminder for a follow-up; records when it was sent (synced to other devices).
Future<void> sendFollowUpReminder(BuildContext context, WidgetRef ref, ClinicalRecord followUp, Patient? patient) async {
  final lang = await _language(context, ref);
  if (lang == null || !context.mounted) return;
  final session = ref.read(sessionProvider);
  final text = followUpReminderText(
    lang: lang,
    patientName: patient?.name ?? '',
    doctor: session.staff.displayName,
    hospital: session.hospital.name,
    date: fmtDate(followUp.eventDate),
    reason: followUp.str('reason'),
  );
  if (await sendWhatsApp(context, phone: patient?.mobile, text: text)) {
    followUp.data['reminded_on'] = ymd(DateTime.now());
    await saveClinicalRecord(ref, followUp);
  }
}

/// The prescription as a WhatsApp text message (the PDF goes through Print, Share PDF).
Future<void> sendPrescriptionWhatsApp(BuildContext context, WidgetRef ref, Prescription rx, Patient patient, {DateTime? nextVisit}) async {
  final lang = await _language(context, ref);
  if (lang == null || !context.mounted) return;
  final session = ref.read(sessionProvider);
  await sendWhatsApp(
    context,
    phone: patient.mobile,
    text: prescriptionText(
      lang: lang,
      patient: patient,
      rx: rx,
      doctor: rx.prescribedBy ?? session.staff.displayName,
      hospital: session.hospital.name,
      date: fmtDate(rx.date ?? DateTime.now()),
      nextVisit: nextVisit == null ? null : fmtDate(nextVisit),
    ),
  );
}

class WhatsAppIcon extends StatelessWidget {
  const WhatsAppIcon({super.key, this.size = 22});
  final double size;
  @override
  Widget build(BuildContext context) => Icon(Icons.chat_rounded, size: size, color: const Color(0xFF1FA855));
}
