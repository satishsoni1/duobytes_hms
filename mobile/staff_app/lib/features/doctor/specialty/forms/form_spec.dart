import '../../data/clinical.dart';

/// Configurable specialty forms.
///
/// A specialty pack declares its case-sheet as a list of [FieldSpec]s. Records are stored as generic
/// clinical records whose type starts with `sp_`; the server accepts any safe field keys for those
/// types (Mobileclinical_model::OPEN_PREFIX), so a new form needs no server change.
enum FieldKind {
  text,
  multiline,
  number,
  choice,
  multi,
  date,

  /// 0–10 score (pain, itch, ...)
  scale,

  /// Right eye / left eye (or right / left side) pair of text values: stored as `<key>_re`, `<key>_le`.
  pair,
}

class FieldSpec {
  const FieldSpec(
    this.key,
    this.label, {
    this.kind = FieldKind.text,
    this.unit,
    this.options = const [],
    this.hint,
    this.required = false,
    this.trend = false,
    this.decimals = 1,
    this.summary = false,
  });

  final String key;
  final String label;
  final FieldKind kind;
  final String? unit;
  final List<String> options;
  final String? hint;
  final bool required;

  /// Plot this numeric field over time on the history screen.
  final bool trend;
  final int decimals;

  /// Show this field in the one-line summary of a record.
  final bool summary;

  /// Data keys this field writes.
  List<String> get keys => kind == FieldKind.pair ? ['${key}_re', '${key}_le'] : [key];

  /// Human-readable value, or null when empty.
  String? display(ClinicalRecord r) {
    switch (kind) {
      case FieldKind.pair:
        final a = r.str('${key}_re'), b = r.str('${key}_le');
        if (a == null && b == null) return null;
        return 'R ${a ?? '–'} · L ${b ?? '–'}';
      case FieldKind.multi:
        final v = r.data[key];
        final list = v is List ? v.map((e) => '$e').toList() : (r.str(key)?.split(', ') ?? const <String>[]);
        return list.isEmpty ? null : list.join(', ');
      case FieldKind.scale:
        final v = r.str(key);
        return v == null ? null : '$v / 10';
      case FieldKind.date:
        final d = r.date(key);
        return d == null ? null : ymd(d);
      case FieldKind.number:
        final v = r.str(key);
        return v == null ? null : (unit == null ? v : '$v $unit');
      default:
        return r.str(key);
    }
  }
}

/// One kind of record a specialty keeps (an eye exam, a tooth finding, a physio session ...).
class RecordForm {
  const RecordForm({
    required this.type,
    required this.title,
    required this.fields,
    this.addLabel = 'Add',
    this.statuses,
    this.datedLabel = 'Date',
  });

  /// Record type; must start with `sp_`.
  final String type;
  final String title;
  final String addLabel;
  final List<FieldSpec> fields;

  /// Optional workflow states (e.g. planned / done) stored in the record status.
  final List<(String, String)>? statuses;
  final String datedLabel;

  List<FieldSpec> get trendFields => [for (final f in fields) if (f.trend && (f.kind == FieldKind.number || f.kind == FieldKind.scale)) f];

  /// "R 6/9 · L 6/12 · IOP 16" — the fields marked [FieldSpec.summary], else the first three filled ones.
  String summaryOf(ClinicalRecord r) {
    final marked = fields.where((f) => f.summary);
    final pick = (marked.isEmpty ? fields : marked).map((f) {
      final v = f.display(r);
      return v == null ? null : (f.kind == FieldKind.text || f.kind == FieldKind.choice || f.kind == FieldKind.multiline ? v : '${f.label} $v');
    }).whereType<String>();
    final list = (marked.isEmpty ? pick.take(3) : pick).toList();
    return list.isEmpty ? 'No details' : list.join(' · ');
  }

  String? validate(Map<String, dynamic> data) {
    for (final f in fields.where((f) => f.required)) {
      final empty = f.keys.every((k) {
        final v = data[k];
        return v == null || (v is String && v.trim().isEmpty) || (v is List && v.isEmpty);
      });
      if (empty) return '${f.label} is required';
    }
    return null;
  }
}

double? numberOf(Object? v) => v == null ? null : (v is num ? v.toDouble() : double.tryParse('$v'.trim()));
