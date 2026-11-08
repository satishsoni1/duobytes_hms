import 'models.dart';

/// Basic allergy screening by name: flags a medicine whose name or composition mentions a recorded
/// allergy, or belongs to a few common allergy classes. This is a safety prompt, not a full
/// drug-allergy interaction database, and the UI says so.
class AllergyCheck {
  AllergyCheck(List<String> allergies) : _terms = allergies.map((a) => a.toLowerCase().trim()).where((a) => a.length >= 3).toList();

  final List<String> _terms;

  static const _classes = <String, List<String>>{
    'penicillin': ['penicillin', 'amoxicillin', 'ampicillin', 'cloxacillin', 'dicloxacillin', 'flucloxacillin', 'piperacillin', 'benzathine'],
    'cephalosporin': ['cef', 'ceph'],
    'sulfa': ['sulfa', 'sulpha', 'sulfamethoxazole', 'cotrimoxazole', 'co-trimoxazole', 'sulfasalazine', 'sulfadiazine'],
    'nsaid': ['ibuprofen', 'diclofenac', 'naproxen', 'aceclofenac', 'ketorolac', 'aspirin', 'mefenamic', 'piroxicam', 'etoricoxib', 'indomethacin', 'nimesulide'],
    'aspirin': ['aspirin', 'acetylsalicylic'],
    'quinolone': ['floxacin'],
    'macrolide': ['erythromycin', 'azithromycin', 'clarithromycin', 'roxithromycin'],
    'opioid': ['morphine', 'codeine', 'tramadol', 'tapentadol', 'fentanyl', 'pethidine'],
  };

  bool get isEmpty => _terms.isEmpty;

  /// The allergy that matched, or null.
  String? match(String name, String? composition) {
    if (_terms.isEmpty) return null;
    final text = '${name.toLowerCase()} ${(composition ?? '').toLowerCase()}';
    for (final a in _terms) {
      if (text.contains(a)) return a;
      for (final entry in _classes.entries) {
        if (a.contains(entry.key) && entry.value.any(text.contains)) return a;
      }
    }
    return null;
  }

  String? matchItem(RxItem it) => match(it.medicineName, it.composition);
  String? matchMedicine(Medicine m) => match(m.name, m.composition);
}
