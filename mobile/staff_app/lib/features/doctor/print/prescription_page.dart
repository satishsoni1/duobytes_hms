import 'dart:io';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../data/models.dart';
import 'print_settings.dart';
import 'print_strings.dart';

/// Page sizes in PDF points (1/72 inch).
Size paperPoints(PaperSize p) => p == PaperSize.a4 ? const Size(595.28, 841.89) : const Size(419.53, 595.28);
const double mm = 72 / 25.4;

/// Everything printed on one prescription.
class PrintJob {
  PrintJob({
    required this.patient,
    required this.rx,
    this.encounter,
    this.diagnoses = const [],
    this.allergies = const [],
    this.nextVisit,
    this.token,
  });

  final Patient patient;
  final Prescription rx;
  final Encounter? encounter;
  final List<IcdCode> diagnoses;
  final List<String> allergies;
  final DateTime? nextVisit;
  final int? token;
}

/// One printed sheet. Laid out in points at the real paper size; the chosen half (or the full page)
/// holds the prescription, scaled down only if it would not fit.
class PrescriptionPage extends StatelessWidget {
  const PrescriptionPage({super.key, required this.job, required this.settings, required this.lang, this.letterhead, this.logo});

  final PrintJob job;
  final PrintSettings settings;
  final String lang;

  /// Hospital letterhead image (HeaderMode.image).
  final File? letterhead;

  /// The hospital's logo (from the server branding), cached on the phone.
  final File? logo;

  @override
  Widget build(BuildContext context) {
    final page = paperPoints(settings.paper);
    final half = settings.area != PrintArea.full;
    final regionH = half ? page.height / 2 : page.height;
    final top = settings.area == PrintArea.bottomHalf ? page.height / 2 : 0.0;
    final scale = settings.paper == PaperSize.a5 || half ? 0.86 : 1.0; // smaller type on smaller areas

    return MediaQuery(
      data: const MediaQueryData(textScaler: TextScaler.noScaling),
      child: Container(
        width: page.width,
        height: page.height,
        color: Colors.white,
        child: Stack(children: [
          Positioned(
            left: 0,
            top: top,
            width: page.width,
            height: regionH,
            child: Padding(
              padding: EdgeInsets.fromLTRB(12 * mm, 8 * mm, 12 * mm, 8 * mm),
              child: LayoutBuilder(
                builder: (context, c) => FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.topLeft,
                  child: SizedBox(
                    width: c.maxWidth,
                    child: ConstrainedBox(
                      constraints: BoxConstraints(minHeight: c.maxHeight),
                      child: DefaultTextStyle(
                        // Based on the app theme so the same font (and Devanagari fallback) is used wherever the page is shown.
                        style: (Theme.of(context).textTheme.bodyMedium ?? const TextStyle()).copyWith(color: Colors.black, fontSize: 10.5 * scale, height: 1.3, decoration: TextDecoration.none),
                        child: _Content(job: job, settings: settings, s: PrintStrings(lang, bilingual: settings.bilingual), lang: lang, letterhead: letterhead, logo: logo, scale: scale),
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
          if (half)
            // Faint cut line between halves.
            Positioned(left: 8 * mm, right: 8 * mm, top: page.height / 2, child: Container(height: 0.5, color: Colors.black12)),
        ]),
      ),
    );
  }
}

class _Content extends StatelessWidget {
  const _Content({required this.job, required this.settings, required this.s, required this.lang, required this.letterhead, required this.logo, required this.scale});
  final PrintJob job;
  final PrintSettings settings;
  final PrintStrings s;
  final String lang;
  final File? letterhead;
  final File? logo;
  final double scale;

  static const _accent = Color(0xFF1A7A7E);

  @override
  Widget build(BuildContext context) {
    final p = job.patient;
    final rx = job.rx;
    final date = DateFormat('d MMM yyyy').format(rx.date ?? DateTime.now());
    final v = job.encounter?.vitals;
    final vitals = <String>[
      if (v?.bp != null) '${s.t('bp')} ${v!.bp}',
      if (v?.pulse != null) '${s.t('pulse')} ${v!.pulse}',
      if (v?.temperature != null) '${s.t('temp')} ${v!.temperature}',
      if (v?.weight != null) '${s.t('weight')} ${v!.weight}',
    ];
    final tests = [...rx.pathology.map((t) => t.name), ...rx.radiology.map((t) => t.name)];
    final h = settings.headerFor(lang);
    final small = TextStyle(fontSize: 8.5 * scale, color: Colors.black87);
    final label = TextStyle(fontSize: 8.5 * scale, color: Colors.black54, fontWeight: FontWeight.w600);
    final sectionStyle = TextStyle(fontSize: 10 * scale, fontWeight: FontWeight.w700, color: _accent);

    Widget section(String title, Widget body) => Padding(
          padding: EdgeInsets.only(top: 7 * scale),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(title, style: sectionStyle), SizedBox(height: 2 * scale), body]),
        );

    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
      // ---------------------------------------------------------------- header
      switch (settings.header) {
        HeaderMode.text => _TextHeader(h: h, s: s, logo: settings.showLogo ? logo : null, scale: scale),
        HeaderMode.image => letterhead != null && letterhead!.existsSync()
            ? Image.file(letterhead!, fit: BoxFit.fitWidth, height: 110 * scale)
            : _TextHeader(h: h, s: s, logo: settings.showLogo ? logo : null, scale: scale),
        HeaderMode.blank => SizedBox(height: (settings.blankTopMm * mm - 8 * mm).clamp(0, 400)),
        HeaderMode.none => const SizedBox.shrink(),
      },
      if (settings.header == HeaderMode.text || settings.header == HeaderMode.image) ...[
        SizedBox(height: 4 * scale),
        Container(height: 1.2, color: _accent),
      ],
      SizedBox(height: 6 * scale),

      // ---------------------------------------------------------------- patient strip
      Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Expanded(
          flex: 5,
          child: Text.rich(TextSpan(children: [
            TextSpan(text: '${s.t('patient')}: ', style: label),
            TextSpan(text: p.name, style: TextStyle(fontWeight: FontWeight.w700, fontSize: 11.5 * scale)),
          ])),
        ),
        Expanded(
          flex: 3,
          child: Text.rich(TextSpan(children: [
            TextSpan(text: '${s.t('age_sex')}: ', style: label),
            TextSpan(text: [p.ageLabel, s.sex(p.gender)].whereType<String>().where((x) => x.isNotEmpty).join(' / ')),
          ])),
        ),
        Expanded(
          flex: 3,
          child: Text.rich(TextSpan(children: [TextSpan(text: '${s.t('date')}: ', style: label), TextSpan(text: date)]), textAlign: TextAlign.right),
        ),
      ]),
      SizedBox(height: 2 * scale),
      Row(children: [
        Expanded(
          child: Text.rich(TextSpan(children: [
            TextSpan(text: '${s.t('patient_id')}: ', style: label),
            TextSpan(text: '${p.id}'),
            if (job.token != null) ...[TextSpan(text: '    ${s.t('token')}: ', style: label), TextSpan(text: '${job.token}')],
            if (p.mobile != null) ...[TextSpan(text: '    ${s.t('phone')}: ', style: label), TextSpan(text: p.mobile)],
          ])),
        ),
        if (rx.serverId != null) Text('Rx #${rx.serverId}', style: small),
      ]),
      if (settings.showVitals && vitals.isNotEmpty) Padding(padding: EdgeInsets.only(top: 2 * scale), child: Text(vitals.join('    '), style: small)),
      if (job.allergies.isNotEmpty)
        Padding(
          padding: EdgeInsets.only(top: 3 * scale),
          child: Text('${s.t('allergies')}: ${job.allergies.join(', ')}', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 9.5 * scale, color: const Color(0xFFB00020))),
        ),
      SizedBox(height: 5 * scale),
      Container(height: 0.6, color: Colors.black26),

      // ---------------------------------------------------------------- clinical
      if (settings.showDiagnosis && job.diagnoses.isNotEmpty) section(s.t('diagnosis'), Text(job.diagnoses.map((c) => '${c.description} (${c.code})').join('; '))),
      if (rx.printFindings && rx.findings != null) section(s.t('findings'), Text(rx.findings!)),
      if (rx.headerNote != null) section(s.t('notes'), Text(rx.headerNote!)),

      // ---------------------------------------------------------------- medicines
      if (rx.items.isNotEmpty)
        Padding(
          padding: EdgeInsets.only(top: 8 * scale),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('Rx', style: TextStyle(fontSize: 18 * scale, fontWeight: FontWeight.w800, fontStyle: FontStyle.italic, color: _accent, height: 1)),
            SizedBox(height: 4 * scale),
            Table(
              columnWidths: const {0: FixedColumnWidth(16), 1: FlexColumnWidth(4.2), 2: FlexColumnWidth(1.6), 3: FlexColumnWidth(2.4), 4: FlexColumnWidth(1.6)},
              border: const TableBorder(horizontalInside: BorderSide(color: Colors.black12, width: 0.5)),
              defaultVerticalAlignment: TableCellVerticalAlignment.top,
              children: [
                TableRow(
                  decoration: const BoxDecoration(color: Color(0xFFEFF6F6)),
                  children: [
                    for (final hd in ['#', s.t('medicine'), s.t('dose'), s.t('when'), s.t('duration')])
                      Padding(padding: EdgeInsets.symmetric(vertical: 3 * scale, horizontal: 2), child: Text(hd, style: label)),
                  ],
                ),
                for (final (i, it) in rx.items.indexed)
                  TableRow(children: [
                    Padding(padding: EdgeInsets.symmetric(vertical: 3 * scale, horizontal: 2), child: Text('${i + 1}.')),
                    Padding(
                      padding: EdgeInsets.symmetric(vertical: 3 * scale, horizontal: 2),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(it.medicineName, style: const TextStyle(fontWeight: FontWeight.w700)),
                        if (it.composition != null) Text(it.composition!, style: small),
                        if (it.instruction.isNotEmpty) Text(s.instruction(it.instruction), style: small.copyWith(fontStyle: FontStyle.italic)),
                      ]),
                    ),
                    Padding(padding: EdgeInsets.symmetric(vertical: 3 * scale, horizontal: 2), child: Text(it.dosageLabel ?? '')),
                    Padding(padding: EdgeInsets.symmetric(vertical: 3 * scale, horizontal: 2), child: Text(s.dosing(it.intervalName))),
                    Padding(padding: EdgeInsets.symmetric(vertical: 3 * scale, horizontal: 2), child: Text(s.duration(it.durationName))),
                  ]),
              ],
            ),
          ]),
        ),

      if (tests.isNotEmpty) section(s.t('tests'), Text(tests.join(', '))),
      if (rx.footerNote != null) section(s.t('advice'), Text(rx.footerNote!)),
      if (job.nextVisit != null) section(s.t('next_visit'), Text(DateFormat('EEE, d MMM yyyy').format(job.nextVisit!), style: const TextStyle(fontWeight: FontWeight.w700))),

      // ---------------------------------------------------------------- signature
      SizedBox(height: 18 * scale),
      Align(
        alignment: Alignment.centerRight,
        child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Container(width: 140 * scale, height: 0.6, color: Colors.black54),
          SizedBox(height: 2 * scale),
          Text(h.doctor.isNotEmpty ? h.doctor : (rx.prescribedBy != null ? 'Dr. ${rx.prescribedBy}' : ''), style: const TextStyle(fontWeight: FontWeight.w700)),
          if (h.regNo.isNotEmpty) Text('${s.t('reg_no')} ${h.regNo}', style: small),
          Text(s.t('signature'), style: small),
        ]),
      ),
      if (settings.footer.trim().isNotEmpty) ...[
        SizedBox(height: 6 * scale),
        Text(settings.footer, style: small, textAlign: TextAlign.center),
      ],
    ]);
  }
}

class _TextHeader extends StatelessWidget {
  const _TextHeader({required this.h, required this.s, required this.logo, required this.scale});
  final HeaderText h;
  final PrintStrings s;
  final File? logo;
  final double scale;

  @override
  Widget build(BuildContext context) {
    final small = TextStyle(fontSize: 8.5 * scale, color: Colors.black87);
    return Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      if (logo != null && logo!.existsSync()) ...[Image.file(logo!, width: 46 * scale, height: 46 * scale, fit: BoxFit.contain), SizedBox(width: 8 * scale)],
      Expanded(
        flex: 6,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(h.hospital, style: TextStyle(fontSize: 15 * scale, fontWeight: FontWeight.w800, color: _Content._accent, height: 1.15)),
          if (h.address.isNotEmpty) Text(h.address, style: small),
          if (h.phone.isNotEmpty) Text('${s.t('phone')}: ${h.phone}', style: small),
        ]),
      ),
      SizedBox(width: 8 * scale),
      Expanded(
        flex: 4,
        child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          if (h.doctor.isNotEmpty) Text(h.doctor, textAlign: TextAlign.right, style: TextStyle(fontSize: 11.5 * scale, fontWeight: FontWeight.w700)),
          if (h.qualification.isNotEmpty) Text(h.qualification, textAlign: TextAlign.right, style: small),
          if (h.regNo.isNotEmpty) Text('${s.t('reg_no')} ${h.regNo}', textAlign: TextAlign.right, style: small),
          if (h.timings.isNotEmpty) Text(h.timings, textAlign: TextAlign.right, style: small),
        ]),
      ),
    ]);
  }
}
