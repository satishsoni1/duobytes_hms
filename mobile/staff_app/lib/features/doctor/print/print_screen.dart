import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';

import '../../../core/api/api_client.dart';
import '../../../core/providers.dart';
import '../../../core/ui/theme.dart';
import '../../../core/ui/widgets.dart';
import '../data/clinical.dart';
import '../data/models.dart';
import '../specialty/specialty.dart';
import '../ui/doctor_providers.dart';
import 'prescription_page.dart';
import 'print_settings.dart';
import 'print_strings.dart';

final printSettingsRepoProvider = Provider((ref) => PrintSettingsRepository(ref.watch(databaseProvider)));

final printSettingsProvider = FutureProvider.autoDispose<PrintSettings>((ref) {
  ref.watch(dataRevisionProvider);
  final session = ref.watch(sessionProvider);
  return ref.watch(printSettingsRepoProvider).load(session.staff, session.hospital);
});

/// Build what to print for a stored prescription.
Future<PrintJob?> buildPrintJob(WidgetRef ref, String localId) async {
  final repo = ref.read(doctorRepoProvider);
  final rx = await repo.prescription(localId);
  if (rx == null) return null;
  final patient = await repo.patient(rx.patientId);
  if (patient == null) return null;
  final enc = await repo.encounter(rx.kind, rx.caseRefId);
  final dx = enc == null ? <IcdCode>[] : (await repo.diagnoses([enc]))[enc.key] ?? <IcdCode>[];
  final records = await ref.read(clinicalRepoProvider).forPatient(rx.patientId, type: RecordType.followup);
  final next = records.where((f) => f.status == 'active' && f.eventDate != null && (enc == null || f.data['visit_ref_id'] == enc.refId)).toList()
    ..sort((a, b) => a.eventDate!.compareTo(b.eventDate!));
  return PrintJob(
    patient: patient,
    rx: rx,
    encounter: enc,
    diagnoses: {...dx, ...rx.newDiagnoses}.toList(),
    allergies: splitAllergies([patient.allergies.join(','), enc?.allergies.join(',')]),
    nextVisit: next.firstOrNull?.eventDate,
    token: (enc?.j['token'] as num?)?.toInt(),
  );
}

Future<void> openPrintScreen(BuildContext context, WidgetRef ref, String localId) async {
  final job = await buildPrintJob(ref, localId);
  if (!context.mounted) return;
  if (job == null) {
    showSnack(context, 'Prescription not found on this phone.', error: true);
    return;
  }
  await Navigator.push(context, MaterialPageRoute(builder: (_) => PrintScreen(job: job)));
}

/// The hospital's logo from the server branding, cached so printing works offline.
Future<File?> hospitalLogoFile(ApiClient api, String? url) async {
  final f = File(p.join((await getApplicationCacheDirectory()).path, 'hospital_logo'));
  if (url == null || url.isEmpty) return f.existsSync() ? f : null;
  if (f.existsSync() && f.lengthSync() > 0 && DateTime.now().difference(f.lastModifiedSync()).inDays < 7) return f;
  try {
    await api.download(url, f.path);
    return f;
  } on ApiException {
    return f.existsSync() ? f : null;
  }
}

/// The hospital letterhead image, cached after the first download.
Future<File?> letterheadFile(ApiClient api, {bool refresh = false}) async {
  final f = File(p.join((await getApplicationCacheDirectory()).path, 'print_header_image'));
  if (f.existsSync() && f.lengthSync() > 0 && !refresh) return f;
  try {
    await api.download('sync/print_header', f.path);
    return f;
  } on ApiException {
    return f.existsSync() ? f : null;
  }
}

class PrintScreen extends ConsumerStatefulWidget {
  const PrintScreen({super.key, required this.job});
  final PrintJob job;

  @override
  ConsumerState<PrintScreen> createState() => _PrintScreenState();
}

class _PrintScreenState extends ConsumerState<PrintScreen> {
  final _pageKey = GlobalKey();
  PrintSettings? _s;
  String? _lang;
  File? _letterhead;
  File? _logo;
  bool _hasLetterhead = false;
  bool _busy = false;
  bool? _canPrint;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final s = await ref.read(printSettingsProvider.future);
    final has = await ref.read(printSettingsRepoProvider).hasLetterheadImage();
    final info = await Printing.info().catchError((_) => const PrintingInfo());
    if (!mounted) return;
    setState(() {
      _s = s.copy();
      _lang = s.languages.contains(s.language) ? s.language : 'en';
      _hasLetterhead = has;
      _canPrint = info.canPrint;
    });
    final logo = await hospitalLogoFile(ref.read(apiProvider), ref.read(sessionProvider).hospital.logoUrl);
    if (mounted) setState(() => _logo = logo);
    if (has) {
      final f = await letterheadFile(ref.read(apiProvider));
      if (mounted) setState(() => _letterhead = f);
    }
  }

  /// Render the preview page at print resolution and wrap it in a PDF of the chosen paper size.
  Future<Uint8List> _pdf() async {
    final boundary = _pageKey.currentContext!.findRenderObject()! as RenderRepaintBoundary;
    final image = await boundary.toImage(pixelRatio: 3.5); // ~250 dpi
    final png = (await image.toByteData(format: ui.ImageByteFormat.png))!.buffer.asUint8List();
    final format = _s!.paper == PaperSize.a4 ? PdfPageFormat.a4 : PdfPageFormat.a5;
    final doc = pw.Document(title: 'Prescription ${widget.job.patient.name}', author: widget.job.rx.prescribedBy);
    doc.addPage(pw.Page(pageFormat: format, margin: pw.EdgeInsets.zero, build: (_) => pw.Image(pw.MemoryImage(png), fit: pw.BoxFit.fill)));
    return doc.save();
  }

  String get _fileName => 'Rx_${widget.job.patient.name.replaceAll(RegExp(r'[^A-Za-z0-9]+'), '_')}_${ymd(widget.job.rx.date ?? DateTime.now())}.pdf';

  Future<void> _print() async {
    setState(() => _busy = true);
    try {
      final bytes = await _pdf();
      final format = _s!.paper == PaperSize.a4 ? PdfPageFormat.a4 : PdfPageFormat.a5;
      await Printing.layoutPdf(name: _fileName, format: format, usePrinterSettings: false, onLayout: (_) async => bytes);
    } catch (e) {
      if (mounted) showSnack(context, 'Could not print: $e', error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _share() async {
    setState(() => _busy = true);
    try {
      await Printing.sharePdf(bytes: await _pdf(), filename: _fileName);
    } catch (e) {
      if (mounted) showSnack(context, 'Could not share: $e', error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _set(VoidCallback f) => setState(f);

  @override
  Widget build(BuildContext context) {
    final s = _s;
    final t = Theme.of(context);
    if (s == null) return Scaffold(appBar: AppBar(title: const Text('Print prescription')), body: const Center(child: CircularProgressIndicator()));
    final page = paperPoints(s.paper);

    Widget group(String label, Widget child) => Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(label, style: t.textTheme.labelMedium?.copyWith(color: t.colorScheme.onSurfaceVariant)),
            const SizedBox(height: 6),
            child,
          ]),
        );
    Widget chips<T>(List<(T, String)> opts, T value, void Function(T) on) => Wrap(spacing: 8, runSpacing: 8, children: [
          for (final o in opts)
            ChoiceChip(
              label: Text(o.$2),
              selected: value == o.$1,
              onSelected: (_) {
                HapticFeedback.selectionClick();
                _set(() => on(o.$1));
              },
            ),
        ]);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Print prescription'),
        actions: [
          IconButton(
            tooltip: 'Save as my default',
            icon: const Icon(Icons.bookmark_add_outlined),
            onPressed: () async {
              s.language = _lang ?? s.language;
              await ref.read(printSettingsRepoProvider).save(s);
              afterLocalWrite(ref);
              if (context.mounted) showSnack(context, 'Saved as your default print layout');
            },
          ),
        ],
      ),
      body: ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
        // Live preview = exactly what prints.
        Center(
          child: Container(
            constraints: const BoxConstraints(maxWidth: 420),
            decoration: BoxDecoration(boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: 0.12), blurRadius: 18, offset: const Offset(0, 6))]),
            child: AspectRatio(
              aspectRatio: page.width / page.height,
              child: FittedBox(
                child: RepaintBoundary(
                  key: _pageKey,
                  child: SizedBox(
                    width: page.width,
                    height: page.height,
                    child: PrescriptionPage(job: widget.job, settings: s, lang: _lang ?? 'en', letterhead: _letterhead, logo: _logo),
                  ),
                ),
              ),
            ),
          ),
        ),
        const SizedBox(height: 18),
        group('Paper', chips([(PaperSize.a4, 'A4'), (PaperSize.a5, 'A5')], s.paper, (v) => s.paper = v)),
        group('Print on', chips([(PrintArea.full, 'Full page'), (PrintArea.topHalf, 'Top half'), (PrintArea.bottomHalf, 'Bottom half')], s.area, (v) => s.area = v)),
        group(
          'Header',
          chips([
            (HeaderMode.text, 'Hospital header'),
            if (_hasLetterhead) (HeaderMode.image, 'Letterhead image'),
            (HeaderMode.blank, 'Pre-printed paper'),
            (HeaderMode.none, 'No header (back side)'),
          ], s.header, (v) => s.header = v),
        ),
        if (s.header == HeaderMode.blank)
          group(
            'Space for pre-printed header: ${s.blankTopMm.round()} mm',
            Slider(value: s.blankTopMm.clamp(10, 100), min: 10, max: 100, divisions: 18, label: '${s.blankTopMm.round()} mm', onChanged: (v) => _set(() => s.blankTopMm = v)),
          ),
        group('Language', chips([for (final l in s.languages) (l, printLanguages[l] ?? l)], _lang ?? 'en', (v) => _lang = v)),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: s.bilingual,
          onChanged: (_lang ?? 'en') == 'en' ? null : (v) => _set(() => s.bilingual = v),
          title: const Text('English labels too'),
          subtitle: const Text('e.g. "Advice / सलाह"'),
        ),
        SwitchListTile(contentPadding: EdgeInsets.zero, value: s.showVitals, onChanged: (v) => _set(() => s.showVitals = v), title: const Text('Print vitals')),
        SwitchListTile(contentPadding: EdgeInsets.zero, value: s.showDiagnosis, onChanged: (v) => _set(() => s.showDiagnosis = v), title: const Text('Print diagnosis')),
        const SizedBox(height: 6),
        if (_canPrint == false)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: Text('Printing is not available on this device. Use Share PDF to send it to a printer or the patient.', style: t.textTheme.bodySmall?.copyWith(color: t.colorScheme.error)),
          ),
      ]),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
          child: Row(children: [
            Expanded(
              child: OutlinedButton.icon(
                style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(50)),
                onPressed: _busy ? null : _share,
                icon: const Icon(Icons.share_rounded),
                label: const Text('Share PDF'),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              flex: 2,
              child: FilledButton.icon(
                style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(Radii.md))),
                onPressed: _busy || _canPrint == false ? null : _print,
                icon: _busy ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.print_rounded),
                label: Text('Print ${s.paper == PaperSize.a4 ? 'A4' : 'A5'}'),
              ),
            ),
          ]),
        ),
      ),
    );
  }
}
