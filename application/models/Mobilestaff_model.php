<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Data and writes for the non-doctor app roles: reception, nursing, lab and pharmacy.
 *
 * Reads feed the same offline sync as the doctor app (patients on the device, plus role extras: bed
 * board, lab worklist, pharmacy stock). Writes go to the web tables the matching web screens use
 * (patients_vitals, nurse_note, pathology_report_parameterdetails), so they show up on the web.
 */
class Mobilestaff_model extends CI_Model
{
    const RECEPTION_DAYS = 2;   // OPD visits kept on a reception device (today and yesterday)
    const LAB_DAYS       = 30;  // pending/recent pathology reports for the lab
    const EXPIRY_DAYS    = 90;  // batches expiring within this many days are flagged
    const MAX_NOTE       = 5000;

    // ================================================================== scope
    /** Patients a device of this role keeps offline. */
    public function scope_patient_ids(array $modules, $staff_id)
    {
        $ids = array();
        $add = function ($rows) use (&$ids) {
            foreach ($rows as $r) {
                if (!empty($r['pid'])) {
                    $ids[(int) $r['pid']] = true;
                }
            }
        };
        if (in_array('doctor', $modules, true)) {
            $this->load->model('mobiledoctor_model');
            foreach ($this->mobiledoctor_model->scope_patient_ids($staff_id) as $id) {
                $ids[$id] = true;
            }
        }
        if (in_array('reception', $modules, true) || in_array('nursing', $modules, true)) {
            $add($this->db->query("SELECT od.patient_id pid FROM visit_details vd JOIN opd_details od ON od.id = vd.opd_details_id
                WHERE vd.appointment_date >= CURDATE() - INTERVAL " . (self::RECEPTION_DAYS - 1) . " DAY")->result_array());
            $add($this->db->query("SELECT patient_id pid FROM appointment WHERE patient_id IS NOT NULL AND date >= CURDATE() AND date < CURDATE() + INTERVAL 2 DAY")->result_array());
        }
        if (in_array('nursing', $modules, true) || in_array('reception', $modules, true)) {
            $add($this->db->query("SELECT patient_id pid FROM ipd_details WHERE discharged = 'no'")->result_array());
        }
        if (in_array('lab', $modules, true)) {
            $add($this->db->query("SELECT COALESCE(pr.patient_id, pb.patient_id) pid FROM pathology_report pr LEFT JOIN pathology_billing pb ON pb.id = pr.pathology_bill_id
                WHERE pr.reporting_date IS NULL OR pr.created_at >= NOW() - INTERVAL " . self::LAB_DAYS . " DAY")->result_array());
        }
        return array_keys($ids);
    }

    /** Appointments shown on the device: the doctor's own, or every doctor's for reception. */
    public function appointments(array $modules, $staff_id, $since)
    {
        $this->load->model('mobiledoctor_model');
        if (!in_array('reception', $modules, true)) {
            return in_array('doctor', $modules, true) ? $this->mobiledoctor_model->appointments($staff_id, $since) : array();
        }
        $sql  = "SELECT a.id, a.patient_id, a.date, a.appointment_status status, a.visit_details_id visit_id, ap.appoint_priority priority,
                a.message, a.source, a.doctor doctor_id, a.updated_at,
                (SELECT q.position FROM appointment_queue q WHERE q.appointment_id = a.id ORDER BY q.id DESC LIMIT 1) token
            FROM appointment a LEFT JOIN appoint_priority ap ON ap.id = a.priority
            WHERE a.patient_id IS NOT NULL AND a.date >= CURDATE() - INTERVAL 1 DAY";
        $args = array();
        if ($since !== null) {
            $sql .= " AND a.updated_at > ?";
            $args[] = $since;
        }
        $rows = $this->db->query($sql, $args)->result_array();
        foreach ($rows as &$r) {
            foreach (array('id', 'patient_id', 'visit_id', 'doctor_id', 'token') as $k) {
                $r[$k] = $r[$k] === null ? null : (int) $r[$k];
            }
        }
        return $rows;
    }

    // ================================================================== role extras (sent with every pull)
    /** Bed board: every active bed with its ward/floor and the admitted patient, if any. */
    public function beds()
    {
        $rows = $this->db->query("SELECT b.id, b.name, bt.name type, bg.id group_id, bg.name ward, f.name floor,
                ip.id ipd_id, ip.patient_id, p.patient_name, ip.date admitted_on,
                TRIM(CONCAT(COALESCE(s.name, ''), ' ', COALESCE(s.surname, ''))) doctor_name
            FROM bed b
            LEFT JOIN bed_type bt ON bt.id = b.bed_type_id
            LEFT JOIN bed_group bg ON bg.id = b.bed_group_id
            LEFT JOIN floor f ON f.id = bg.floor
            LEFT JOIN ipd_details ip ON ip.bed = b.id AND ip.discharged = 'no'
            LEFT JOIN patients p ON p.id = ip.patient_id
            LEFT JOIN staff s ON s.id = ip.cons_doctor
            WHERE b.is_active = 'yes'
            ORDER BY f.name, bg.name, b.name")->result_array();
        foreach ($rows as &$r) {
            foreach (array('id', 'group_id', 'ipd_id', 'patient_id') as $k) {
                $r[$k] = $r[$k] === null ? null : (int) $r[$k];
            }
        }
        return $rows;
    }

    /** Vital sign types defined on the web (for recording vitals). */
    public function vital_types()
    {
        $rows = $this->db->query("SELECT id, name, unit, reference_range FROM vitals ORDER BY id")->result_array();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
        }
        return $rows;
    }

    /** Pathology reports waiting for results (and recent ones), with every parameter of the test. */
    public function lab_worklist()
    {
        $rows = $this->db->query("SELECT pr.id, COALESCE(pr.patient_id, pb.patient_id) patient_id, COALESCE(p2.patient_name, pr.patient_name) patient_name,
                pt.id test_id, pt.test_name, pt.short_name, pr.collection_date, pr.reporting_date, pr.pathology_result result, pr.created_at
            FROM pathology_report pr
            JOIN pathology pt ON pt.id = pr.pathology_id
            LEFT JOIN pathology_billing pb ON pb.id = pr.pathology_bill_id
            LEFT JOIN patients p2 ON p2.id = COALESCE(pr.patient_id, pb.patient_id)
            WHERE pr.reporting_date IS NULL OR pr.created_at >= NOW() - INTERVAL " . self::LAB_DAYS . " DAY
            ORDER BY pr.reporting_date IS NULL DESC, pr.id DESC LIMIT 500")->result_array();
        if (empty($rows)) {
            return array();
        }
        $tests = implode(',', array_unique(array_map('intval', array_column($rows, 'test_id'))));
        $params = array();
        foreach ($this->db->query("SELECT ppd.id detail_id, ppd.pathology_id test_id, pp.parameter_name name, pp.reference_range, u.unit_name unit
                FROM pathology_parameterdetails ppd JOIN pathology_parameter pp ON pp.id = ppd.pathology_parameter_id LEFT JOIN unit u ON u.id = pp.unit
                WHERE ppd.pathology_id IN ($tests) ORDER BY ppd.id")->result_array() as $p) {
            $params[(int) $p['test_id']][] = $p;
        }
        $ids    = implode(',', array_map('intval', array_column($rows, 'id')));
        $values = array();
        foreach ($this->db->query("SELECT pathology_report_id rid, pathology_parameterdetail_id did, pathology_report_value v FROM pathology_report_parameterdetails WHERE pathology_report_id IN ($ids)")->result_array() as $v) {
            $values[(int) $v['rid']][(int) $v['did']] = $v['v'];
        }
        $out = array();
        foreach ($rows as $r) {
            $rid  = (int) $r['id'];
            $list = array();
            foreach (isset($params[(int) $r['test_id']]) ? $params[(int) $r['test_id']] : array() as $p) {
                $did    = (int) $p['detail_id'];
                $list[] = array('detail_id' => $did, 'name' => $p['name'], 'unit' => $p['unit'], 'reference_range' => $p['reference_range'],
                    'value' => isset($values[$rid][$did]) ? $values[$rid][$did] : '');
            }
            $out[] = array(
                'id' => $rid, 'patient_id' => $r['patient_id'] ? (int) $r['patient_id'] : null, 'patient_name' => $r['patient_name'],
                'test_name' => $r['test_name'], 'short_name' => $r['short_name'], 'collection_date' => $r['collection_date'],
                'reporting_date' => $r['reporting_date'], 'result' => $r['result'], 'created_at' => $r['created_at'], 'parameters' => $list,
            );
        }
        return $out;
    }

    /** Medicine stock: available quantity per medicine, batches, and expiry alerts. */
    public function pharmacy_stock()
    {
        $rows = $this->db->query("SELECT ph.id, ph.medicine_name name, ph.medicine_company company, mc.medicine_category category, ph.min_level, ph.reorder_level,
                COALESCE(SUM(CASE WHEN mbd.expiry >= CURDATE() THEN mbd.available_quantity ELSE 0 END), 0) available,
                COALESCE(SUM(CASE WHEN mbd.expiry < CURDATE() THEN mbd.available_quantity ELSE 0 END), 0) expired,
                COALESCE(SUM(CASE WHEN mbd.expiry >= CURDATE() AND mbd.expiry < CURDATE() + INTERVAL " . self::EXPIRY_DAYS . " DAY THEN mbd.available_quantity ELSE 0 END), 0) expiring,
                MIN(CASE WHEN mbd.available_quantity > 0 AND mbd.expiry >= CURDATE() THEN mbd.expiry END) next_expiry,
                MAX(mbd.sale_rate) sale_rate
            FROM pharmacy ph
            LEFT JOIN medicine_category mc ON mc.id = ph.medicine_category_id
            LEFT JOIN medicine_batch_details mbd ON mbd.pharmacy_id = ph.id
            WHERE (ph.is_active IS NULL OR ph.is_active <> 'no')
            GROUP BY ph.id ORDER BY ph.medicine_name")->result_array();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            foreach (array('available', 'expired', 'expiring') as $k) {
                $r[$k] = (int) $r[$k];
            }
            $r['min_level']     = $r['min_level'] === null || $r['min_level'] === '' ? null : (int) $r['min_level'];
            $r['reorder_level'] = $r['reorder_level'] === null || $r['reorder_level'] === '' ? null : (int) $r['reorder_level'];
            $r['sale_rate']     = $r['sale_rate'] === null ? null : (float) $r['sale_rate'];
        }
        return $rows;
    }

    // ================================================================== writes
    /**
     * Record vital signs (patients_vitals, like the web vitals form). $values: {vital_id: value}.
     * Returns error string or the saved rows.
     */
    public function save_vitals($patient_id, $measured_at, array $values)
    {
        if (!$this->db->query("SELECT 1 FROM patients WHERE id = ?", array((int) $patient_id))->row_array()) {
            return 'Patient not found.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', (string) $measured_at) || strtotime($measured_at) === false || strtotime($measured_at) > time() + 3600) {
            return 'Invalid time of measurement.';
        }
        $known = array_flip(array_map('intval', array_column($this->db->query("SELECT id FROM vitals")->result_array(), 'id')));
        $rows  = array();
        foreach ($values as $vid => $v) {
            $vid = (int) $vid;
            $v   = mb_substr(trim(strip_tags((string) $v)), 0, 100);
            if ($v === '') {
                continue;
            }
            if (!isset($known[$vid])) {
                return 'Unknown vital sign. Sync and try again.';
            }
            $rows[] = array('patient_id' => (int) $patient_id, 'vital_id' => $vid, 'reference_range' => $v, 'messure_date' => $measured_at);
        }
        if (empty($rows)) {
            return 'Enter at least one reading.';
        }
        $this->db->insert_batch('patients_vitals', $rows);
        return $rows;
    }

    /** Nurse note on an admission (nurse_note, like the web IPD nurse notes). */
    public function save_nurse_note($ipd_id, $date, $note, $comment, $staff_id)
    {
        $ipd = $this->db->query("SELECT id, discharged FROM ipd_details WHERE id = ?", array((int) $ipd_id))->row_array();
        if (!$ipd) {
            return 'The admission no longer exists.';
        }
        $note = mb_substr(trim(strip_tags((string) $note)), 0, self::MAX_NOTE);
        if ($note === '') {
            return 'The note is empty.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', (string) $date)) {
            return 'Invalid date.';
        }
        $this->db->insert('nurse_note', array(
            'date' => $date, 'ipd_id' => (int) $ipd_id, 'staff_id' => (int) $staff_id,
            'note' => $note, 'comment' => mb_substr(trim(strip_tags((string) $comment)), 0, 2000), 'created_by' => (int) $staff_id,
        ));
        return (int) $this->db->insert_id();
    }

    /** Pathology result entry: parameter values (by pathology_parameterdetails id) and optional result text. */
    public function save_lab_result($report_id, array $values, $result, $complete)
    {
        $r = $this->db->query("SELECT id, pathology_id FROM pathology_report WHERE id = ?", array((int) $report_id))->row_array();
        if (!$r) {
            return 'This lab report no longer exists.';
        }
        $allowed = array_flip(array_map('intval', array_column($this->db->query("SELECT id FROM pathology_parameterdetails WHERE pathology_id = ?", array((int) $r['pathology_id']))->result_array(), 'id')));
        $this->db->trans_start();
        foreach ($values as $did => $v) {
            $did = (int) $did;
            if (!isset($allowed[$did])) {
                continue;
            }
            $v        = mb_substr(trim(strip_tags((string) $v)), 0, 200);
            $existing = $this->db->query("SELECT id FROM pathology_report_parameterdetails WHERE pathology_report_id = ? AND pathology_parameterdetail_id = ? LIMIT 1",
                array((int) $report_id, $did))->row_array();
            if ($existing) {
                $this->db->where('id', $existing['id'])->update('pathology_report_parameterdetails', array('pathology_report_value' => $v));
            } elseif ($v !== '') {
                $this->db->insert('pathology_report_parameterdetails', array('pathology_report_id' => (int) $report_id, 'pathology_parameterdetail_id' => $did, 'pathology_report_value' => $v));
            }
        }
        $update = array('parameter_update' => date('Y-m-d'));
        if ($result !== null) {
            $update['pathology_result'] = mb_substr(trim(strip_tags((string) $result)), 0, self::MAX_NOTE);
        }
        if ($complete) {
            $update['reporting_date'] = date('Y-m-d');
        }
        $this->db->where('id', (int) $report_id)->update('pathology_report', $update);
        $this->db->trans_complete();
        return $this->db->trans_status() ? true : 'Could not save; will retry.';
    }
}
