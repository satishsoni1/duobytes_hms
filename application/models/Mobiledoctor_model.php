<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Doctor mobile app data: which patients live on the device, their history, and prescription writes.
 *
 * Prescriptions are stored exactly like the web screens do (ipd_prescription_basic / _details / _test
 * for both OPD visits and IPD admissions), so they show up unchanged in the web app and print layouts.
 */
class Mobiledoctor_model extends CI_Model
{
    const SCOPE_VISIT_DAYS       = 365; // OPD visits with this doctor
    const SCOPE_APPOINTMENT_DAYS = 30;  // appointments with this doctor (past)
    const RECENT_RX_DAYS         = 30;  // always re-send recent prescriptions (catches web edits that only delete a line)
    const MAX_ITEMS              = 40;
    const MAX_NOTE               = 5000;

    // ================================================================== scope
    /** Patients this doctor treats: OPD visits, IPD admissions (consultant or assigned), appointments. */
    public function scope_patient_ids($staff_id)
    {
        $id   = (int) $staff_id;
        $rows = $this->db->query("SELECT od.patient_id pid FROM visit_details vd JOIN opd_details od ON od.id = vd.opd_details_id
                WHERE vd.cons_doctor = ? AND (vd.appointment_date >= NOW() - INTERVAL " . self::SCOPE_VISIT_DAYS . " DAY OR od.discharged = 'no')
            UNION SELECT ip.patient_id FROM ipd_details ip
                WHERE (ip.cons_doctor = ? OR ip.id IN (SELECT ipd_id FROM ipd_doctors WHERE consult_doctor = ?))
                  AND (ip.discharged = 'no' OR ip.date >= NOW() - INTERVAL " . self::SCOPE_VISIT_DAYS . " DAY)
            UNION SELECT a.patient_id FROM appointment a
                WHERE a.doctor = ? AND a.patient_id IS NOT NULL AND a.date >= CURDATE() - INTERVAL " . self::SCOPE_APPOINTMENT_DAYS . " DAY",
            array($id, $id, $id, $id))->result_array();
        $ids = array();
        foreach ($rows as $r) {
            if ($r['pid']) {
                $ids[(int) $r['pid']] = true;
            }
        }
        return array_keys($ids);
    }

    /** Has this doctor ever treated the patient (used when doctor_restriction is enabled). */
    public function doctor_has_patient($staff_id, $patient_id)
    {
        $id = (int) $staff_id;
        $p  = (int) $patient_id;
        return (bool) $this->db->query("SELECT 1 FROM visit_details vd JOIN opd_details od ON od.id = vd.opd_details_id WHERE od.patient_id = ? AND vd.cons_doctor = ?
            UNION SELECT 1 FROM ipd_details ip WHERE ip.patient_id = ? AND (ip.cons_doctor = ? OR ip.id IN (SELECT ipd_id FROM ipd_doctors WHERE consult_doctor = ?))
            UNION SELECT 1 FROM appointment a WHERE a.patient_id = ? AND a.doctor = ? LIMIT 1",
            array($p, $id, $p, $id, $id, $p, $id))->row_array();
    }

    public function search_patients($q, $limit = 30)
    {
        $q = trim($q);
        $like = '%' . $this->db->escape_like_str($q) . '%';
        $id   = ctype_digit($q) ? (int) $q : -1;
        $rows = $this->db->query("SELECT p.id, p.patient_name name, p.gender, p.age, p.month, p.mobileno, p.dob
            FROM patients p WHERE p.is_active = 'yes' AND (p.id = ? OR p.patient_name LIKE ? OR p.mobileno LIKE ? OR p.identification_number LIKE ?)
            ORDER BY (p.id = ?) DESC, p.patient_name LIMIT " . (int) $limit, array($id, $like, $like, $like, $id))->result_array();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
        }
        return $rows;
    }

    // ================================================================== pull
    /**
     * Patient data for the device. $since === null => everything for these patients.
     * Otherwise only rows changed after $since (MySQL datetime).
     */
    public function patient_data(array $patient_ids, $since = null)
    {
        $out = array('patients' => array(), 'visits' => array(), 'admissions' => array(), 'diagnoses' => array(),
            'prescriptions' => array(), 'vitals' => array(), 'lab_reports' => array(), 'timeline' => array(), 'clinical_records' => array(), 'billing' => array());
        $this->load->model('mobilebilling_model');
        $this->load->model('mobileclinical_model');
        foreach (array_chunk(array_values(array_unique(array_map('intval', $patient_ids))), 300) as $chunk) {
            $in = implode(',', $chunk);
            $out['patients']      = array_merge($out['patients'], $this->patients($in, $since));
            $visits               = $this->visits($in, $since);
            $admissions           = $this->admissions($in, $since);
            $out['visits']        = array_merge($out['visits'], $visits);
            $out['admissions']    = array_merge($out['admissions'], $admissions);
            $out['diagnoses']     = array_merge($out['diagnoses'], $this->diagnoses($in, $since));
            $out['prescriptions'] = array_merge($out['prescriptions'], $this->prescriptions_for_patients($in, $since));
            $out['vitals']        = array_merge($out['vitals'], $this->vitals($in, $since));
            $out['lab_reports']   = array_merge($out['lab_reports'], $this->lab_reports($in, $since));
            $out['timeline']      = array_merge($out['timeline'], $this->timeline($in, $since));
            $out['clinical_records'] = array_merge($out['clinical_records'], $this->mobileclinical_model->for_patients($in, $since));
            $out['billing']          = array_merge($out['billing'], $this->mobilebilling_model->for_patients($in, $since));
        }
        return $out;
    }

    /** All prescription ids for these patients, so the device can drop ones deleted on the web. */
    public function prescription_ids(array $patient_ids)
    {
        $ids = array();
        foreach (array_chunk(array_values(array_unique(array_map('intval', $patient_ids))), 300) as $chunk) {
            $in   = implode(',', $chunk);
            $rows = $this->db->query("SELECT b.id FROM ipd_prescription_basic b
                LEFT JOIN visit_details vd ON vd.id = b.visit_details_id LEFT JOIN opd_details od ON od.id = vd.opd_details_id
                LEFT JOIN ipd_details ip ON ip.id = b.ipd_id
                WHERE od.patient_id IN ($in) OR ip.patient_id IN ($in)")->result_array();
            foreach ($rows as $r) {
                $ids[] = (int) $r['id'];
            }
        }
        return $ids;
    }

    /** This doctor's appointments from 30 days ago onward (drives the "Today" queue). */
    public function appointments($staff_id, $since = null)
    {
        $sql = "SELECT a.id, a.patient_id, a.date, a.appointment_status status, a.visit_details_id visit_id, ap.appoint_priority priority,
                a.message, a.source, a.doctor doctor_id, a.updated_at,
                (SELECT q.position FROM appointment_queue q WHERE q.appointment_id = a.id ORDER BY q.id DESC LIMIT 1) token
            FROM appointment a LEFT JOIN appoint_priority ap ON ap.id = a.priority
            WHERE a.doctor = ? AND a.patient_id IS NOT NULL AND a.date >= CURDATE() - INTERVAL " . self::SCOPE_APPOINTMENT_DAYS . " DAY";
        $args = array((int) $staff_id);
        if ($since !== null) {
            $sql .= " AND a.updated_at > ?";
            $args[] = $since;
        }
        return $this->ints($this->db->query($sql, $args)->result_array(), array('id', 'patient_id', 'visit_id', 'doctor_id', 'token'));
    }

    private function since_clause($cols, $since, &$args)
    {
        if ($since === null) {
            return '';
        }
        $parts = array();
        foreach ((array) $cols as $c) {
            $parts[] = "$c > ?";
            $args[]  = $since;
        }
        return ' AND (' . implode(' OR ', $parts) . ')';
    }

    private function patients($in, $since)
    {
        $args = array();
        $rows = $this->db->query("SELECT p.id, p.patient_name name, p.gender, p.dob, p.age, p.month, p.day, p.mobileno, p.email,
                COALESCE(NULLIF(bbp.name, ''), p.blood_group) blood_group, p.address, p.guardian_name, p.known_allergies, p.note,
                p.identification_number, p.insurance_id, p.is_dead, p.image, p.updated_at
            FROM patients p LEFT JOIN blood_bank_products bbp ON bbp.id = p.blood_bank_product_id
            WHERE p.id IN ($in)" . $this->since_clause('p.updated_at', $since, $args), $args)->result_array();
        foreach ($rows as &$r) {
            $r['image'] = !empty($r['image']) && strpos($r['image'], 'no_image') === false ? base_url($r['image']) : null;
        }
        return $this->ints($rows, array('id', 'age', 'month', 'day'));
    }

    private function visits($in, $since)
    {
        $args = array();
        $rows = $this->db->query("SELECT vd.id, vd.opd_details_id opd_id, od.patient_id, vd.cons_doctor doctor_id,
                TRIM(CONCAT(COALESCE(s.name,''), ' ', COALESCE(s.surname,''))) doctor_name, vd.appointment_date, vd.case_type, vd.symptoms,
                vd.bp, vd.height, vd.weight, vd.pulse, vd.temperature, vd.respiration, vd.known_allergies, vd.note, vd.casualty,
                od.discharged, GREATEST(vd.updated_at, od.updated_at) updated_at,
                COALESCE(
                    (SELECT q.position FROM appointment ap2 JOIN appointment_queue q ON q.appointment_id = ap2.id WHERE ap2.visit_details_id = vd.id ORDER BY q.id DESC LIMIT 1),
                    -- walk-in visits created from the app: token kept in the visit's queue record
                    (SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(cr.data, '$.token')) AS UNSIGNED) FROM clinical_records cr
                        WHERE cr.patient_id = od.patient_id AND cr.record_type = 'opd_status' AND cr.deleted = 0
                          AND JSON_EXTRACT(cr.data, '$.visit_id') = vd.id LIMIT 1)
                ) token
            FROM visit_details vd JOIN opd_details od ON od.id = vd.opd_details_id LEFT JOIN staff s ON s.id = vd.cons_doctor
            WHERE od.patient_id IN ($in)" . $this->since_clause(array('vd.updated_at', 'od.updated_at'), $since, $args), $args)->result_array();
        foreach ($rows as &$r) {
            $r['symptoms'] = $this->plain($r['symptoms']);
            $r['note']     = $this->plain($r['note']);
        }
        return $this->ints($rows, array('id', 'opd_id', 'patient_id', 'doctor_id', 'token'));
    }

    private function admissions($in, $since)
    {
        $args = array();
        $rows = $this->db->query("SELECT ip.id, ip.patient_id, ip.cons_doctor doctor_id,
                TRIM(CONCAT(COALESCE(s.name,''), ' ', COALESCE(s.surname,''))) doctor_name, ip.date, ip.case_type, ip.symptoms,
                ip.bp, ip.height, ip.weight, ip.pulse, ip.temperature, ip.respiration, ip.known_allergies, ip.note, ip.discharged,
                b.name bed_name, bg.name bed_group,
                (SELECT GROUP_CONCAT(d.consult_doctor) FROM ipd_doctors d WHERE d.ipd_id = ip.id) assigned_doctor_ids, ip.updated_at
            FROM ipd_details ip LEFT JOIN staff s ON s.id = ip.cons_doctor LEFT JOIN bed b ON b.id = ip.bed LEFT JOIN bed_group bg ON bg.id = ip.bed_group_id
            WHERE ip.patient_id IN ($in)" . $this->since_clause('ip.updated_at', $since, $args), $args)->result_array();
        foreach ($rows as &$r) {
            $r['symptoms']            = $this->plain($r['symptoms']);
            $r['note']                = $this->plain($r['note']);
            $r['assigned_doctor_ids'] = $r['assigned_doctor_ids'] ? array_map('intval', explode(',', $r['assigned_doctor_ids'])) : array();
        }
        return $this->ints($rows, array('id', 'patient_id', 'doctor_id'));
    }

    /**
     * ICD-10 diagnoses per case. The code link tables have no timestamps, so a delta pull re-sends
     * diagnoses for cases that are open, recent or changed. The device replaces diagnoses per case.
     */
    private function diagnoses($in, $since)
    {
        $opd_filter = $ipd_filter = '';
        $args_o = $args_i = array();
        if ($since !== null) {
            $opd_filter = " AND (od.discharged = 'no' OR od.updated_at > ? OR od.created_at >= NOW() - INTERVAL 30 DAY)";
            $ipd_filter = " AND (ip.discharged = 'no' OR ip.updated_at > ? OR ip.date >= NOW() - INTERVAL 30 DAY)";
            $args_o[] = $since;
            $args_i[] = $since;
        }
        $cases = array();
        foreach ($this->db->query("SELECT od.id FROM opd_details od WHERE od.patient_id IN ($in)$opd_filter", $args_o)->result_array() as $r) {
            $cases[] = array('kind' => 'opd', 'case_id' => (int) $r['id'], 'codes' => array());
        }
        foreach ($this->db->query("SELECT ip.id FROM ipd_details ip WHERE ip.patient_id IN ($in)$ipd_filter", $args_i)->result_array() as $r) {
            $cases[] = array('kind' => 'ipd', 'case_id' => (int) $r['id'], 'codes' => array());
        }
        if (empty($cases)) {
            return array();
        }
        $idx = array();
        foreach ($cases as $i => $c) {
            $idx[$c['kind'] . ':' . $c['case_id']] = $i;
        }
        foreach (array('opd', 'ipd') as $kind) {
            $ids = array();
            foreach ($cases as $c) {
                if ($c['kind'] === $kind) {
                    $ids[] = $c['case_id'];
                }
            }
            if (empty($ids)) {
                continue;
            }
            $t   = $kind === 'opd' ? 'opd_icd10_codes' : 'ipd_icd10_codes';
            $col = $kind === 'opd' ? 'opd_id' : 'ipd_id';
            $rows = $this->db->query("SELECT x.$col case_id, c.id, c.icd_code code, c.icd_description description
                FROM $t x JOIN icd10_codes c ON c.id = x.icd_code_id WHERE x.$col IN (" . implode(',', $ids) . ") ORDER BY c.icd_code")->result_array();
            foreach ($rows as $r) {
                $cases[$idx[$kind . ':' . $r['case_id']]]['codes'][] = array('id' => (int) $r['id'], 'code' => $r['code'], 'description' => $r['description']);
            }
        }
        return $cases;
    }

    private function vitals($in, $since)
    {
        $args = array();
        $rows = $this->db->query("SELECT pv.id, pv.patient_id, v.name, pv.reference_range value, v.unit, v.reference_range normal_range,
                pv.messure_date measured_at, pv.updated_at
            FROM patients_vitals pv JOIN vitals v ON v.id = pv.vital_id
            WHERE pv.patient_id IN ($in)" . $this->since_clause('pv.updated_at', $since, $args), $args)->result_array();
        return $this->ints($rows, array('id', 'patient_id'));
    }

    private function lab_reports($in, $since)
    {
        $out = array();
        // Pathology
        $args = array();
        $rows = $this->db->query("SELECT pr.id, COALESCE(pr.patient_id, pb.patient_id) patient_id, p.test_name, p.short_name,
                pr.collection_date, pr.reporting_date, pr.pathology_result result, pr.description, pr.updated_at,
                pr.pathology_report file_path, pr.report_name file_name
            FROM pathology_report pr JOIN pathology p ON p.id = pr.pathology_id LEFT JOIN pathology_billing pb ON pb.id = pr.pathology_bill_id
            WHERE COALESCE(pr.patient_id, pb.patient_id) IN ($in)" . $this->since_clause('pr.updated_at', $since, $args), $args)->result_array();
        $params = $this->lab_params('pathology', array_column($rows, 'id'));
        foreach ($rows as $r) {
            $r['kind']       = 'pathology';
            $r['parameters'] = isset($params[$r['id']]) ? $params[$r['id']] : array();
            $out[]           = $r;
        }
        // Radiology
        $args = array();
        $rows = $this->db->query("SELECT rr.id, COALESCE(rr.patient_id, rb.patient_id) patient_id, r.test_name, r.short_name,
                rr.collection_date, rr.reporting_date, rr.radiology_result result, rr.description, rr.updated_at,
                rr.radiology_report file_path, rr.report_name file_name
            FROM radiology_report rr JOIN radio r ON r.id = rr.radiology_id LEFT JOIN radiology_billing rb ON rb.id = rr.radiology_bill_id
            WHERE COALESCE(rr.patient_id, rb.patient_id) IN ($in)" . $this->since_clause('rr.updated_at', $since, $args), $args)->result_array();
        $params = $this->lab_params('radiology', array_column($rows, 'id'));
        foreach ($rows as $r) {
            $r['kind']       = 'radiology';
            $r['parameters'] = isset($params[$r['id']]) ? $params[$r['id']] : array();
            $out[]           = $r;
        }
        foreach ($out as &$r) {
            $r['result']      = $this->plain($r['result']);
            $r['description'] = $this->plain($r['description']);
            // Never expose the storage path; the app downloads through report_file with its token.
            $r['has_file']    = $this->report_path($r['kind'], $r['file_path']) !== null;
            $r['file_name']   = $r['has_file'] ? ($r['file_name'] ?: basename((string) $r['file_path'])) : null;
            unset($r['file_path']);
        }
        unset($r);
        return $this->ints($out, array('id', 'patient_id'));
    }

    /**
     * Uploaded report file (PDF/image) for a pathology or radiology report.
     * Returns array(patient_id, absolute_path, download_name) or null.
     */
    public function lab_report_file($kind, $id)
    {
        if ($kind === 'pathology') {
            $r = $this->db->query("SELECT COALESCE(pr.patient_id, pb.patient_id) patient_id, pr.pathology_report path, pr.report_name name
                FROM pathology_report pr LEFT JOIN pathology_billing pb ON pb.id = pr.pathology_bill_id WHERE pr.id = ?", array((int) $id))->row_array();
        } elseif ($kind === 'radiology') {
            $r = $this->db->query("SELECT COALESCE(rr.patient_id, rb.patient_id) patient_id, rr.radiology_report path, rr.report_name name
                FROM radiology_report rr LEFT JOIN radiology_billing rb ON rb.id = rr.radiology_bill_id WHERE rr.id = ?", array((int) $id))->row_array();
        } else {
            return null;
        }
        if (!$r) {
            return null;
        }
        $path = $this->report_path($kind, $r['path']);
        return $path === null ? null : array((int) $r['patient_id'], $path, $r['name'] ?: basename($path));
    }

    /** Resolve a stored report path safely inside uploads/. Stored values are "uploads/..." or a bare file name. */
    private function report_path($kind, $stored)
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return null;
        }
        $rel  = strpos($stored, '/') !== false ? ltrim($stored, './') : 'uploads/' . ($kind === 'radiology' ? 'radiology_report' : 'pathology_report') . '/' . $stored;
        $full = realpath(FCPATH . $rel);
        $root = realpath(FCPATH . 'uploads');
        if ($full === false || $root === false || strpos($full, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) {
            return null;
        }
        return $full;
    }

    private function lab_params($kind, array $report_ids)
    {
        if (empty($report_ids)) {
            return array();
        }
        $in = implode(',', array_map('intval', $report_ids));
        if ($kind === 'pathology') {
            $rows = $this->db->query("SELECT rp.pathology_report_id rid, pp.parameter_name name, rp.pathology_report_value value, pp.reference_range, u.unit_name unit
                FROM pathology_report_parameterdetails rp
                JOIN pathology_parameterdetails ppd ON ppd.id = rp.pathology_parameterdetail_id
                JOIN pathology_parameter pp ON pp.id = ppd.pathology_parameter_id LEFT JOIN unit u ON u.id = pp.unit
                WHERE rp.pathology_report_id IN ($in) ORDER BY rp.id")->result_array();
        } else {
            $rows = $this->db->query("SELECT rp.radiology_report_id rid, pp.parameter_name name, rp.radiology_report_value value, pp.reference_range, pp.unit
                FROM radiology_report_parameterdetails rp
                JOIN radiology_parameterdetails ppd ON ppd.id = rp.radiology_parameterdetail_id
                JOIN radiology_parameter pp ON pp.id = ppd.radiology_parameter_id
                WHERE rp.radiology_report_id IN ($in) ORDER BY rp.id")->result_array();
        }
        $by = array();
        foreach ($rows as $r) {
            $by[$r['rid']][] = array('name' => $r['name'], 'value' => $r['value'], 'reference_range' => $r['reference_range'], 'unit' => $r['unit']);
        }
        return $by;
    }

    private function timeline($in, $since)
    {
        $args = array();
        $rows = $this->db->query("SELECT t.id, t.patient_id, t.title, t.timeline_date, t.description, t.updated_at
            FROM patient_timeline t WHERE t.patient_id IN ($in)" . $this->since_clause('t.updated_at', $since, $args), $args)->result_array();
        foreach ($rows as &$r) {
            $r['description'] = $this->plain($r['description']);
        }
        return $this->ints($rows, array('id', 'patient_id'));
    }

    // ================================================================== prescriptions (read)
    private function prescriptions_for_patients($in, $since)
    {
        $where = "(od.patient_id IN ($in) OR ip.patient_id IN ($in))";
        $args  = array();
        if ($since !== null) {
            $where .= " AND (b.updated_at > ? OR b.date >= CURDATE() - INTERVAL " . self::RECENT_RX_DAYS . " DAY
                OR EXISTS (SELECT 1 FROM ipd_prescription_details d WHERE d.basic_id = b.id AND d.updated_at > ?)
                OR EXISTS (SELECT 1 FROM ipd_prescription_test t WHERE t.ipd_prescription_basic_id = b.id AND t.updated_at > ?))";
            $args = array($since, $since, $since);
        }
        return $this->prescriptions_where($where, $args);
    }

    public function prescription_by_id($id)
    {
        $r = $this->prescriptions_where('b.id = ?', array((int) $id));
        return $r ? $r[0] : null;
    }

    private function prescriptions_where($where, array $args)
    {
        $rows = $this->db->query("SELECT b.id, b.visit_details_id visit_id, b.ipd_id, COALESCE(od.patient_id, ip.patient_id) patient_id,
                b.date, b.header_note, b.footer_note, b.finding_description, b.prescribe_by,
                TRIM(CONCAT(COALESCE(s.name,''), ' ', COALESCE(s.surname,''))) prescribe_by_name, b.generated_by, b.created_at,
                GREATEST(b.updated_at,
                    COALESCE((SELECT MAX(d.updated_at) FROM ipd_prescription_details d WHERE d.basic_id = b.id), b.updated_at),
                    COALESCE((SELECT MAX(t.updated_at) FROM ipd_prescription_test t WHERE t.ipd_prescription_basic_id = b.id), b.updated_at)) version
            FROM ipd_prescription_basic b
            LEFT JOIN visit_details vd ON vd.id = b.visit_details_id LEFT JOIN opd_details od ON od.id = vd.opd_details_id
            LEFT JOIN ipd_details ip ON ip.id = b.ipd_id LEFT JOIN staff s ON s.id = b.prescribe_by
            WHERE $where ORDER BY b.date DESC, b.id DESC", $args)->result_array();
        if (empty($rows)) {
            return array();
        }
        $ids   = implode(',', array_map('intval', array_column($rows, 'id')));
        $items = array();
        foreach ($this->db->query("SELECT d.id, d.basic_id, d.pharmacy_id medicine_id, ph.medicine_name, ph.medicine_composition composition,
                    ph.medicine_category_id category_id, d.dosage dosage_id, TRIM(CONCAT(COALESCE(md.dosage,''), ' ', COALESCE(u.unit_name,''))) dosage_label,
                    d.dose_interval_id interval_id, di.name interval_name, d.dose_duration_id duration_id, dd.name duration_name, d.instruction
                FROM ipd_prescription_details d LEFT JOIN pharmacy ph ON ph.id = d.pharmacy_id
                LEFT JOIN medicine_dosage md ON md.id = d.dosage LEFT JOIN unit u ON u.id = md.units_id
                LEFT JOIN dose_interval di ON di.id = d.dose_interval_id LEFT JOIN dose_duration dd ON dd.id = d.dose_duration_id
                WHERE d.basic_id IN ($ids) ORDER BY d.id")->result_array() as $d) {
            $d['instruction'] = $this->plain($d['instruction']);
            $items[$d['basic_id']][] = $this->ints(array($d), array('id', 'medicine_id', 'category_id', 'dosage_id', 'interval_id', 'duration_id'))[0];
        }
        $tests = array();
        foreach ($this->db->query("SELECT t.ipd_prescription_basic_id basic_id, t.pathology_id, t.radiology_id, p.test_name pname, r.test_name rname
                FROM ipd_prescription_test t LEFT JOIN pathology p ON p.id = t.pathology_id LEFT JOIN radio r ON r.id = t.radiology_id
                WHERE t.ipd_prescription_basic_id IN ($ids)")->result_array() as $t) {
            if ($t['pathology_id']) {
                $tests[$t['basic_id']]['pathology'][] = array('id' => (int) $t['pathology_id'], 'name' => $t['pname']);
            }
            if ($t['radiology_id']) {
                $tests[$t['basic_id']]['radiology'][] = array('id' => (int) $t['radiology_id'], 'name' => $t['rname']);
            }
        }
        foreach ($rows as &$r) {
            foreach (array('header_note', 'footer_note', 'finding_description') as $f) {
                $r[$f] = $this->plain($r[$f]);
            }
            $r['items']     = isset($items[$r['id']]) ? $items[$r['id']] : array();
            $r['pathology'] = isset($tests[$r['id']]['pathology']) ? $tests[$r['id']]['pathology'] : array();
            $r['radiology'] = isset($tests[$r['id']]['radiology']) ? $tests[$r['id']]['radiology'] : array();
        }
        return $this->ints($rows, array('id', 'visit_id', 'ipd_id', 'patient_id', 'prescribe_by', 'generated_by'));
    }

    // ================================================================== prescriptions (write)
    /** OPD visit or IPD admission the prescription belongs to. */
    public function case_context($kind, $id)
    {
        if ($kind === 'opd') {
            $r = $this->db->query("SELECT vd.id, od.id case_id, od.patient_id, vd.cons_doctor doctor_id, od.discharged
                FROM visit_details vd JOIN opd_details od ON od.id = vd.opd_details_id WHERE vd.id = ?", array((int) $id))->row_array();
            if ($r) {
                $r['doctor_ids'] = array((int) $r['doctor_id']);
            }
        } elseif ($kind === 'ipd') {
            $r = $this->db->query("SELECT ip.id, ip.id case_id, ip.patient_id, ip.cons_doctor doctor_id, ip.discharged FROM ipd_details ip WHERE ip.id = ?",
                array((int) $id))->row_array();
            if ($r) {
                $ids = array((int) $r['doctor_id']);
                foreach ($this->db->query("SELECT consult_doctor FROM ipd_doctors WHERE ipd_id = ?", array((int) $id))->result_array() as $d) {
                    $ids[] = (int) $d['consult_doctor'];
                }
                $r['doctor_ids'] = array_values(array_unique($ids));
            }
        } else {
            return null;
        }
        if (empty($r)) {
            return null;
        }
        $r['kind'] = $kind;
        return $r;
    }

    public function basic_row($id)
    {
        return $this->db->query("SELECT b.*, GREATEST(b.updated_at,
                COALESCE((SELECT MAX(d.updated_at) FROM ipd_prescription_details d WHERE d.basic_id = b.id), b.updated_at),
                COALESCE((SELECT MAX(t.updated_at) FROM ipd_prescription_test t WHERE t.ipd_prescription_basic_id = b.id), b.updated_at)) version
            FROM ipd_prescription_basic b WHERE b.id = ?", array((int) $id))->row_array();
    }

    /**
     * Validate a prescription payload from the app. Returns array(clean, errors).
     * Errors are human-readable and shown to the doctor as-is.
     */
    public function validate_prescription(array $p)
    {
        $err   = array();
        $items = isset($p['items']) && is_array($p['items']) ? array_values($p['items']) : array();
        $path  = array_values(array_unique(array_filter(array_map('intval', isset($p['pathology_ids']) ? (array) $p['pathology_ids'] : array()))));
        $rad   = array_values(array_unique(array_filter(array_map('intval', isset($p['radiology_ids']) ? (array) $p['radiology_ids'] : array()))));
        $icd   = array_values(array_unique(array_filter(array_map('intval', isset($p['icd_ids']) ? (array) $p['icd_ids'] : array()))));

        if (empty($items) && empty($path) && empty($rad)) {
            $err[] = 'Add at least one medicine or test.';
        }
        if (count($items) > self::MAX_ITEMS) {
            $err[] = 'A prescription can have at most ' . self::MAX_ITEMS . ' medicines.';
        }

        $clean_items = array();
        $med_ids = $dos_ids = $int_ids = $dur_ids = array();
        foreach ($items as $i => $it) {
            $n = $i + 1;
            $row = array(
                'id'               => isset($it['id']) && $it['id'] ? (int) $it['id'] : null,
                'pharmacy_id'      => (int) (isset($it['medicine_id']) ? $it['medicine_id'] : 0),
                'dosage'           => (int) (isset($it['dosage_id']) ? $it['dosage_id'] : 0),
                'dose_interval_id' => (int) (isset($it['interval_id']) ? $it['interval_id'] : 0),
                'dose_duration_id' => !empty($it['duration_id']) ? (int) $it['duration_id'] : null,
                'instruction'      => $this->to_html(isset($it['instruction']) ? $it['instruction'] : '', 300),
            );
            if (!$row['pharmacy_id']) {
                $err[] = "Medicine $n: choose a medicine.";
            }
            if (!$row['dosage']) {
                $err[] = "Medicine $n: choose a dose.";
            }
            if (!$row['dose_interval_id']) {
                $err[] = "Medicine $n: choose how often.";
            }
            $med_ids[] = $row['pharmacy_id'];
            $dos_ids[] = $row['dosage'];
            $int_ids[] = $row['dose_interval_id'];
            if ($row['dose_duration_id']) {
                $dur_ids[] = $row['dose_duration_id'];
            }
            $clean_items[] = $row;
        }
        if (empty($err)) {
            if (!$this->all_exist('pharmacy', $med_ids)) {
                $err[] = 'One of the medicines is no longer in the pharmacy list. Sync and pick it again.';
            }
            if (!$this->all_exist('medicine_dosage', $dos_ids) || !$this->all_exist('dose_interval', $int_ids) || !$this->all_exist('dose_duration', $dur_ids)) {
                $err[] = 'A dose, interval or duration option was removed on the server. Sync and pick it again.';
            }
            if (!$this->all_exist('pathology', $path) || !$this->all_exist('radio', $rad)) {
                $err[] = 'A lab test was removed on the server. Sync and pick it again.';
            }
            if (!$this->all_exist('icd10_codes', $icd)) {
                $err[] = 'A diagnosis code is not valid.';
            }
        }

        // Date the doctor wrote it (may be earlier than the sync). Clamp to a sane window.
        $date  = isset($p['date']) ? (string) $p['date'] : '';
        $ts    = strtotime($date);
        $today = strtotime(date('Y-m-d'));
        if (!$ts || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $ts > $today + 86400 || $ts < $today - 60 * 86400) {
            $date = date('Y-m-d');
        }

        return array(array(
            'items'               => $clean_items,
            'pathology'           => $path,
            'radiology'           => $rad,
            'icd_ids'             => $icd,
            'date'                => $date,
            'header_note'         => $this->to_html(isset($p['header_note']) ? $p['header_note'] : '', self::MAX_NOTE),
            'footer_note'         => $this->to_html(isset($p['footer_note']) ? $p['footer_note'] : '', self::MAX_NOTE),
            'finding_description' => $this->to_html(isset($p['finding_description']) ? $p['finding_description'] : '', self::MAX_NOTE),
            'is_finding_print'    => !empty($p['print_findings']) ? 'yes' : 'no',
        ), $err);
    }

    /** Insert or update one prescription in a single transaction. Returns the basic id or false. */
    public function save_prescription(array $ctx, array $clean, $staff_id, $prescribe_by, $existing_id = null)
    {
        $basic = array(
            'header_note'         => $clean['header_note'],
            'footer_note'         => $clean['footer_note'],
            'finding_description' => $clean['finding_description'],
            'is_finding_print'    => $clean['is_finding_print'],
            'date'                => $clean['date'],
            'prescribe_by'        => (int) $prescribe_by,
        );

        $this->db->trans_start();
        if ($existing_id) {
            $basic_id = (int) $existing_id;
            $this->db->where('id', $basic_id)->update('ipd_prescription_basic', $basic);
            // Always bump: MySQL does not touch updated_at when the values are unchanged.
            $this->db->query("UPDATE ipd_prescription_basic SET updated_at = NOW() WHERE id = ?", array($basic_id));
        } else {
            $basic['visit_details_id'] = $ctx['kind'] === 'opd' ? (int) $ctx['id'] : null;
            $basic['ipd_id']           = $ctx['kind'] === 'ipd' ? (int) $ctx['id'] : null;
            $basic['attachment']       = '';
            $basic['attachment_name']  = '';
            $basic['generated_by']     = (int) $staff_id;
            $this->db->insert('ipd_prescription_basic', $basic);
            $basic_id = (int) $this->db->insert_id();
        }

        // Medicines: update lines the app still has, insert new ones, delete the rest.
        $existing_lines = array();
        foreach ($this->db->query("SELECT id FROM ipd_prescription_details WHERE basic_id = ?", array($basic_id))->result_array() as $r) {
            $existing_lines[(int) $r['id']] = true;
        }
        $keep = array();
        foreach ($clean['items'] as $it) {
            $line_id = $it['id'];
            unset($it['id']);
            if ($line_id && isset($existing_lines[$line_id])) {
                $this->db->where(array('id' => $line_id, 'basic_id' => $basic_id))->update('ipd_prescription_details', $it);
                $keep[$line_id] = true;
            } else {
                $it['basic_id'] = $basic_id;
                $this->db->insert('ipd_prescription_details', $it);
            }
        }
        $remove = array_diff(array_keys($existing_lines), array_keys($keep));
        if (!empty($remove)) {
            $this->db->where('basic_id', $basic_id)->where_in('id', $remove)->delete('ipd_prescription_details');
        }

        // Tests: replace the set.
        $this->db->where('ipd_prescription_basic_id', $basic_id)->delete('ipd_prescription_test');
        $tests = array();
        foreach ($clean['pathology'] as $pid) {
            $tests[] = array('ipd_prescription_basic_id' => $basic_id, 'pathology_id' => $pid, 'radiology_id' => null);
        }
        foreach ($clean['radiology'] as $rid) {
            $tests[] = array('ipd_prescription_basic_id' => $basic_id, 'pathology_id' => null, 'radiology_id' => $rid);
        }
        if (!empty($tests)) {
            $this->db->insert_batch('ipd_prescription_test', $tests);
        }
        $this->db->trans_complete();

        return $this->db->trans_status() ? $basic_id : false;
    }

    /** Add ICD-10 codes to the case, keeping the codes already there. */
    public function add_diagnoses(array $ctx, array $icd_ids)
    {
        if (empty($icd_ids)) {
            return true;
        }
        $t   = $ctx['kind'] === 'opd' ? 'opd_icd10_codes' : 'ipd_icd10_codes';
        $col = $ctx['kind'] === 'opd' ? 'opd_id' : 'ipd_id';
        $have = array();
        foreach ($this->db->query("SELECT icd_code_id FROM $t WHERE $col = ?", array((int) $ctx['case_id']))->result_array() as $r) {
            $have[(int) $r['icd_code_id']] = true;
        }
        $batch = array();
        foreach ($icd_ids as $id) {
            if (!isset($have[$id])) {
                $batch[] = array($col => (int) $ctx['case_id'], 'icd_code_id' => (int) $id);
            }
        }
        if (!empty($batch)) {
            $this->db->insert_batch($t, $batch);
        }
        return true;
    }

    /** Doctors and their consultation charge, for registering OPD visits offline. */
    private function opd_doctors()
    {
        $this->load->model('mobileregistration_model');
        return $this->mobileregistration_model->opd_doctors();
    }

    // ================================================================== masters
    public function masters($staff_id)
    {
        $this->load->model(array('smartrx_model', 'mobilebilling_model'));
        $this->smartrx_model->ensure_schema();

        $medicines = $this->db->query("SELECT ph.id, ph.medicine_name name, ph.medicine_composition composition, ph.medicine_company company,
                ph.medicine_category_id category_id, mc.medicine_category category
            FROM pharmacy ph LEFT JOIN medicine_category mc ON mc.id = ph.medicine_category_id
            WHERE (ph.is_active IS NULL OR ph.is_active <> 'no') ORDER BY ph.medicine_name")->result_array();
        $dosages = $this->db->query("SELECT md.id, md.medicine_category_id category_id, TRIM(CONCAT(md.dosage, ' ', COALESCE(u.unit_name, ''))) label
            FROM medicine_dosage md LEFT JOIN unit u ON u.id = md.units_id ORDER BY md.id")->result_array();
        // Diagnosis codes this hospital actually uses; the full ICD-10 list is searched online.
        $icd = $this->db->query("SELECT c.id, c.icd_code code, c.icd_description description, COUNT(*) n FROM (
                SELECT icd_code_id FROM opd_icd10_codes UNION ALL SELECT icd_code_id FROM ipd_icd10_codes) x
            JOIN icd10_codes c ON c.id = x.icd_code_id GROUP BY c.id ORDER BY n DESC LIMIT 2000")->result_array();
        foreach ($icd as &$c) {
            unset($c['n']);
        }
        unset($c);

        $templates = array();
        foreach ($this->smartrx_model->templates($staff_id) as $t) {
            $templates[] = array('id' => (int) $t['id'], 'name' => $t['name'], 'shared' => (bool) $t['is_shared'], 'mine' => $t['mine'], 'payload' => $t['payload']);
        }

        return array(
            'medicines'  => $this->ints($medicines, array('id', 'category_id')),
            'dosages'    => $this->ints($dosages, array('id', 'category_id')),
            'intervals'  => $this->ints($this->db->query("SELECT id, name FROM dose_interval ORDER BY id")->result_array(), array('id')),
            'durations'  => $this->ints($this->db->query("SELECT id, name FROM dose_duration ORDER BY id")->result_array(), array('id')),
            'pathology'  => $this->ints($this->db->query("SELECT id, test_name name, short_name FROM pathology ORDER BY test_name")->result_array(), array('id')),
            'radiology'  => $this->ints($this->db->query("SELECT id, test_name name, short_name FROM radio ORDER BY test_name")->result_array(), array('id')),
            'icd'        => $this->ints($icd, array('id')),
            'templates'  => $templates,
            'frequent_medicine_ids' => array_map('intval', array_column($this->smartrx_model->frequent_medicines($staff_id, 20), 'id')),
            'charges'    => $this->mobilebilling_model->charges_master(),
            'opd_doctors' => $this->opd_doctors(),
            'staff_settings' => (object) $this->mobilebilling_model->staff_settings($staff_id),
            'has_print_header' => $this->mobilebilling_model->prescription_header_path() !== null,
        );
    }

    // ================================================================== helpers
    private function all_exist($table, array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return true;
        }
        $n = $this->db->query("SELECT COUNT(*) n FROM `$table` WHERE id IN (" . implode(',', $ids) . ")")->row()->n;
        return (int) $n === count($ids);
    }

    /** Rich-text/HTML from the web editor -> plain text for the app. */
    public function plain($html)
    {
        if ($html === null || $html === '') {
            return $html === null ? null : '';
        }
        $t = preg_replace('#<br\s*/?>|</p>|</div>|</li>#i', "\n", (string) $html);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\xC2\xA0", ' ', $t)));
    }

    /** Plain text from the app -> safe HTML for the web screens and print layouts (which echo it raw). */
    private function to_html($text, $max)
    {
        $text = trim(mb_substr(str_replace("\r\n", "\n", (string) $text), 0, $max));
        return $text === '' ? '' : nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false);
    }

    private function ints(array $rows, array $cols)
    {
        foreach ($rows as &$r) {
            foreach ($cols as $c) {
                if (array_key_exists($c, $r) && $r[$c] !== null && $r[$c] !== '') {
                    $r[$c] = (int) $r[$c];
                } elseif (array_key_exists($c, $r)) {
                    $r[$c] = null;
                }
            }
        }
        return $rows;
    }
}
