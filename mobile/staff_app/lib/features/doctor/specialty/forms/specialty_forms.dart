import 'form_spec.dart';

/// Case-sheet definitions for the configurable specialty packs. Record types start with `sp_` so the
/// server stores them without a code change (see form_spec.dart).
class SpForms {
  // ------------------------------------------------------------------ ophthalmology
  static const eye = RecordForm(
    type: 'sp_eye_exam',
    title: 'Eye examination',
    addLabel: 'New eye exam',
    datedLabel: 'Examined on',
    fields: [
      FieldSpec('va', 'Visual acuity', kind: FieldKind.pair, hint: '6/9', summary: true),
      FieldSpec('va_near', 'Near vision', kind: FieldKind.pair, hint: 'N6'),
      FieldSpec('sph', 'Sphere (SPH)', kind: FieldKind.pair, hint: '-1.25'),
      FieldSpec('cyl', 'Cylinder (CYL)', kind: FieldKind.pair, hint: '-0.50'),
      FieldSpec('axis', 'Axis', kind: FieldKind.pair, unit: '°', hint: '180'),
      FieldSpec('add', 'Near add (ADD)', kind: FieldKind.pair, hint: '+1.50'),
      FieldSpec('iop', 'IOP', kind: FieldKind.pair, unit: 'mmHg', hint: '16', summary: true),
      FieldSpec('slit_lamp', 'Slit lamp', kind: FieldKind.multiline, hint: 'Lids, conjunctiva, cornea, AC, lens'),
      FieldSpec('fundus', 'Fundus', kind: FieldKind.multiline, hint: 'Disc, macula, vessels'),
      FieldSpec('diagnosis', 'Diagnosis', summary: true),
      FieldSpec('advice', 'Advice', kind: FieldKind.multi, options: ['Spectacles', 'Contact lens', 'Eye drops', 'Cataract surgery', 'Laser', 'Refer retina', 'Review']),
      FieldSpec('lens_type', 'Lens type', kind: FieldKind.choice, options: ['Single vision', 'Bifocal', 'Progressive', 'Reading only']),
    ],
  );

  // ------------------------------------------------------------------ orthopaedics
  static const ortho = RecordForm(
    type: 'sp_ortho',
    title: 'Injury & musculoskeletal',
    addLabel: 'New ortho record',
    statuses: [('active', 'Under treatment'), ('done', 'Healed')],
    fields: [
      FieldSpec('site', 'Site', required: true, hint: 'e.g. distal radius, L4-L5, knee', summary: true),
      FieldSpec('side', 'Side', kind: FieldKind.choice, options: ['Right', 'Left', 'Both', 'Midline'], summary: true),
      FieldSpec('mechanism', 'Mechanism', kind: FieldKind.choice, options: ['Fall', 'Road accident', 'Sports', 'Work', 'Degenerative', 'Other']),
      FieldSpec('xray', 'X-ray / imaging', kind: FieldKind.multiline, hint: 'Undisplaced fracture ...'),
      FieldSpec('diagnosis', 'Diagnosis', summary: true),
      FieldSpec('immobilisation', 'Immobilisation', kind: FieldKind.choice, options: ['POP cast', 'Slab', 'Splint', 'Brace', 'Sling', 'Traction', 'None']),
      FieldSpec('removal_on', 'Cast / splint removal on', kind: FieldKind.date),
      FieldSpec('surgery', 'Surgery / implant', hint: 'ORIF with plate ...'),
      FieldSpec('pain', 'Pain score', kind: FieldKind.scale, trend: true),
      FieldSpec('rom', 'Range of motion', hint: 'Flexion 0-90°'),
      FieldSpec('physio', 'Physiotherapy advised', kind: FieldKind.choice, options: ['Yes', 'No']),
      FieldSpec('note', 'Notes', kind: FieldKind.multiline),
    ],
  );

  // ------------------------------------------------------------------ dermatology
  static const derm = RecordForm(
    type: 'sp_derm',
    title: 'Skin findings',
    addLabel: 'New skin record',
    fields: [
      FieldSpec('lesion', 'Lesion type', kind: FieldKind.multi, options: ['Macule', 'Patch', 'Papule', 'Plaque', 'Nodule', 'Vesicle', 'Pustule', 'Scale', 'Ulcer', 'Wheal']),
      FieldSpec('site', 'Site', hint: 'Face, groin, scalp ...', summary: true),
      FieldSpec('distribution', 'Distribution', kind: FieldKind.choice, options: ['Localised', 'Generalised', 'Symmetrical', 'Dermatomal', 'Sun-exposed', 'Flexural']),
      FieldSpec('duration', 'Duration', hint: '3 weeks'),
      FieldSpec('itch', 'Itch score', kind: FieldKind.scale, trend: true),
      FieldSpec('extent', 'Body surface area', kind: FieldKind.number, unit: '%', trend: true),
      FieldSpec('diagnosis', 'Diagnosis', summary: true),
      FieldSpec('treatment', 'Topical / application advice', kind: FieldKind.multiline, hint: 'Apply thin layer twice daily for 2 weeks'),
      FieldSpec('procedure', 'Procedure', kind: FieldKind.choice, options: ['None', 'Chemical peel', 'Cautery', 'Cryotherapy', 'Laser', 'PRP', 'Microneedling', 'Biopsy']),
      FieldSpec('session', 'Procedure session', hint: '2 of 6'),
      FieldSpec('note', 'Notes', kind: FieldKind.multiline),
    ],
  );

  // ------------------------------------------------------------------ dental
  static const dentalFindings = ['Caries', 'Deep caries', 'Fractured', 'Mobile', 'Missing', 'Impacted', 'Sensitive', 'Calculus', 'Restored', 'Healthy'];
  static const dentalProcedures = ['Filling', 'RCT', 'Crown', 'Extraction', 'Scaling', 'Bridge', 'Implant', 'Denture', 'Braces', 'Observation'];
  static const dental = RecordForm(
    type: 'sp_dental',
    title: 'Dental chart',
    addLabel: 'Add tooth record',
    statuses: [('active', 'Planned'), ('done', 'Done')],
    fields: [
      FieldSpec('tooth', 'Tooth (FDI)', required: true, hint: '36', summary: true),
      FieldSpec('surface', 'Surface', kind: FieldKind.multi, options: ['M', 'O', 'D', 'B', 'L/P', 'I']),
      FieldSpec('finding', 'Finding', kind: FieldKind.choice, options: dentalFindings, summary: true),
      FieldSpec('procedure', 'Treatment', kind: FieldKind.choice, options: dentalProcedures, summary: true),
      FieldSpec('sitting', 'Sitting', hint: '1 of 3'),
      FieldSpec('charge', 'Charge', kind: FieldKind.number, unit: '₹'),
      FieldSpec('paid', 'Paid', kind: FieldKind.number, unit: '₹'),
      FieldSpec('note', 'Notes', kind: FieldKind.multiline),
    ],
  );

  // ------------------------------------------------------------------ physiotherapy
  static const physioPlan = RecordForm(
    type: 'sp_physio_plan',
    title: 'Therapy plan',
    addLabel: 'New therapy plan',
    statuses: [('active', 'Ongoing'), ('closed', 'Completed')],
    datedLabel: 'Started on',
    fields: [
      FieldSpec('condition', 'Condition', required: true, hint: 'Frozen shoulder (R)', summary: true),
      FieldSpec('sessions_total', 'Sessions in package', kind: FieldKind.number, hint: '10'),
      FieldSpec('package_amount', 'Package amount', kind: FieldKind.number, unit: '₹'),
      FieldSpec('paid', 'Paid', kind: FieldKind.number, unit: '₹'),
      FieldSpec('goals', 'Goals', kind: FieldKind.multiline, hint: 'Overhead reach without pain'),
      FieldSpec('home_plan', 'Home exercise plan', kind: FieldKind.multiline, hint: 'Pendulum exercises 10 × 3 daily'),
    ],
  );

  static const physioSession = RecordForm(
    type: 'sp_physio_session',
    title: 'Therapy sessions',
    addLabel: 'Record session',
    fields: [
      FieldSpec('modality', 'Treatment given', kind: FieldKind.multi, options: ['IFT', 'TENS', 'Ultrasound', 'Hot pack', 'Cold pack', 'Mobilisation', 'Exercises', 'Traction', 'Laser', 'Taping'], summary: true),
      FieldSpec('pain_before', 'Pain before', kind: FieldKind.scale, trend: true),
      FieldSpec('pain_after', 'Pain after', kind: FieldKind.scale, trend: true, summary: true),
      FieldSpec('rom', 'Range of motion', hint: 'Flexion 120°', summary: true),
      FieldSpec('rom_deg', 'Main ROM', kind: FieldKind.number, unit: '°', trend: true),
      FieldSpec('note', 'Notes', kind: FieldKind.multiline),
    ],
  );

  // ------------------------------------------------------------------ AYUSH
  static const ayush = RecordForm(
    type: 'sp_ayush',
    title: 'AYUSH case',
    addLabel: 'New AYUSH case note',
    fields: [
      FieldSpec('system', 'System', kind: FieldKind.choice, options: ['Ayurveda', 'Homeopathy', 'Unani', 'Siddha', 'Naturopathy'], summary: true),
      FieldSpec('prakriti', 'Prakriti / constitution', kind: FieldKind.choice, options: ['Vata', 'Pitta', 'Kapha', 'Vata-Pitta', 'Pitta-Kapha', 'Vata-Kapha', 'Sama']),
      FieldSpec('case_history', 'Case history', kind: FieldKind.multiline),
      FieldSpec('generals', 'Physical & mental generals', kind: FieldKind.multiline, hint: 'Thermal, thirst, desires, sleep, mind'),
      FieldSpec('nadi', 'Nadi / examination', hint: 'Vata-pradhan'),
      FieldSpec('remedy', 'Remedy / formulation', summary: true, hint: 'Bryonia, Triphala churna'),
      FieldSpec('potency', 'Potency', kind: FieldKind.choice, options: ['Q', '6C', '30C', '200C', '1M', '10M', 'LM']),
      FieldSpec('repetition', 'Dose & repetition', hint: '4 pills TDS × 5 days', summary: true),
      FieldSpec('anupana', 'Anupana (vehicle)', hint: 'Warm water'),
      FieldSpec('dispensed', 'Dispensed in clinic', kind: FieldKind.choice, options: ['Yes', 'No']),
      FieldSpec('pathya', 'Diet: take (pathya)', kind: FieldKind.multiline),
      FieldSpec('apathya', 'Diet: avoid (apathya)', kind: FieldKind.multiline, hint: 'Cold drinks, curd at night'),
    ],
  );

  // ------------------------------------------------------------------ diabetes
  static const diabetes = RecordForm(
    type: 'sp_diabetes',
    title: 'Diabetes review',
    addLabel: 'New diabetes review',
    fields: [
      FieldSpec('fbs', 'Fasting sugar', kind: FieldKind.number, unit: 'mg/dl', trend: true, summary: true),
      FieldSpec('ppbs', 'Post-meal sugar', kind: FieldKind.number, unit: 'mg/dl', trend: true),
      FieldSpec('hba1c', 'HbA1c', kind: FieldKind.number, unit: '%', trend: true, summary: true),
      FieldSpec('weight', 'Weight', kind: FieldKind.number, unit: 'kg', trend: true),
      FieldSpec('insulin', 'Insulin regimen', hint: 'Glargine 14 U at night', summary: true),
      FieldSpec('hypo', 'Hypoglycaemia since last visit', kind: FieldKind.choice, options: ['None', 'Mild', 'Severe']),
      FieldSpec('eye_check', 'Last eye (retina) check', kind: FieldKind.date),
      FieldSpec('foot_check', 'Last foot check', kind: FieldKind.date),
      FieldSpec('kidney_check', 'Last urine microalbumin / creatinine', kind: FieldKind.date),
      FieldSpec('note', 'Notes', kind: FieldKind.multiline),
    ],
  );

  static const all = [eye, ortho, derm, dental, physioPlan, physioSession, ayush, diabetes];
}
