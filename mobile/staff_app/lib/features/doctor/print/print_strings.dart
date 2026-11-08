/// Labels and patient-facing phrases for printed prescriptions.
///
/// Medicine names stay as written (English). Translations should be reviewed by a native speaker
/// before go-live; add a language by adding a column here and its code to [printLanguages].
const printLanguages = <String, String>{'en': 'English', 'hi': 'हिंदी', 'mr': 'मराठी'};

const _labels = <String, Map<String, String>>{
  'prescription': {'en': 'Prescription', 'hi': 'पर्चा', 'mr': 'प्रिस्क्रिप्शन'},
  'patient': {'en': 'Patient', 'hi': 'रोगी', 'mr': 'रुग्ण'},
  'age_sex': {'en': 'Age / Sex', 'hi': 'आयु / लिंग', 'mr': 'वय / लिंग'},
  'patient_id': {'en': 'Patient ID', 'hi': 'रोगी क्रमांक', 'mr': 'रुग्ण क्रमांक'},
  'date': {'en': 'Date', 'hi': 'दिनांक', 'mr': 'दिनांक'},
  'token': {'en': 'Token', 'hi': 'टोकन', 'mr': 'टोकन'},
  'allergies': {'en': 'Allergies', 'hi': 'एलर्जी', 'mr': 'ॲलर्जी'},
  'vitals': {'en': 'Vitals', 'hi': 'जाँच', 'mr': 'तपासणी'},
  'bp': {'en': 'BP', 'hi': 'रक्तचाप', 'mr': 'रक्तदाब'},
  'pulse': {'en': 'Pulse', 'hi': 'नाड़ी', 'mr': 'नाडी'},
  'temp': {'en': 'Temp', 'hi': 'तापमान', 'mr': 'तापमान'},
  'weight': {'en': 'Weight', 'hi': 'वजन', 'mr': 'वजन'},
  'diagnosis': {'en': 'Diagnosis', 'hi': 'निदान', 'mr': 'निदान'},
  'findings': {'en': 'Findings', 'hi': 'जाँच निष्कर्ष', 'mr': 'तपासणी निष्कर्ष'},
  'medicines': {'en': 'Medicines', 'hi': 'दवाइयाँ', 'mr': 'औषधे'},
  'medicine': {'en': 'Medicine', 'hi': 'दवा', 'mr': 'औषध'},
  'dose': {'en': 'Dose', 'hi': 'खुराक', 'mr': 'मात्रा'},
  'when': {'en': 'When', 'hi': 'कब लें', 'mr': 'केव्हा घ्यावे'},
  'duration': {'en': 'Duration', 'hi': 'अवधि', 'mr': 'कालावधी'},
  'tests': {'en': 'Tests advised', 'hi': 'सुझाई गई जाँचें', 'mr': 'सुचवलेल्या चाचण्या'},
  'advice': {'en': 'Advice', 'hi': 'सलाह', 'mr': 'सल्ला'},
  'notes': {'en': 'Notes', 'hi': 'टिप्पणी', 'mr': 'टीप'},
  'next_visit': {'en': 'Next visit', 'hi': 'अगली मुलाक़ात', 'mr': 'पुढील भेट'},
  'signature': {'en': "Doctor's signature", 'hi': 'डॉक्टर के हस्ताक्षर', 'mr': 'डॉक्टरांची स्वाक्षरी'},
  'reg_no': {'en': 'Reg. No.', 'hi': 'पंजीकरण सं.', 'mr': 'नोंदणी क्र.'},
  'phone': {'en': 'Ph', 'hi': 'फ़ोन', 'mr': 'फोन'},
  'male': {'en': 'Male', 'hi': 'पुरुष', 'mr': 'पुरुष'},
  'female': {'en': 'Female', 'hi': 'महिला', 'mr': 'स्त्री'},
};

/// Dosing shorthand -> patient words.
const _dosing = <String, Map<String, String>>{
  'OD': {'en': 'Once a day', 'hi': 'दिन में 1 बार', 'mr': 'दिवसातून 1 वेळा'},
  'QD': {'en': 'Once a day', 'hi': 'दिन में 1 बार', 'mr': 'दिवसातून 1 वेळा'},
  'BD': {'en': 'Twice a day', 'hi': 'दिन में 2 बार', 'mr': 'दिवसातून 2 वेळा'},
  'BID': {'en': 'Twice a day', 'hi': 'दिन में 2 बार', 'mr': 'दिवसातून 2 वेळा'},
  'TDS': {'en': '3 times a day', 'hi': 'दिन में 3 बार', 'mr': 'दिवसातून 3 वेळा'},
  'TID': {'en': '3 times a day', 'hi': 'दिन में 3 बार', 'mr': 'दिवसातून 3 वेळा'},
  'QID': {'en': '4 times a day', 'hi': 'दिन में 4 बार', 'mr': 'दिवसातून 4 वेळा'},
  'QDS': {'en': '4 times a day', 'hi': 'दिन में 4 बार', 'mr': 'दिवसातून 4 वेळा'},
  'HS': {'en': 'At bedtime', 'hi': 'सोते समय', 'mr': 'झोपताना'},
  'SOS': {'en': 'Only if needed', 'hi': 'ज़रूरत होने पर', 'mr': 'गरज असल्यास'},
  'PRN': {'en': 'Only if needed', 'hi': 'ज़रूरत होने पर', 'mr': 'गरज असल्यास'},
  'STAT': {'en': 'Immediately', 'hi': 'तुरंत', 'mr': 'लगेच'},
};

/// Common instruction phrases (the editor's quick chips).
const _phrases = <String, Map<String, String>>{
  'after food': {'hi': 'खाने के बाद', 'mr': 'जेवणानंतर'},
  'before food': {'hi': 'खाने से पहले', 'mr': 'जेवणापूर्वी'},
  'empty stomach': {'hi': 'खाली पेट', 'mr': 'उपाशीपोटी'},
  'at bedtime': {'hi': 'सोते समय', 'mr': 'झोपताना'},
  'if needed (sos)': {'hi': 'ज़रूरत होने पर', 'mr': 'गरज असल्यास'},
};

const _units = <String, Map<String, String>>{
  'day': {'en': 'day', 'hi': 'दिन', 'mr': 'दिवस'},
  'days': {'en': 'days', 'hi': 'दिन', 'mr': 'दिवस'},
  'week': {'en': 'week', 'hi': 'सप्ताह', 'mr': 'आठवडा'},
  'weeks': {'en': 'weeks', 'hi': 'सप्ताह', 'mr': 'आठवडे'},
  'month': {'en': 'month', 'hi': 'महीना', 'mr': 'महिना'},
  'months': {'en': 'months', 'hi': 'महीने', 'mr': 'महिने'},
};

class PrintStrings {
  const PrintStrings(this.lang, {this.bilingual = false});
  final String lang;

  /// Show the English label next to the local one ("Advice / सलाह").
  final bool bilingual;

  String t(String key) {
    final m = _labels[key];
    if (m == null) return key;
    final local = m[lang] ?? m['en']!;
    return bilingual && lang != 'en' ? '${m['en']} / $local' : local;
  }

  String sex(String? gender) {
    final g = (gender ?? '').toLowerCase();
    if (g.startsWith('m')) return t('male');
    if (g.startsWith('f')) return t('female');
    return gender ?? '';
  }

  /// "TDS" -> "दिन में 3 बार (TDS)"; unknown codes are returned unchanged.
  String dosing(String? code) {
    if (code == null || code.trim().isEmpty) return '';
    final w = _dosing[code.trim().toUpperCase().replaceAll('.', '')];
    if (w == null) return code;
    return '${w[lang] ?? w['en']} (${code.trim()})';
  }

  /// "5 days" -> "5 दिन".
  String duration(String? text) {
    if (text == null || text.trim().isEmpty || lang == 'en') return text ?? '';
    return text.replaceAllMapped(RegExp(r'\b(days?|weeks?|months?)\b', caseSensitive: false), (m) => _units[m[1]!.toLowerCase()]?[lang] ?? m[0]!);
  }

  /// Translates known instruction phrases, keeps anything else as typed.
  String instruction(String text) {
    if (lang == 'en' || text.trim().isEmpty) return text;
    var out = text;
    for (final e in _phrases.entries) {
      final local = e.value[lang];
      if (local == null) continue;
      out = out.replaceAll(RegExp(RegExp.escape(e.key), caseSensitive: false), local);
    }
    return out;
  }
}
