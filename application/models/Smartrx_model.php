<?php defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Smart Prescription: data for the fast prescription screen.
 * Saving still goes through the existing add_opd_prescription / add_ipdprescription actions.
 */
class Smartrx_model extends CI_Model
{
    public function ensure_schema()
    {
        static $done = false;
        if ($done || $this->session->userdata('smartrx_schema_v1')) {
            return;
        }
        $this->db->query("CREATE TABLE IF NOT EXISTS `rx_templates` (
            `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `staff_id` int NOT NULL,
            `name` varchar(100) NOT NULL,
            `is_shared` tinyint(1) NOT NULL DEFAULT 0,
            `payload` mediumtext NOT NULL,
            `use_count` int NOT NULL DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `rx_templates_staff` (`staff_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
        $this->session->set_userdata('smartrx_schema_v1', true);
    }

    // ------------------------------------------------------------------ context
    /** Patient + case for an OPD visit or IPD admission. */
    public function context($type, $id)
    {
        if ($type === 'opd') {
            $row = $this->db->query("SELECT vd.id visit_id, od.id opd_id, od.patient_id, vd.cons_doctor doctor_id,
                    vd.symptoms, vd.known_allergies visit_allergies
                FROM visit_details vd JOIN opd_details od ON od.id = vd.opd_details_id WHERE vd.id = ?", array((int) $id))->row_array();
        } else {
            $row = $this->db->query("SELECT ip.id ipd_id, ip.patient_id, ip.cons_doctor doctor_id, ip.symptoms, ip.known_allergies visit_allergies
                FROM ipd_details ip WHERE ip.id = ?", array((int) $id))->row_array();
        }
        if (!$row) {
            return null;
        }
        $p = $this->db->query("SELECT id, patient_name, gender, dob, age, month, day, known_allergies, mobileno FROM patients WHERE id = ?",
            array((int) $row['patient_id']))->row_array();
        $allergies = array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', trim($p['known_allergies'] . ',' . (isset($row['visit_allergies']) ? $row['visit_allergies'] : ''))))));
        $doctor = $row['doctor_id'] ? $this->db->query("SELECT name, surname FROM staff WHERE id = ?", array((int) $row['doctor_id']))->row_array() : null;
        return array(
            'type'      => $type,
            'id'        => (int) $id,
            'caseId'    => $type === 'opd' ? (int) $row['opd_id'] : (int) $row['ipd_id'],
            'patient'   => array('id' => (int) $p['id'], 'name' => $p['patient_name'], 'gender' => $p['gender'], 'dob' => $p['dob'],
                'age' => trim(($p['age'] ? $p['age'] . 'y ' : '') . ($p['month'] ? $p['month'] . 'm' : '')), 'allergies' => array_values(array_unique($allergies))),
            'doctor'    => $doctor ? trim('Dr. ' . $doctor['name'] . ' ' . $doctor['surname']) : null,
            'symptoms'  => trim(strip_tags((string) $row['symptoms'])),
            'diagnosis' => $this->diagnosis_codes($type, $type === 'opd' ? $row['opd_id'] : $row['ipd_id']),
            'lastRx'    => $this->last_prescription((int) $p['id']),
            'doctorId'  => $row['doctor_id'] ? (int) $row['doctor_id'] : null,
            'prescribers' => $type === 'ipd' ? $this->ipd_prescribers((int) $id, $row['doctor_id']) : array(),
        );
    }

    /** Consultant + doctors assigned to the admission (IPD saves require "prescribe_by"). */
    private function ipd_prescribers($ipd_id, $consultant_id)
    {
        $rows = $this->db->query("SELECT s.id, s.name, s.surname, s.employee_id FROM staff s
            WHERE s.id = ? OR s.id IN (SELECT consult_doctor FROM ipd_doctors WHERE ipd_id = ?)", array((int) $consultant_id, (int) $ipd_id))->result_array();
        $out = array();
        foreach ($rows as $r) {
            $out[] = array('id' => (int) $r['id'], 'name' => trim('Dr. ' . $r['name'] . ' ' . $r['surname']) . ($r['employee_id'] ? ' (' . $r['employee_id'] . ')' : ''));
        }
        return $out;
    }

    public function diagnosis_codes($type, $case_id)
    {
        $t = $type === 'opd' ? 'opd_icd10_codes' : 'ipd_icd10_codes';
        $col = $type === 'opd' ? 'opd_id' : 'ipd_id';
        return $this->db->query("SELECT c.id, c.icd_code code, c.icd_description description FROM $t x JOIN icd10_codes c ON c.id = x.icd_code_id
            WHERE x.$col = ? ORDER BY c.icd_code", array((int) $case_id))->result_array();
    }

    /** Most recent prescription for this patient (OPD or IPD), as editable items. */
    public function last_prescription($patient_id)
    {
        $b = $this->db->query("SELECT b.id, b.date FROM ipd_prescription_basic b
            LEFT JOIN visit_details vd ON vd.id = b.visit_details_id LEFT JOIN opd_details od ON od.id = vd.opd_details_id
            LEFT JOIN ipd_details ip ON ip.id = b.ipd_id
            WHERE COALESCE(od.patient_id, ip.patient_id) = ?
              AND EXISTS (SELECT 1 FROM ipd_prescription_details d WHERE d.basic_id = b.id)
            ORDER BY b.date DESC, b.id DESC LIMIT 1", array((int) $patient_id))->row_array();
        if (!$b) {
            return null;
        }
        return array('id' => (int) $b['id'], 'date' => $b['date'], 'items' => $this->items_for_basic($b['id']));
    }

    public function items_for_basic($basic_id)
    {
        $rows = $this->db->query("SELECT d.pharmacy_id, d.dosage dosage_id, d.dose_interval_id, d.dose_duration_id, d.instruction,
                ph.medicine_name, ph.medicine_composition, ph.medicine_category_id
            FROM ipd_prescription_details d JOIN pharmacy ph ON ph.id = d.pharmacy_id WHERE d.basic_id = ?", array((int) $basic_id))->result_array();
        $out = array();
        foreach ($rows as $r) {
            $out[] = array('medicineId' => (int) $r['pharmacy_id'], 'name' => $r['medicine_name'], 'composition' => $r['medicine_composition'],
                'categoryId' => (int) $r['medicine_category_id'], 'dosageId' => $r['dosage_id'] ? (int) $r['dosage_id'] : null,
                'intervalId' => $r['dose_interval_id'] ? (int) $r['dose_interval_id'] : null,
                'durationId' => $r['dose_duration_id'] ? (int) $r['dose_duration_id'] : null, 'instruction' => strip_tags((string) $r['instruction']));
        }
        return $out;
    }

    // ------------------------------------------------------------------ masters
    public function masters()
    {
        $dos = $this->db->query("SELECT md.id, md.medicine_category_id cat, md.dosage, u.unit_name unit FROM medicine_dosage md
            LEFT JOIN unit u ON u.id = md.units_id ORDER BY md.id")->result_array();
        $by_cat = array();
        foreach ($dos as $d) {
            $by_cat[$d['cat']][] = array('id' => (int) $d['id'], 'label' => trim($d['dosage'] . ' ' . $d['unit']));
        }
        return array(
            'intervals' => $this->db->query("SELECT id, name FROM dose_interval ORDER BY id")->result_array(),
            'durations' => $this->db->query("SELECT id, name FROM dose_duration ORDER BY id")->result_array(),
            'dosages'   => $by_cat,
            'pathology' => $this->db->query("SELECT id, test_name name, short_name FROM pathology ORDER BY test_name")->result_array(),
            'radiology' => $this->db->query("SELECT id, test_name name, short_name FROM radio ORDER BY test_name")->result_array(),
        );
    }

    /** Medicine search: name, composition (generic), company. Prefix matches rank first. */
    public function search_medicines($q, $limit = 20)
    {
        $q = trim($q);
        if ($q === '') {
            return array();
        }
        $like = '%' . $this->db->escape_like_str($q) . '%';
        $pre  = $this->db->escape_like_str($q) . '%';
        return $this->db->query("SELECT ph.id, ph.medicine_name name, ph.medicine_composition composition, ph.medicine_company company,
                ph.medicine_category_id categoryId, mc.medicine_category category,
                (SELECT COALESCE(SUM(b.available_quantity), 0) FROM medicine_batch_details b WHERE b.pharmacy_id = ph.id) stock
            FROM pharmacy ph LEFT JOIN medicine_category mc ON mc.id = ph.medicine_category_id
            WHERE (ph.is_active IS NULL OR ph.is_active <> 'no')
              AND (ph.medicine_name LIKE ? OR ph.medicine_composition LIKE ? OR ph.medicine_company LIKE ?)
            ORDER BY (ph.medicine_name LIKE ?) DESC, ph.medicine_name LIMIT " . (int) $limit,
            array($like, $like, $like, $pre))->result_array();
    }

    public function search_icd($q, $limit = 20)
    {
        $q = trim($q);
        if ($q === '') {
            return array();
        }
        $like = '%' . $this->db->escape_like_str($q) . '%';
        return $this->db->query("SELECT id, icd_code code, icd_description description FROM icd10_codes
            WHERE icd_code LIKE ? OR icd_description LIKE ? ORDER BY (icd_code LIKE ?) DESC, icd_code LIMIT " . (int) $limit,
            array($like, $like, $this->db->escape_like_str($q) . '%'))->result_array();
    }

    /** Medicines this doctor prescribes most (quick picks). */
    public function frequent_medicines($staff_id, $limit = 12)
    {
        return $this->db->query("SELECT ph.id, ph.medicine_name name, ph.medicine_composition composition, ph.medicine_category_id categoryId, COUNT(*) n
            FROM ipd_prescription_details d JOIN ipd_prescription_basic b ON b.id = d.basic_id JOIN pharmacy ph ON ph.id = d.pharmacy_id
            WHERE b.generated_by = ? AND b.date >= CURDATE() - INTERVAL 180 DAY
            GROUP BY ph.id ORDER BY n DESC LIMIT " . (int) $limit, array((int) $staff_id))->result_array();
    }

    // ------------------------------------------------------------------ templates
    public function templates($staff_id)
    {
        $rows = $this->db->query("SELECT id, staff_id, name, is_shared, payload, use_count FROM rx_templates
            WHERE staff_id = ? OR is_shared = 1 ORDER BY use_count DESC, name", array((int) $staff_id))->result_array();
        foreach ($rows as &$r) {
            $r['payload'] = json_decode($r['payload'], true);
            $r['mine'] = (int) $r['staff_id'] === (int) $staff_id;
            unset($r['staff_id']);
        }
        return $rows;
    }

    public function save_template($staff_id, $name, $shared, $payload)
    {
        $this->db->insert('rx_templates', array('staff_id' => (int) $staff_id, 'name' => $name, 'is_shared' => $shared ? 1 : 0,
            'payload' => json_encode($payload)));
        return $this->db->insert_id();
    }

    public function delete_template($staff_id, $id)
    {
        $this->db->delete('rx_templates', array('id' => (int) $id, 'staff_id' => (int) $staff_id));
        return $this->db->affected_rows() > 0;
    }

    public function used_template($id)
    {
        $this->db->query("UPDATE rx_templates SET use_count = use_count + 1 WHERE id = ?", array((int) $id));
    }
}
