import 'package:flutter/material.dart';

import '../data/clinical.dart';
import 'chronic_care.dart';
import 'followups.dart';
import 'obstetrics.dart';
import 'paediatrics.dart';
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
    name: 'Chronic care',
    description: 'BP, pulse, weight and sugar trends',
    icon: Icons.monitor_heart_rounded,
    keywords: const ['medicine', 'physician', 'cardio', 'diabet', 'endocrin', 'nephro', 'geriat', 'pulmo', 'chest', 'neuro', 'internal'],
    appliesTo: (d, _) => (d.patient.ageYears ?? 99) >= 12,
    buildCards: (context, d, r) => [VitalsTrendCard(d: d)],
  ),
];
