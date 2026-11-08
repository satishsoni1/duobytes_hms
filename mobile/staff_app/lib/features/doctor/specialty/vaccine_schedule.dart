import '../data/clinical.dart';

/// One scheduled vaccine dose, due at an age (days from birth).
class VaccineDose {
  const VaccineDose(this.vaccine, this.dose, this.ageDays, this.ageLabel);
  final String vaccine;
  final String dose;
  final int ageDays;
  final String ageLabel;

  String get key => '$vaccine#$dose';
}

/// Childhood schedule based on the Indian Academy of Pediatrics (IAP ACVIP) recommendations.
/// Shown to doctors as "based on IAP"; hospitals should check it against their protocol and the
/// national UIP. Region-specific and optional vaccines (JE, cholera, meningococcal, COVID-19, ...)
/// are recorded with "Add other vaccine".
const iapSchedule = <VaccineDose>[
  VaccineDose('BCG', '1', 0, 'Birth'),
  VaccineDose('OPV', '0', 0, 'Birth'),
  VaccineDose('Hepatitis B', '1', 0, 'Birth'),
  VaccineDose('DTwP/DTaP', '1', 42, '6 weeks'),
  VaccineDose('IPV', '1', 42, '6 weeks'),
  VaccineDose('Hib', '1', 42, '6 weeks'),
  VaccineDose('Hepatitis B', '2', 42, '6 weeks'),
  VaccineDose('Rotavirus', '1', 42, '6 weeks'),
  VaccineDose('PCV', '1', 42, '6 weeks'),
  VaccineDose('DTwP/DTaP', '2', 70, '10 weeks'),
  VaccineDose('IPV', '2', 70, '10 weeks'),
  VaccineDose('Hib', '2', 70, '10 weeks'),
  VaccineDose('Hepatitis B', '3', 70, '10 weeks'),
  VaccineDose('Rotavirus', '2', 70, '10 weeks'),
  VaccineDose('PCV', '2', 70, '10 weeks'),
  VaccineDose('DTwP/DTaP', '3', 98, '14 weeks'),
  VaccineDose('IPV', '3', 98, '14 weeks'),
  VaccineDose('Hib', '3', 98, '14 weeks'),
  VaccineDose('Hepatitis B', '4', 98, '14 weeks'),
  VaccineDose('Rotavirus', '3', 98, '14 weeks'),
  VaccineDose('PCV', '3', 98, '14 weeks'),
  VaccineDose('Influenza', '1', 182, '6 months'),
  VaccineDose('Typhoid conjugate', '1', 182, '6–9 months'),
  VaccineDose('Influenza', '2', 213, '7 months'),
  VaccineDose('MMR', '1', 274, '9 months'),
  VaccineDose('Hepatitis A', '1', 365, '12 months'),
  VaccineDose('PCV', 'Booster', 365, '12–15 months'),
  VaccineDose('MMR', '2', 456, '15 months'),
  VaccineDose('Varicella', '1', 456, '15 months'),
  VaccineDose('DTwP/DTaP', 'Booster 1', 487, '16–18 months'),
  VaccineDose('IPV', 'Booster 1', 487, '16–18 months'),
  VaccineDose('Hib', 'Booster 1', 487, '16–18 months'),
  VaccineDose('Hepatitis A', '2', 548, '18–19 months'),
  VaccineDose('Varicella', '2', 548, '18–21 months'),
  VaccineDose('DTwP/DTaP', 'Booster 2', 1461, '4–6 years'),
  VaccineDose('IPV', 'Booster 2', 1461, '4–6 years'),
  VaccineDose('MMR', '3', 1461, '4–6 years'),
  VaccineDose('HPV', '1', 3287, '9–14 years'),
  VaccineDose('HPV', '2', 3470, '6 months after HPV 1'),
  VaccineDose('Tdap/Td', '1', 3652, '10–12 years'),
];

enum VaccineState { given, overdue, due, upcoming }

class VaccineStatus {
  VaccineStatus(this.dose, this.dueDate, this.state, this.record);
  final VaccineDose dose;
  final DateTime? dueDate;
  final VaccineState state;
  final ClinicalRecord? record;
}

/// Due status for every scheduled dose. "Due" = from 14 days before to 30 days after the due date.
List<VaccineStatus> vaccineStatuses(DateTime? dob, List<ClinicalRecord> vaccinations, {DateTime? today}) {
  final now = today ?? DateTime.now();
  final given = <String, ClinicalRecord>{
    for (final r in vaccinations)
      if (r.type == RecordType.vaccination && !r.deleted) '${r.str('vaccine')}#${r.str('dose')}': r,
  };
  return [
    for (final d in iapSchedule)
      () {
        final rec = given[d.key];
        final due = dob?.add(Duration(days: d.ageDays));
        final state = rec != null
            ? VaccineState.given
            : due == null
                ? VaccineState.upcoming
                : due.isBefore(now.subtract(const Duration(days: 30)))
                    ? VaccineState.overdue
                    : due.isBefore(now.add(const Duration(days: 14)))
                        ? VaccineState.due
                        : VaccineState.upcoming;
        return VaccineStatus(d, due, state, rec);
      }(),
  ];
}

/// Vaccinations recorded that are not part of the schedule (e.g. JE, meningococcal, annual flu).
List<ClinicalRecord> otherVaccinations(List<ClinicalRecord> vaccinations) {
  final keys = {for (final d in iapSchedule) d.key};
  return vaccinations.where((r) => r.type == RecordType.vaccination && !r.deleted && !keys.contains('${r.str('vaccine')}#${r.str('dose')}')).toList();
}
