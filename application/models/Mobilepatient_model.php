<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Patient mobile app: the signed-in patient's own records, read-only.
 *
 * Reuses Mobiledoctor_model's history queries and then applies what the web patient panel shows:
 * the admin's patient module switches (permission_patient), only timeline entries marked visible,
 * and no internal staff notes.
 */
class Mobilepatient_model extends CI_Model
{
    /** Patient modules the app understands, by permission_patient.short_code. */
    const MODULES = array('opd', 'ipd', 'pathology', 'radiology', 'my_appointments');

    public function account($user_id)
    {
        $r = $this->db->query("SELECT u.id user_id, u.username, p.id patient_id, p.patient_name name, p.gender, p.dob, p.age, p.month, p.day,
                p.mobileno, p.email, p.image, p.known_allergies, p.address, p.guardian_name,
                COALESCE(NULLIF(bbp.name, ''), p.blood_group) blood_group
            FROM users u JOIN patients p ON p.id = u.user_id LEFT JOIN blood_bank_products bbp ON bbp.id = p.blood_bank_product_id
            WHERE u.id = ? AND u.role = 'patient'", array((int) $user_id))->row_array();
        if (!$r) {
            return null;
        }
        foreach (array('user_id', 'patient_id', 'age', 'month', 'day') as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['image'] = !empty($r['image']) && strpos($r['image'], 'no_image') === false ? base_url($r['image']) : null;
        return $r;
    }

    /** Which sections the hospital has enabled for patients. */
    public function modules()
    {
        $on = array_fill_keys(self::MODULES, false);
        foreach ($this->db->query("SELECT short_code, is_active FROM permission_patient")->result_array() as $m) {
            if (array_key_exists($m['short_code'], $on)) {
                $on[$m['short_code']] = (int) $m['is_active'] === 1;
            }
        }
        return $on;
    }

    /** Everything the patient app shows, as one snapshot. */
    public function snapshot($patient_id, array $modules)
    {
        $this->load->model('mobiledoctor_model');
        $d = $this->mobiledoctor_model->patient_data(array((int) $patient_id), null);

        // Internal staff notes are not shown to patients.
        foreach ($d['patients'] as &$p) {
            unset($p['note']);
        }
        unset($p);

        $d['visits'] = $modules['opd'] ? $this->strip($d['visits'], array('note')) : array();
        $d['admissions'] = $modules['ipd'] ? $this->strip($d['admissions'], array('note', 'assigned_doctor_ids')) : array();
        $d['diagnoses'] = array_values(array_filter($d['diagnoses'], function ($x) use ($modules) {
            return $x['kind'] === 'opd' ? $modules['opd'] : $modules['ipd'];
        }));
        $d['prescriptions'] = array_values(array_filter($d['prescriptions'], function ($rx) use ($modules) {
            return $rx['ipd_id'] ? $modules['ipd'] : $modules['opd'];
        }));
        $d['lab_reports'] = array_values(array_filter($d['lab_reports'], function ($r) use ($modules) {
            return $r['kind'] === 'radiology' ? $modules['radiology'] : $modules['pathology'];
        }));

        // Same rule as the web patient dashboard: only timeline entries marked visible.
        $visible = array();
        foreach ($this->db->query("SELECT id FROM patient_timeline WHERE patient_id = ? AND status = 'yes'", array((int) $patient_id))->result_array() as $t) {
            $visible[(int) $t['id']] = true;
        }
        $d['timeline'] = array_values(array_filter($d['timeline'], function ($t) use ($visible) {
            return isset($visible[$t['id']]);
        }));

        // Specialty records (vaccinations, follow-ups, growth, pregnancy) are the patient's own; drop deleted ones.
        $d['clinical_records'] = array_values(array_filter($d['clinical_records'], function ($r) {
            return empty($r['deleted']);
        }));

        $d['appointments'] = $modules['my_appointments'] ? $this->appointments($patient_id) : array();
        return $d;
    }

    private function appointments($patient_id)
    {
        $rows = $this->db->query("SELECT a.id, a.date, a.appointment_status status, a.visit_details_id visit_id, ap.appoint_priority priority,
                a.message, a.source, a.live_consult, TRIM(CONCAT(COALESCE(s.name, ''), ' ', COALESCE(s.surname, ''))) doctor_name,
                d.department_name department
            FROM appointment a LEFT JOIN staff s ON s.id = a.doctor LEFT JOIN department d ON d.id = s.department_id
            LEFT JOIN appoint_priority ap ON ap.id = a.priority
            WHERE a.patient_id = ? AND a.date >= NOW() - INTERVAL 2 YEAR
            ORDER BY a.date DESC LIMIT 100", array((int) $patient_id))->result_array();
        foreach ($rows as &$r) {
            $r['id']       = (int) $r['id'];
            $r['visit_id'] = $r['visit_id'] ? (int) $r['visit_id'] : null;
        }
        return $rows;
    }

    private function strip(array $rows, array $keys)
    {
        foreach ($rows as &$r) {
            foreach ($keys as $k) {
                unset($r[$k]);
            }
        }
        return $rows;
    }
}
