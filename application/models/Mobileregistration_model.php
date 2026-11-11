<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Patient registration and OPD visits created offline on a phone/tablet, uploaded on sync.
 *
 * Writes go through the same web models as the reception screens (Patient_model::add_patient,
 * User_model::add for the patient login, Patient_model::add_opd for case + charge + payment + visit),
 * so the records look exactly like ones created on the web.
 *
 * Idempotency comes from the sync op log (Mobileapi_model::op_result): a replayed op returns its
 * first answer. As a second guard, a patient whose mobile number and name match an existing patient
 * is linked to that patient instead of creating a duplicate.
 */
class Mobileregistration_model extends CI_Model
{
    const PAYMENT_MODES = array('Cash', 'UPI', 'Card', 'Online', 'Other');
    const GENDERS       = array('Male', 'Female', 'Other');

    /** Doctors a visit can be booked with, with their consultation charge (shift_details.charge_id). */
    public function opd_doctors()
    {
        $rows = $this->db->query("SELECT s.id, s.name, s.surname, d.department_name department, sd.charge_id, ch.standard_charge fee, COALESCE(tc.percentage, 0) tax
            FROM staff s
            JOIN staff_roles sr ON sr.staff_id = s.id AND sr.role_id = 3
            LEFT JOIN department d ON d.id = s.department_id
            LEFT JOIN shift_details sd ON sd.staff_id = s.id
            LEFT JOIN charges ch ON ch.id = sd.charge_id
            LEFT JOIN tax_category tc ON tc.id = ch.tax_category_id
            WHERE s.is_active = 1
            GROUP BY s.id ORDER BY s.name, s.surname")->result_array();
        $out = array();
        foreach ($rows as $r) {
            $out[] = array(
                'id'         => (int) $r['id'],
                'name'       => trim('Dr. ' . $r['name'] . ' ' . $r['surname']),
                'department' => $r['department'],
                'charge_id'  => $r['charge_id'] ? (int) $r['charge_id'] : null,
                'fee'        => $r['fee'] !== null ? (float) $r['fee'] : null,
                'tax'        => (float) $r['tax'],
            );
        }
        return $out;
    }

    // ================================================================== patient
    /** Returns array(clean_patient_row, error|null). */
    public function validate_patient(array $p)
    {
        $s = function ($k, $max = 100) use ($p) {
            return isset($p[$k]) && is_scalar($p[$k]) ? mb_substr(trim(strip_tags((string) $p[$k])), 0, $max) : '';
        };
        $name = $s('name');
        if ($name === '') {
            return array(null, 'Patient name is required.');
        }
        $gender = $s('gender', 10);
        if ($gender !== '' && !in_array($gender, self::GENDERS, true)) {
            return array(null, 'Invalid gender.');
        }
        $mobile = preg_replace('/[^0-9+]/', '', $s('mobileno', 30));
        if ($mobile !== '' && !preg_match('/^\+?[0-9]{6,15}$/', $mobile)) {
            return array(null, 'Invalid mobile number.');
        }
        $email = $s('email');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return array(null, 'Invalid email address.');
        }
        $dob = $s('dob', 10);
        if ($dob !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) || strtotime($dob) === false || $dob > date('Y-m-d'))) {
            return array(null, 'Invalid date of birth.');
        }
        $y = max(0, min(130, (int) (isset($p['age']) ? $p['age'] : 0)));
        $m = max(0, min(11, (int) (isset($p['month']) ? $p['month'] : 0)));
        $d = max(0, min(31, (int) (isset($p['day']) ? $p['day'] : 0)));
        if ($dob !== '') {
            $diff = (new DateTime($dob))->diff(new DateTime(date('Y-m-d')));
            list($y, $m, $d) = array($diff->y, $diff->m, $diff->d);
        } elseif ($y === 0 && $m === 0 && $d === 0) {
            return array(null, 'Age or date of birth is required.');
        }

        $blood_group = $s('blood_group', 10);
        $bbp         = null;
        if ($blood_group !== '') {
            $r   = $this->db->query("SELECT id FROM blood_bank_products WHERE name = ? AND is_blood_group = 1 LIMIT 1", array($blood_group))->row_array();
            $bbp = $r ? (int) $r['id'] : null;
        }

        return array(array(
            'patient_name'          => $name,
            'mobileno'              => $mobile,
            'email'                 => $email,
            'gender'                => $gender,
            'guardian_name'         => $s('guardian_name'),
            'address'               => $s('address', 500),
            'known_allergies'       => $s('known_allergies', 200),
            'identification_number' => $s('identification_number', 60),
            'note'                  => $s('note', 200),
            'marital_status'        => $s('marital_status', 20),
            'blood_bank_product_id' => $bbp,
            'blood_group'           => $bbp ? '' : $blood_group,
            'dob'                   => $dob === '' ? null : $dob,
            'age'                   => $y,
            'month'                 => $m,
            'day'                   => $d,
            'as_of_date'            => date('Y-m-d'),
            'is_active'             => 'yes',
            'patient_type'          => '',
            'is_ipd'                => '',
            'app_key'               => '',
            'is_antenatal'          => 0,
        ), null);
    }

    /** Existing active patient with the same mobile number and name (case-insensitive), if any. */
    public function find_duplicate(array $row)
    {
        if ($row['mobileno'] === '') {
            return null;
        }
        $r = $this->db->query("SELECT id FROM patients WHERE mobileno = ? AND LOWER(TRIM(patient_name)) = LOWER(?) AND is_active = 'yes' ORDER BY id LIMIT 1",
            array($row['mobileno'], $row['patient_name']))->row_array();
        return $r ? (int) $r['id'] : null;
    }

    /** Other patients already using this mobile number (families often share one phone). */
    public function mobile_in_use($mobile, $except_id)
    {
        if ($mobile === '') {
            return 0;
        }
        return (int) $this->db->query("SELECT COUNT(*) n FROM patients WHERE mobileno = ? AND id <> ?", array($mobile, (int) $except_id))->row()->n;
    }

    /** Create the patient like Patient::addpatient (record, login, default photo, barcode). Returns the id. */
    public function create_patient(array $row, $staff_id)
    {
        $this->load->model(array('patient_model', 'user_model'));
        $row['generated_by'] = (int) $staff_id;
        $id = $this->patient_model->add_patient($row);
        if (!$id) {
            return null;
        }
        $password = $this->random_password();
        $this->user_model->add(array('username' => 'pat' . $id, 'password' => $password, 'user_id' => $id, 'role' => 'patient'));
        $this->patient_model->add(array('id' => $id, 'image' => 'uploads/patient_images/no_image.png'));
        try {
            $scan = isset($this->sch_setting_detail->scan_code_type) ? $this->sch_setting_detail->scan_code_type : 'barcode';
            $this->customlib->generatebarcode($id, $scan);
        } catch (Throwable $e) {
            log_message('error', 'Mobile registration barcode failed for patient ' . $id . ': ' . $e->getMessage());
        }
        if (class_exists('Saasvalidation', false) || isset($this->saasvalidation)) {
            try {
                $this->saasvalidation->updateResouceQuota('no_of_patient', 1);
            } catch (Throwable $e) {
                log_message('error', 'SaaS no_of_patient quota increment failed (mobile registration): ' . $e->getMessage());
            }
        }
        return (int) $id;
    }

    private function random_password()
    {
        $out = '';
        for ($i = 0; $i < 6; $i++) {
            $out .= random_int(0, 9);
        }
        return $out;
    }

    // ================================================================== OPD visit
    /** Returns array(clean, error|null). */
    public function validate_visit(array $v)
    {
        $patient_id = isset($v['patient_id']) ? (int) $v['patient_id'] : 0;
        if ($patient_id <= 0 || !$this->db->query("SELECT 1 FROM patients WHERE id = ? AND is_active = 'yes'", array($patient_id))->row_array()) {
            return array(null, 'Patient not found on the server.');
        }
        $doctor_id = isset($v['doctor_id']) ? (int) $v['doctor_id'] : 0;
        $doctor    = $this->db->query("SELECT s.id, s.name, s.surname FROM staff s JOIN staff_roles sr ON sr.staff_id = s.id AND sr.role_id = 3 WHERE s.id = ? AND s.is_active = 1",
            array($doctor_id))->row_array();
        if (!$doctor) {
            return array(null, 'The consultant doctor is not available. Pick another doctor.');
        }
        $date = isset($v['date']) ? (string) $v['date'] : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $date) || strtotime($date) === false) {
            return array(null, 'Invalid visit date.');
        }
        if (strtotime($date) > time() + 86400 * 60) {
            return array(null, 'Visit date is too far ahead.');
        }
        $charge_id = isset($v['charge_id']) ? (int) $v['charge_id'] : 0;
        if ($charge_id <= 0) {
            $r         = $this->db->query("SELECT charge_id FROM shift_details WHERE staff_id = ? AND charge_id IS NOT NULL LIMIT 1", array($doctor_id))->row_array();
            $charge_id = $r ? (int) $r['charge_id'] : 0;
        }
        $charge = $this->db->query("SELECT c.id, c.standard_charge, COALESCE(tc.percentage, 0) tax FROM charges c
            LEFT JOIN tax_category tc ON tc.id = c.tax_category_id WHERE c.id = ?", array($charge_id))->row_array();
        if (!$charge) {
            return array(null, 'No consultation charge is set for this doctor. Set one on the web (doctor shift) or pick a charge.');
        }
        $discount = max(0, min(100, (float) (isset($v['discount_percentage']) ? $v['discount_percentage'] : 0)));
        $standard = (float) $charge['standard_charge'];
        $apply    = round($standard * (1 - $discount / 100), 2);
        $tax      = (float) $charge['tax'];
        $amount   = round($apply + $apply * $tax / 100, 2);
        $paid     = round(max(0, (float) (isset($v['paid_amount']) ? $v['paid_amount'] : 0)), 2);
        if ($paid > $amount + 0.005) {
            return array(null, 'Paid amount is more than the consultation charge (' . $amount . ').');
        }
        $mode = isset($v['payment_mode']) ? (string) $v['payment_mode'] : 'Cash';
        if (!in_array($mode, self::PAYMENT_MODES, true)) {
            return array(null, 'Invalid payment mode.');
        }
        $s = function ($k, $max) use ($v) {
            return isset($v[$k]) && is_scalar($v[$k]) ? mb_substr(trim(strip_tags((string) $v[$k])), 0, $max) : '';
        };
        return array(array(
            'patient_id'   => $patient_id,
            'doctor'       => $doctor,
            'date'         => strlen($date) === 16 ? $date . ':00' : $date,
            'charge_id'    => $charge_id,
            'standard'     => $standard,
            'apply'        => $apply,
            'tax'          => $tax,
            'amount'       => $amount,
            'discount'     => $discount,
            'paid'         => $paid,
            'payment_mode' => $mode,
            'symptoms'     => $s('symptoms', 2000),
            'note'         => $s('note', 1000),
            'allergies'    => $s('known_allergies', 100),
            'casualty'     => !empty($v['casualty']) ? 'Yes' : 'No',
            'vitals'       => array(
                'bp' => $s('bp', 20), 'pulse' => $s('pulse', 20), 'temperature' => $s('temperature', 20),
                'weight' => $s('weight', 20), 'height' => $s('height', 20), 'respiration' => $s('respiration', 20),
            ),
        ), null);
    }

    /**
     * Create the OPD case, consultation charge, payment and visit like Bill::add_opd.
     * Returns array(visit_details_id, opd_details_id, token).
     */
    public function create_visit(array $c, $staff_id)
    {
        $this->load->model(array('patient_model', 'transaction_model'));
        $payment_section = $this->config->item('payment_section');
        $doctor_name     = trim($c['doctor']['name'] . ' ' . $c['doctor']['surname']);

        $this->db->trans_start();
        $opd_id = $this->patient_model->add_opd(
            array('patient_id' => $c['patient_id'], 'generated_by' => (int) $staff_id),
            array(
                'case_reference_id' => 0,
                'opd_id'            => 0,
                'patient_id'        => $c['patient_id'],
                'amount'            => $c['paid'],
                'type'              => 'payment',
                'section'           => isset($payment_section['opd']) ? $payment_section['opd'] : 'OPD',
                'payment_mode'      => $c['payment_mode'],
                'note'              => $c['note'],
                'payment_date'      => $c['date'],
                'received_by'       => (int) $staff_id,
            ),
            array(
                'opd_id'              => 0,
                'date'                => $c['date'],
                'charge_id'           => $c['charge_id'],
                'qty'                 => 1,
                'apply_charge'        => $c['apply'],
                'standard_charge'     => $c['standard'],
                'tpa_charge'          => 0,
                'amount'              => $c['amount'],
                'created_at'          => date('Y-m-d'),
                'note'                => $doctor_name,
                'tax'                 => $c['tax'],
                'discount_percentage' => $c['discount'],
                'organisation_id'     => null,
                'insurance_validity'  => null,
                'insurance_id'        => null,
            ),
            array_merge(array(
                'appointment_date'  => $c['date'],
                'opd_details_id'    => 0,
                'symptoms'          => $c['symptoms'],
                'refference'        => '',
                'cons_doctor'       => (int) $c['doctor']['id'],
                'casualty'          => $c['casualty'],
                'case_type'         => '',
                'payment_mode'      => $c['payment_mode'],
                'note'              => $c['note'],
                'live_consult'      => 'no',
                'generated_by'      => (int) $staff_id,
                'patient_charge_id' => 0,
                'transaction_id'    => 0,
                'can_delete'        => 'no',
                'known_allergies'   => $c['allergies'],
                'is_antenatal'      => 0,
            ), $c['vitals'])
        );
        $visit = $this->patient_model->getvisitminid($opd_id);
        $this->db->trans_complete();
        if (!$this->db->trans_status() || empty($visit['visitid'])) {
            return null;
        }
        $visit_id = (int) $visit['visitid'];
        return array($visit_id, (int) $opd_id, $this->token_for($visit_id));
    }

    /** OPD token: position of this visit among the doctor's visits that day. */
    public function token_for($visit_id)
    {
        $r = $this->db->query("SELECT cons_doctor, DATE(appointment_date) d FROM visit_details WHERE id = ?", array((int) $visit_id))->row_array();
        if (!$r) {
            return null;
        }
        return (int) $this->db->query("SELECT COUNT(*) n FROM visit_details WHERE cons_doctor = ? AND DATE(appointment_date) = ? AND id <= ?",
            array((int) $r['cons_doctor'], $r['d'], (int) $visit_id))->row()->n;
    }

    /** Same UUID the app derives for a visit's queue record (v5, URL namespace, "opd_status:<visit id>"). */
    public static function queue_uuid($visit_id)
    {
        $ns   = hex2bin('6ba7b8119dad11d180b400c04fd430c8');
        $hash = sha1($ns . 'opd_status:' . (int) $visit_id);
        return sprintf('%08s-%04s-%04x-%04x-%12s', substr($hash, 0, 8), substr($hash, 8, 4),
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000, (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000, substr($hash, 20, 12));
    }
}
