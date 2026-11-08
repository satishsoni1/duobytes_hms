import 'models.dart';

enum LabFlag { low, high, normal, unknown }

double? _num(String s) {
  final m = RegExp(r'-?\d+(?:[.,]\d+)?').firstMatch(s.replaceAll(',', ''));
  return m == null ? null : double.tryParse(m.group(0)!);
}

/// Compares a result with its reference range. Understands "12-15", "12 – 15", "12 to 15",
/// "<200", "≤ 5", "> 40", "up to 10". Text results ("Negative") and ranges with several bands
/// (sex- or age-specific) are left unflagged rather than guessed.
LabFlag flagFor(String value, String? range) {
  final v = _num(value);
  final r = (range ?? '').trim().toLowerCase();
  if (v == null || r.isEmpty) return LabFlag.unknown;

  final rangeRe = RegExp(r'(-?\d+(?:\.\d+)?)\s*(?:-|–|—|to)\s*(-?\d+(?:\.\d+)?)');
  final ranges = rangeRe.allMatches(r.replaceAll(',', '')).toList();
  // Several ranges ("Male 13-17, Female 12-15", age bands): which applies is unknown, so do not guess.
  if (ranges.length > 1) return LabFlag.unknown;
  final between = ranges.firstOrNull;
  if (between != null) {
    final lo = double.parse(between.group(1)!), hi = double.parse(between.group(2)!);
    if (lo > hi) return LabFlag.unknown;
    return v < lo ? LabFlag.low : v > hi ? LabFlag.high : LabFlag.normal;
  }
  final upper = RegExp(r'^(?:<|≤|<=|up to|upto|less than|below)\s*(-?\d+(?:\.\d+)?)').firstMatch(r.replaceAll(',', ''));
  if (upper != null) {
    final hi = double.parse(upper.group(1)!);
    return v > hi ? LabFlag.high : LabFlag.normal;
  }
  final lower = RegExp(r'^(?:>|≥|>=|more than|above|greater than)\s*(-?\d+(?:\.\d+)?)').firstMatch(r.replaceAll(',', ''));
  if (lower != null) {
    final lo = double.parse(lower.group(1)!);
    return v < lo ? LabFlag.low : LabFlag.normal;
  }
  return LabFlag.unknown;
}

extension LabReportFlags on LabReport {
  /// Parameters with a value outside their reference range.
  List<(LabParam, LabFlag)> get abnormal => [
        for (final p in parameters)
          if (p.value.isNotEmpty)
            if (flagFor(p.value, p.referenceRange) case final f when f == LabFlag.low || f == LabFlag.high) (p, f),
      ];

  bool get isAbnormal => abnormal.isNotEmpty;
}
