<?php defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ABDM integration data layer.
 *  - Own tables: abdm_patients (ABHA per patient), abdm_sync (what was shared with the bridge).
 *  - Maps Smart Hospital records to ABDM care contexts:
 *      OPD visit        → OPConsultation     (OPD-<visit_details.id>)
 *      Prescription     → Prescription       (RX-<ipd_prescription_basic.id>)
 *      Pathology report → DiagnosticReport   (LAB-<pathology_report.id>)
 *      Radiology report → DiagnosticReport   (RAD-<radiology_report.id>)
 *      Discharge card   → DischargeSummary   (DS-<discharge_card.id>)
 */
class Abdm_model extends CI_Model
{
    const ICD10 = 'http://hl7.org/fhir/sid/icd-10';

    // Consent shown before the Aadhaar OTP for ABHA creation (CRT_ABHA_102). Its SHA-256 is stored with each consent,
    // so change CONSENT_VERSION whenever the text changes. Check it against ABDM's current approved wording:
    // https://sandbox.abdm.gov.in/sandbox/v3/new-documentation?doc=consentLanguage
    const CONSENT_VERSION = '1.4';
    const CONSENT_EN = 'I, hereby declare that I am voluntarily sharing my Aadhaar number and demographic information issued by UIDAI, with National Health Authority (NHA) for the sole purpose of creation of ABHA number. I understand that my ABHA number can be used and shared for purposes as may be notified by ABDM from time to time including provision of healthcare services. Further, I am aware that my personal identifiable information (Name, Address, Age, Date of Birth, Gender and Photograph) may be made available to the entities working in the National Digital Health Ecosystem (NDHE) which inter alia includes stakeholders and entities such as healthcare professionals (e.g. doctors), facilities (e.g. hospitals, laboratories) and data fiduciaries (e.g. health programmes), which are registered with or linked to the Ayushman Bharat Digital Mission (ABDM), and various processes there under. I reserve the right to revoke the given consent at any point of time.';

    public function __construct()
    {
        parent::__construct();
        $this->config->load('abdm', true);
    }

    private function cfg($k)
    {
        return $this->config->item($k, 'abdm');
    }

    // =====================================================================================
    // Schema + permissions (idempotent; runs once per session)
    // =====================================================================================
    public function ensure_schema()
    {
        static $done = false;
        if ($done || (!is_cli() && $this->session->userdata('abdm_schema_v3'))) {
            return;
        }
        $this->db->query("CREATE TABLE IF NOT EXISTS `abdm_patients` (
            `patient_id` int NOT NULL PRIMARY KEY,
            `abha_number` varchar(20) DEFAULT NULL,
            `abha_address` varchar(100) DEFAULT NULL,
            `abha_name` varchar(150) DEFAULT NULL,
            `verified_by` int DEFAULT NULL,
            `verified_at` datetime DEFAULT NULL,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `abdm_patients_addr` (`abha_address`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS `abdm_sync` (
            `entity` varchar(12) NOT NULL,
            `entity_id` int NOT NULL,
            `patient_id` int NOT NULL,
            `reference` varchar(64) NOT NULL,
            `hash` char(64) DEFAULT NULL,
            `linked` tinyint(1) NOT NULL DEFAULT 0,
            `synced_at` datetime DEFAULT NULL,
            `checked_at` datetime DEFAULT NULL,
            `error` varchar(500) DEFAULT NULL,
            PRIMARY KEY (`entity`, `entity_id`),
            KEY `abdm_sync_patient` (`patient_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $group = $this->db->get_where('permission_group', array('short_code' => 'abdm'))->row();
        if (!$group) {
            $this->db->insert('permission_group', array('name' => 'ABDM', 'short_code' => 'abdm', 'is_active' => 1, 'system' => 0, 'sort_order' => 30.5));
            $group_id = $this->db->insert_id();
        } else {
            $group_id = $group->id;
        }
        $cats = array(
            array('ABDM ABHA (create / verify)', 'abdm_abha', 1, 1, 0, 0),
            array('ABDM Health Records (sync / link)', 'abdm_records', 1, 1, 0, 0),
            array('ABDM Consent Requests (HIU)', 'abdm_consent', 1, 1, 0, 0),
            array('ABDM Settings & Sync Status', 'abdm_settings', 1, 0, 1, 0),
        );
        foreach ($cats as $c) {
            if (!$this->db->get_where('permission_category', array('short_code' => $c[1]))->row()) {
                $this->db->insert('permission_category', array('perm_group_id' => $group_id, 'name' => $c[0], 'short_code' => $c[1],
                    'enable_view' => $c[2], 'enable_add' => $c[3], 'enable_edit' => $c[4], 'enable_delete' => $c[5]));
            }
        }
        // Consent given for ABHA creation (CRT_ABHA_102): who took it, when, which text. No Aadhaar is stored.
        $this->db->query("CREATE TABLE IF NOT EXISTS `abdm_consents` (
            `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `purpose` varchar(40) NOT NULL,
            `consent_version` varchar(20) NOT NULL,
            `language` varchar(10) NOT NULL DEFAULT 'en',
            `text_sha256` char(64) NOT NULL,
            `txn_id` varchar(64) DEFAULT NULL,
            `abha_number` varchar(20) DEFAULT NULL,
            `patient_id` int DEFAULT NULL,
            `staff_id` int DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `abdm_consents_txn` (`txn_id`),
            KEY `abdm_consents_abha` (`abha_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->ensure_unique_abha_number();
        // LOINC code per pathology parameter, set or confirmed by staff (Health_RECORD_CREATION_101).
        $this->db->query("CREATE TABLE IF NOT EXISTS `abdm_loinc` (
            `parameter_id` int NOT NULL PRIMARY KEY,
            `loinc_code` varchar(10) NOT NULL,
            `loinc_display` varchar(200) DEFAULT NULL,
            `updated_by` int DEFAULT NULL,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $done = true;
        if (!is_cli()) {
            $this->session->set_userdata('abdm_schema_v3', true);
        }
    }

    /**
     * One ABHA number per patient, enforced by the database (TAGGING_UNIQUEPATIENTID_UNIQUEABHANUMBER).
     * Added only when the existing rows hold no duplicate; otherwise it logs them and leaves the table as it is.
     */
    private function ensure_unique_abha_number()
    {
        if ($this->db->query("SHOW INDEX FROM abdm_patients WHERE Key_name = 'abdm_patients_number'")->num_rows()) {
            return;
        }
        $dups = $this->db->query("SELECT abha_number, GROUP_CONCAT(patient_id) ids FROM abdm_patients
            WHERE abha_number IS NOT NULL GROUP BY abha_number HAVING COUNT(*) > 1")->result_array();
        if ($dups) {
            log_message('error', 'ABDM: cannot add the unique ABHA number index; the same ABHA number is on several patients: '
                . implode('; ', array_map(function ($d) { return 'patients ' . $d['ids']; }, $dups)));
            return;
        }
        $this->db->query("ALTER TABLE abdm_patients ADD UNIQUE KEY `abdm_patients_number` (`abha_number`)");
    }

    /** Record the consent taken before an Aadhaar OTP for ABHA creation. */
    public function record_consent($purpose, $version, $text, $txn_id, $staff_id, $language = 'en')
    {
        $this->db->insert('abdm_consents', array(
            'purpose' => $purpose, 'consent_version' => (string) $version, 'language' => $language,
            'text_sha256' => hash('sha256', (string) $text), 'txn_id' => $txn_id ? substr((string) $txn_id, 0, 64) : null,
            'staff_id' => $staff_id ? (int) $staff_id : null,
        ));
    }

    /** Carry the consent forward as the transaction produces an ABHA number, then a patient. */
    public function consent_abha($txn_id, $abha_number)
    {
        if ($txn_id && $abha_number) {
            $this->db->where('txn_id', (string) $txn_id)->where('abha_number IS NULL', null, false)
                ->update('abdm_consents', array('abha_number' => $this->format_abha_number($abha_number)));
        }
    }

    public function consent_patient($abha_number, $patient_id)
    {
        if ($abha_number) {
            $this->db->where('abha_number', $this->format_abha_number($abha_number))->where('patient_id IS NULL', null, false)
                ->update('abdm_consents', array('patient_id' => (int) $patient_id));
        }
    }

    public function format_abha_number($n)
    {
        $d = preg_replace('/\D/', '', (string) $n);
        return $d === '' ? null : preg_replace('/^(\d{2})(\d{4})(\d{4})(\d{4})$/', '$1-$2-$3-$4', $d);
    }

    // =====================================================================================
    // Patients & ABHA
    // =====================================================================================
    public function mrn($patient_id)
    {
        return $this->cfg('abdm_mrn_prefix') . $patient_id;
    }

    public function patient_id_from_mrn($mrn)
    {
        $p = $this->cfg('abdm_mrn_prefix');
        return (strpos($mrn, $p) === 0 && ctype_digit(substr($mrn, strlen($p)))) ? (int) substr($mrn, strlen($p)) : null;
    }

    public function get_patient($id)
    {
        return $this->db->query("SELECT p.*, a.abha_number, a.abha_address, a.abha_name, a.verified_at
            FROM patients p LEFT JOIN abdm_patients a ON a.patient_id = p.id WHERE p.id = ?", array((int) $id))->row_array();
    }

    /**
     * Tag an ABHA to a patient. Refuses (409) an ABHA number already on another patient, and never touches
     * another patient's row (REPLACE would delete it once the unique index exists).
     */
    public function save_abha($patient_id, $abha_number, $abha_address, $abha_name, $staff_id)
    {
        $number = $abha_number ? $this->format_abha_number($abha_number) : null;
        if ($number) {
            $other = $this->db->query('SELECT patient_id FROM abdm_patients WHERE abha_number = ? AND patient_id <> ?',
                array($number, (int) $patient_id))->row_array();
            if ($other) {
                throw new Exception('This ABHA number is already linked to patient #' . $other['patient_id'], 409);
            }
        }
        $row = array(
            'abha_number'  => $number,
            'abha_address' => $abha_address ? strtolower($abha_address) : null,
            'abha_name'    => $abha_name,
            'verified_by'  => $staff_id,
        );
        $exists = $this->db->where('patient_id', (int) $patient_id)->count_all_results('abdm_patients') > 0;
        $debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $this->db->set('verified_at', 'NOW()', false);
        $ok = $exists
            ? $this->db->where('patient_id', (int) $patient_id)->update('abdm_patients', $row)
            : $this->db->insert('abdm_patients', array('patient_id' => (int) $patient_id) + $row);
        $err = $this->db->error();
        $this->db->db_debug = $debug;
        if (!$ok) {
            if ((int) $err['code'] === 1062) {
                throw new Exception('This ABHA number is already linked to another patient', 409);
            }
            throw new Exception('Could not save the ABHA: ' . $err['message'], 500);
        }
        $this->consent_patient($number, $patient_id);
    }

    /** A known patient sharing again with another ABHA address on the same ABHA number keeps one record (SHARE_PATIENT_PROFILE_702). */
    public function update_abha_address($patient_id, $abha_address)
    {
        if ($abha_address) {
            $this->db->where('patient_id', (int) $patient_id)->update('abdm_patients', array('abha_address' => strtolower($abha_address)));
        }
    }

    /**
     * Returning patient (CRT_ABHA_116, SHARE_PATIENT_PROFILE_702): patients who may be this ABHA holder, scored on
     * mobile, name, gender and age. 'full' is true only when every one of them matches; only then is the record reused.
     * $p: name, gender (M/F/O), yearOfBirth, mobile.
     */
    public function match_candidates($p, $limit = 5)
    {
        $mobile = substr(preg_replace('/\D/', '', isset($p['mobile']) ? (string) $p['mobile'] : ''), -10);
        $name   = trim(isset($p['name']) ? (string) $p['name'] : '');
        $words  = array_slice(array_filter(preg_split('/\s+/', preg_replace('/[^a-z ]/i', ' ', $name))), 0, 2);
        if ($mobile === '' && !$words) {
            return array();
        }
        $this->db->select('p.id, p.patient_name, p.gender, p.dob, p.age, p.as_of_date, p.created_at, p.mobileno, p.email, p.address,
                p.state, p.district, p.city_village, p.pincode, a.abha_number, a.abha_address')
            ->from('patients p')->join('abdm_patients a', 'a.patient_id = p.id', 'left')->where('p.is_active', 'yes')->group_start();
        if (strlen($mobile) === 10) {
            $this->db->like('p.mobileno', $mobile, 'before');
        }
        foreach ($words as $w) {
            $this->db->or_like('p.patient_name', $w);
        }
        $rows = $this->db->group_end()->order_by('p.id', 'DESC')->limit(50)->get()->result_array();

        $prof = array('name' => $name, 'gender' => isset($p['gender']) ? $p['gender'] : '', 'yearOfBirth' => isset($p['yearOfBirth']) ? $p['yearOfBirth'] : null);
        $out = array();
        foreach ($rows as $r) {
            $miss = implode(' ', $this->demographic_mismatches($r, $prof));
            $hp = $this->patient_payload($r);
            $checks = array(
                'mobile' => strlen($mobile) === 10 && substr(preg_replace('/\D/', '', (string) $r['mobileno']), -10) === $mobile,
                'name'   => strpos($miss, 'Name:') === false,
                'gender' => $prof['gender'] !== '' && strpos($miss, 'Gender:') === false && $this->gender($r['gender']) !== 'U',
                'age'    => !empty($prof['yearOfBirth']) && $hp['yearOfBirth'] && strpos($miss, 'Year of birth:') === false,
            );
            $score = array_sum(array_map('intval', $checks));
            if ($score < 2) {
                continue;
            }
            $out[] = array(
                'id' => (int) $r['id'], 'patient_name' => $r['patient_name'], 'gender' => $r['gender'], 'age' => $r['age'],
                'yearOfBirth' => $hp['yearOfBirth'], 'mobileno' => $r['mobileno'], 'abha_number' => $r['abha_number'],
                'abha_address' => $r['abha_address'], 'checks' => $checks, 'score' => $score, 'full' => $score === 4,
            );
        }
        usort($out, function ($a, $b) { return $b['score'] - $a['score'] ?: $b['id'] - $a['id']; });
        return array_slice($out, 0, $limit);
    }

    /**
     * Today's Scan & Share tokens, each registered (or matched) automatically. Returns the rows with hms_patient_id,
     * and 'register_error' for one that could not be registered (the screen offers a retry).
     */
    public function process_share_queue($client, $staff_id)
    {
        $rows = $client->get('/m1/share/queue');
        foreach ($rows as &$r) {
            try {
                list($pid, $how) = $this->register_shared($r, $staff_id);
                $r['hms_patient_id'] = $pid;
                $r['registered'] = $how;
                if ($how !== 'abha') {
                    $this->sync_patient($client, $pid);
                }
            } catch (Exception $e) {
                $r['hms_patient_id'] = null;
                $r['register_error'] = $e->getMessage();
                log_message('error', 'ABDM: Scan & Share token ' . (int) $r['id'] . ' not registered: ' . $e->getMessage());
            }
            unset($r['profile']);
        }
        return $rows;
    }

    // =====================================================================================
    // LOINC codes for lab results (Health_RECORD_CREATION_101)
    // =====================================================================================
    /**
     * Suggestions for common tests, offered on the Lab codes screen and saved only when staff confirm them.
     * Most specific first: the first pattern that matches a parameter name wins. Each code passes the LOINC check digit.
     */
    private static $LOINC_SEED = array(
        array('/\b(hba1c|glycated|glycosylated)\b/', '4548-4', 'Hemoglobin A1c/Hemoglobin.total in Blood'),
        array('/\bmchc\b/', '786-4', 'MCHC [Mass/volume] by Automated count'),
        array('/\bmch\b/', '785-6', 'MCH [Entitic mass] by Automated count'),
        array('/\bmcv\b/', '787-2', 'MCV [Entitic volume] by Automated count'),
        array('/\b(ha?emoglobin|hb|hgb)\b/', '718-7', 'Hemoglobin [Mass/volume] in Blood'),
        array('/\b(wbc|tlc|total (leu[ck]ocyte|wbc) count|leu[ck]ocytes?)\b/', '6690-2', 'Leukocytes [#/volume] in Blood by Automated count'),
        array('/\b(rbc|red (blood )?cell count|erythrocytes?)\b/', '789-8', 'Erythrocytes [#/volume] in Blood by Automated count'),
        array('/\b(platelets?|plt)\b/', '777-3', 'Platelets [#/volume] in Blood by Automated count'),
        array('/\b(pcv|ha?ematocrit|hct)\b/', '4544-3', 'Hematocrit [Volume Fraction] of Blood by Automated count'),
        array('/\besr\b|erythrocyte sedimentation/', '4537-7', 'Erythrocyte sedimentation rate by Westergren method'),
        array('/\b(fbs|fasting (blood )?(sugar|glucose)|glucose,? fasting|bsl,? fasting)\b/', '1558-6', 'Fasting glucose [Mass/volume] in Serum or Plasma'),
        array('/\b(rbs|random (blood )?(sugar|glucose)|glucose|blood sugar)\b/', '2345-7', 'Glucose [Mass/volume] in Serum or Plasma'),
        array('/\bcreatinine\b/', '2160-0', 'Creatinine [Mass/volume] in Serum or Plasma'),
        array('/\b(bun|blood urea nitrogen|urea nitrogen)\b/', '3094-0', 'Urea nitrogen [Mass/volume] in Serum or Plasma'),
        array('/\burea\b/', '3091-6', 'Urea [Mass/volume] in Serum or Plasma'),
        array('/\bhdl\b/', '2085-9', 'Cholesterol in HDL [Mass/volume] in Serum or Plasma'),
        array('/\bldl\b/', '13457-7', 'Cholesterol in LDL [Mass/volume] in Serum or Plasma by calculation'),
        array('/\btriglycerides?\b/', '2571-8', 'Triglyceride [Mass/volume] in Serum or Plasma'),
        array('/\b(total )?cholesterol\b/', '2093-3', 'Cholesterol [Mass/volume] in Serum or Plasma'),
        array('/\b(sgpt|alt|alanine aminotransferase)\b/', '1742-6', 'Alanine aminotransferase [Enzymatic activity/volume] in Serum or Plasma'),
        array('/\b(sgot|ast|aspartate aminotransferase)\b/', '1920-8', 'Aspartate aminotransferase [Enzymatic activity/volume] in Serum or Plasma'),
        array('/\b(alp|alkaline phosphatase)\b/', '6768-6', 'Alkaline phosphatase [Enzymatic activity/volume] in Serum or Plasma'),
        array('/\b(direct|conjugated) bilirubin\b|bilirubin,? (direct|conjugated)/', '1968-7', 'Bilirubin.direct [Mass/volume] in Serum or Plasma'),
        array('/\bbilirubin\b/', '1975-2', 'Bilirubin.total [Mass/volume] in Serum or Plasma'),
        array('/\balbumin\b/', '1751-7', 'Albumin [Mass/volume] in Serum or Plasma'),
        array('/\btotal protein\b|protein,? total/', '2885-2', 'Protein [Mass/volume] in Serum or Plasma'),
        array('/\b(sodium|na\+?)\b/', '2951-2', 'Sodium [Moles/volume] in Serum or Plasma'),
        array('/\b(potassium|k\+?)\b/', '2823-3', 'Potassium [Moles/volume] in Serum or Plasma'),
        array('/\b(chloride|cl-?)\b/', '2075-0', 'Chloride [Moles/volume] in Serum or Plasma'),
        array('/\bcalcium\b/', '17861-6', 'Calcium [Mass/volume] in Serum or Plasma'),
        array('/\buric acid\b|\burate\b/', '3084-1', 'Urate [Mass/volume] in Serum or Plasma'),
        array('/\btsh\b|thyroid stimulating/', '3016-3', 'Thyrotropin [Units/volume] in Serum or Plasma'),
        array('/\b(ft4|free t4|free thyroxine)\b/', '3024-7', 'Thyroxine (T4) free [Mass/volume] in Serum or Plasma'),
        array('/\b(t3|total t3|triiodothyronine)\b/', '3053-6', 'Triiodothyronine (T3) [Mass/volume] in Serum or Plasma'),
    );

    /** LOINC mod-10 check digit: catches a mistyped code. */
    public function loinc_valid($code)
    {
        if (!preg_match('/^(\d{1,7})-(\d)$/', (string) $code, $m)) {
            return false;
        }
        $d = strrev($m[1]);
        $odd = $even = '';
        for ($i = 0; $i < strlen($d); $i++) {
            if ($i % 2 === 0) {
                $odd = $d[$i] . $odd;
            } else {
                $even = $d[$i] . $even;
            }
        }
        $sum = array_sum(str_split($even . (string) ((int) $odd * 2)));
        return (10 - $sum % 10) % 10 === (int) $m[2];
    }

    public function loinc_suggest($parameter_name)
    {
        $n = strtolower(preg_replace('/\s+/', ' ', (string) $parameter_name));
        foreach (self::$LOINC_SEED as $s) {
            if (preg_match($s[0], $n)) {
                return array('code' => $s[1], 'display' => $s[2]);
            }
        }
        return null;
    }

    /** Pathology parameters with their saved code (if any), a suggestion and how many tests use them. */
    public function loinc_rows()
    {
        $rows = $this->db->query("SELECT p.id, p.parameter_name, p.reference_range, COALESCE(u.unit_name, '') unit,
                l.loinc_code, l.loinc_display, l.updated_at, (SELECT COUNT(*) FROM pathology_parameterdetails d WHERE d.pathology_parameter_id = p.id) used
            FROM pathology_parameter p LEFT JOIN unit u ON u.id = p.unit LEFT JOIN abdm_loinc l ON l.parameter_id = p.id
            ORDER BY (l.loinc_code IS NULL) DESC, p.parameter_name")->result_array();
        foreach ($rows as &$r) {
            $r['suggestion'] = $r['loinc_code'] ? null : $this->loinc_suggest($r['parameter_name']);
        }
        return $rows;
    }

    public function save_loinc($parameter_id, $code, $display, $staff_id)
    {
        if (!$this->db->get_where('pathology_parameter', array('id' => (int) $parameter_id))->row()) {
            throw new Exception('Unknown lab parameter', 404);
        }
        $code = trim((string) $code);
        if ($code === '') {
            $this->db->delete('abdm_loinc', array('parameter_id' => (int) $parameter_id));
            $this->resend_lab_reports($parameter_id);
            return;
        }
        if (!$this->loinc_valid($code)) {
            throw new Exception('"' . $code . '" is not a valid LOINC code (format 1234-5, with its check digit)', 400);
        }
        $this->db->query('INSERT INTO abdm_loinc (parameter_id, loinc_code, loinc_display, updated_by) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE loinc_code = VALUES(loinc_code), loinc_display = VALUES(loinc_display), updated_by = VALUES(updated_by)',
            array((int) $parameter_id, $code, mb_substr(trim((string) $display), 0, 200) ?: null, $staff_id ? (int) $staff_id : null));
        $this->resend_lab_reports($parameter_id);
    }

    /** Lab reports using this parameter go out again on the next sync, now carrying the code (older ones too). */
    private function resend_lab_reports($parameter_id)
    {
        $this->db->query("UPDATE abdm_sync SET synced_at = '2000-01-01' WHERE entity = 'lab' AND entity_id IN (
            SELECT v.pathology_report_id FROM pathology_report_parameterdetails v
            JOIN pathology_parameterdetails pd ON pd.id = v.pathology_parameterdetail_id WHERE pd.pathology_parameter_id = ?)", array((int) $parameter_id));
    }

    /** Hospital-side details of a patient, for the ABHA vs HMIS comparison screen (VRFY_ABHA_308). */
    public function patient_summary($id)
    {
        $p = $this->get_patient($id);
        if (!$p) {
            return null;
        }
        $hp = $this->patient_payload($p);
        return array(
            'id' => (int) $p['id'], 'name' => $p['patient_name'], 'gender' => $p['gender'],
            'dob' => $hp['dob'] ? date('d-m-Y', strtotime($hp['dob'])) : ($hp['yearOfBirth'] ? (string) $hp['yearOfBirth'] : ''),
            'mobile' => $p['mobileno'], 'email' => $p['email'],
            'line' => trim(implode(', ', array_filter(array($p['address_line'] ?: $p['address'], $p['city_village'], $p['district'], $p['state'], $p['pincode'])))),
            'abhaNumber' => $p['abha_number'], 'abhaAddress' => $p['abha_address'],
        );
    }

    /**
     * Register a patient from a Scan & Share token with no manual step (SHARE_PATIENT_PROFILE_701/702):
     * same ABHA number → that patient (address updated if it changed); else a full demographic match with no ABHA of
     * its own → tag the ABHA to it; else a new patient. Returns array(patient_id, 'abha'|'matched'|'new').
     */
    public function register_shared($row, $staff_id)
    {
        $prof = isset($row['profile']) && is_array($row['profile']) ? $row['profile'] : array();
        $address = isset($row['abha_address']) ? $row['abha_address'] : (isset($prof['abhaAddress']) ? $prof['abhaAddress'] : null);
        $number = isset($row['abhaNumber']) ? $row['abhaNumber'] : (isset($prof['abhaNumber']) ? $prof['abhaNumber'] : null);

        $by_number = $number ? $this->db->query("SELECT patient_id, abha_address FROM abdm_patients WHERE REPLACE(abha_number, '-', '') = ?",
            array(preg_replace('/\D/', '', $number)))->row_array() : null;
        if ($by_number) {
            if ($address && strtolower($address) !== strtolower((string) $by_number['abha_address'])) {
                $this->update_abha_address($by_number['patient_id'], $address);
            }
            return array((int) $by_number['patient_id'], 'abha');
        }
        $hit = $this->find_patient_by_abha($address, null);
        if ($hit) {
            return array((int) $hit['patient_id'], 'abha');
        }
        $name = isset($prof['name']) ? $prof['name'] : (isset($row['name']) ? $row['name'] : '');
        foreach ($this->match_candidates(array('name' => $name, 'gender' => isset($prof['gender']) ? $prof['gender'] : '',
            'yearOfBirth' => isset($prof['yearOfBirth']) ? $prof['yearOfBirth'] : null,
            'mobile' => isset($prof['phoneNumber']) ? $prof['phoneNumber'] : (isset($prof['mobile']) ? $prof['mobile'] : '')), 1) as $c) {
            if ($c['full'] && !$c['abha_number'] && !$c['abha_address']) {
                $this->save_abha($c['id'], $number, $address, $name, $staff_id);
                return array($c['id'], 'matched');
            }
        }
        $pid = $this->create_patient_from_abha(array_merge($prof, array('name' => $name, 'abhaAddress' => $address, 'abhaNumber' => $number)), $staff_id);
        return array($pid, 'new');
    }

    /**
     * Identity check before attaching an ABHA: compare HMS demographics with the ABHA profile.
     * Returns a list of human-readable mismatches (empty = matches).
     */
    public function demographic_mismatches($patient, $prof)
    {
        $norm = function ($n) {
            $parts = preg_split('/\s+/', strtolower(preg_replace('/[^a-z ]/i', ' ', (string) $n)), -1, PREG_SPLIT_NO_EMPTY);
            $parts = array_values(array_diff($parts, array('mr', 'mrs', 'ms', 'dr', 'shri', 'smt', 'kumari', 'baby', 'master')));
            sort($parts);
            return $parts;
        };
        $out = array();
        $abha_name = isset($prof['name']) ? $prof['name'] : trim((isset($prof['firstName']) ? $prof['firstName'] : '') . ' ' . (isset($prof['middleName']) ? $prof['middleName'] : '') . ' ' . (isset($prof['lastName']) ? $prof['lastName'] : ''));
        $a = $norm($patient['patient_name']);
        $b = $norm($abha_name);
        // Same person if every word of the shorter name appears in the longer one ("Satish Soni" vs "Satish Kumar Soni").
        $short = count($a) <= count($b) ? $a : $b;
        $long = count($a) <= count($b) ? $b : $a;
        if (!$short || array_diff($short, $long)) {
            $out[] = 'Name: hospital "' . $patient['patient_name'] . '" vs ABHA "' . $abha_name . '"';
        }
        $g = strtoupper(substr((string) (isset($prof['gender']) ? $prof['gender'] : ''), 0, 1));
        $hg = $this->gender($patient['gender']);
        if ($g && $hg !== 'U' && $g !== $hg) {
            $out[] = 'Gender: hospital ' . $patient['gender'] . ' vs ABHA ' . $prof['gender'];
        }
        $abha_dob = isset($prof['dateOfBirth']) ? $prof['dateOfBirth'] : (isset($prof['dob']) ? $prof['dob'] : '');
        $abha_yob = isset($prof['yearOfBirth']) ? (int) $prof['yearOfBirth'] : (preg_match('/(\d{4})/', (string) $abha_dob, $m) ? (int) $m[1] : 0);
        $hp = $this->patient_payload($patient);
        if ($abha_yob && $hp['yearOfBirth'] && abs($abha_yob - (int) $hp['yearOfBirth']) > 1) {
            $out[] = 'Year of birth: hospital ' . $hp['yearOfBirth'] . ' vs ABHA ' . $abha_yob;
        }
        return $out;
    }

    /** Patient picker: exact id, or name / mobile contains (active patients, newest first). */
    public function search_patients($q)
    {
        $q = trim((string) $q);
        if ($q === '') {
            return array();
        }
        $this->db->select('p.id, p.patient_name, p.gender, p.age, p.mobileno, a.abha_number, a.abha_address')
            ->from('patients p')->join('abdm_patients a', 'a.patient_id = p.id', 'left')->where('p.is_active', 'yes');
        if (ctype_digit($q) && strlen($q) < 8) {
            $this->db->where('p.id', (int) $q);
        } else {
            $this->db->group_start()->like('p.patient_name', $q)->or_like('p.mobileno', $q)->group_end();
        }
        return $this->db->order_by('p.id', 'DESC')->limit(15)->get()->result_array();
    }

    public function find_patient_by_abha($abha_address, $abha_number)
    {
        $num = $abha_number ? preg_replace('/\D/', '', $abha_number) : '';
        return $this->db->query("SELECT patient_id FROM abdm_patients WHERE (abha_address = ? AND ? <> '')
            OR (REPLACE(abha_number, '-', '') = ? AND ? <> '') LIMIT 1",
            array(strtolower((string) $abha_address), (string) $abha_address, $num, $num))->row_array();
    }

    private function gender($g)
    {
        $g = strtolower(trim((string) $g));
        return $g === 'male' || $g === 'm' ? 'M' : ($g === 'female' || $g === 'f' ? 'F' : ($g === '' ? 'U' : 'O'));
    }

    public function patient_payload($p)
    {
        $dob = (!empty($p['dob']) && $p['dob'] !== '0000-00-00') ? $p['dob'] : null;
        $yob = $dob ? (int) substr($dob, 0, 4) : null;
        if (!$yob && (int) $p['age'] > 0) {
            $asof = !empty($p['as_of_date']) && $p['as_of_date'] !== '0000-00-00' ? $p['as_of_date'] : substr($p['created_at'], 0, 10);
            $yob  = (int) substr($asof, 0, 4) - (int) $p['age'];
        }
        return array(
            'mrn'         => $this->mrn($p['id']),
            'name'        => trim($p['patient_name']),
            'gender'      => $this->gender($p['gender']),
            'dob'         => $dob,
            'yearOfBirth' => $yob ?: null,
            'mobile'      => $p['mobileno'] ? $p['mobileno'] : null,
            'abhaNumber'  => !empty($p['abha_number']) ? $p['abha_number'] : null,
            'abhaAddress' => !empty($p['abha_address']) ? $p['abha_address'] : null,
        );
    }

    // =====================================================================================
    // Record → care context builders
    // =====================================================================================
    /** Hospital timezone from Smart Hospital settings (cron runs outside the web request that sets it). */
    private function tz()
    {
        static $tz = null;
        if ($tz === null) {
            $row = $this->db->select('timezone')->get('sch_settings')->row();
            try {
                $tz = new DateTimeZone($row && $row->timezone ? $row->timezone : 'Asia/Kolkata');
            } catch (Exception $e) {
                $tz = new DateTimeZone('Asia/Kolkata');
            }
        }
        return $tz;
    }

    /** DB datetime (hospital local time) → ISO 8601 with offset. Date-only values get midday. */
    private function iso($dt)
    {
        if (!$dt || strpos($dt, '0000-00-00') === 0) {
            return null;
        }
        $d = new DateTime(strlen($dt) === 10 ? $dt . ' 12:00:00' : $dt, $this->tz());
        return $d->format('c');
    }

    private function staff_name($id)
    {
        if (!$id) {
            return null;
        }
        $s = $this->db->query("SELECT name, surname FROM staff WHERE id = ?", array((int) $id))->row_array();
        return $s ? trim($s['name'] . ' ' . $s['surname']) : null;
    }

    private function num_or_str($v)
    {
        $v = trim((string) $v);
        return is_numeric($v) ? $v + 0 : $v;
    }

    private function prescription_meds($basic_id)
    {
        $rows = $this->db->query("SELECT ph.medicine_name, md.dosage, u.unit_name, di.name interval_name, dd.name duration_name, d.instruction
            FROM ipd_prescription_details d
            LEFT JOIN pharmacy ph ON ph.id = d.pharmacy_id
            LEFT JOIN medicine_dosage md ON md.id = d.dosage
            LEFT JOIN unit u ON u.id = md.units_id
            LEFT JOIN dose_interval di ON di.id = d.dose_interval_id
            LEFT JOIN dose_duration dd ON dd.id = d.dose_duration_id
            WHERE d.basic_id = ?", array((int) $basic_id))->result_array();
        $meds = array();
        foreach ($rows as $r) {
            if (!$r['medicine_name']) {
                continue;
            }
            $meds[] = array(
                'name'      => $r['medicine_name'],
                'dosage'    => trim($r['dosage'] . ' ' . $r['unit_name']),
                'frequency' => trim($r['interval_name'] . ($r['instruction'] ? ' - ' . strip_tags($r['instruction']) : '')),
                'duration'  => $r['duration_name'],
            );
        }
        return $meds;
    }

    private function icd_codes($table, $col, $id)
    {
        $rows = $this->db->query("SELECT c.icd_code, c.icd_description FROM $table x JOIN icd10_codes c ON c.id = x.icd_code_id WHERE x.$col = ?",
            array((int) $id))->result_array();
        $out = array();
        foreach ($rows as $r) {
            $out[] = array('text' => $r['icd_description'], 'code' => $r['icd_code'], 'system' => self::ICD10);
        }
        return $out;
    }

    private function vitals($r)
    {
        $map = array(
            array('bp', 'Blood pressure', '85354-9', 'mmHg'), array('pulse', 'Pulse rate', '8867-4', '/min'),
            array('temperature', 'Body temperature', '8310-5', ''), array('respiration', 'Respiratory rate', '9279-1', '/min'),
            array('height', 'Body height', '8302-2', 'cm'), array('weight', 'Body weight', '29463-7', 'kg'),
        );
        $obs = array();
        foreach ($map as $m) {
            if (isset($r[$m[0]]) && trim((string) $r[$m[0]]) !== '') {
                $obs[] = array('name' => $m[1], 'loinc' => $m[2], 'value' => $this->num_or_str($r[$m[0]]), 'unit' => $m[3]);
            }
        }
        return $obs;
    }

    /** Drop exact duplicates (several prescriptions on one visit often repeat items). */
    private function unique_rows($rows)
    {
        $seen = array();
        $out = array();
        foreach ($rows as $r) {
            $k = strtolower(json_encode($r));
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = $r;
            }
        }
        return $out;
    }

    /** "09 Oct 2026" in the hospital timezone, for care-context names patients can recognise. */
    private function label_date($dt)
    {
        if (!$dt || strpos($dt, '0000-00-00') === 0) {
            return '';
        }
        $d = new DateTime(strlen($dt) === 10 ? $dt . ' 12:00:00' : $dt, $this->tz());
        return $d->format('d M Y');
    }

    private function allergies($patient_id, $extra = '')
    {
        $p = $this->db->query("SELECT known_allergies FROM patients WHERE id = ?", array((int) $patient_id))->row_array();
        $all = preg_split('/[,;\n]+/', (string) ($p ? $p['known_allergies'] : '') . ',' . (string) $extra);
        $out = array();
        foreach ($all as $a) {
            $a = trim(strip_tags($a));
            if ($a !== '' && !preg_match('/^(nil|none|no|na|n\/a|nkda|no known allergies?)$/i', $a)) {
                $out[strtolower($a)] = $a;
            }
        }
        return array_values($out);
    }

    /** Lab / radiology tests ordered on these prescriptions → ServiceRequests. */
    private function ordered_tests($basic_ids)
    {
        if (!$basic_ids) {
            return array();
        }
        $in = implode(',', array_map('intval', $basic_ids));
        $rows = $this->db->query("SELECT p.test_name lab, r.test_name rad FROM ipd_prescription_test t
            LEFT JOIN pathology p ON p.id = t.pathology_id LEFT JOIN radio r ON r.id = t.radiology_id
            WHERE t.ipd_prescription_basic_id IN ($in)")->result_array();
        $out = array();
        foreach ($rows as $r) {
            if ($r['lab']) {
                $out[] = array('text' => $r['lab'], 'category' => 'LAB');
            }
            if ($r['rad']) {
                $out[] = array('text' => $r['rad'], 'category' => 'RAD');
            }
        }
        return $out;
    }

    /** Follow-up date written by Smart Prescription ("Follow-up on: 18 Oct 2026"). */
    private function follow_up($basic_ids)
    {
        if (!$basic_ids) {
            return null;
        }
        $in = implode(',', array_map('intval', $basic_ids));
        foreach ($this->db->query("SELECT footer_note FROM ipd_prescription_basic WHERE id IN ($in) ORDER BY id DESC")->result_array() as $r) {
            if (preg_match('/Follow-up on:\s*([0-9]{1,2} [A-Za-z]{3,9} [0-9]{4})/', strip_tags((string) $r['footer_note']), $m)) {
                try {
                    $d = new DateTime($m[1] . ' 10:00:00', $this->tz());
                    return $d->format('c');
                } catch (Exception $e) {
                    return null;
                }
            }
        }
        return null;
    }

    /** Uploaded report file (PDF/image) as base64, if present and reasonably small. */
    private function report_file($value, $dir)
    {
        if (!$value) {
            return null;
        }
        $path = FCPATH . (strpos($value, '/') !== false ? ltrim($value, './') : $dir . '/' . $value);
        if (!is_file($path) || filesize($path) > 5 * 1024 * 1024) {
            return null;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $types = array('pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png');
        if (!isset($types[$ext])) {
            return null;
        }
        return array('contentType' => $types[$ext], 'title' => basename($path), 'base64' => base64_encode(file_get_contents($path)));
    }

    /** Candidate SQL per entity: id, patient_id, record date, change marker. */
    private function entity_sql()
    {
        return array(
            'opd' => "SELECT vd.id, od.patient_id, COALESCE(vd.appointment_date, vd.date, vd.created_at) rec_date, vd.created_at marker
                      FROM visit_details vd JOIN opd_details od ON od.id = vd.opd_details_id WHERE od.patient_id IS NOT NULL",
            'rx'  => "SELECT b.id, COALESCE(od.patient_id, ip.patient_id) patient_id, b.date rec_date, b.created_at marker
                      FROM ipd_prescription_basic b
                      LEFT JOIN visit_details vd ON vd.id = b.visit_details_id LEFT JOIN opd_details od ON od.id = vd.opd_details_id
                      LEFT JOIN ipd_details ip ON ip.id = b.ipd_id
                      WHERE EXISTS (SELECT 1 FROM ipd_prescription_details d WHERE d.basic_id = b.id)",
            'lab' => "SELECT r.id, r.patient_id, COALESCE(r.reporting_date, r.collection_date, r.created_at) rec_date, GREATEST(r.created_at, COALESCE(r.parameter_update, r.created_at)) marker
                      FROM pathology_report r WHERE r.patient_id IS NOT NULL AND (r.approved_by IS NOT NULL OR r.reporting_date IS NOT NULL)",
            'rad' => "SELECT r.id, r.patient_id, COALESCE(r.reporting_date, r.collection_date, r.created_at) rec_date, GREATEST(r.created_at, COALESCE(r.parameter_update, r.created_at)) marker
                      FROM radiology_report r WHERE r.patient_id IS NOT NULL AND (r.approved_by IS NOT NULL OR r.reporting_date IS NOT NULL)",
            'ds'  => "SELECT dc.id, COALESCE(ip.patient_id, od.patient_id) patient_id, COALESCE(dc.discharge_date, dc.created_at) rec_date, dc.created_at marker
                      FROM discharge_card dc LEFT JOIN ipd_details ip ON ip.id = dc.ipd_details_id LEFT JOIN opd_details od ON od.id = dc.opd_details_id",
        );
    }

    /** Build the care-context payload for one record, or null if it has nothing shareable. */
    public function build($entity, $id)
    {
        switch ($entity) {
            case 'opd':
                $r = $this->db->query("SELECT vd.*, od.patient_id, od.id opd_id FROM visit_details vd JOIN opd_details od ON od.id = vd.opd_details_id WHERE vd.id = ?", array((int) $id))->row_array();
                if (!$r) {
                    return null;
                }
                $doctor = $this->staff_name($r['cons_doctor']);
                $meds = array();
                $basic_ids = array();
                foreach ($this->db->query("SELECT id FROM ipd_prescription_basic WHERE visit_details_id = ?", array((int) $id))->result_array() as $b) {
                    $basic_ids[] = (int) $b['id'];
                    $meds = array_merge($meds, $this->prescription_meds($b['id']));
                }
                $complaints = trim(strip_tags((string) $r['symptoms']));
                $date = $r['appointment_date'] ?: ($r['date'] ?: $r['created_at']);
                return array('patient_id' => $r['patient_id'], 'reference' => 'OPD-' . $r['id'], 'hiType' => 'OPConsultation',
                    'display' => 'OPD consultation ' . $this->label_date($date) . ($doctor ? ' - Dr. ' . $doctor : ''), 'recordDate' => $this->iso($date),
                    'data' => array('doctor' => array('name' => $doctor ?: 'Consultant'),
                        'chiefComplaints' => $complaints !== '' ? array($complaints) : array(),
                        'allergies' => $this->allergies($r['patient_id'], $r['known_allergies']),
                        'observations' => $this->vitals($r), 'diagnoses' => $this->icd_codes('opd_icd10_codes', 'opd_id', $r['opd_id']),
                        'medications' => $this->unique_rows($meds), 'investigations' => $this->unique_rows($this->ordered_tests($basic_ids)),
                        'followUp' => $this->follow_up($basic_ids),
                        'notes' => trim(strip_tags((string) $r['note']))));

            case 'rx':
                $b = $this->db->query("SELECT b.*, COALESCE(od.patient_id, ip.patient_id) patient_id, COALESCE(vd.cons_doctor, ip.cons_doctor) doctor_id
                    FROM ipd_prescription_basic b LEFT JOIN visit_details vd ON vd.id = b.visit_details_id LEFT JOIN opd_details od ON od.id = vd.opd_details_id
                    LEFT JOIN ipd_details ip ON ip.id = b.ipd_id WHERE b.id = ?", array((int) $id))->row_array();
                if (!$b || !$b['patient_id']) {
                    return null;
                }
                $meds = $this->prescription_meds($b['id']);
                if (!$meds) {
                    return null;
                }
                $doctor = $this->staff_name($b['prescribe_by'] ?: $b['doctor_id']);
                return array('patient_id' => $b['patient_id'], 'reference' => 'RX-' . $b['id'], 'hiType' => 'Prescription',
                    'display' => ($b['ipd_id'] ? 'IPD' : 'OPD') . ' prescription ' . $this->label_date($b['date'] ?: $b['created_at']) . ($doctor ? ' - Dr. ' . $doctor : ''),
                    'recordDate' => $this->iso($b['date'] ?: $b['created_at']),
                    'data' => array('doctor' => array('name' => $doctor ?: 'Consultant'), 'medications' => $meds,
                        'notes' => trim(strip_tags($b['header_note'] . "\n" . $b['finding_description'] . "\n" . $b['footer_note']))));

            case 'lab':
            case 'rad':
                $lab = $entity === 'lab';
                $t   = $lab ? 'pathology' : 'radiology';
                $r = $this->db->query("SELECT r.*, x.test_name FROM {$t}_report r LEFT JOIN " . ($lab ? 'pathology' : 'radio') . " x ON x.id = r.{$t}_id WHERE r.id = ?",
                    array((int) $id))->row_array();
                if (!$r || !$r['patient_id']) {
                    return null;
                }
                // {$t}_parameter.unit is an id into the `unit` table.
                // LOINC codes confirmed on the Lab codes screen (pathology only).
                $loinc_sel  = $lab ? 'l.loinc_code' : 'NULL loinc_code';
                $loinc_join = $lab ? 'LEFT JOIN abdm_loinc l ON l.parameter_id = p.id' : '';
                $params = $this->db->query("SELECT p.parameter_name, COALESCE(u.unit_name, '') unit, p.reference_range, v.{$t}_report_value val, $loinc_sel
                    FROM {$t}_report_parameterdetails v
                    JOIN {$t}_parameterdetails pd ON pd.id = v.{$t}_parameterdetail_id
                    JOIN {$t}_parameter p ON p.id = pd.{$t}_parameter_id
                    LEFT JOIN unit u ON u.id = p.unit $loinc_join
                    WHERE v.{$t}_report_id = ?", array((int) $id))->result_array();
                $obs = array();
                foreach ($params as $p) {
                    if (trim((string) $p['val']) === '') {
                        continue;
                    }
                    $o = array('name' => $p['parameter_name'], 'value' => $this->num_or_str($p['val']), 'unit' => (string) $p['unit'],
                        'referenceRange' => $p['reference_range'] ?: null);
                    if (!empty($p['loinc_code'])) {
                        $o['loinc'] = $p['loinc_code'];
                    }
                    $obs[] = $o;
                }
                $conclusion = trim(strip_tags((string) ($r[$t . '_result'] ?: $r['description'])));
                if (!$obs && $conclusion === '' && !$r[$t . '_report']) {
                    return null;
                }
                $doctor = $this->staff_name($r['approved_by']);
                $name   = $r['test_name'] ?: ($r['report_name'] ?: ($lab ? 'Laboratory report' : 'Radiology report'));
                $rdate = $r['reporting_date'] ?: ($r['collection_date'] ?: $r['created_at']);
                $data = array('doctor' => array('name' => $doctor ?: ($lab ? 'Pathologist' : 'Radiologist')), 'category' => $lab ? 'LAB' : 'RAD',
                    'reportName' => $name, 'observations' => $obs, 'conclusion' => $conclusion);
                $file = $this->report_file($r[$t . '_report'], 'uploads/' . $t . '_report');
                if ($file) {
                    $data['pdf'] = $file;
                }
                return array('patient_id' => $r['patient_id'], 'reference' => strtoupper($entity) . '-' . $r['id'], 'hiType' => 'DiagnosticReport',
                    'display' => ($lab ? 'Lab: ' : 'Radiology: ') . $name . ' ' . $this->label_date($rdate),
                    'recordDate' => $this->iso($rdate), 'data' => $data);

            case 'ds':
                $d = $this->db->query("SELECT dc.*, COALESCE(ip.patient_id, od.patient_id) patient_id, ip.symptoms, ip.id ipd_id
                    FROM discharge_card dc LEFT JOIN ipd_details ip ON ip.id = dc.ipd_details_id LEFT JOIN opd_details od ON od.id = dc.opd_details_id
                    WHERE dc.id = ?", array((int) $id))->row_array();
                if (!$d || !$d['patient_id']) {
                    return null;
                }
                $diag = $d['ipd_id'] ? $this->icd_codes('ipd_icd10_codes', 'ipd_id', $d['ipd_id']) : array();
                if (trim((string) $d['diagnosis']) !== '') {
                    $diag[] = array('text' => trim(strip_tags($d['diagnosis'])));
                }
                $notes = array();
                foreach (array('operation' => 'Operation', 'investigations' => 'Investigations', 'treatment_home' => 'Treatment at home', 'note' => 'Note') as $k => $label) {
                    if (trim(strip_tags((string) $d[$k])) !== '') {
                        $notes[] = $label . ': ' . trim(strip_tags($d[$k]));
                    }
                }
                $complaints = trim(strip_tags((string) $d['symptoms']));
                return array('patient_id' => $d['patient_id'], 'reference' => 'DS-' . $d['id'], 'hiType' => 'DischargeSummary',
                    'display' => 'Discharge summary ' . $this->label_date($d['discharge_date'] ?: $d['created_at']),
                    'recordDate' => $this->iso($d['discharge_date'] ?: $d['created_at']),
                    'data' => array('doctor' => array('name' => $this->staff_name($d['discharge_by']) ?: 'Consultant'),
                        'chiefComplaints' => $complaints !== '' ? array($complaints) : array(), 'diagnoses' => $diag,
                        'allergies' => $this->allergies($d['patient_id']),
                        'medications' => array(), 'notes' => implode("\n", $notes)));
        }
        return null;
    }

    // =====================================================================================
    // Sync to the bridge
    // =====================================================================================

    /**
     * Push new/changed records (all patients, or one patient). Returns a summary array.
     * Unchanged records are detected by hash and not re-sent.
     */
    public function sync($client, $patient_id = null, $limit = null)
    {
        $limit  = $limit ?: (int) $this->cfg('abdm_batch_size');
        $from   = $this->cfg('abdm_sync_from');
        $resync = (int) $this->cfg('abdm_resync_days');
        $candidates = array();
        foreach ($this->entity_sql() as $entity => $sql) {
            $params = array($entity, $from);
            $where  = '';
            if ($patient_id) {
                $where    = ' AND q.patient_id = ?';
                $params[] = (int) $patient_id;
            }
            $params[] = $resync;
            $rows = $this->db->query("SELECT q.id, q.patient_id FROM ($sql) q
                LEFT JOIN abdm_sync s ON s.entity = ? AND s.entity_id = q.id
                WHERE q.rec_date >= ? $where
                  AND (s.entity_id IS NULL OR s.error IS NOT NULL OR q.marker > s.synced_at
                       OR (q.rec_date >= NOW() - INTERVAL ? DAY AND (s.checked_at IS NULL OR s.checked_at < NOW() - INTERVAL 1 HOUR)))
                ORDER BY q.id LIMIT " . (int) $limit, $params)->result_array();
            foreach ($rows as $r) {
                $candidates[] = array($entity, (int) $r['id']);
            }
            if (count($candidates) >= $limit) {
                break;
            }
        }
        $candidates = array_slice($candidates, 0, $limit);

        $ccs = array();
        $patients = array();
        $meta = array();
        $summary = array('checked' => count($candidates), 'sent' => 0, 'unchanged' => 0, 'skipped' => 0, 'errors' => array());
        foreach ($candidates as $c) {
            list($entity, $id) = $c;
            $cc = $this->build($entity, $id);
            if (!$cc) {
                $summary['skipped']++;
                continue;
            }
            $pid  = (int) $cc['patient_id'];
            unset($cc['patient_id']);
            $cc['patientMrn'] = $this->mrn($pid);
            $hash = hash('sha256', json_encode($cc));
            $prev = $this->db->get_where('abdm_sync', array('entity' => $entity, 'entity_id' => $id))->row_array();
            if ($prev && $prev['hash'] === $hash && !$prev['error']) {
                $this->db->query("UPDATE abdm_sync SET checked_at = NOW() WHERE entity = ? AND entity_id = ?", array($entity, $id));
                $summary['unchanged']++;
                continue;
            }
            if (!isset($patients[$pid])) {
                $p = $this->get_patient($pid);
                if (!$p) {
                    $summary['skipped']++;
                    continue;
                }
                $patients[$pid] = $this->patient_payload($p);
            }
            $ccs[] = $cc;
            $meta[$cc['reference']] = array($entity, $id, $pid, $hash);
        }
        if (!$ccs) {
            return $summary;
        }

        // notifyUpdates: an edited record that is already linked is announced to the patient's PHR app (HIP_INTI_LINK_506).
        $res = $client->post('/hip/sync', array('patients' => array_values($patients), 'careContexts' => $ccs, 'notifyUpdates' => true));
        foreach ($res['patients'] as $p) {
            if (!$p['ok']) {
                $summary['errors'][] = 'Patient ' . $p['mrn'] . ': ' . $p['error'];
            }
        }
        foreach ($res['careContexts'] as $r) {
            if (!isset($meta[$r['reference']])) {
                continue;
            }
            list($entity, $id, $pid, $hash) = $meta[$r['reference']];
            // Timestamps from the DB clock: they are compared with NOW() and record timestamps in SQL.
            $this->db->query("REPLACE INTO abdm_sync (entity, entity_id, patient_id, reference, hash, linked, synced_at, checked_at, error)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)", array($entity, $id, $pid, $r['reference'], $r['ok'] ? $hash : null,
                !empty($r['linked']) ? 1 : 0, $r['ok'] ? null : substr($r['error'], 0, 500)));
            if ($r['ok']) {
                $summary['sent']++;
            } else {
                $summary['errors'][] = $r['reference'] . ': ' . $r['error'];
            }
        }
        return $summary;
    }

    /**
     * Link every shared but unlinked record of patients who have an ABHA, with no click (HIP_INTI_LINK_505).
     * The bridge returns a job already in flight rather than starting another, so running this each cron is safe.
     */
    public function auto_link($client, $limit = 25)
    {
        $rows = $this->db->query("SELECT DISTINCT s.patient_id FROM abdm_sync s JOIN abdm_patients a ON a.patient_id = s.patient_id
            WHERE s.linked = 0 AND s.error IS NULL AND a.abha_address IS NOT NULL ORDER BY s.patient_id LIMIT " . (int) $limit)->result_array();
        $out = array('patients' => count($rows), 'started' => 0, 'running' => 0, 'done' => 0, 'errors' => array());
        foreach ($rows as $r) {
            $pid = (int) $r['patient_id'];
            try {
                $res = $client->post('/hip/patients/' . rawurlencode($this->mrn($pid)) . '/link', array());
                $out[!empty($res['existing']) ? 'running' : 'started']++;
            } catch (Exception $e) {
                if ((int) $e->getCode() === 400 && stripos($e->getMessage(), 'no unlinked') !== false) {
                    // Everything this patient shared is linked: stop asking until a new record arrives.
                    $this->db->query('UPDATE abdm_sync SET linked = 1 WHERE patient_id = ?', array($pid));
                    $out['done']++;
                } else {
                    $out['errors'][] = 'Patient ' . $pid . ': ' . $e->getMessage();
                }
            }
        }
        return $out;
    }

    /** Push just the patient demographics/ABHA (e.g. right after ABHA verification). */
    public function sync_patient($client, $patient_id)
    {
        $p = $this->get_patient($patient_id);
        if (!$p) {
            throw new Exception('Patient not found');
        }
        $res = $client->post('/hip/sync', array('patients' => array($this->patient_payload($p)), 'careContexts' => array()));
        if (empty($res['patients'][0]['ok'])) {
            throw new Exception(isset($res['patients'][0]['error']) ? $res['patients'][0]['error'] : 'Patient sync failed');
        }
    }

    public function records_for_patient($patient_id)
    {
        $out = array();
        foreach ($this->entity_sql() as $entity => $sql) {
            $rows = $this->db->query("SELECT q.id, q.rec_date, s.reference, s.synced_at, s.linked, s.error FROM ($sql) q
                LEFT JOIN abdm_sync s ON s.entity = ? AND s.entity_id = q.id WHERE q.patient_id = ? ORDER BY q.rec_date DESC",
                array($entity, (int) $patient_id))->result_array();
            foreach ($rows as $r) {
                $r['entity'] = $entity;
                $out[] = $r;
            }
        }
        usort($out, function ($a, $b) { return strcmp($b['rec_date'], $a['rec_date']); });
        return $out;
    }

    public function stats()
    {
        return array(
            'abha_patients' => (int) $this->db->count_all('abdm_patients'),
            'synced'        => (int) $this->db->where('error IS NULL', null, false)->count_all_results('abdm_sync'),
            'errors'        => $this->db->query("SELECT entity, entity_id, reference, error, synced_at FROM abdm_sync WHERE error IS NOT NULL ORDER BY synced_at DESC LIMIT 20")->result_array(),
            'last_sync'     => $this->db->query("SELECT MAX(synced_at) t FROM abdm_sync")->row()->t,
        );
    }

    /** Create a Smart Hospital patient from an ABHA profile (Scan & Share). Returns patient id. */
    public function create_patient_from_abha($profile, $staff_id, $note = 'Registered via ABHA Scan & Share')
    {
        $dob = null;
        if (!empty($profile['yearOfBirth'])) {
            $dob = sprintf('%04d-%02d-%02d', $profile['yearOfBirth'], !empty($profile['monthOfBirth']) ? $profile['monthOfBirth'] : 1, !empty($profile['dayOfBirth']) ? $profile['dayOfBirth'] : 1);
        }
        $age = $dob ? (int) date_diff(date_create($dob), date_create('today'))->y : 0;
        $gender = array('M' => 'Male', 'F' => 'Female', 'O' => 'Other');
        $g = strtoupper(substr(isset($profile['gender']) ? (string) $profile['gender'] : '', 0, 1));
        $addr = isset($profile['address']) ? (is_array($profile['address']) ? $profile['address'] : array('line' => (string) $profile['address'])) : array();
        $pick = function ($k) use ($profile, $addr) {
            $v = isset($addr[$k]) ? $addr[$k] : (isset($profile[$k]) && !is_array($profile[$k]) ? $profile[$k] : '');
            return trim((string) $v);
        };
        // The full address in 'address' (what existing screens show); its parts in the structured columns below.
        $line = trim(implode(', ', array_filter(array($pick('line'), $pick('city'), $pick('district'), $pick('state'), $pick('pincode')))));
        $row = array(
            'patient_name' => $profile['name'], 'dob' => $dob, 'age' => $age, 'month' => 0, 'day' => 0, 'as_of_date' => date('Y-m-d'),
            'mobileno' => isset($profile['phoneNumber']) ? $profile['phoneNumber'] : '', 'gender' => isset($gender[$g]) ? $gender[$g] : 'Other',
            'email' => isset($profile['email']) ? (string) $profile['email'] : '',
            'marital_status' => '', 'blood_group' => '', 'address' => $line,
            'patient_type' => '', 'identification_number' => '', 'known_allergies' => '', 'note' => $note,
            'is_ipd' => '', 'app_key' => '', 'is_antenatal' => 0, 'is_active' => 'yes', 'generated_by' => $staff_id,
        );
        // Structured address columns, where this install has them.
        foreach (array('state' => 'state', 'district' => 'district', 'city_village' => 'city', 'pincode' => 'pincode', 'address_line' => 'line') as $col => $k) {
            if ($this->db->field_exists($col, 'patients') && $pick($k) !== '') {
                $row[$col] = $pick($k);
            }
        }
        // Patient and ABHA tag together, or neither: a refused tag must not leave an untagged duplicate patient.
        $this->db->trans_begin();
        try {
            $this->db->insert('patients', $row);
            $id = $this->db->insert_id();
            $this->save_abha($id, isset($profile['abhaNumber']) ? $profile['abhaNumber'] : null, isset($profile['abhaAddress']) ? $profile['abhaAddress'] : null, $profile['name'], $staff_id);
        } catch (Exception $e) {
            $this->db->trans_rollback();
            throw $e;
        }
        $this->db->trans_commit();
        return $id;
    }
}
