-- IPD Case Sheet demo data (fictional patient). LOCAL TEST DATABASE ONLY - never run against the live hospital database.
-- Creates patient #209 'Aditya Wankhede', case 9004, IPD admission 8003 (bed GW-03) and a full case sheet:
-- admission + Marathi consent, doctor's notes, 5 days of nursing charts, medication chart with charted doses, discharge summary + Rx.
-- Re-runnable: removes its own rows first. Open: admin/casesheet/index/8003
SET NAMES utf8mb4;
DELETE a FROM ipd_med_admin a JOIN ipd_med_chart m ON m.id = a.med_id WHERE m.ipd_id = 8003;
DELETE FROM ipd_med_chart WHERE ipd_id = 8003;
DELETE FROM ipd_nursing_chart WHERE ipd_id = 8003;
DELETE FROM ipd_casesheet WHERE ipd_id = 8003;
DELETE FROM patient_bed_history WHERE case_reference_id = 9004;
DELETE FROM ipd_details WHERE id = 8003;
DELETE FROM case_references WHERE id = 9004;
DELETE FROM patients WHERE id = 209;

INSERT INTO patients (id, patient_name, age, month, day, dob, gender, mobileno, email, address, guardian_name, marital_status, blood_group,
  patient_type, identification_number, known_allergies, note, is_ipd, app_key, is_antenatal, is_active)
  VALUES (209, 'Aditya Wankhede', 20, 0, 0, '2006-03-14', 'Male', '9822011209', '', 'Ramdaspeth, Akola, Maharashtra 444001', 'Sunil Wankhede', 'Single', '',
  '', '', 'No known drug allergy', '', 'yes', '', 0, 'yes');
INSERT INTO case_references (id, bill_id) VALUES (9004, NULL);
INSERT INTO ipd_details (id, patient_id, case_reference_id, height, weight, pulse, temperature, respiration, bp, bed, bed_group_id, case_type, casualty,
  symptoms, known_allergies, patient_old, note, refference, cons_doctor, credit_limit, payment_mode, date, discharged, is_antenatal, created_at)
  VALUES (8003, 209, 9004, '172', '61', '86', '99', '18', '130/80', 3, 1, '', 'no', 'Pain and itching all over body after fall from bike', '', '', '', '', 12, '20000',
  'Cash', '2026-10-12 13:45:00', 'no', 0, '2026-10-12 13:45:00');
INSERT INTO patient_bed_history (case_reference_id, bed_group_id, bed_id, from_date, is_active) VALUES (9004, 1, 3, '2026-10-12 13:45:00', 'yes');
INSERT INTO ipd_casesheet (ipd_id, admitted_by_name, admitted_by_relation, admitted_by_phone, consent_language, consent_signed_by, consent_witness1, consent_witness2, consent_at, chief_complaints, history, examination, admission_vitals, provisional_diagnosis, lab_findings, initial_advice, final_diagnosis, procedures, course_in_hospital, condition_at_discharge, discharge_advice, diet_advice, follow_up_date, follow_up_note, emergency_note, discharge_at, summary_by, discharge_rx) VALUES (8003, 'Sunil Wankhede', 'Father', '9822011290', 'mr', 'Sunil Wankhede', 'Kavita Wankhede (Mother)', 'Pooja Thakre (Staff Nurse)', '2026-10-12 13:55:00', 'Pain and itching all over body since 2 days
Rash over trunk and limbs
Fever on and off', 'Fall from bike 8 days back; abrasion wounds over face and forearm dressed at a local clinic.
Started oral antibiotic and painkiller outside 3 days back; itching and rash began the next day (? allergic reaction).', 'Conscious, oriented. Abrasion wounds over face and right forearm - healing, mild surrounding redness.
Generalised urticarial rash over trunk and limbs. No lip or eye swelling. Chest clear, CVS S1S2 normal, P/A soft.', '{"pulse": "86", "bp": "130/80", "temp": "99.0", "spo2": "99", "rr": "18", "weight": "61"}', 'Allergic reaction (acute urticaria) with infected abrasion wounds following fall from bike', 'WBC 14,900 /cumm (raised), Hb 13.8 g/dl, Platelets 2.4 lakh
Dengue NS1 - negative', 'Soft diet. Admit in general ward. Stop outside medicines. Wound dressing daily.', 'Acute urticaria (drug-induced allergic reaction) with secondarily infected abrasion wounds over face and right forearm, following fall from two-wheeler. Dengue NS1 negative.', 'Daily wound cleaning and dressing', 'Patient was admitted on 12-10-2026 with pain and itching all over the body, rash and fever, 8 days after a fall from bike. Outside oral medicines were stopped. He was treated with IV antibiotics (Augmentin, changed to Meropenem on day 2 in view of raised WBC and spiking fever), IV antihistamines and steroids (Avil, Dexa, Hydrocort), proton-pump inhibitor, analgesics and IV fluids. Rash subsided by day 3 and fever settled by day 3. Wounds were dressed daily and are healing well. Dengue NS1 was negative.', 'Afebrile for 48 hours, rash subsided, wounds healing with no discharge. Pulse 84/min, BP 120/76 mmHg, SpO2 99%. Taking orally, ambulatory.', 'Keep the wounds clean and dry; dressing on alternate days at nearest clinic.
Avoid the antibiotic and painkiller taken before admission - show this summary to any doctor before taking new medicines.
Plenty of oral fluids. Avoid scratching.', 'Normal diet', '2026-10-21', 'Review in medicine OPD with repeat CBC report', 'Fever above 101 °F, fresh rash or swelling of lips/eyes, difficulty in breathing, pus or increasing redness around the wounds.', '2026-10-16 11:00:00', '12', '[{"drug": "Tab. Augmentin 625 mg", "dose": "1 tab", "frequency": "1-0-1", "timing": "After food", "duration": "5 days", "instruction": "Complete the full course"}, {"drug": "Tab. Allegra 180 mg", "dose": "1 tab", "frequency": "1-0-0", "timing": "After food", "duration": "7 days", "instruction": "For itching"}, {"drug": "Tab. Pan 40 mg", "dose": "1 tab", "frequency": "1-0-0", "timing": "Before food", "duration": "5 days", "instruction": "Empty stomach, before breakfast"}, {"drug": "Tab. Calpol 650 mg", "dose": "1 tab", "frequency": "SOS", "timing": "After food", "duration": "3 days", "instruction": "Only if fever / pain; max 3 tablets a day"}, {"drug": "Oint. Mupirocin 2%", "dose": "Thin layer", "frequency": "1-0-1", "timing": "", "duration": "7 days", "instruction": "Apply on face & forearm wounds after cleaning"}]');
INSERT INTO ipd_nursing_chart (ipd_id, chart_date, special_instructions, vitals, investigations, stat_drugs, iv_fluids, io, insulin, updated_by) VALUES (8003, '2026-10-12', 'Monitor temperature 4 hourly. Watch for lip / eye swelling.', '{"14:00": {"spo2": "99", "temp": "99.6", "hr": "108", "rr": "20", "bp": "140/90", "motion": "Yes", "avpu": "A"}, "18:00": {"spo2": "98", "temp": "100.2", "hr": "110", "rr": "20", "bp": "130/84", "avpu": "A"}, "22:00": {"spo2": "99", "temp": "99.4", "hr": "102", "rr": "18", "bp": "126/80", "avpu": "A"}}', 'CBC
Dengue (NS1)
RBS', '[{"drug": "Inj. Avil", "dose": "2 ml IV", "time": "14:10"}, {"drug": "Inj. Dexa", "dose": "8 mg IV", "time": "14:10"}]', '[{"fluid": "IO NS 500 ml", "quantity": "500 ml", "flowrate": "80 ml/hr", "started": "14:00", "finished": "20:15"}]', '{"evening": {"ivf": "300", "oral": "200", "uo": "2 times"}, "night": {"ivf": "200", "oral": "150", "uo": "1 time"}}', '[]', 1);
INSERT INTO ipd_nursing_chart (ipd_id, chart_date, special_instructions, vitals, investigations, stat_drugs, iv_fluids, io, insulin, updated_by) VALUES (8003, '2026-10-13', 'Inj. Meropenem started - Augmentin stopped. Wound dressing done.', '{"08:00": {"spo2": "99", "temp": "99", "hr": "108", "rr": "20", "bp": "140/90", "motion": "Yes", "avpu": "A"}, "12:00": {"spo2": "98", "temp": "97", "hr": "101", "rr": "18", "bp": "130/90", "avpu": "A"}, "14:00": {"spo2": "99", "temp": "99.6", "hr": "109", "rr": "20", "bp": "117/53", "avpu": "A"}, "18:00": {"spo2": "99", "temp": "98.5", "hr": "108", "rr": "18", "bp": "124/64", "avpu": "A"}, "22:00": {"spo2": "99", "temp": "100.4", "hr": "104", "rr": "18", "bp": "122/70", "avpu": "A"}}', 'Blood culture
CRP
Wound swab C/S', '[{"drug": "Tab. Calpol 650 mg", "dose": "1 tab", "time": "22:30"}]', '[{"fluid": "IO NS 500 ml", "quantity": "500 ml", "flowrate": "80 ml/hr", "started": "12:00", "finished": "18:00"}, {"fluid": "Inj. Dynapar AQ in 100 ml NS", "quantity": "100 ml", "flowrate": "over 30 min", "started": "09:00", "finished": "09:30"}]', '{"morning": {"ivf": "200", "oral": "200", "uo": "2 times"}, "evening": {"ivf": "300", "oral": "300", "uo": "2 times"}, "night": {"oral": "200", "uo": "1 time"}}', '[]', 1);
INSERT INTO ipd_nursing_chart (ipd_id, chart_date, special_instructions, vitals, investigations, stat_drugs, iv_fluids, io, insulin, updated_by) VALUES (8003, '2026-10-14', 'Rash reducing. Encourage oral fluids.', '{"08:00": {"spo2": "99", "temp": "99.2", "hr": "96", "rr": "18", "bp": "124/78", "motion": "Yes", "avpu": "A"}, "14:00": {"spo2": "99", "temp": "98.8", "hr": "92", "rr": "18", "bp": "120/76", "avpu": "A"}, "18:00": {"spo2": "99", "temp": "98.6", "hr": "90", "rr": "16", "bp": "118/74", "avpu": "A"}, "22:00": {"spo2": "99", "temp": "98.4", "hr": "88", "rr": "16", "bp": "118/72", "avpu": "A"}}', 'Repeat CBC', '[]', '[{"fluid": "IO NS 500 ml", "quantity": "500 ml", "flowrate": "60 ml/hr", "started": "10:00", "finished": "18:20"}]', '{"morning": {"ivf": "250", "oral": "400", "uo": "2 times"}, "evening": {"ivf": "250", "oral": "500", "uo": "3 times"}, "night": {"oral": "300", "uo": "1 time"}}', '[]', 1);
INSERT INTO ipd_nursing_chart (ipd_id, chart_date, special_instructions, vitals, investigations, stat_drugs, iv_fluids, io, insulin, updated_by) VALUES (8003, '2026-10-15', 'IV fluids stopped. Ambulate.', '{"08:00": {"spo2": "99", "temp": "98.2", "hr": "86", "rr": "16", "bp": "120/78", "motion": "Yes", "avpu": "A"}, "14:00": {"spo2": "99", "temp": "98.4", "hr": "84", "rr": "16", "bp": "118/76", "avpu": "A"}, "22:00": {"spo2": "99", "temp": "98.2", "hr": "82", "rr": "16", "bp": "116/74", "avpu": "A"}, "06:00": {"spo2": "99", "temp": "98.2", "hr": "84", "rr": "16", "bp": "120/76", "avpu": "A"}}', '', '[]', '[]', '{"morning": {"oral": "600", "uo": "3 times"}, "evening": {"oral": "700", "uo": "3 times"}, "night": {"oral": "300", "uo": "1 time"}}', '[]', 1);
INSERT INTO ipd_nursing_chart (ipd_id, chart_date, special_instructions, vitals, investigations, stat_drugs, iv_fluids, io, insulin, updated_by) VALUES (8003, '2026-10-16', 'Planned for discharge.', '{"08:00": {"spo2": "99", "temp": "98.0", "hr": "84", "rr": "16", "bp": "120/76", "motion": "Yes", "avpu": "A"}}', '', '[]', '[]', '{"morning": {"oral": "400", "uo": "2 times"}}', '[]', 1);
INSERT INTO ipd_med_chart (ipd_id, drug, dose, route, frequency, slots, start_date, stop_date, instruction, ordered_by) VALUES (8003, 'Inj. Augmentin', '1.2 gm', 'IV', 'BD', '08:00,20:00', '2026-10-12', '2026-10-13', 'Test dose given', 12);
SET @m = LAST_INSERT_ID();
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-12', '20:00', 1, '2026-10-12 20:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-13', '08:00', 1, '2026-10-13 08:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-13', '20:00', 1, '2026-10-13 20:07:00');
INSERT INTO ipd_med_chart (ipd_id, drug, dose, route, frequency, slots, start_date, stop_date, instruction, ordered_by) VALUES (8003, 'Inj. Pan', '40 mg', 'IV', 'OD', '08:00', '2026-10-12', '2026-10-15', 'Before breakfast', 12);
SET @m = LAST_INSERT_ID();
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-13', '08:00', 1, '2026-10-13 08:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-14', '08:00', 1, '2026-10-14 08:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-15', '08:00', 1, '2026-10-15 08:07:00');
INSERT INTO ipd_med_chart (ipd_id, drug, dose, route, frequency, slots, start_date, stop_date, instruction, ordered_by) VALUES (8003, 'Inj. Dynapar AQ', '75 mg', 'IV', 'BD', '08:00,20:00', '2026-10-12', '2026-10-14', 'In 100 ml NS over 30 min', 12);
SET @m = LAST_INSERT_ID();
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-12', '20:00', 1, '2026-10-12 20:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-13', '08:00', 1, '2026-10-13 08:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-13', '20:00', 1, '2026-10-13 20:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-14', '08:00', 1, '2026-10-14 08:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-14', '20:00', 1, '2026-10-14 20:07:00');
INSERT INTO ipd_med_chart (ipd_id, drug, dose, route, frequency, slots, start_date, stop_date, instruction, ordered_by) VALUES (8003, 'Inj. Avil', '2 ml', 'IV', 'BD', '08:00,20:00', '2026-10-12', '2026-10-14', '', 12);
SET @m = LAST_INSERT_ID();
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-12', '20:00', 1, '2026-10-12 20:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-13', '08:00', 1, '2026-10-13 08:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-13', '20:00', 1, '2026-10-13 20:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-14', '08:00', 1, '2026-10-14 08:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-14', '20:00', 1, '2026-10-14 20:07:00');
INSERT INTO ipd_med_chart (ipd_id, drug, dose, route, frequency, slots, start_date, stop_date, instruction, ordered_by) VALUES (8003, 'Inj. Dexa', '8 mg', 'IV', 'BD', '08:00,20:00', '2026-10-12', '2026-10-13', '', 12);
SET @m = LAST_INSERT_ID();
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-12', '20:00', 1, '2026-10-12 20:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-13', '08:00', 1, '2026-10-13 08:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-13', '20:00', 1, '2026-10-13 20:07:00');
INSERT INTO ipd_med_chart (ipd_id, drug, dose, route, frequency, slots, start_date, stop_date, instruction, ordered_by) VALUES (8003, 'Inj. Meropenem', '1 gm', 'IV', 'TDS', '08:00,14:00,20:00', '2026-10-13', NULL, '', 12);
SET @m = LAST_INSERT_ID();
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-13', '08:00', 1, '2026-10-13 08:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-13', '14:00', 1, '2026-10-13 14:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-13', '20:00', 1, '2026-10-13 20:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-14', '08:00', 1, '2026-10-14 08:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-14', '20:00', 1, '2026-10-14 20:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-15', '08:00', 1, '2026-10-15 08:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-15', '14:00', 1, '2026-10-15 14:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-15', '20:00', 1, '2026-10-15 20:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-16', '08:00', 1, '2026-10-16 08:07:00');
INSERT INTO ipd_med_chart (ipd_id, drug, dose, route, frequency, slots, start_date, stop_date, instruction, ordered_by) VALUES (8003, 'Inj. Hydrocort', '100 mg', 'IV', 'BD', '08:00,20:00', '2026-10-13', '2026-10-14', '', 12);
SET @m = LAST_INSERT_ID();
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-13', '08:00', 1, '2026-10-13 08:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-13', '20:00', 1, '2026-10-13 20:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-14', '08:00', 1, '2026-10-14 08:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-14', '20:00', 1, '2026-10-14 20:07:00');
INSERT INTO ipd_med_chart (ipd_id, drug, dose, route, frequency, slots, start_date, stop_date, instruction, ordered_by) VALUES (8003, 'Tab. Allegra', '180 mg', 'Oral', 'OD', '08:00', '2026-10-13', NULL, '', 12);
SET @m = LAST_INSERT_ID();
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-13', '08:00', 1, '2026-10-13 08:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-14', '08:00', 1, '2026-10-14 08:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-15', '08:00', 1, '2026-10-15 08:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-16', '08:00', 1, '2026-10-16 08:07:00');
INSERT INTO ipd_med_chart (ipd_id, drug, dose, route, frequency, slots, start_date, stop_date, instruction, ordered_by) VALUES (8003, 'Tab. Atarax', '25 mg', 'Oral', 'TDS', '08:00,14:00,20:00', '2026-10-14', NULL, '', 12);
SET @m = LAST_INSERT_ID();
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-14', '08:00', 1, '2026-10-14 08:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-14', '14:00', 1, '2026-10-14 14:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-14', '20:00', 1, '2026-10-14 20:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-15', '08:00', 1, '2026-10-15 08:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-15', '14:00', 1, '2026-10-15 14:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-15', '20:00', 1, '2026-10-15 20:07:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-16', '08:00', 1, '2026-10-16 08:07:00');
INSERT INTO ipd_med_chart (ipd_id, drug, dose, route, frequency, slots, start_date, stop_date, instruction, ordered_by) VALUES (8003, 'Tab. Calpol', '650 mg', 'Oral', 'SOS', '', '2026-10-12', NULL, 'If temp > 100 °F', 12);
SET @m = LAST_INSERT_ID();
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-13', '22:30', 1, '2026-10-13 22:37:00');
INSERT INTO ipd_med_admin (med_id, admin_date, slot, given_by, given_at) VALUES (@m, '2026-10-14', '06:00', 1, '2026-10-14 06:07:00');
