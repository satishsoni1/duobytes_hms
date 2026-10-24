#!/usr/bin/env python3
"""Generates DEMO_SEED_ALL_MODULES.sql for Smart Hospital (fictional data, demo/local DB only)."""
import sys

OUT = sys.argv[1]
STAFF_PW = '$2y$12$WnCUVc/bb3ur706TEic.7uFXukhxNySkzfs6aWsmbbb0RV9SE20fK'  # Demo@123
PATIENT_PW = 'demo123'
BASE = 7000

lines = []
emit = lines.append
owned = {}  # table -> list of fixed ids this seed owns (deleted before re-insert)


class R(str):
    """Raw SQL expression (not quoted)."""


def q(v):
    if v is None:
        return 'NULL'
    if isinstance(v, R):
        return str(v)
    if isinstance(v, bool):
        return '1' if v else '0'
    if isinstance(v, (int, float)):
        return repr(round(v, 2)) if isinstance(v, float) else str(v)
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''") + "'"


def day(n):
    """Date n days before today (negative = future)."""
    if n == 0:
        return R('@today')
    return R(f'(@today - INTERVAL {n} DAY)') if n > 0 else R(f'(@today + INTERVAL {-n} DAY)')


def dt(n, hm='10:00'):
    return R(f"TIMESTAMP({day(n)}, '{hm}:00')")


def ins(table, row):
    if 'id' in row and isinstance(row['id'], int):
        owned.setdefault(table, []).append(row['id'])
    cols = ', '.join(f'`{c}`' for c in row)
    vals = ', '.join(q(v) for v in row.values())
    emit(f'INSERT INTO `{table}` ({cols}) VALUES ({vals});')


def goc(var, table, key, extra=None):
    """Get-or-create a master row by its key columns; store its id in @var."""
    row = dict(key)
    row.update(extra or {})
    cols = ', '.join(f'`{c}`' for c in row)
    vals = ', '.join(q(v) for v in row.values())
    cond = ' AND '.join(f'`{c}` = {q(v)}' for c, v in key.items())
    emit(f'INSERT INTO `{table}` ({cols}) SELECT {vals} FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `{table}` WHERE {cond});')
    emit(f'SET @{var} = (SELECT id FROM `{table}` WHERE {cond} ORDER BY id LIMIT 1);')
    return R('@' + var)


def section(title):
    emit('')
    emit('-- ' + '=' * 76)
    emit('-- ' + title)
    emit('-- ' + '=' * 76)


class Seq:
    def __init__(self):
        self.n = {}

    def __call__(self, table):
        self.n[table] = self.n.get(table, BASE) + 1
        return self.n[table]


nid = Seq()

# ---------------------------------------------------------------------------
section('MASTERS (get-or-create by name: reused if already present, never deleted)')

emit('-- Tax / charge units / charge categories')
tax0 = goc('tax0', 'tax_category', {'name': 'GST Exempt (Healthcare)'}, {'percentage': 0})
tax12 = goc('tax12', 'tax_category', {'name': 'GST 12%'}, {'percentage': 12})
cu = {}
for k, u in [('visit', 'Per visit'), ('test', 'Per test'), ('day', 'Per day'), ('unit', 'Per unit'), ('trip', 'Per trip'), ('proc', 'Per procedure')]:
    cu[k] = goc('cu_' + k, 'charge_units', {'unit': u}, {'is_active': 1})

ctype = {}
for k, name in [('appt', 'Appointment'), ('opd', 'OPD'), ('ipd', 'IPD'), ('path', 'Pathology'), ('radio', 'Radiology'),
                ('blood', 'Blood Bank'), ('amb', 'Ambulance'), ('proc', 'Procedures'), ('ot', 'Operations'), ('other', 'Others')]:
    emit(f"SET @ct_{k} = (SELECT id FROM charge_type_master WHERE charge_type = {q(name)} ORDER BY id LIMIT 1);")
    ctype[k] = R('@ct_' + k)

ccat = {}
for k, t, name in [('opd', 'opd', 'OPD Consultation'), ('appt', 'appt', 'Appointment Fees'), ('room', 'ipd', 'IPD Room Charges'),
                   ('nursing', 'ipd', 'IPD Nursing & Services'), ('path', 'path', 'Pathology'), ('radio', 'radio', 'Radiology'),
                   ('blood', 'blood', 'Blood Bank Charges'), ('amb', 'amb', 'Ambulance Charges'), ('proc', 'proc', 'Minor Procedures'),
                   ('ot', 'ot', 'Surgery Charges')]:
    ccat[k] = goc('cc_' + k, 'charge_categories', {'name': name}, {'charge_type_id': ctype[t], 'description': name, 'short_code': k.upper(), 'is_default': 'no'})

CH = {}  # key -> (var, price)


def charge(key, cat, name, price, unit):
    CH[key] = (goc('ch_' + key, 'charges', {'name': name},
                   {'charge_category_id': ccat[cat], 'tax_category_id': tax0, 'charge_unit_id': cu[unit], 'standard_charge': price,
                    'date': day(30), 'description': name, 'status': 'active'}), price)


charge('cons_gen', 'opd', 'Consultation - General Medicine', 500, 'visit')
charge('cons_spl', 'opd', 'Consultation - Specialist', 800, 'visit')
charge('cons_follow', 'opd', 'Follow-up Consultation', 300, 'visit')
charge('appt_fee', 'appt', 'Appointment Booking Fee', 500, 'visit')
charge('room_gen', 'room', 'General Ward - per day', 1500, 'day')
charge('room_pvt', 'room', 'Private Room - per day', 3500, 'day')
charge('room_icu', 'room', 'ICU - per day', 8000, 'day')
charge('nursing', 'nursing', 'Nursing Charges - per day', 600, 'day')
charge('doc_visit', 'nursing', 'Doctor Visit (IPD)', 700, 'visit')
charge('dressing', 'proc', 'Wound Dressing', 300, 'proc')
charge('cbc', 'path', 'CBC', 350, 'test')
charge('lipid', 'path', 'Lipid Profile', 700, 'test')
charge('hba1c', 'path', 'HbA1c', 600, 'test')
charge('lft', 'path', 'Liver Function Test', 650, 'test')
charge('kft', 'path', 'Kidney Function Test', 650, 'test')
charge('tsh', 'path', 'TSH', 400, 'test')
charge('urine', 'path', 'Urine Routine', 200, 'test')
charge('xray', 'radio', 'Chest X-Ray PA view', 450, 'test')
charge('usg', 'radio', 'USG Abdomen & Pelvis', 1200, 'test')
charge('ct', 'radio', 'CT Brain (Plain)', 2800, 'test')
charge('ecg', 'radio', 'ECG 12 lead', 300, 'test')
charge('blood_unit', 'blood', 'Blood Processing Charge - per unit', 1450, 'unit')
charge('amb_local', 'amb', 'Ambulance - Local (within city)', 800, 'trip')
charge('amb_out', 'amb', 'Ambulance - Outstation (per trip)', 3500, 'trip')
charge('ot_appx', 'ot', 'Laparoscopic Appendectomy', 35000, 'proc')
charge('ot_lscs', 'ot', 'LSCS (Caesarean Section)', 40000, 'proc')
charge('ot_orif', 'ot', 'ORIF - Forearm Fracture', 45000, 'proc')

emit('')
emit('-- Departments / specialists / designations')
DEPT = {}
for k, n in [('med', 'General Medicine'), ('paed', 'Paediatrics'), ('gyn', 'Gynaecology & Obstetrics'), ('ortho', 'Orthopaedics'),
             ('cardio', 'Cardiology'), ('surg', 'General Surgery'), ('lab', 'Laboratory'), ('radio', 'Radiology'), ('pharm', 'Pharmacy'),
             ('nurse', 'Nursing'), ('front', 'Front Office'), ('acct', 'Accounts'), ('admin', 'Administration')]:
    DEPT[k] = goc('dept_' + k, 'department', {'department_name': n}, {'is_active': 'yes'})
SPEC = {}
for k, n in [('gp', 'General Physician'), ('paed', 'Paediatrician'), ('gyn', 'Gynaecologist'), ('ortho', 'Orthopaedic Surgeon'),
             ('cardio', 'Cardiologist'), ('surg', 'General Surgeon'), ('anaes', 'Anaesthetist'), ('path', 'Pathologist'), ('radio', 'Radiologist')]:
    SPEC[k] = goc('sp_' + k, 'specialist', {'specialist_name': n}, {'is_active': 'yes'})
DESIG = {}
for k, n in [('cons', 'Consultant'), ('nurse', 'Staff Nurse'), ('pharm', 'Pharmacist'), ('labt', 'Lab Technician'), ('radio', 'Radiographer'),
             ('recep', 'Receptionist'), ('acct', 'Accountant'), ('admin', 'Hospital Administrator')]:
    DESIG[k] = goc('dg_' + k, 'staff_designation', {'designation': n}, {'is_active': 'yes'})

emit('')
emit('-- Beds')
FL = {k: goc('fl_' + k, 'floor', {'name': n}, {'description': ''}) for k, n in [('g', 'Ground Floor'), ('1', 'First Floor'), ('2', 'Second Floor')]}
BT = {k: goc('bt_' + k, 'bed_type', {'name': n}) for k, n in [('gen', 'General'), ('pvt', 'Private'), ('icu', 'ICU')]}
BG = {}
for k, n, color, fl in [('male', 'General Ward - Male', '#2e7d32', 'g'), ('female', 'General Ward - Female', '#ad1457', 'g'),
                        ('pvt', 'Private Rooms', '#1565c0', '1'), ('icu', 'ICU', '#c62828', '2')]:
    BG[k] = goc('bg_' + k, 'bed_group', {'name': n}, {'color': color, 'description': n, 'floor': FL[fl], 'is_active': 0})
BED = {}
for grp, prefix, btype, count in [('male', 'MW', 'gen', 6), ('female', 'FW', 'gen', 6), ('pvt', 'PR', 'pvt', 4), ('icu', 'ICU', 'icu', 4)]:
    for i in range(1, count + 1):
        name = f'{prefix}-{i:02d}'
        BED[name] = goc('bed_' + name.replace('-', '_'), 'bed', {'name': name}, {'bed_type_id': BT[btype], 'bed_group_id': BG[grp], 'is_active': 'yes'})

emit('')
emit('-- Blood groups and components')
BLOOD = {}
for g in ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-']:
    BLOOD[g] = goc('bl_' + g.replace('+', 'p').replace('-', 'n'), 'blood_bank_products', {'name': g, 'is_blood_group': 1})
COMP = {k: goc('bc_' + k, 'blood_bank_products', {'name': n, 'is_blood_group': 2})
        for k, n in [('prbc', 'Packed Red Cells'), ('ffp', 'Fresh Frozen Plasma'), ('plt', 'Platelets')]}

emit('')
emit('-- Pharmacy masters')
MCAT = {k: goc('mc_' + k, 'medicine_category', {'medicine_category': n})
        for k, n in [('tab', 'Tablet'), ('cap', 'Capsule'), ('syr', 'Syrup'), ('inj', 'Injection'), ('inh', 'Inhaler'), ('oint', 'Ointment'), ('drop', 'Drops')]}
UNIT = {}
for k, n, t in [('mg', 'mg', 'medicine'), ('ml', 'ml', 'medicine'), ('strip', 'Strip', 'medicine'), ('bottle', 'Bottle', 'medicine'),
                ('vial', 'Vial', 'medicine'), ('tube', 'Tube', 'medicine'),
                ('gdl', 'g/dL', 'patho'), ('k_ul', '10^3/uL', 'patho'), ('mgdl', 'mg/dL', 'patho'), ('pct', '%', 'patho'),
                ('miul', 'mIU/L', 'patho'), ('ul', 'U/L', 'patho'), ('hpf', '/HPF', 'patho')]:
    UNIT[k] = goc('un_' + k, 'unit', {'unit_name': n, 'unit_type': t})
COMPANY = {k: goc('co_' + k, 'pharmacy_company', {'company_name': n})
           for k, n in [('sun', 'Sun Pharma'), ('cipla', 'Cipla'), ('mankind', 'Mankind Pharma'), ('alkem', 'Alkem Laboratories'), ('gsk', 'GSK India')]}
MGRP = {k: goc('mg_' + k, 'medicine_group', {'group_name': n})
        for k, n in [('anal', 'Analgesics & Antipyretics'), ('abx', 'Antibiotics'), ('gi', 'Gastrointestinal'), ('cvs', 'Cardiovascular'),
                     ('dm', 'Antidiabetic'), ('resp', 'Respiratory'), ('allergy', 'Antihistamines'), ('vit', 'Vitamins & Supplements')]}
SUPP = {}
for k, n, person, phone, lic, addr in [
        ('shree', 'Shree Medical Distributors', 'Mahesh Agrawal', '9822000101', 'MH-NAG-20B-11021', 'Itwari, Nagpur, Maharashtra'),
        ('lifeline', 'Lifeline Pharma Agencies', 'Rekha Bhonsle', '9822000102', 'MH-NAG-20B-11587', 'Sitabuldi, Nagpur, Maharashtra'),
        ('vidarbha', 'Vidarbha Surgicals & Drugs', 'Imran Sheikh', '9822000103', 'MH-AKL-20B-07714', 'Tilak Road, Akola, Maharashtra')]:
    SUPP[k] = goc('su_' + k, 'medicine_supplier', {'supplier': n},
                  {'contact': phone, 'supplier_person': person, 'supplier_person_contact': phone, 'supplier_drug_licence': lic, 'address': addr})

MED = {}  # key -> (var, mrp per unit)
for k, n, cat, co, grp, comp, unit, rack in [
        ('para', 'Paracetamol 650', 'tab', 'mankind', 'anal', 'Paracetamol 650 mg', 'strip', 'A1'),
        ('dolo', 'Dolo 650', 'tab', 'mankind', 'anal', 'Paracetamol 650 mg', 'strip', 'A1'),
        ('aug', 'Augmentin 625 Duo', 'tab', 'gsk', 'abx', 'Amoxicillin 500 mg + Clavulanic acid 125 mg', 'strip', 'B2'),
        ('azi', 'Azithral 500', 'tab', 'alkem', 'abx', 'Azithromycin 500 mg', 'strip', 'B2'),
        ('pan', 'Pan 40', 'tab', 'alkem', 'gi', 'Pantoprazole 40 mg', 'strip', 'C1'),
        ('cetz', 'Cetirizine 10', 'tab', 'cipla', 'allergy', 'Cetirizine 10 mg', 'strip', 'C3'),
        ('glyc', 'Glycomet 500', 'tab', 'sun', 'dm', 'Metformin 500 mg', 'strip', 'D1'),
        ('telma', 'Telma 40', 'tab', 'sun', 'cvs', 'Telmisartan 40 mg', 'strip', 'D2'),
        ('atorva', 'Atorva 10', 'tab', 'sun', 'cvs', 'Atorvastatin 10 mg', 'strip', 'D2'),
        ('eco', 'Ecosprin 75', 'tab', 'sun', 'cvs', 'Aspirin 75 mg', 'strip', 'D3'),
        ('shelcal', 'Shelcal 500', 'tab', 'alkem', 'vit', 'Calcium 500 mg + Vitamin D3', 'strip', 'E1'),
        ('becos', 'Becosules', 'cap', 'cipla', 'vit', 'Vitamin B-complex with Vitamin C', 'strip', 'E1'),
        ('omez', 'Omez 20', 'cap', 'cipla', 'gi', 'Omeprazole 20 mg', 'strip', 'C1'),
        ('calsyr', 'Calpol Syrup', 'syr', 'gsk', 'anal', 'Paracetamol 120 mg/5 ml', 'bottle', 'F1'),
        ('ors', 'ORS Electral', 'syr', 'cipla', 'gi', 'Oral rehydration salts', 'bottle', 'F2'),
        ('ceftri', 'Ceftriaxone 1g', 'inj', 'alkem', 'abx', 'Ceftriaxone 1 g', 'vial', 'G1'),
        ('pantoinj', 'Pantop 40 Injection', 'inj', 'sun', 'gi', 'Pantoprazole 40 mg IV', 'vial', 'G1'),
        ('asthalin', 'Asthalin Inhaler', 'inh', 'cipla', 'resp', 'Salbutamol 100 mcg/puff', 'bottle', 'H1'),
        ('volini', 'Volini Gel', 'oint', 'sun', 'anal', 'Diclofenac + Methyl salicylate', 'tube', 'H2'),
        ('moxi', 'Moxicip Eye Drops', 'drop', 'cipla', 'abx', 'Moxifloxacin 0.5%', 'bottle', 'H3')]:
    MED[k] = goc('md_' + k, 'pharmacy', {'medicine_name': n},
                 {'medicine_category_id': MCAT[cat], 'medicine_image': '', 'medicine_company': COMPANY[co], 'medicine_composition': comp,
                  'medicine_group': MGRP[grp], 'unit': UNIT[unit], 'min_level': '20', 'reorder_level': '50', 'vat': 0,
                  'unit_packing': '10' if unit == 'strip' else '1', 'vat_ac': '', 'rack_number': rack, 'note': '', 'is_active': 'yes'})

DOSE = {}
for k, cat, d in [('1tab', 'tab', '1 tablet'), ('1cap', 'cap', '1 capsule'), ('5ml', 'syr', '5 ml'), ('10ml', 'syr', '10 ml'),
                  ('1vial', 'inj', '1 vial'), ('2puff', 'inh', '2 puffs'), ('thin', 'oint', 'Apply thin layer'), ('2drop', 'drop', '2 drops')]:
    DOSE[k] = goc('do_' + k, 'medicine_dosage', {'medicine_category_id': MCAT[cat], 'dosage': d})
INTV = {k: goc('iv_' + k, 'dose_interval', {'name': n})
        for k, n in [('bd', '1-0-1 (BD)'), ('tds', '1-1-1 (TDS)'), ('od', '1-0-0 (OD)'), ('hs', '0-0-1 (HS)'), ('sos', 'SOS'), ('qid', '1-1-1-1 (QID)')]}
DUR = {k: goc('du_' + k, 'dose_duration', {'name': n})
       for k, n in [('3d', '3 days'), ('5d', '5 days'), ('7d', '7 days'), ('10d', '10 days'), ('1m', '1 month'), ('3m', '3 months')]}

emit('')
emit('-- Symptoms / findings')
SYC = {k: goc('syc_' + k, 'symptoms_classification', {'symptoms_type': n})
       for k, n in [('fever', 'Fever & infection'), ('resp', 'Respiratory'), ('gi', 'Stomach problems'), ('msk', 'Muscle or joint problems'),
                    ('skin', 'Skin problems'), ('cardio', 'Heart & blood pressure')]}
for t, cls, desc in [('Fever with chills', 'fever', 'High grade fever with chills and rigors'), ('Cough and cold', 'resp', 'Dry or productive cough with running nose'),
                     ('Breathlessness', 'resp', 'Shortness of breath on exertion'), ('Abdominal pain', 'gi', 'Pain in abdomen, colicky or constant'),
                     ('Loose motions', 'gi', 'Frequent watery stools'), ('Knee pain', 'msk', 'Pain and stiffness in knee joint'),
                     ('Low back pain', 'msk', 'Pain in lower back radiating to legs'), ('Itching and rash', 'skin', 'Itchy red rash over body'),
                     ('Chest pain', 'cardio', 'Retrosternal chest pain'), ('Headache and giddiness', 'cardio', 'Headache with giddiness, ? high BP')]:
    goc('sy', 'symptoms', {'symptoms_title': t}, {'description': desc, 'type': SYC[cls]})
FC = {k: goc('fc_' + k, 'finding_category', {'category': n}) for k, n in [('gen', 'General Examination'), ('sys', 'Systemic Examination')]}
for n, cat, desc in [('Pallor', 'gen', 'Pallor present'), ('Pedal oedema', 'gen', 'Bilateral pitting pedal oedema'), ('Dehydration', 'gen', 'Dry tongue, reduced skin turgor'),
                     ('Crepitations', 'sys', 'Bilateral basal crepitations on auscultation'), ('Tenderness RIF', 'sys', 'Tenderness in right iliac fossa')]:
    goc('fi', 'finding', {'name': n}, {'description': desc, 'finding_category_id': FC[cat]})

emit('')
emit('-- Pathology tests')
PCAT = {k: goc('pc_' + k, 'pathology_category', {'category_name': n}) for k, n in [('haem', 'Haematology'), ('bio', 'Biochemistry'), ('endo', 'Endocrinology'), ('clin', 'Clinical Pathology')]}
PPARAM = {}
for k, n, rr, lo, hi, unit in [
        ('hb', 'Haemoglobin', '12 - 16 g/dL', '12', '16', 'gdl'), ('wbc', 'Total WBC Count', '4 - 11 10^3/uL', '4', '11', 'k_ul'),
        ('plt', 'Platelet Count', '150 - 450 10^3/uL', '150', '450', 'k_ul'), ('chol', 'Total Cholesterol', '< 200 mg/dL', '0', '200', 'mgdl'),
        ('tg', 'Triglycerides', '< 150 mg/dL', '0', '150', 'mgdl'), ('hdl', 'HDL Cholesterol', '> 40 mg/dL', '40', '100', 'mgdl'),
        ('ldl', 'LDL Cholesterol', '< 100 mg/dL', '0', '100', 'mgdl'), ('hba1c', 'HbA1c', '4 - 5.6 %', '4', '5.6', 'pct'),
        ('sgpt', 'SGPT (ALT)', '7 - 56 U/L', '7', '56', 'ul'), ('sgot', 'SGOT (AST)', '5 - 40 U/L', '5', '40', 'ul'),
        ('bili', 'Total Bilirubin', '0.2 - 1.2 mg/dL', '0.2', '1.2', 'mgdl'), ('urea', 'Blood Urea', '15 - 40 mg/dL', '15', '40', 'mgdl'),
        ('creat', 'Serum Creatinine', '0.6 - 1.3 mg/dL', '0.6', '1.3', 'mgdl'), ('tsh', 'TSH', '0.4 - 4.0 mIU/L', '0.4', '4.0', 'miul'),
        ('pus', 'Pus Cells', '0 - 5 /HPF', '0', '5', 'hpf'), ('rbcu', 'RBC (Urine)', '0 - 2 /HPF', '0', '2', 'hpf')]:
    PPARAM[k] = goc('pp_' + k, 'pathology_parameter', {'parameter_name': n},
                    {'test_value': '', 'reference_range': rr, 'range_from': lo, 'range_to': hi, 'gender': '', 'unit': UNIT[unit], 'description': ''})
PTEST = {}  # key -> (var, charge key, params)
for k, n, short, cat, ch, days, method, params in [
        ('cbc', 'Complete Blood Count', 'CBC', 'haem', 'cbc', '1', 'Automated cell counter', ['hb', 'wbc', 'plt']),
        ('lipid', 'Lipid Profile', 'LIPID', 'bio', 'lipid', '1', 'Enzymatic', ['chol', 'tg', 'hdl', 'ldl']),
        ('hba1c', 'HbA1c (Glycated Haemoglobin)', 'HBA1C', 'endo', 'hba1c', '1', 'HPLC', ['hba1c']),
        ('lft', 'Liver Function Test', 'LFT', 'bio', 'lft', '1', 'Spectrophotometry', ['sgpt', 'sgot', 'bili']),
        ('kft', 'Kidney Function Test', 'KFT', 'bio', 'kft', '1', 'Spectrophotometry', ['urea', 'creat']),
        ('tsh', 'Thyroid Stimulating Hormone', 'TSH', 'endo', 'tsh', '2', 'CLIA', ['tsh']),
        ('urine', 'Urine Routine & Microscopy', 'URINE', 'clin', 'urine', '1', 'Microscopy', ['pus', 'rbcu'])]:
    var = goc('pt_' + k, 'pathology', {'test_name': n},
              {'short_name': short, 'test_type': 'Blood' if cat != 'clin' else 'Urine', 'pathology_category_id': PCAT[cat], 'unit': '',
               'sub_category': '', 'report_days': days, 'method': method, 'charge_id': CH[ch][0]})
    for p in params:
        emit(f'INSERT INTO pathology_parameterdetails (pathology_id, pathology_parameter_id) SELECT {var}, {PPARAM[p]} FROM DUAL '
             f'WHERE NOT EXISTS (SELECT 1 FROM pathology_parameterdetails WHERE pathology_id = {var} AND pathology_parameter_id = {PPARAM[p]});')
    PTEST[k] = (var, ch, params)

emit('')
emit('-- Radiology tests')
RCAT = {k: goc('rc_' + k, 'lab', {'lab_name': n}) for k, n in [('xray', 'X-Ray'), ('usg', 'Ultrasound'), ('ct', 'CT Scan'), ('cardiac', 'Cardiac')]}
RPARAM = {}
for k, n, rr, unit in [('imp', 'Impression', 'Normal study', 'Text'), ('findings', 'Findings', 'Within normal limits', 'Text')]:
    RPARAM[k] = goc('rp_' + k, 'radiology_parameter', {'parameter_name': n},
                    {'test_value': '', 'reference_range': rr, 'range_from': '', 'range_to': '', 'gender': '', 'unit': unit, 'description': ''})
RTEST = {}
for k, n, short, cat, ch in [('xray', 'Chest X-Ray PA view', 'CXR', 'xray', 'xray'), ('usg', 'USG Abdomen & Pelvis', 'USG-A', 'usg', 'usg'),
                             ('ct', 'CT Brain (Plain)', 'CT-B', 'ct', 'ct'), ('ecg', 'ECG 12 lead', 'ECG', 'cardiac', 'ecg')]:
    var = goc('rt_' + k, 'radio', {'test_name': n},
              {'short_name': short, 'test_type': cat, 'radiology_category_id': RCAT[cat], 'sub_category': '', 'report_days': '1', 'charge_id': CH[ch][0]})
    for p in RPARAM.values():
        emit(f'INSERT INTO radiology_parameterdetails (radiology_id, radiology_parameter_id) SELECT {var}, {p} FROM DUAL '
             f'WHERE NOT EXISTS (SELECT 1 FROM radiology_parameterdetails WHERE radiology_id = {var} AND radiology_parameter_id = {p});')
    RTEST[k] = (var, ch)

emit('')
emit('-- Operation theatre masters')
OCAT = {k: goc('oc_' + k, 'operation_category', {'category': n}, {'is_active': 'yes'})
        for k, n in [('gen', 'General Surgery'), ('obg', 'Obstetrics & Gynaecology'), ('ortho', 'Orthopaedics')]}
OPER = {k: goc('op_' + k, 'operation', {'operation': n}, {'category_id': OCAT[c], 'is_active': 'yes'})
        for k, n, c in [('appx', 'Laparoscopic Appendectomy', 'gen'), ('hernia', 'Inguinal Hernia Repair', 'gen'),
                        ('lscs', 'LSCS (Caesarean Section)', 'obg'), ('orif', 'ORIF - Forearm Fracture', 'ortho')]}

emit('')
emit('-- Front office / finance / inventory / HR masters')
VP = {k: goc('vp_' + k, 'visitors_purpose', {'visitors_purpose': n}, {'description': n})
      for k, n in [('visit', 'Patient Visit'), ('meet', 'Meeting'), ('vendor', 'Vendor / Supplier'), ('inquiry', 'Inquiry'), ('interview', 'Interview')]}
SRC = {k: goc('src_' + k, 'source', {'source': n}, {'description': n})
       for k, n in [('walkin', 'Walk-in'), ('phone', 'Phone Call'), ('online', 'Website / Online'), ('ref', 'Doctor Referral')]}
CT = {k: goc('cmt_' + k, 'complaint_type', {'complaint_type': n}, {'description': n})
      for k, n in [('wait', 'Long waiting time'), ('billing', 'Billing issue'), ('clean', 'Cleanliness'), ('staff', 'Staff behaviour')]}
IH = {k: goc('ih_' + k, 'income_head', {'income_category': n}, {'description': n, 'is_active': 'yes', 'is_deleted': 'no'})
      for k, n in [('canteen', 'Canteen Rent'), ('camp', 'Health Camp'), ('scrap', 'Scrap Sale'), ('parking', 'Parking Fees')]}
EH = {k: goc('eh_' + k, 'expense_head', {'exp_category': n}, {'description': n, 'is_active': 'yes', 'is_deleted': 'no'})
      for k, n in [('elec', 'Electricity Bill'), ('water', 'Water Bill'), ('maint', 'Building Maintenance'), ('oxygen', 'Medical Oxygen'),
                   ('laundry', 'Laundry'), ('internet', 'Internet & Telephone'), ('stationery', 'Stationery')]}
ICAT = {k: goc('ic_' + k, 'item_category', {'item_category': n}, {'is_active': 'yes', 'description': n})
        for k, n in [('linen', 'Linen'), ('surg', 'Surgical Consumables'), ('stat', 'Stationery'), ('clean', 'Housekeeping'), ('equip', 'Equipment')]}
ISTORE = {k: goc('is_' + k, 'item_store', {'item_store': n}, {'code': code, 'description': n})
          for k, n, code in [('main', 'Main Store', 'MS'), ('ot', 'OT Store', 'OTS'), ('ward', 'Ward Store', 'WS')]}
ISUP = {k: goc('isu_' + k, 'item_supplier', {'item_supplier': n},
               {'phone': ph, 'email': em, 'address': ad, 'contact_person_name': cp, 'contact_person_phone': ph, 'contact_person_email': em, 'description': ''})
        for k, n, ph, em, ad, cp in [('medline', 'Medline Surgicals', '9822000201', 'sales@medline.example.com', 'Dhantoli, Nagpur', 'Vinod Kale'),
                                     ('office', 'Office Point Stationers', '9822000202', 'orders@officepoint.example.com', 'Sadar, Nagpur', 'Kiran Jain')]}
LT = {k: goc('lt_' + k, 'leave_types', {'type': n}, {'is_active': 'yes'})
      for k, n in [('casual', 'Casual Leave'), ('sick', 'Sick Leave'), ('earned', 'Earned Leave')]}
ORG = {k: goc('org_' + k, 'organisation', {'organisation_name': n},
              {'code': code, 'contact_no': ph, 'address': ad, 'contact_person_name': cp, 'contact_person_phone': ph})
       for k, n, code, ph, ad, cp in [('star', 'Star Health Insurance (Demo TPA)', 'STAR', '1800000001', 'Mumbai, Maharashtra', 'Claims Desk'),
                                      ('cghs', 'CGHS (Demo)', 'CGHS', '1800000002', 'Nagpur, Maharashtra', 'CGHS Wellness Centre')]}
RC = {k: goc('rfc_' + k, 'referral_category', {'name': n}, {'is_active': 1}) for k, n in [('doc', 'Doctor'), ('clinic', 'Clinic')]}
for k, t in [('present', 'Present'), ('late', 'Late'), ('absent', 'Absent'), ('half', 'Half Day')]:
    emit(f"SET @att_{k} = (SELECT id FROM staff_attendance_type WHERE type = {q(t)} ORDER BY id LIMIT 1);")
for k, n in [('normal', 'Normal'), ('urgent', 'Urgent')]:
    emit(f"SET @prio_{k} = (SELECT id FROM appoint_priority WHERE appoint_priority = {q(n)} ORDER BY id LIMIT 1);")
for k, n in [('admin', 'Admin'), ('acct', 'Accountant'), ('doctor', 'Doctor'), ('pharm', 'Pharmacist'), ('path', 'Pathologist'),
             ('radio', 'Radiologist'), ('recep', 'Receptionist'), ('nurse', 'Nurse')]:
    emit(f"SET @role_{k} = (SELECT id FROM roles WHERE name = {q(n)} ORDER BY id LIMIT 1);")

# ---------------------------------------------------------------------------
section('STAFF (login: email below / password Demo@123)')
STAFF = {}
staff_rows = [
    # key, emp, first, last, gender, dept, desig, role, specialists, qualification, exp, salary
    ('dr_desai', 'DMO701', 'Anil', 'Deshpande', 'Male', 'med', 'cons', 'doctor', ['gp'], 'MBBS, MD (Medicine)', '15 years', 150000),
    ('dr_rao', 'DMO702', 'Kavita', 'Rao', 'Female', 'paed', 'cons', 'doctor', ['paed'], 'MBBS, MD (Paediatrics)', '10 years', 130000),
    ('dr_khan', 'DMO703', 'Sameer', 'Khan', 'Male', 'ortho', 'cons', 'doctor', ['ortho'], 'MBBS, MS (Ortho)', '12 years', 160000),
    ('dr_menon', 'DMO704', 'Lakshmi', 'Menon', 'Female', 'gyn', 'cons', 'doctor', ['gyn'], 'MBBS, MS (OBGY)', '14 years', 160000),
    ('dr_bhatt', 'DMO705', 'Nikhil', 'Bhatt', 'Male', 'cardio', 'cons', 'doctor', ['cardio'], 'MBBS, MD, DM (Cardiology)', '18 years', 220000),
    ('dr_patil', 'DMO706', 'Ravi', 'Patil', 'Male', 'surg', 'cons', 'doctor', ['surg', 'anaes'], 'MBBS, MS (General Surgery)', '11 years', 170000),
    ('nurse_pooja', 'DMN701', 'Pooja', 'Thakre', 'Female', 'nurse', 'nurse', 'nurse', [], 'B.Sc Nursing', '6 years', 32000),
    ('nurse_sunita', 'DMN702', 'Sunita', 'Gawande', 'Female', 'nurse', 'nurse', 'nurse', [], 'GNM', '9 years', 30000),
    ('pharm_amit', 'DMP701', 'Amit', 'Joshi', 'Male', 'pharm', 'pharm', 'pharm', [], 'B.Pharm', '7 years', 28000),
    ('lab_meena', 'DML701', 'Meena', 'Wagh', 'Female', 'lab', 'labt', 'path', ['path'], 'DMLT, B.Sc', '8 years', 27000),
    ('radio_vijay', 'DMR701', 'Vijay', 'Ingle', 'Male', 'radio', 'radio', 'radio', ['radio'], 'B.Sc Radiography', '5 years', 30000),
    ('recep_sneha', 'DMF701', 'Sneha', 'Pande', 'Female', 'front', 'recep', 'recep', [], 'B.Com', '3 years', 18000),
    ('acct_rahul', 'DMA701', 'Rahul', 'Agrawal', 'Male', 'acct', 'acct', 'acct', [], 'M.Com', '9 years', 40000),
    ('admin_seema', 'DMH701', 'Seema', 'Kulkarni', 'Female', 'admin', 'admin', 'admin', [], 'MHA', '12 years', 75000),
]
for i, (k, emp, fn, ln, g, dept, desig, role, specs, qual, exp, sal) in enumerate(staff_rows):
    sid = nid('staff')
    STAFF[k] = sid
    spec_expr = R("CONCAT_WS(','," + ','.join(str(SPEC[s]) for s in specs) + ")") if specs else ''
    ins('staff', {
        'id': sid, 'employee_id': emp, 'lang_id': 0, 'department_id': DEPT[dept], 'staff_designation_id': DESIG[desig],
        'specialist': spec_expr, 'qualification': qual, 'work_exp': exp, 'specialization': '', 'name': fn, 'surname': ln,
        'father_name': '', 'mother_name': '', 'contact_no': f'98900{70100 + i}', 'emergency_contact_no': f'98900{70200 + i}',
        'email': f'{fn.lower()}.{ln.lower()}@example.com', 'dob': f'{1975 + i % 15}-0{1 + i % 9}-1{i % 9}', 'marital_status': 'Married' if i % 3 else 'Single',
        'date_of_joining': f'{2015 + i % 8}-04-01', 'date_of_leaving': None, 'local_address': 'Nagpur, Maharashtra',
        'permanent_address': 'Nagpur, Maharashtra', 'note': '', 'image': '', 'password': STAFF_PW, 'gender': g,
        'blood_group': ['O+', 'A+', 'B+', 'AB+'][i % 4], 'account_title': f'{fn} {ln}', 'bank_account_no': f'5010{i:08d}',
        'bank_name': 'Demo Bank', 'ifsc_code': 'DEMO0000123', 'bank_branch': 'Nagpur Main', 'payscale': '', 'basic_salary': str(sal),
        'epf_no': '', 'contract_type': 'permanent', 'shift': '', 'location': '', 'facebook': '', 'twitter': '', 'linkedin': '',
        'instagram': '', 'resume': '', 'joining_letter': '', 'resignation_letter': '', 'other_document_name': '', 'other_document_file': '',
        'user_id': 0, 'is_active': 1, 'verification_code': '', 'zoom_api_key': '', 'zoom_api_secret': '', 'pan_number': f'DEMOP{i:04d}X',
        'identification_number': '', 'local_identification_number': '', 'generated_by': 1})
    ins('staff_roles', {'id': nid('staff_roles'), 'role_id': R('@role_' + role), 'staff_id': sid, 'is_active': 0})
for k, fee in [('dr_desai', 500), ('dr_rao', 600), ('dr_khan', 800), ('dr_menon', 800), ('dr_bhatt', 1000), ('dr_patil', 800)]:
    ins('consult_charges', {'id': nid('consult_charges'), 'doctor': STAFF[k], 'standard_charge': fee, 'date': day(60), 'status': 'active'})
S = STAFF

# ---------------------------------------------------------------------------
section('PATIENTS (patient panel login: username pat<id> / password demo123)')
PAT = {}
patients = [
    # key, name, gender, age, blood, mobile, guardian, marital, address, allergy, org
    ('ramesh', 'Ramesh Wankhede', 'Male', 58, 'B+', '9850070001', 'Sushila Wankhede', 'Married', 'Dharampeth, Nagpur', 'Sulfa drugs', None),
    ('sunanda', 'Sunanda Bhagat', 'Female', 46, 'O+', '9850070002', 'Prakash Bhagat', 'Married', 'Manish Nagar, Nagpur', '', 'star'),
    ('arjun', 'Arjun Meshram', 'Male', 8, 'A+', '9850070003', 'Nitin Meshram', 'Single', 'Pratap Nagar, Nagpur', '', None),
    ('farzana', 'Farzana Qureshi', 'Female', 29, 'B-', '9850070004', 'Imran Qureshi', 'Married', 'Mominpura, Nagpur', '', None),
    ('vinod', 'Vinod Choudhary', 'Male', 64, 'O+', '9850070005', 'Asha Choudhary', 'Married', 'Civil Lines, Nagpur', 'Penicillin', 'cghs'),
    ('priyanka', 'Priyanka Sontakke', 'Female', 31, 'AB+', '9850070006', 'Amol Sontakke', 'Married', 'Wardha Road, Nagpur', '', 'star'),
    ('kunal', 'Kunal Dhote', 'Male', 24, 'A-', '9850070007', 'Mohan Dhote', 'Single', 'Hingna, Nagpur', '', None),
    ('shabana', 'Shabana Pathan', 'Female', 52, 'O-', '9850070008', 'Salim Pathan', 'Married', 'Gittikhadan, Nagpur', '', None),
    ('ganesh', 'Ganesh Raut', 'Male', 41, 'B+', '9850070009', 'Manda Raut', 'Married', 'Kamptee Road, Nagpur', '', None),
    ('anjali', 'Anjali Borkar', 'Female', 19, 'A+', '9850070010', 'Dilip Borkar', 'Single', 'Sadar, Nagpur', 'Dust allergy', None),
    ('suresh', 'Suresh Nimje', 'Male', 71, 'AB-', '9850070011', 'Ramesh Nimje (son)', 'Widowed', 'Mahal, Nagpur', '', 'cghs'),
    ('meera', 'Meera Lanjewar', 'Female', 35, 'O+', '9850070012', 'Sandeep Lanjewar', 'Married', 'Trimurti Nagar, Nagpur', '', None),
    ('aarav', 'Aarav Thakur', 'Male', 3, 'B+', '9850070013', 'Rohit Thakur', 'Single', 'Besa, Nagpur', '', None),
    ('rekha', 'Rekha Ghodmare', 'Female', 49, 'A+', '9850070014', 'Shankar Ghodmare', 'Married', 'Koradi Road, Nagpur', '', None),
    ('imran', 'Imran Ansari', 'Male', 37, 'O+', '9850070015', 'Rubina Ansari', 'Married', 'Jafar Nagar, Nagpur', '', None),
    ('kalpana', 'Kalpana Wasnik', 'Female', 27, 'B+', '9850070016', 'Rahul Wasnik', 'Married', 'Indora, Nagpur', '', None),
    ('devendra', 'Devendra Kale', 'Male', 55, 'A+', '9850070017', 'Usha Kale', 'Married', 'Ramdaspeth, Akola', '', None),
    ('neha', 'Neha Chafle', 'Female', 22, 'O-', '9850070018', 'Vilas Chafle', 'Single', 'Wadi, Nagpur', '', None),
]
for i, (k, name, g, age, bg, mob, guard, mar, addr, allergy, org) in enumerate(patients):
    pid = nid('patients')
    PAT[k] = pid
    ins('patients', {
        'id': pid, 'lang_id': 0, 'patient_name': name, 'dob': R(f'(@today - INTERVAL {age} YEAR - INTERVAL {17 * i % 300} DAY)'),
        'age': age, 'month': 0, 'day': 0, 'as_of_date': day(0), 'image': '', 'mobileno': mob,
        'email': name.lower().replace(' ', '.') + '@example.com', 'gender': g, 'marital_status': mar, 'blood_group': '',
        'blood_bank_product_id': BLOOD[bg], 'address': addr + ', Maharashtra', 'guardian_name': guard, 'patient_type': '',
        'identification_number': '', 'known_allergies': allergy, 'note': '', 'is_ipd': '', 'app_key': '',
        'organisation_id': ORG[org] if org else None, 'insurance_id': f'POL-{org.upper()}-{pid}' if org else '',
        'insurance_validity': day(-365) if org else None, 'is_dead': 'no', 'is_antenatal': 0, 'is_active': 'yes', 'generated_by': S['recep_sneha']})
    ins('users', {'id': nid('users'), 'user_id': pid, 'username': f'pat{pid}', 'password': PATIENT_PW, 'childs': '', 'role': 'patient',
                  'verification_code': '', 'is_active': 'yes'})
P = PAT


def txn(section_name, patient, amount, days_ago, hm, mode='Cash', received_by=None, **links):
    tid = nid('transactions')
    row = {'id': tid, 'type': 'payment', 'section': section_name, 'patient_id': patient, 'amount': amount,
           'processing_charge_type': '', 'gateway_processing_charge': 0, 'payment_mode': mode,
           'cheque_no': '000412' if mode == 'Cheque' else None, 'cheque_date': day(days_ago) if mode == 'Cheque' else None,
           'payment_date': dt(days_ago, hm), 'note': '', 'received_by': received_by or S['recep_sneha']}
    row.update(links)
    ins('transactions', row)
    return tid


def new_case():
    cid = nid('case_references')
    ins('case_references', {'id': cid, 'bill_id': None, 'discount_percentage': 0})
    return cid


# ---------------------------------------------------------------------------
section('APPOINTMENTS')
appointments = [
    # patient, doctor, spec, days_ago, time, status, source, priority, msg, paid
    ('sunanda', 'dr_menon', 'gyn', 0, '10:30', 'approved', 'Offline', 'normal', 'Irregular periods, follow-up', True),
    ('kunal', 'dr_khan', 'ortho', 0, '11:00', 'approved', 'Online', 'normal', 'Right knee pain after football', True),
    ('anjali', 'dr_desai', 'gp', 0, '12:15', 'pending', 'Online', 'normal', 'Fever and sore throat since 2 days', False),
    ('devendra', 'dr_bhatt', 'cardio', -1, '10:00', 'approved', 'Offline', 'urgent', 'Chest heaviness on walking', True),
    ('meera', 'dr_menon', 'gyn', -2, '16:00', 'pending', 'Online', 'normal', 'Antenatal check-up', False),
    ('aarav', 'dr_rao', 'paed', -1, '17:30', 'approved', 'Offline', 'normal', 'Vaccination and growth check', True),
    ('neha', 'dr_desai', 'gp', 2, '11:45', 'cancel', 'Online', 'normal', 'Headache', False),
    ('rekha', 'dr_khan', 'ortho', 3, '12:00', 'approved', 'Offline', 'normal', 'Low back pain', True),
]
for pat, doc, spec, d, hm, status, src, prio, msg, paid in appointments:
    aid = nid('appointment')
    fee = 800 if spec in ('gyn', 'ortho') else (1000 if spec == 'cardio' else (600 if spec == 'paed' else 500))
    ins('appointment', {'id': aid, 'patient_id': P[pat], 'case_reference_id': None, 'visit_details_id': None, 'date': dt(d, hm),
                        'priority': R('@prio_' + prio), 'specialist': SPEC[spec], 'doctor': S[doc], 'amount': fee, 'message': msg,
                        'appointment_status': status, 'source': src, 'is_opd': 'no', 'is_ipd': 'no', 'is_queue': 0,
                        'created_time': dt(max(d, 0) + 2, '09:00'), 'live_consult': 'no', 'created_by': S['recep_sneha']})
    if paid:
        mode = 'Online' if src == 'Online' else 'Cash'
        t = txn('Appointment', P[pat], fee, max(d, 0) + 2, '09:05', mode, appointment_id=aid)
        ins('appointment_payment', {'id': nid('appointment_payment'), 'appointment_id': aid, 'charge_id': CH['appt_fee'][0],
                                    'standard_amount': fee, 'tax': 0, 'discount_percentage': 0, 'paid_amount': fee, 'payment_mode': mode,
                                    'payment_type': src, 'transaction_id': str(t), 'note': '', 'date': dt(max(d, 0) + 2, '09:05')})

# ---------------------------------------------------------------------------
section('OPD: visits, charges, payments, prescriptions')
OPD = {}
VISIT = {}
opd_visits = [
    # key, patient, doctor, days_ago, time, charge, symptoms, vitals(bp,ht,wt,pulse,temp,resp), note, rx[(med,dose,interval,duration,instr)], mode
    ('ramesh1', 'ramesh', 'dr_desai', 6, '10:15', 'cons_gen', 'Fever with chills since 3 days, body ache', ('130/84', '168', '72', '96', '101.4', '20'),
     'Suspected viral fever. CBC advised.', [('para', '1tab', 'tds', '5d', 'After food'), ('cetz', '1tab', 'hs', '5d', '')], 'Cash'),
    ('sunanda1', 'sunanda', 'dr_menon', 5, '11:00', 'cons_spl', 'Irregular menstrual cycles, fatigue', ('118/76', '158', '64', '82', '98.4', '16'),
     'Thyroid profile and USG pelvis advised.', [('shelcal', '1tab', 'od', '1m', 'After lunch')], 'UPI'),
    ('arjun1', 'arjun', 'dr_rao', 4, '17:20', 'cons_spl', 'Cough and cold, mild fever', ('100/60', '126', '24', '104', '100.2', '24'),
     'URTI. Steam inhalation, plenty of fluids.', [('calsyr', '5ml', 'tds', '3d', 'If fever > 100 F'), ('cetz', '1tab', 'hs', '3d', 'Half tablet')], 'Cash'),
    ('vinod1', 'vinod', 'dr_bhatt', 4, '12:30', 'cons_spl', 'Breathlessness on exertion, pedal oedema', ('150/94', '170', '81', '92', '98.2', '22'),
     'Post-discharge review. Known hypertensive with heart failure. ECG and lipid profile advised.', [('telma', '1tab', 'od', '1m', 'Morning'), ('atorva', '1tab', 'hs', '1m', ''), ('eco', '1tab', 'od', '1m', 'After lunch')], 'Cash'),
    ('farzana1', 'farzana', 'dr_menon', 3, '10:45', 'cons_spl', 'Antenatal visit - 32 weeks', ('112/72', '160', '66', '88', '98.4', '16'),
     'G2P1, 32 weeks. Foetal movements good. Continue supplements.', [('shelcal', '1tab', 'od', '1m', 'After food')], 'UPI'),
    ('ganesh1', 'ganesh', 'dr_patil', 3, '15:10', 'cons_spl', 'Pain in right lower abdomen since morning, vomiting', ('128/82', '172', '70', '102', '100.6', '20'),
     'Tenderness RIF. ? Acute appendicitis. USG and CBC STAT. Admitted.', [], 'Cash'),
    ('kunal1', 'kunal', 'dr_khan', 0, '11:05', 'cons_spl', 'Right knee pain after football, swelling', ('120/78', '178', '74', '84', '98.6', '16'),
     'Medial collateral ligament strain. Knee brace, rest, ice.', [('volini', 'thin', 'tds', '7d', 'Local application'), ('para', '1tab', 'sos', '5d', 'For pain')], 'UPI'),
    ('anjali1', 'anjali', 'dr_desai', 2, '12:20', 'cons_gen', 'Sore throat and fever since 2 days', ('110/70', '160', '52', '98', '100.8', '18'),
     'Acute pharyngitis.', [('azi', '1tab', 'od', '3d', 'Empty stomach'), ('para', '1tab', 'tds', '3d', 'After food')], 'Cash'),
    ('shabana1', 'shabana', 'dr_desai', 2, '10:00', 'cons_gen', 'Increased thirst and frequent urination', ('136/86', '155', '78', '86', '98.4', '16'),
     'Known T2DM, poor control. HbA1c and KFT advised.', [('glyc', '1tab', 'bd', '1m', 'After food')], 'Cash'),
    ('aarav1', 'aarav', 'dr_rao', 1, '17:40', 'cons_spl', 'Loose motions 5-6 times since yesterday', ('90/60', '95', '14', '118', '99.2', '26'),
     'Acute gastroenteritis with mild dehydration. ORS.', [('ors', '10ml', 'qid', '3d', 'After every loose stool')], 'Cash'),
    ('imran1', 'imran', 'dr_khan', 1, '13:00', 'cons_spl', 'Fall from two-wheeler, pain right forearm', ('124/80', '174', '76', '96', '98.6', '18'),
     'Swelling and deformity right forearm. X-ray: fracture both bones. Admitted for ORIF.', [], 'Cash'),
    ('ramesh2', 'ramesh', 'dr_desai', 1, '11:30', 'cons_follow', 'Follow-up: fever subsided', ('126/80', '168', '71', '84', '98.4', '16'),
     'Recovering well. CBC normal.', [('becos', '1cap', 'od', '10d', 'After food')], 'Cash'),
    ('rekha1', 'rekha', 'dr_khan', 0, '10:20', 'cons_spl', 'Low back pain radiating to left leg', ('132/84', '156', '69', '80', '98.2', '16'),
     'Lumbar radiculopathy. Physiotherapy advised.', [('para', '1tab', 'bd', '5d', 'After food'), ('pan', '1tab', 'od', '5d', 'Before breakfast')], 'Cash'),
    ('suresh1', 'suresh', 'dr_bhatt', 0, '12:00', 'cons_spl', 'Chest pain and giddiness', ('164/98', '165', '68', '58', '98.2', '20'),
     'Bradycardia, ? heart block. ECG done. Shifted to ICU.', [], 'Cash'),
]
for k, pat, doc, d, hm, ch, sym, (bp, ht, wt, pulse, temp, resp), note, rx, mode in opd_visits:
    if k.endswith('1'):
        case = new_case()
        oid = nid('opd_details')
        ins('opd_details', {'id': oid, 'case_reference_id': case, 'patient_id': P[pat], 'generated_by': S['recep_sneha'],
                            'is_ipd_moved': 0, 'discharged': 'yes' if d >= 4 else 'no'})
        OPD[pat] = (oid, case)
    oid, case = OPD[pat]
    price = CH[ch][1]
    t = txn('OPD', P[pat], price, d, hm, mode, case_reference_id=case, opd_id=oid)
    pc = nid('patient_charges')
    ins('patient_charges', {'id': pc, 'date': dt(d, hm), 'ipd_id': None, 'opd_id': oid, 'qty': 1, 'charge_id': CH[ch][0],
                            'standard_charge': price, 'tpa_charge': 0, 'discount_percentage': 0, 'tax': 0, 'apply_charge': price,
                            'amount': price, 'note': ''})
    vid = nid('visit_details')
    VISIT[k] = vid
    ins('visit_details', {'id': vid, 'opd_details_id': oid, 'patient_charge_id': pc, 'transaction_id': t, 'cons_doctor': S[doc],
                          'case_type': '', 'appointment_date': dt(d, hm), 'symptoms': sym, 'bp': bp, 'height': ht, 'weight': wt,
                          'pulse': pulse, 'temperature': temp, 'respiration': resp, 'known_allergies': '', 'patient_old': 'yes' if k.endswith('2') else '',
                          'casualty': 'no', 'refference': '', 'date': day(d), 'note': note, 'payment_mode': mode,
                          'generated_by': S['recep_sneha'], 'live_consult': 'no', 'is_antenatal': 1 if pat == 'farzana' else 0, 'can_delete': 'yes'})
    if rx:
        bid = nid('ipd_prescription_basic')
        ins('ipd_prescription_basic', {'id': bid, 'ipd_id': None, 'visit_details_id': vid, 'attachment': '', 'attachment_name': '',
                                       'header_note': f'<p>Complaints: {sym}</p>', 'footer_note': '<p>Review after 5 days or earlier if needed.</p>',
                                       'finding_description': note, 'is_finding_print': 'yes', 'date': day(d), 'generated_by': S[doc], 'prescribe_by': S[doc]})
        for med, dose, iv, du, instr in rx:
            ins('ipd_prescription_details', {'id': nid('ipd_prescription_details'), 'basic_id': bid, 'pharmacy_id': MED[med],
                                             'dosage': DOSE[dose], 'dose_interval_id': INTV[iv], 'dose_duration_id': DUR[du], 'instruction': instr})

# ---------------------------------------------------------------------------
section('IPD: admissions, beds, charges, notes, discharge')
IPD = {}
admissions = [
    # key, patient, doctor, bed, days_ago_admit, discharged_days_ago, room_charge, symptoms, vitals, org, credit, from_opd
    ('ganesh', 'ganesh', 'dr_patil', 'MW-02', 3, None, 'room_gen', 'Acute pain right iliac fossa with vomiting - ? appendicitis',
     ('128/82', '172', '70', '102', '100.6', '20'), None, '50000', True),
    ('imran', 'imran', 'dr_khan', 'PR-01', 1, None, 'room_pvt', 'Fracture both bones right forearm after road traffic accident',
     ('124/80', '174', '76', '96', '98.6', '18'), None, '75000', True),
    ('suresh', 'suresh', 'dr_bhatt', 'ICU-01', 0, None, 'room_icu', 'Chest pain, giddiness, bradycardia (HR 42) - ? complete heart block',
     ('164/98', '165', '68', '42', '98.2', '20'), 'cghs', '100000', True),
    ('priyanka', 'priyanka', 'dr_menon', 'FW-03', 9, 5, 'room_gen', 'Full term pregnancy with labour pains, previous LSCS',
     ('118/76', '160', '71', '90', '98.6', '18'), 'star', '60000', False),
    ('vinod', 'vinod', 'dr_bhatt', 'MW-05', 8, 4, 'room_gen', 'Acute exacerbation of heart failure - breathlessness, pedal oedema',
     ('156/96', '170', '83', '104', '98.4', '26'), 'cghs', '50000', False),
    ('kalpana', 'kalpana', 'dr_desai', 'FW-01', 2, None, 'room_gen', 'Dengue fever with thrombocytopenia (platelets 62,000)',
     ('104/68', '157', '54', '108', '102.2', '22'), None, '30000', False),
]
for k, pat, doc, bed, d_in, d_out, room, sym, (bp, ht, wt, pulse, temp, resp), org, credit, from_opd in admissions:
    case = OPD[pat][1] if from_opd else new_case()
    if from_opd:
        emit(f"UPDATE opd_details SET is_ipd_moved = 1 WHERE id = {OPD[pat][0]};")
    iid = nid('ipd_details')
    IPD[k] = (iid, case, pat, doc, d_in, d_out)
    ins('ipd_details', {'id': iid, 'patient_id': P[pat], 'case_reference_id': case, 'height': ht, 'weight': wt, 'pulse': pulse,
                        'temperature': temp, 'respiration': resp, 'bp': bp, 'bed': BED[bed], 'bed_group_id': R(f'(SELECT bed_group_id FROM bed WHERE id = {BED[bed]})'),
                        'case_type': '', 'casualty': 'yes' if k in ('imran', 'suresh') else 'no', 'symptoms': sym, 'known_allergies': '',
                        'patient_old': '', 'note': '', 'refference': '', 'cons_doctor': S[doc], 'organisation_id': ORG[org] if org else None,
                        'credit_limit': credit, 'payment_mode': 'Cash', 'date': dt(d_in, '14:30'), 'discharged': 'yes' if d_out is not None else 'no',
                        'live_consult': 'no', 'generated_by': S['recep_sneha'], 'is_antenatal': 1 if pat == 'priyanka' else 0})
    ins('patient_bed_history', {'id': nid('patient_bed_history'), 'case_reference_id': case, 'bed_group_id': R(f'(SELECT bed_group_id FROM bed WHERE id = {BED[bed]})'),
                                'bed_id': BED[bed], 'revert_reason': None, 'from_date': dt(d_in, '14:30'),
                                'to_date': dt(d_out, '11:00') if d_out is not None else None, 'is_active': 'no' if d_out is not None else 'yes'})
    emit(f"UPDATE bed SET is_active = '{'yes' if d_out is not None else 'no'}' WHERE id = {BED[bed]};")
    if d_out is None:
        emit(f"UPDATE patients SET is_ipd = 'yes' WHERE id = {P[pat]};")
    ins('ipd_doctors', {'id': nid('ipd_doctors'), 'ipd_id': iid, 'consult_doctor': S[doc]})
    days = max((d_in - (d_out if d_out is not None else 0)), 1)
    total = 0
    for chk, qty in [(room, days), ('nursing', days), ('doc_visit', days)] + ([('dressing', 1)] if k in ('ganesh', 'imran') else []):
        price = CH[chk][1]
        ins('patient_charges', {'id': nid('patient_charges'), 'date': dt(d_in, '15:00'), 'ipd_id': iid, 'opd_id': None, 'qty': qty,
                                'charge_id': CH[chk][0], 'standard_charge': price, 'tpa_charge': 0, 'discount_percentage': 0, 'tax': 0,
                                'apply_charge': price * qty, 'amount': price * qty, 'note': '',
                                'organisation_id': ORG[org] if org else None})
        total += price * qty
    deposit = 10000 if k != 'suresh' else 25000
    txn('IPD', P[pat], deposit, d_in, '15:10', 'Cash', case_reference_id=case, ipd_id=iid, note='Advance deposit')
    if pat == 'priyanka':
        total += CH['ot_lscs'][1]  # operation charge is added in the OT section
    if d_out is not None and total > deposit:
        txn('IPD', P[pat], total - deposit, d_out, '10:30', 'Online', case_reference_id=case, ipd_id=iid, note='Final settlement')
    # nurse notes and consultant instructions, one per day
    for n in range((d_in - d_out) if d_out is not None else d_in + 1):
        dd = d_in - n
        ins('nurse_note', {'id': nid('nurse_note'), 'date': dt(dd, '20:00'), 'ipd_id': iid, 'staff_id': S['nurse_pooja' if n % 2 == 0 else 'nurse_sunita'],
                           'note': ['Vitals stable. Patient comfortable, taking orally.', 'IV fluids continued. Medicines given as charted.',
                                    'Afebrile. Ambulating with support.', 'Slept well. No fresh complaints.'][n % 4],
                           'comment': '', 'created_by': S['nurse_pooja']})
        ins('consultant_register', {'id': nid('consultant_register'), 'ipd_id': iid, 'date': dt(dd, '09:30'), 'ins_date': day(dd),
                                    'instruction': ['Continue IV antibiotics. Monitor vitals 4 hourly.', 'Repeat CBC tomorrow. Encourage oral fluids.',
                                                    'Plan discharge if afebrile for 24 hours.', 'Review reports and adjust medicines.'][n % 4],
                                    'cons_doctor': S[doc]})
    if d_out is not None:
        ins('discharge_card', {'id': nid('discharge_card'), 'case_reference_id': case, 'opd_details_id': None, 'ipd_details_id': iid,
                               'discharge_by': S[doc], 'discharge_date': dt(d_out, '11:00'), 'discharge_status': 1,
                               'operation': 'LSCS (Caesarean Section)' if pat == 'priyanka' else '',
                               'diagnosis': 'Full term pregnancy, delivered by repeat LSCS - healthy male baby' if pat == 'priyanka'
                               else 'Acute decompensated heart failure, hypertension',
                               'investigations': 'CBC, KFT normal. USG: single live foetus.' if pat == 'priyanka' else 'ECG: LVH. Echo: EF 40%. KFT normal.',
                               'treatment_home': 'Tab. Shelcal 1-0-0 x 1 month, wound care' if pat == 'priyanka' else 'Tab. Telma 40 OD, Tab. Lasix 40 OD, salt restriction',
                               'note': 'Review in OPD after 7 days.'})

# ---------------------------------------------------------------------------
section('OPERATION THEATRE')
ots = [('ganesh', 'appx', 2, '18:00', 'dr_patil', 'Spinal', 'Successful. Inflamed appendix removed laparoscopically.'),
       ('imran', 'orif', 0, '09:30', 'dr_khan', 'General', 'ORIF with plating of radius and ulna done. Uneventful.'),
       ('priyanka', 'lscs', 8, '11:00', 'dr_menon', 'Spinal', 'Repeat LSCS. Live male baby 3.1 kg delivered. Mother stable.')]
for k, op, d, hm, doc, anaes, result in ots:
    iid = IPD[k][0]
    ins('operation_theatre', {'id': nid('operation_theatre'), 'opd_details_id': None, 'ipd_details_id': iid, 'customer_type': None,
                              'operation_id': OPER[op], 'date': dt(d, hm), 'operation_type': '', 'consultant_doctor': S[doc],
                              'ass_consultant_1': 'Dr. Ravi Patil' if doc != 'dr_patil' else 'Dr. Anil Deshpande', 'ass_consultant_2': '',
                              'anesthetist': 'Dr. Ravi Patil' if doc != 'dr_patil' else 'Dr. Sameer Khan', 'anaethesia_type': anaes,
                              'ot_technician': 'Vijay Ingle', 'ot_assistant': 'Pooja Thakre', 'result': result, 'remark': '', 'generated_by': S[doc]})
    price = CH['ot_' + op][1]
    ins('patient_charges', {'id': nid('patient_charges'), 'date': dt(d, hm), 'ipd_id': iid, 'opd_id': None, 'qty': 1,
                            'charge_id': CH['ot_' + op][0], 'standard_charge': price, 'tpa_charge': 0, 'discount_percentage': 0, 'tax': 0,
                            'apply_charge': price, 'amount': price, 'note': 'Operation charges'})

# ---------------------------------------------------------------------------
section('BIRTH & DEATH RECORDS')
ins('birth_report', {'id': nid('birth_report'), 'child_name': 'Baby of Priyanka Sontakke', 'child_pic': '', 'gender': 'Male',
                     'birth_date': dt(8, '11:24'), 'weight': '3.1', 'patient_id': P['priyanka'], 'case_reference_id': IPD['priyanka'][1],
                     'contact': '9850070006', 'mother_pic': '', 'father_name': 'Amol Sontakke', 'father_pic': '',
                     'birth_report': 'Live male baby by repeat LSCS. APGAR 8/9. Cried immediately after birth.', 'document': '',
                     'address': 'Wardha Road, Nagpur, Maharashtra', 'is_active': 'yes', 'generated_by': S['dr_menon']})
# Death record for a patient brought dead to casualty
dpid = nid('patients')
PAT['late'] = dpid
ins('patients', {'id': dpid, 'lang_id': 0, 'patient_name': 'Bhaurao Gedam', 'dob': R('(@today - INTERVAL 82 YEAR)'), 'age': 82, 'month': 0, 'day': 0,
                 'as_of_date': day(0), 'image': '', 'mobileno': '9850070099', 'email': 'bhaurao.gedam@example.com', 'gender': 'Male',
                 'marital_status': 'Widowed', 'blood_group': '', 'blood_bank_product_id': BLOOD['O+'], 'address': 'Kalmeshwar, Nagpur, Maharashtra',
                 'guardian_name': 'Prakash Gedam (son)', 'patient_type': '', 'identification_number': '', 'known_allergies': '', 'note': '',
                 'is_ipd': '', 'app_key': '', 'organisation_id': None, 'insurance_id': '', 'insurance_validity': None, 'is_dead': 'yes',
                 'is_antenatal': 0, 'is_active': 'yes', 'generated_by': S['recep_sneha']})
ins('users', {'id': nid('users'), 'user_id': dpid, 'username': f'pat{dpid}', 'password': PATIENT_PW, 'childs': '', 'role': 'patient',
              'verification_code': '', 'is_active': 'no'})
dcase = new_case()
ins('death_report', {'id': nid('death_report'), 'patient_id': dpid, 'case_reference_id': dcase, 'attachment': '', 'attachment_name': '',
                     'death_date': dt(6, '04:40'), 'guardian_name': 'Prakash Gedam (son)',
                     'death_report': 'Brought to casualty with cardiac arrest. CPR given for 30 minutes. Declared dead at 04:40.',
                     'is_active': 'yes', 'generated_by': S['dr_desai']})

# ---------------------------------------------------------------------------
section('PHARMACY: purchases (stock), sales bills')
BATCH = {}  # med -> (batch id, sale rate, qty)
purchases = [
    ('shree', 'SMD/2026/1182', 20, 'Cash', ['para', 'dolo', 'aug', 'azi', 'pan', 'cetz', 'omez']),
    ('lifeline', 'LPA-55871', 15, 'Cheque', ['glyc', 'telma', 'atorva', 'eco', 'shelcal', 'becos']),
    ('vidarbha', 'VSD/7714/26', 10, 'transfer_to_bank_account', ['calsyr', 'ors', 'ceftri', 'pantoinj', 'asthalin', 'volini', 'moxi']),
]
med_price = {'para': (2.0, 3.0), 'dolo': (2.2, 3.1), 'aug': (14.0, 20.4), 'azi': (16.0, 23.6), 'pan': (8.0, 15.5), 'cetz': (1.2, 2.0),
             'omez': (4.0, 6.5), 'glyc': (1.6, 2.4), 'telma': (6.0, 9.6), 'atorva': (7.5, 11.2), 'eco': (0.4, 0.6), 'shelcal': (7.0, 10.9),
             'becos': (2.6, 3.9), 'calsyr': (28.0, 41.0), 'ors': (14.0, 21.0), 'ceftri': (42.0, 64.0), 'pantoinj': (38.0, 55.0),
             'asthalin': (110.0, 152.0), 'volini': (90.0, 128.0), 'moxi': (75.0, 109.0)}
for supp, inv, d, mode, meds in purchases:
    sbid = nid('supplier_bill_basic')
    total = 0
    rows = []
    for j, m in enumerate(meds):
        cost, mrp = med_price[m]
        qty = 500 if mrp < 30 else 60
        amt = round(cost * qty, 2)
        total += amt
        bid = nid('medicine_batch_details')
        rows.append({'id': bid, 'supplier_bill_basic_id': sbid, 'pharmacy_id': MED[m], 'inward_date': dt(d, '12:00'),
                     'expiry': R(f'(@today + INTERVAL {14 + j} MONTH)'), 'batch_no': f'{m.upper()[:3]}{2600 + j * 7}',
                     'packing_qty': '1', 'purchase_rate_packing': str(cost), 'quantity': str(qty), 'mrp': mrp, 'purchase_price': cost,
                     'tax': 0, 'sale_rate': mrp, 'batch_amount': amt, 'amount': amt, 'available_quantity': qty})
        BATCH[m] = [bid, mrp, qty]
    total = round(total, 2)
    ins('supplier_bill_basic', {'id': sbid, 'invoice_no': inv, 'date': dt(d, '12:00'), 'supplier_id': SUPP[supp], 'file': '', 'total': total,
                                'tax': 0, 'discount': 0, 'net_amount': total, 'note': '', 'payment_mode': mode,
                                'cheque_no': '118842' if mode == 'Cheque' else None, 'cheque_date': day(d) if mode == 'Cheque' else None,
                                'payment_date': dt(d, '12:30'), 'received_by': S['pharm_amit'], 'payment_note': ''})
    for r in rows:
        ins('medicine_batch_details', r)

sales = [
    # patient key or None (walk-in name), doctor name, days_ago, time, items[(med, qty)], discount%, mode
    ('ramesh', 'Dr. Anil Deshpande', 6, '10:40', [('para', 15), ('cetz', 5)], 0, 'Cash'),
    ('arjun', 'Dr. Kavita Rao', 4, '17:50', [('calsyr', 1), ('cetz', 3)], 0, 'Cash'),
    ('vinod', 'Dr. Nikhil Bhatt', 4, '13:00', [('telma', 30), ('atorva', 30), ('eco', 30)], 5, 'UPI'),
    ('anjali', 'Dr. Anil Deshpande', 2, '12:45', [('azi', 3), ('para', 9)], 0, 'Cash'),
    ('shabana', 'Dr. Anil Deshpande', 2, '10:30', [('glyc', 60)], 0, 'Cash'),
    ('aarav', 'Dr. Kavita Rao', 1, '18:05', [('ors', 4)], 0, 'Cash'),
    ('kalpana', 'Dr. Anil Deshpande', 1, '09:00', [('para', 10), ('pantoinj', 3)], 0, 'Cash'),
    ('kunal', 'Dr. Sameer Khan', 0, '11:30', [('volini', 1), ('para', 10)], 0, 'UPI'),
    ('rekha', 'Dr. Sameer Khan', 0, '10:50', [('para', 10), ('pan', 5)], 10, 'Cash'),
]
for pat, docname, d, hm, items, disc, mode in sales:
    pbid = nid('pharmacy_bill_basic')
    case = OPD.get(pat, (None, None))[1]
    if pat == 'kalpana':
        case = IPD['kalpana'][1]
    total = 0
    det = []
    for m, qty in items:
        b = BATCH[m]
        amt = round(b[1] * qty, 2)
        total += amt
        b[2] -= qty
        det.append({'id': nid('pharmacy_bill_detail'), 'pharmacy_bill_basic_id': pbid, 'medicine_batch_detail_id': b[0], 'quantity': str(qty),
                    'sale_price': b[1], 'discount': 0, 'amount': amt})
    total = round(total, 2)
    discount = round(total * disc / 100, 2)
    net = round(total - discount, 2)
    ins('pharmacy_bill_basic', {'id': pbid, 'date': dt(d, hm), 'patient_id': P[pat], 'ipd_prescription_basic_id': None, 'case_reference_id': case,
                                'customer_name': '', 'customer_type': '', 'doctor_name': docname, 'file': '', 'total': total,
                                'discount_percentage': disc, 'discount': discount, 'tax_percentage': 0, 'tax': 0, 'net_amount': net, 'note': '',
                                'generated_by': S['pharm_amit']})
    for r in det:
        ins('pharmacy_bill_detail', r)
    txn('Pharmacy', P[pat], net, d, hm, mode, received_by=S['pharm_amit'], case_reference_id=case, pharmacy_bill_basic_id=pbid)
for m, (bid, _, left) in BATCH.items():
    emit(f'UPDATE medicine_batch_details SET available_quantity = {left} WHERE id = {bid};')

# ---------------------------------------------------------------------------
section('PATHOLOGY: bills, sample collection, reports with values')
path_values = {'hb': ['13.8', '11.2', '14.6', '10.4'], 'wbc': ['14.9', '6.8', '8.2', '3.1'], 'plt': ['240', '310', '198', '62'],
               'chol': ['232', '188'], 'tg': ['176', '142'], 'hdl': ['38', '46'], 'ldl': ['158', '112'], 'hba1c': ['8.9', '6.1'],
               'sgpt': ['34', '68'], 'sgot': ['29', '54'], 'bili': ['0.8', '1.4'], 'urea': ['32', '28'], 'creat': ['1.1', '0.9'],
               'tsh': ['6.8'], 'pus': ['2-3'], 'rbcu': ['0-1']}
used = {}
path_bills = [
    # patient, doctor, days_ago, time, tests, mode, report done?
    ('ramesh', 'dr_desai', 6, '10:30', ['cbc'], 'Cash', True),
    ('sunanda', 'dr_menon', 5, '11:20', ['tsh', 'cbc'], 'UPI', True),
    ('vinod', 'dr_bhatt', 4, '12:50', ['lipid', 'kft'], 'Cash', True),
    ('ganesh', 'dr_patil', 3, '15:20', ['cbc', 'urine'], 'Cash', True),
    ('shabana', 'dr_desai', 2, '10:15', ['hba1c', 'kft', 'lipid'], 'Cash', True),
    ('kalpana', 'dr_desai', 2, '16:00', ['cbc', 'lft'], 'Cash', True),
    ('rekha', 'dr_khan', 0, '10:40', ['cbc'], 'Cash', False),
]
for pat, doc, d, hm, tests, mode, done in path_bills:
    pbid = nid('pathology_billing')
    case = IPD['kalpana'][1] if pat == 'kalpana' else OPD[pat][1]
    total = sum(CH[PTEST[t][1]][1] for t in tests)
    t_id = txn('Pathology', P[pat], total, d, hm, mode, received_by=S['lab_meena'], case_reference_id=case, pathology_billing_id=pbid)
    ins('pathology_billing', {'id': pbid, 'case_reference_id': case, 'ipd_prescription_basic_id': None, 'date': dt(d, hm), 'patient_id': P[pat],
                              'doctor_id': S[doc], 'doctor_name': '', 'total': total, 'discount_percentage': 0, 'discount': 0, 'tax_percentage': 0,
                              'tax': 0, 'net_amount': total, 'transaction_id': t_id, 'note': '', 'generated_by': S['lab_meena']})
    for t in tests:
        var, ch, params = PTEST[t]
        rid = nid('pathology_report')
        ins('pathology_report', {'id': rid, 'pathology_bill_id': pbid, 'pathology_id': var, 'customer_type': None, 'patient_id': P[pat],
                                 'reporting_date': day(d - 1) if done else day(-1), 'parameter_update': day(d) if done else None, 'tax_percentage': 0,
                                 'apply_charge': CH[ch][1], 'collection_date': day(d), 'collection_specialist': S['lab_meena'],
                                 'pathology_center': 'In-house Laboratory', 'approved_by': S['lab_meena'] if done else None,
                                 'patient_name': None, 'description': '', 'pathology_report': None, 'report_name': None, 'pathology_result': ''})
        if done:
            for p in params:
                vals = path_values[p]
                v = vals[used.get(p, 0) % len(vals)]
                used[p] = used.get(p, 0) + 1
                ins('pathology_report_parameterdetails', {
                    'id': nid('pathology_report_parameterdetails'), 'pathology_report_id': rid,
                    'pathology_parameterdetail_id': R(f'(SELECT id FROM pathology_parameterdetails WHERE pathology_id = {var} AND pathology_parameter_id = {PPARAM[p]} ORDER BY id LIMIT 1)'),
                    'pathology_report_value': v})

# ---------------------------------------------------------------------------
section('RADIOLOGY: bills and reports')
radio_bills = [
    ('vinod', 'dr_bhatt', 4, '13:10', ['xray', 'ecg'], 'Cash', {'xray': 'Cardiomegaly with mild pulmonary congestion.', 'ecg': 'Sinus tachycardia, LVH pattern.'}),
    ('sunanda', 'dr_menon', 5, '12:00', ['usg'], 'UPI', {'usg': 'Bulky uterus with small intramural fibroid (1.8 cm). Ovaries normal.'}),
    ('ganesh', 'dr_patil', 3, '15:40', ['usg'], 'Cash', {'usg': 'Non-compressible tubular structure in RIF, 9 mm - acute appendicitis.'}),
    ('imran', 'dr_khan', 1, '13:20', ['xray'], 'Cash', {'xray': 'Displaced fracture of shaft of right radius and ulna.'}),
    ('suresh', 'dr_bhatt', 0, '12:10', ['ecg', 'ct'], 'Cash', {'ecg': 'Complete heart block, ventricular rate 42/min.', 'ct': None}),
]
for pat, doc, d, hm, tests, mode, results in radio_bills:
    rbid = nid('radiology_billing')
    case = OPD[pat][1]
    total = sum(CH[RTEST[t][1]][1] for t in tests)
    t_id = txn('Radiology', P[pat], total, d, hm, mode, received_by=S['radio_vijay'], case_reference_id=case, radiology_billing_id=rbid)
    ins('radiology_billing', {'id': rbid, 'patient_id': P[pat], 'case_reference_id': case, 'ipd_prescription_basic_id': None, 'doctor_id': S[doc],
                              'date': dt(d, hm), 'doctor_name': '', 'total': total, 'discount_percentage': 0, 'discount': 0, 'tax_percentage': 0,
                              'tax': 0, 'net_amount': total, 'transaction_id': t_id, 'note': '', 'generated_by': S['radio_vijay']})
    for t in tests:
        var, ch = RTEST[t]
        res = results.get(t)
        rid = nid('radiology_report')
        ins('radiology_report', {'id': rid, 'radiology_bill_id': rbid, 'radiology_id': var, 'patient_id': P[pat], 'customer_type': None,
                                 'patient_name': None, 'consultant_doctor': '', 'reporting_date': day(d) if res else day(-1),
                                 'parameter_update': day(d) if res else None, 'description': '', 'radiology_report': None, 'report_name': None,
                                 'radiology_result': res or '', 'tax_percentage': 0, 'apply_charge': CH[ch][1], 'radiology_center': 'In-house Imaging',
                                 'generated_by': S['radio_vijay'], 'collection_specialist': S['radio_vijay'], 'collection_date': day(d),
                                 'approved_by': S['radio_vijay'] if res else None})
        if res:
            for pk, val in [('findings', res), ('imp', res.split('.')[0])]:
                ins('radiology_report_parameterdetails', {
                    'id': nid('radiology_report_parameterdetails'), 'radiology_report_id': rid,
                    'radiology_parameterdetail_id': R(f'(SELECT id FROM radiology_parameterdetails WHERE radiology_id = {var} AND radiology_parameter_id = {RPARAM[pk]} ORDER BY id LIMIT 1)'),
                    'radiology_report_value': val[:200]})

# ---------------------------------------------------------------------------
section('BLOOD BANK: donors, donations (bags + components), issues')
donors = [('Sachin Bawankar', 'O+', 'Male', 32, 'Dattatray Bawankar', '9850071001'), ('Pallavi Moon', 'B+', 'Female', 27, 'Vasant Moon', '9850071002'),
          ('Rahul Thool', 'A+', 'Male', 41, 'Shrikrishna Thool', '9850071003'), ('Aslam Shaikh', 'O-', 'Male', 35, 'Yusuf Shaikh', '9850071004'),
          ('Varsha Dongre', 'AB+', 'Female', 30, 'Madhukar Dongre', '9850071005'), ('Tushar Hedau', 'B-', 'Male', 29, 'Ramesh Hedau', '9850071006')]
BAGS = []
for i, (name, bg, g, age, father, ph) in enumerate(donors):
    did = nid('blood_donor')
    ins('blood_donor', {'id': did, 'donor_name': name, 'date_of_birth': R(f'(@today - INTERVAL {age} YEAR)'), 'blood_bank_product_id': BLOOD[bg],
                        'gender': g, 'father_name': father, 'address': 'Nagpur, Maharashtra', 'contact_no': ph})
    d = 20 - i * 3
    cyc = nid('blood_donor_cycle')
    price = CH['blood_unit'][1]
    ins('blood_donor_cycle', {'id': cyc, 'blood_donor_cycle_id': 0, 'blood_bank_product_id': BLOOD[bg], 'blood_donor_id': did,
                              'charge_id': CH['blood_unit'][0], 'donate_date': day(d), 'bag_no': f'BB{cyc}', 'lot': 'L1', 'quantity': 1,
                              'standard_charge': price, 'apply_charge': price, 'amount': price, 'institution': 'In-house donation camp',
                              'note': '', 'discount_percentage': 0, 'tax_percentage': 0, 'volume': '350', 'unit': cu['unit'], 'available': 1})
    txn('Blood Bank', None, 0, d, '11:00', 'Cash', received_by=S['lab_meena'], blood_donor_cycle_id=cyc, note='Donation (no charge)')
    BAGS.append((cyc, bg))
    if i < 3:  # split into components
        for ck, vol in [('prbc', '250'), ('ffp', '200'), ('plt', '50')]:
            comp = nid('blood_donor_cycle')
            ins('blood_donor_cycle', {'id': comp, 'blood_donor_cycle_id': cyc, 'blood_bank_product_id': COMP[ck], 'blood_donor_id': None,
                                      'charge_id': CH['blood_unit'][0], 'donate_date': day(d), 'bag_no': f'BB{cyc}-{ck.upper()}', 'lot': 'L1',
                                      'quantity': 1, 'standard_charge': price, 'apply_charge': price, 'amount': price, 'institution': '',
                                      'note': 'Component', 'discount_percentage': 0, 'tax_percentage': 0, 'volume': vol, 'unit': cu['unit'],
                                      'available': 1})
            if ck == 'plt' and bg == 'A+':
                PLT_FOR_KALPANA = comp
issues = [('ganesh', BAGS[1][0], 2, 'dr_patil', 'Pre-operative, Hb 11.2'),
          ('kalpana', PLT_FOR_KALPANA, 1, 'dr_desai', 'Dengue with platelets 62,000')]
for pat, bag, d, doc, reason in issues:
    bi = nid('blood_issue')
    case = IPD[pat][1]
    price = CH['blood_unit'][1]
    t_id = txn('Blood Bank', P[pat], price, d, '13:00', 'Cash', received_by=S['lab_meena'], case_reference_id=case, blood_issue_id=bi)
    ins('blood_issue', {'id': bi, 'patient_id': P[pat], 'case_reference_id': case, 'blood_donor_cycle_id': bag, 'date_of_issue': dt(d, '13:00'),
                        'hospital_doctor': S[doc], 'reference': reason, 'charge_id': CH['blood_unit'][0], 'standard_charge': price,
                        'tax_percentage': 0, 'discount_percentage': 0, 'amount': price, 'net_amount': price, 'institution': '',
                        'technician': 'Meena Wagh', 'remark': '', 'generated_by': S['lab_meena']})
    emit(f'UPDATE blood_donor_cycle SET available = 0 WHERE id = {bag};')

# ---------------------------------------------------------------------------
section('AMBULANCE: vehicles and calls')
VEH = {}
for k, no, model, yr, vtype, driver, lic, ph in [('a1', 'MH-31-EQ-1021', 'Force Traveller (ALS)', '2022', 'Owned', 'Shankar Dhurve', 'MH3120180012345', '9850072001'),
                                                 ('a2', 'MH-31-FX-4410', 'Maruti Eeco (BLS)', '2023', 'Owned', 'Kailas Uikey', 'MH3120190067812', '9850072002'),
                                                 ('a3', 'MH-40-BG-7788', 'Tata Winger (ALS)', '2021', 'Contractual', 'Javed Khan', 'MH4020170045671', '9850072003')]:
    vid = nid('vehicles')
    VEH[k] = (vid, model, driver)
    ins('vehicles', {'id': vid, 'vehicle_no': no, 'vehicle_model': model[:100], 'manufacture_year': yr, 'vehicle_type': vtype, 'driver_name': driver,
                     'driver_licence': lic, 'driver_contact': ph, 'note': ''})
for pat, veh, d, hm, frm, to, ch, mode in [('imran', 'a1', 1, '12:20', 'Wardha Road accident site', 'Hospital casualty', 'amb_local', 'Cash'),
                                            ('suresh', 'a1', 0, '11:20', 'Mahal, Nagpur', 'Hospital casualty', 'amb_local', 'Cash'),
                                            ('devendra', 'a3', 9, '07:00', 'Ramdaspeth, Akola', 'Hospital', 'amb_out', 'UPI'),
                                            ('vinod', 'a2', 4, '16:00', 'Hospital', 'Civil Lines, Nagpur (home drop)', 'amb_local', 'Cash')]:
    acid = nid('ambulance_call')
    vid, model, driver = VEH[veh]
    price = CH[ch][1]
    t_id = txn('Ambulance', P[pat], price, d, hm, mode, case_reference_id=None, ambulance_call_id=acid)
    ins('ambulance_call', {'id': acid, 'patient_id': P[pat], 'case_reference_id': None, 'vehicle_id': vid, 'contact_no': None,
                           'address': None, 'vehicle_model': model[:20], 'driver': driver, 'date': dt(d, hm), 'call_from': frm, 'call_to': to,
                           'charge_category_id': ccat['amb'], 'charge_id': CH[ch][0], 'standard_charge': price, 'discount_percentage': 0,
                           'discount': 0, 'tax_percentage': 0, 'amount': price, 'net_amount': price, 'transaction_id': t_id, 'note': '',
                           'generated_by': S['recep_sneha']})

# ---------------------------------------------------------------------------
section('FRONT OFFICE: visitors, phone calls, complaints, postal')
for name, purpose, ph, visit_to, rel, n, d, tin, tout, src in [
        ('Manda Raut', 'visit', '9850073001', 'ipd_patient', 'Ganesh Raut', 2, 0, '11:00', '12:00', 'walkin'),
        ('Rubina Ansari', 'visit', '9850073002', 'ipd_patient', 'Imran Ansari', 1, 0, '16:30', '18:00', 'walkin'),
        ('Mahesh Agrawal', 'vendor', '9822000101', 'staff', 'Amit Joshi (Pharmacy)', 1, 1, '15:00', '15:40', 'walkin'),
        ('Dr. Prashant Gupta', 'meet', '9850073004', 'staff', 'Dr. Nikhil Bhatt', 1, 2, '13:00', '13:45', 'phone'),
        ('Rohini Fulzele', 'interview', '9850073005', 'staff', 'Seema Kulkarni (Admin)', 1, 3, '11:00', '12:10', 'online'),
        ('Pankaj Mahajan', 'inquiry', '9850073006', 'staff', 'Front Office', 1, 0, '10:10', '10:20', 'walkin')]:
    ins('visitors_book', {'id': nid('visitors_book'), 'source': R(f'(SELECT source FROM source WHERE id = {SRC[src]})'),
                          'purpose': R(f'(SELECT visitors_purpose FROM visitors_purpose WHERE id = {VP[purpose]})'), 'name': name, 'email': '',
                          'contact': ph, 'id_proof': 'Aadhaar', 'visit_to': visit_to, 'ipd_opd_staff_id': None, 'related_to': rel,
                          'no_of_pepple': n, 'date': day(d), 'in_time': tin, 'out_time': tout, 'note': '', 'image': ''})
for name, ph, d, desc, fu, dur, ctype_ in [('Ashok Lonare', '9850074001', 0, 'Asked about cardiology OPD timings', None, '3 min', 'Incoming'),
                                           ('Swati Kadu', '9850074002', 1, 'Health check-up package rates', 3, '6 min', 'Incoming'),
                                           ('Star Health TPA', '1800000001', 2, 'Pre-authorisation for Priyanka Sontakke', None, '8 min', 'Outgoing'),
                                           ('Medline Surgicals', '9822000201', 3, 'Follow-up on pending surgical gloves order', 1, '4 min', 'Outgoing'),
                                           ('Rajendra Bhoyar', '9850074005', 0, 'Wants home sample collection', -1, '2 min', 'Incoming')]:
    ins('general_calls', {'id': nid('general_calls'), 'name': name, 'contact': ph, 'date': day(d), 'description': desc,
                          'follow_up_date': day(fu) if fu is not None else None, 'call_duration': dur, 'note': '', 'call_type': ctype_})
for ct, src, name, ph, d, desc, action, assigned in [('wait', 'walkin', 'Dilip Borkar', '9850070010', 2, 'Waited 90 minutes for OPD consultation.',
                                                       'Token system explained, apology given.', 'Sneha Pande'),
                                                      ('billing', 'phone', 'Amol Sontakke', '9850070006', 4, 'Insurance amount not adjusted in final bill.',
                                                       'Bill corrected after TPA approval.', 'Rahul Agrawal'),
                                                      ('clean', 'online', 'Asha Choudhary', '9850070005', 1, 'Washroom in general ward not cleaned on time.',
                                                       '', 'Seema Kulkarni')]:
    ins('complaint', {'id': nid('complaint'), 'complaint_type_id': CT[ct], 'source': R(f'(SELECT source FROM source WHERE id = {SRC[src]})'), 'name': name,
                      'contact': ph, 'email': '', 'date': day(d), 'description': desc, 'action_taken': action, 'assigned': assigned,
                      'note': '', 'image': ''})
for ref, to_t, from_t, d, typ, note in [('DSP/2026/101', 'Star Health Insurance, Mumbai', 'Demo Hospital, Nagpur', 3, 'dispatch', 'Claim documents - Priyanka Sontakke'),
                                        ('DSP/2026/102', 'Municipal Corporation, Nagpur', 'Demo Hospital, Nagpur', 1, 'dispatch', 'Monthly birth & death register'),
                                        ('RCV/2026/201', 'Demo Hospital, Nagpur', 'District Health Office', 2, 'receive', 'Circular: Dengue surveillance'),
                                        ('RCV/2026/202', 'Demo Hospital, Nagpur', 'CGHS Wellness Centre', 0, 'receive', 'Empanelment renewal letter')]:
    ins('dispatch_receive', {'id': nid('dispatch_receive'), 'reference_no': ref, 'to_title': to_t, 'address': 'Nagpur, Maharashtra', 'note': note,
                             'from_title': from_t, 'date': day(d), 'image': '', 'type': typ})

# ---------------------------------------------------------------------------
section('FINANCE: income and expenses')
for head, name, inv, d, amt in [('canteen', 'Canteen rent - October', 'INC-2601', 20, 15000), ('parking', 'Parking contractor', 'INC-2602', 15, 8000),
                                ('camp', 'Corporate health camp - MIDC', 'INC-2603', 10, 42000), ('scrap', 'Scrap sale (cartons, plastic)', 'INC-2604', 5, 3200),
                                ('camp', 'School health check-up camp', 'INC-2605', 2, 18500)]:
    ins('income', {'id': nid('income'), 'inc_head_id': IH[head], 'name': name, 'invoice_no': inv, 'date': day(d), 'amount': amt, 'note': '',
                   'is_deleted': 'no', 'documents': '', 'generated_by': S['acct_rahul'], 'is_active': 'yes'})
for head, name, inv, d, amt in [('elec', 'MSEDCL electricity bill', 'EXP-2601', 22, 186000), ('water', 'NMC water bill', 'EXP-2602', 22, 14500),
                                ('oxygen', 'Oxygen cylinders refill (40)', 'EXP-2603', 12, 36000), ('laundry', 'Laundry - first half', 'EXP-2604', 9, 22400),
                                ('maint', 'AC servicing - OT & ICU', 'EXP-2605', 6, 28000), ('internet', 'Broadband + PRI lines', 'EXP-2606', 4, 9800),
                                ('stationery', 'Printer cartridges & paper', 'EXP-2607', 3, 6450), ('oxygen', 'Oxygen cylinders refill (25)', 'EXP-2608', 1, 22500)]:
    ins('expenses', {'id': nid('expenses'), 'exp_head_id': EH[head], 'name': name, 'invoice_no': inv, 'date': day(d), 'amount': amt, 'documents': '',
                     'note': '', 'is_active': 'yes', 'is_deleted': 'no', 'generated_by': S['acct_rahul']})

# ---------------------------------------------------------------------------
section('INVENTORY: items, stock, issues')
ITEM = {}
for k, cat, name, unit, qty in [('bedsheet', 'linen', 'Bedsheet (white)', 'Piece', 200), ('gown', 'linen', 'Patient Gown', 'Piece', 150),
                                ('gloves', 'surg', 'Surgical Gloves (7.0)', 'Box', 80), ('syringe', 'surg', 'Syringe 5 ml', 'Box', 120),
                                ('mask', 'surg', 'Surgical Mask (3 ply)', 'Box', 100), ('paper', 'stat', 'A4 Paper Ream', 'Ream', 60),
                                ('register', 'stat', 'OPD Register', 'Piece', 25), ('phenyl', 'clean', 'Phenyl (5 L)', 'Can', 40),
                                ('bp', 'equip', 'BP Monitor (digital)', 'Piece', 10), ('oxim', 'equip', 'Pulse Oximeter', 'Piece', 15)]:
    iid = nid('item')
    ITEM[k] = (iid, cat)
    ins('item', {'id': iid, 'item_category_id': ICAT[cat], 'name': name, 'unit': unit, 'item_photo': None, 'description': '', 'quantity': 0, 'date': day(25)})
    ins('item_stock', {'id': nid('item_stock'), 'item_id': iid, 'supplier_id': ISUP['office' if cat == 'stat' else 'medline'], 'symbol': '+',
                       'store_id': ISTORE['ot' if cat == 'surg' else 'main'], 'quantity': qty,
                       'purchase_price': {'linen': 180, 'surg': 450, 'stat': 260, 'clean': 320, 'equip': 1800}[cat], 'date': day(25),
                       'attachment': None, 'description': '', 'generated_by': S['admin_seema'], 'is_active': 'yes'})
for item, role, staff, d, ret, qty, returned in [('gloves', 'nurse', 'nurse_pooja', 5, None, 10, 0), ('bedsheet', 'nurse', 'nurse_sunita', 4, None, 40, 0),
                                                 ('paper', 'recep', 'recep_sneha', 3, None, 5, 0), ('bp', 'doctor', 'dr_desai', 10, 2, 1, 1),
                                                 ('oxim', 'nurse', 'nurse_pooja', 1, -6, 2, 0)]:
    iid, cat = ITEM[item]
    ins('item_issue', {'id': nid('item_issue'), 'issue_type': R('@role_' + role), 'issue_to': S[staff], 'issue_by': 'Seema Kulkarni',
                       'issue_date': day(d), 'return_date': day(ret) if ret is not None else None, 'item_category_id': ICAT[cat], 'item_id': iid,
                       'quantity': qty, 'note': '', 'is_returned': returned, 'is_active': 'yes'})

# ---------------------------------------------------------------------------
section('HUMAN RESOURCE: attendance (last 7 days), leave, payroll')
for d in range(1, 8):
    for i, (k, sid) in enumerate(STAFF.items()):
        att = 'present'
        if (i + d) % 11 == 0:
            att = 'absent'
        elif (i + d) % 7 == 0:
            att = 'late'
        elif (i * d) % 13 == 5:
            att = 'half'
        ins('staff_attendance', {'id': nid('staff_attendance'), 'date': day(d), 'staff_id': sid, 'staff_attendance_type_id': R('@att_' + att),
                                 'biometric_attendence': 0, 'qrcode_attendance': 0, 'remark': '', 'is_active': 0,
                                 'in_time': None if att == 'absent' else ('09:40:00' if att == 'late' else '09:00:00'),
                                 'out_time': None if att == 'absent' else ('13:00:00' if att == 'half' else '17:30:00')})
for staff, lt, f, t, status, remark in [('nurse_sunita', 'sick', 3, 2, 'approve', 'Viral fever'), ('dr_rao', 'casual', -5, -7, 'pending', 'Family function'),
                                         ('recep_sneha', 'casual', -2, -2, 'pending', 'Personal work'), ('acct_rahul', 'earned', 20, 16, 'approve', 'Vacation'),
                                         ('radio_vijay', 'casual', 9, 9, 'disapprove', 'Short staffed that day')]:
    ins('staff_leave_request', {'id': nid('staff_leave_request'), 'staff_id': S[staff], 'leave_type_id': LT[lt], 'leave_from': day(f), 'leave_to': day(t),
                                'leave_days': f - t + 1, 'employee_remark': remark, 'admin_remark': '' if status == 'pending' else 'OK',
                                'status': status, 'approved_date': day(t + 1) if status != 'pending' else None, 'applied_by': S[staff],
                                'status_updated_by': S['admin_seema'] if status != 'pending' else None, 'document_file': '', 'date': day(f + 3)})
emit("SET @pm = DATE_FORMAT(@today - INTERVAL 1 MONTH, '%M'), @py = DATE_FORMAT(@today - INTERVAL 1 MONTH, '%Y');")
for i, (k, emp, fn, ln, g, dept, desig, role, specs, qual, exp, sal) in enumerate(staff_rows):
    allow = round(sal * 0.2)
    ded = round(sal * 0.12)
    tax = round(sal * 0.05) if sal > 50000 else 0
    ins('staff_payslip', {'id': nid('staff_payslip'), 'staff_id': STAFF[k], 'basic': sal, 'total_allowance': allow, 'total_deduction': ded,
                          'leave_deduction': 0, 'tax': tax, 'net_salary': sal + allow - ded - tax, 'status': 'paid' if i % 5 else 'generated',
                          'month': R('@pm'), 'year': R('@py'), 'payment_mode': 'transfer_to_bank_account' if i % 5 else '',
                          'payment_date': R("LAST_DAY(@today - INTERVAL 1 MONTH)"), 'remark': '', 'generated_by': S['acct_rahul']})

# ---------------------------------------------------------------------------
section('REFERRALS & CALENDAR')
for name, cat, ph, person, comm in [('Shree Clinic, Hingna', 'clinic', '9850075001', 'Dr. Mohan Raut', 10), ('Dr. Archana Mule (GP)', 'doc', '9850075002', 'Dr. Archana Mule', 8)]:
    ins('referral_person', {'id': nid('referral_person'), 'name': name, 'category_id': RC[cat], 'contact': ph, 'person_name': person,
                            'person_phone': ph, 'standard_commission': comm, 'address': 'Nagpur', 'is_active': 1})
for title, desc, d1, d2, color in [('Free Diabetes Check-up Camp', 'Free HbA1c and sugar testing for all OPD patients.', -5, -5, '#2e7d32'),
                                   ('CME: Management of Dengue', 'Continuing medical education for doctors and nursing staff.', -9, -9, '#1565c0'),
                                   ('Fire Safety Mock Drill', 'Mandatory drill for all departments.', -3, -3, '#c62828')]:
    ins('events', {'id': nid('events'), 'event_title': title, 'event_description': desc, 'start_date': dt(d1, '10:00'), 'end_date': dt(d2, '13:00'),
                   'event_type': 'public', 'event_color': color, 'event_for': '', 'role_id': None, 'is_active': 'yes'})

# ---------------------------------------------------------------------------
body = lines[:]
lines.clear()

emit('-- Smart Hospital: demo data for every module (fictional people, places and numbers).')
emit('-- LOCAL / DEMO DATABASE ONLY. Never run against the live hospital database.')
emit('--')
emit('-- What it creates (dates are relative to the day you run it, so dashboards look current):')
emit('--   Staff (14, all roles)  login: <first>.<last>@example.com / Demo@123  e.g. anil.deshpande@example.com (doctor)')
emit('--   Patients (19)          patient panel: pat7001 .. pat7019 / demo123')
emit('--   Appointments, OPD visits + prescriptions, IPD admissions (current + discharged) with beds, charges,')
emit('--   nurse notes, consultant instructions, discharge cards, operation theatre, birth/death records,')
emit('--   pharmacy purchases/stock/sales, pathology + radiology bills with reports, blood bank donors/components/issues,')
emit('--   ambulance, front office (visitors, calls, complaints, postal), income/expenses, inventory, attendance,')
emit('--   leave, payroll, referrals and events.')
emit('--')
emit('-- Master/lookup rows (departments, charges, beds, medicines, tests...) are matched by name and reused if they')
emit('-- already exist; they are kept on re-run. Everything else uses fixed ids from 7001 up and is replaced on re-run.')
emit('-- Run:  mysql -u <user> -p <database> < DEMO_SEED_ALL_MODULES.sql')
emit('')
emit('SET NAMES utf8mb4;')
emit("SET @today = CURDATE();")
emit('')
emit('-- Safety: stop if ids this seed uses already hold non-demo patients or staff (demo rows use @example.com emails).')
emit("SELECT IF((SELECT COUNT(*) FROM patients WHERE id BETWEEN %d AND %d AND email NOT LIKE '%%@example.com')"
     " + (SELECT COUNT(*) FROM staff WHERE id BETWEEN %d AND %d AND email NOT LIKE '%%@example.com') = 0, 'ok',"
     " (SELECT 'ABORT: ids 7001+ hold real patients/staff - do not run on this database' UNION SELECT 'x')) AS safety_check;"
     % (BASE + 1, nid.n['patients'], BASE + 1, nid.n['staff']))
emit('')
emit('START TRANSACTION;')
emit('SET FOREIGN_KEY_CHECKS = 0;')
emit('')
emit('-- Free beds held by a previous run, then remove its rows')
emit("UPDATE bed b JOIN ipd_details i ON i.bed = b.id SET b.is_active = 'yes' WHERE i.id BETWEEN %d AND %d;" % (min(owned['ipd_details']), max(owned['ipd_details'])))
for t, ids in reversed(list(owned.items())):
    emit(f'DELETE FROM `{t}` WHERE id BETWEEN {min(ids)} AND {max(ids)};')
lines.extend(body)
emit('')
emit('SET FOREIGN_KEY_CHECKS = 1;')
emit('COMMIT;')
emit("SELECT 'Demo data loaded' AS status;")

out = '\n'.join(lines) + '\n'
open(OUT, 'w').write(out)
print('tables:', len(owned), 'rows:', sum(len(v) for v in owned.values()))
