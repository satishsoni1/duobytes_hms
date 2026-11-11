import 'package:flutter/material.dart';

import '../data/clinical.dart';
import 'chronic_care.dart';
import 'followups.dart';
import 'obstetrics.dart';
import 'paediatrics.dart';
import 'specialty_packs.dart';
import 'forms/record_form_sheet.dart';
import 'forms/specialty_forms.dart';
import 'specialty.dart';

bool _has(List<ClinicalRecord> r, Set<String> types) => r.any((x) => types.contains(x.type));

/// All specialty toolkits. Order = order of cards on the Care tab.
final List<SpecialtyToolkit> allSpecialtyToolkits = [
  SpecialtyToolkit(
    id: 'followups',
    name: 'Follow-ups',
    description: 'Schedule and track patient reviews',
    icon: Icons.event_repeat_rounded,
    keywords: const [],
    alwaysOn: true,
    appliesTo: (_, _) => true,
    buildCards: (context, d, r) => [FollowUpsCard(d: d, records: r)],
  ),
  SpecialtyToolkit(
    id: 'paediatrics',
    name: 'Paediatrics',
    description: 'Vaccination chart, growth, weight-based doses',
    icon: Icons.child_care_rounded,
    keywords: const ['paed', 'pediat', 'child', 'neonat', 'nicu', 'infant'],
    appliesTo: (d, r) => (d.patient.ageYears ?? 99) < 18 || _has(r, {RecordType.vaccination, RecordType.growth}),
    buildCards: (context, d, r) => [VaccinationCard(d: d, records: r), GrowthCard(d: d, records: r), DoseCalculatorCard(d: d, records: r)],
  ),
  SpecialtyToolkit(
    id: 'obstetrics',
    name: 'Obstetrics & Gynaecology',
    description: 'Pregnancy dating, EDD, antenatal plan, obstetric history',
    icon: Icons.pregnant_woman_rounded,
    keywords: const ['gyn', 'obst', 'matern', 'antenatal', 'fertility', 'ivf', 'women'],
    appliesTo: (d, r) {
      final age = d.patient.ageYears;
      return (d.patient.isFemale && (age == null || (age >= 12 && age <= 55))) || _has(r, {RecordType.pregnancy, RecordType.obstetricHistory});
    },
    buildCards: (context, d, r) => [PregnancyCard(d: d, records: r)],
  ),
  SpecialtyToolkit(
    id: 'chronic',
    recordTypes: {SpForms.diabetes.type},
    name: 'Chronic care',
    description: 'BP, pulse, weight and sugar trends',
    icon: Icons.monitor_heart_rounded,
    keywords: const ['medicine', 'physician', 'cardio', 'diabet', 'endocrin', 'nephro', 'geriat', 'pulmo', 'chest', 'neuro', 'internal'],
    appliesTo: (d, _) => (d.patient.ageYears ?? 99) >= 12,
    buildCards: (context, d, r) => [VitalsTrendCard(d: d), DiabetesCard(d: d, records: r)],
  ),
  SpecialtyToolkit(
    id: 'dental',
    optIn: true,
    recordTypes: {SpForms.dental.type},
    name: 'Dental',
    description: 'Tooth chart (FDI), treatment plan per tooth, sittings and balance',
    icon: Icons.medical_information_rounded,
    keywords: const ['dent', 'oral', 'maxillo', 'orthodont', 'endodont', 'periodont', 'prosthodont'],
    appliesTo: (_, _) => true,
    buildCards: (context, d, r) => [DentalChartCard(d: d, records: r)],
  ),
  SpecialtyToolkit(
    id: 'ophthalmology',
    optIn: true,
    recordTypes: {SpForms.eye.type},
    name: 'Ophthalmology',
    description: 'Visual acuity, refraction, IOP, spectacle prescription',
    icon: Icons.visibility_rounded,
    keywords: const ['ophthal', 'eye', 'optom', 'retina', 'cornea', 'glaucoma'],
    appliesTo: (_, _) => true,
    buildCards: (context, d, r) => [EyeCard(d: d, records: r)],
  ),
  SpecialtyToolkit(
    id: 'orthopaedics',
    optIn: true,
    recordTypes: {SpForms.ortho.type},
    name: 'Orthopaedics',
    description: 'Injury site, X-ray, cast removal date, pain and range of motion',
    icon: Icons.personal_injury_rounded,
    keywords: const ['orthop', 'bone', 'spine', 'joint', 'trauma', 'sports med', 'arthro'],
    appliesTo: (_, _) => true,
    buildCards: (context, d, r) => [OrthoCard(d: d, records: r)],
  ),
  SpecialtyToolkit(
    id: 'dermatology',
    optIn: true,
    recordTypes: {SpForms.derm.type},
    name: 'Dermatology',
    description: 'Lesion details, itch score, procedures and sessions',
    icon: Icons.spa_rounded,
    keywords: const ['derma', 'skin', 'cosmet', 'venereo', 'trich', 'aesthetic'],
    appliesTo: (_, _) => true,
    buildCards: (context, d, r) => [SpecialtyRecordCard(patientId: d.patient.id, records: r, form: SpForms.derm, icon: Icons.spa_rounded)],
  ),
  SpecialtyToolkit(
    id: 'physiotherapy',
    optIn: true,
    recordTypes: {SpForms.physioPlan.type, SpForms.physioSession.type},
    name: 'Physiotherapy',
    description: 'Therapy plans, session packages, pain and ROM progress',
    icon: Icons.accessibility_new_rounded,
    keywords: const ['physio', 'rehab', 'physical therap', 'occupational'],
    appliesTo: (_, _) => true,
    buildCards: (context, d, r) => [PhysioCard(d: d, records: r)],
  ),
  SpecialtyToolkit(
    id: 'ayush',
    optIn: true,
    recordTypes: {SpForms.ayush.type},
    name: 'Ayurveda & Homeopathy',
    description: 'Prakriti, case taking, remedy and potency, pathya-apathya',
    icon: Icons.eco_rounded,
    keywords: const ['ayur', 'homeo', 'unani', 'siddha', 'naturo', 'ayush', 'yoga'],
    appliesTo: (_, _) => true,
    buildCards: (context, d, r) => [SpecialtyRecordCard(patientId: d.patient.id, records: r, form: SpForms.ayush, icon: Icons.eco_rounded)],
  ),
];
