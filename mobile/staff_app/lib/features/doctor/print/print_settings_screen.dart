import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/ui/widgets.dart';
import '../ui/doctor_providers.dart';
import 'print_screen.dart';
import 'print_settings.dart';
import 'print_strings.dart';

/// Default print layout, languages, and the header text in each language.
class PrintSettingsScreen extends ConsumerStatefulWidget {
  const PrintSettingsScreen({super.key});

  @override
  ConsumerState<PrintSettingsScreen> createState() => _PrintSettingsScreenState();
}

class _PrintSettingsScreenState extends ConsumerState<PrintSettingsScreen> {
  PrintSettings? _s;
  String _editLang = 'en';
  final _c = <String, TextEditingController>{};
  final _footer = TextEditingController();

  static const _fields = [
    ('hospital', 'Hospital name'),
    ('address', 'Address'),
    ('phone', 'Phone'),
    ('doctor', 'Doctor name'),
    ('qualification', 'Qualification / specialty'),
    ('reg_no', 'Registration no.'),
    ('timings', 'OPD timings'),
  ];

  @override
  void initState() {
    super.initState();
    ref.read(printSettingsProvider.future).then((s) {
      if (!mounted) return;
      setState(() => _s = s.copy());
      _footer.text = s.footer;
      _bind();
    });
  }

  @override
  void dispose() {
    for (final c in _c.values) {
      c.dispose();
    }
    _footer.dispose();
    super.dispose();
  }

  /// Point the text fields at the header of the language being edited.
  void _bind() {
    final h = _s!.headers[_editLang] ?? HeaderText();
    final j = h.toJson();
    for (final f in _fields) {
      final c = _c.putIfAbsent(f.$1, () => TextEditingController());
      c.text = '${j[f.$1] ?? ''}';
    }
  }

  void _commitFields() {
    final j = {for (final f in _fields) f.$1: _c[f.$1]!.text.trim()};
    _s!.headers[_editLang] = HeaderText.fromJson(j);
  }

  Future<void> _save() async {
    _commitFields();
    _s!.footer = _footer.text.trim();
    if (!_s!.languages.contains(_s!.language)) _s!.language = _s!.languages.first;
    await ref.read(printSettingsRepoProvider).save(_s!);
    afterLocalWrite(ref);
    if (mounted) {
      showSnack(context, 'Print settings saved');
      Navigator.pop(context);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = _s;
    final t = Theme.of(context);
    if (s == null) return Scaffold(appBar: AppBar(title: const Text('Print settings')), body: const Center(child: CircularProgressIndicator()));
    Widget chips<T>(List<(T, String)> opts, T value, void Function(T) on) => Wrap(spacing: 8, runSpacing: 8, children: [
          for (final o in opts) ChoiceChip(label: Text(o.$2), selected: value == o.$1, onSelected: (_) => setState(() => on(o.$1))),
        ]);

    return Scaffold(
      appBar: AppBar(title: const Text('Print settings'), actions: [TextButton(onPressed: _save, child: const Text('Save'))]),
      body: ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 32), children: [
        const SectionHeader('Default layout', padding: EdgeInsets.fromLTRB(4, 8, 0, 8)),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('Paper', style: t.textTheme.labelMedium),
              const SizedBox(height: 6),
              chips([(PaperSize.a4, 'A4'), (PaperSize.a5, 'A5')], s.paper, (v) => s.paper = v),
              const SizedBox(height: 12),
              Text('Print on', style: t.textTheme.labelMedium),
              const SizedBox(height: 6),
              chips([(PrintArea.full, 'Full page'), (PrintArea.topHalf, 'Top half'), (PrintArea.bottomHalf, 'Bottom half')], s.area, (v) => s.area = v),
              const SizedBox(height: 12),
              Text('Header', style: t.textTheme.labelMedium),
              const SizedBox(height: 6),
              chips([
                (HeaderMode.text, 'Hospital header'),
                (HeaderMode.image, 'Letterhead image'),
                (HeaderMode.blank, 'Pre-printed paper'),
                (HeaderMode.none, 'No header'),
              ], s.header, (v) => s.header = v),
              if (s.header == HeaderMode.image)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text('Uses the OPD prescription header image from the hospital print settings on the web.', style: t.textTheme.bodySmall),
                ),
              if (s.header == HeaderMode.blank) ...[
                const SizedBox(height: 6),
                Text('Blank space for the pre-printed header: ${s.blankTopMm.round()} mm', style: t.textTheme.bodySmall),
                Slider(value: s.blankTopMm.clamp(10, 100), min: 10, max: 100, divisions: 18, onChanged: (v) => setState(() => s.blankTopMm = v)),
              ],
              SwitchListTile(contentPadding: EdgeInsets.zero, value: s.showLogo, onChanged: (v) => setState(() => s.showLogo = v), title: const Text('Hospital logo in header')),
            ]),
          ),
        ),

        const SectionHeader('Languages', padding: EdgeInsets.fromLTRB(4, 20, 0, 8)),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('Offer on the print screen', style: t.textTheme.labelMedium),
              Wrap(spacing: 8, children: [
                for (final e in printLanguages.entries)
                  FilterChip(
                    label: Text(e.value),
                    selected: s.languages.contains(e.key),
                    onSelected: e.key == 'en'
                        ? null
                        : (v) => setState(() {
                              v ? s.languages.add(e.key) : s.languages.remove(e.key);
                            }),
                  ),
              ]),
              const SizedBox(height: 10),
              Text('Default language', style: t.textTheme.labelMedium),
              chips([for (final l in s.languages) (l, printLanguages[l] ?? l)], s.language, (v) => s.language = v),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                value: s.bilingual,
                onChanged: (v) => setState(() => s.bilingual = v),
                title: const Text('Also print English labels'),
              ),
            ]),
          ),
        ),

        const SectionHeader('Header text', padding: EdgeInsets.fromLTRB(4, 20, 0, 8)),
        Text('Fill the header in each language. Empty fields use the English text.', style: t.textTheme.bodySmall),
        const SizedBox(height: 8),
        SegmentedButton<String>(
          showSelectedIcon: false,
          segments: [for (final l in s.languages) ButtonSegment(value: l, label: Text(printLanguages[l] ?? l))],
          selected: {_editLang},
          onSelectionChanged: (v) => setState(() {
            _commitFields();
            _editLang = v.first;
            _bind();
          }),
        ),
        const SizedBox(height: 10),
        for (final f in _fields)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: TextField(
              controller: _c[f.$1],
              maxLines: f.$1 == 'address' ? 2 : 1,
              decoration: InputDecoration(labelText: f.$2, hintText: _editLang == 'en' ? null : (s.headers['en']?.toJson()[f.$1] as String?)),
            ),
          ),
        const SectionHeader('Footer', padding: EdgeInsets.fromLTRB(4, 12, 0, 8)),
        TextField(
          controller: _footer,
          decoration: const InputDecoration(labelText: 'Bottom line (optional)', hintText: 'e.g. In emergency call 0724-xxxxxxx'),
        ),
      ]),
    );
  }
}
