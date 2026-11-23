<?php defined('BASEPATH') or exit('No direct script access allowed');

/**
 * PM-JAY (Ayushman Bharat) data layer.
 * Phase 1: beneficiaries, HBP package master, IPD cases with pre-auth / claim tracking (TMS 2.0 portal workflow).
 * Phase 2: NHCX exchanges (FHIR bundles). In 'simulator' mode responses are generated locally for demos.
 */
class Pmjay_model extends CI_Model
{
    /** Case pipeline: status => [label, badge colour, allowed next statuses]. */
    const STATUSES = array(
        'registered'        => array('Registered', 'secondary', array('preauth_submitted', 'cancelled')),
        'preauth_submitted' => array('Pre-auth submitted', 'warning', array('preauth_approved', 'query', 'rejected')),
        'preauth_approved'  => array('Pre-auth approved', 'info', array('discharged', 'cancelled')),
        'discharged'        => array('Discharged', 'primary', array('claim_submitted')),
        'claim_submitted'   => array('Claim submitted', 'warning', array('claim_approved', 'query', 'rejected')),
        'query'             => array('Query raised', 'danger', array('preauth_submitted', 'claim_submitted')),
        'claim_approved'    => array('Claim approved', 'success', array('paid')),
        'paid'              => array('Paid', 'success', array()),
        'rejected'          => array('Rejected', 'dark', array()),
        'cancelled'         => array('Cancelled', 'light', array()),
    );

    /** Main pipeline shown as a stepper. */
    const PIPELINE = array('registered', 'preauth_submitted', 'preauth_approved', 'discharged', 'claim_submitted', 'claim_approved', 'paid');

    const STANDARD_DOCS = array('PM-JAY / Ayushman card', 'Aadhaar / ID proof', 'Patient photo at admission', 'Discharge summary', 'Final bill');

    // =====================================================================================
    // Schema + permissions (idempotent; once per session)
    // =====================================================================================
    public function ensure_schema()
    {
        static $done = false;
        if ($done || (!is_cli() && $this->session->userdata('pmjay_schema_v1'))) {
            return;
        }
        $q = function ($sql) { $this->db->query($sql); };
        $q("CREATE TABLE IF NOT EXISTS `pmjay_beneficiaries` (
            `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `patient_id` int NOT NULL,
            `pmjay_id` varchar(40) NOT NULL,
            `family_id` varchar(40) DEFAULT NULL,
            `card_name` varchar(150) DEFAULT NULL,
            `scheme` varchar(80) NOT NULL DEFAULT 'AB PM-JAY',
            `state` varchar(60) DEFAULT NULL,
            `verified` tinyint(1) NOT NULL DEFAULT 0,
            `verified_at` datetime DEFAULT NULL,
            `verified_by` int DEFAULT NULL,
            `remarks` varchar(255) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `pmjay_ben_patient` (`patient_id`),
            KEY `pmjay_ben_id` (`pmjay_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $q("CREATE TABLE IF NOT EXISTS `pmjay_packages` (
            `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `code` varchar(30) NOT NULL,
            `name` varchar(200) NOT NULL,
            `specialty` varchar(80) DEFAULT NULL,
            `rate` decimal(12,2) NOT NULL DEFAULT 0,
            `preauth` tinyint(1) NOT NULL DEFAULT 1,
            `los_days` int DEFAULT NULL,
            `documents` text,
            `is_sample` tinyint(1) NOT NULL DEFAULT 0,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            UNIQUE KEY `pmjay_pkg_code` (`code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $q("CREATE TABLE IF NOT EXISTS `pmjay_cases` (
            `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `patient_id` int NOT NULL,
            `ipd_id` int DEFAULT NULL,
            `package_id` int NOT NULL,
            `tms_case_no` varchar(40) DEFAULT NULL,
            `status` varchar(30) NOT NULL DEFAULT 'registered',
            `package_amount` decimal(12,2) NOT NULL DEFAULT 0,
            `preauth_amount` decimal(12,2) DEFAULT NULL,
            `claim_amount` decimal(12,2) DEFAULT NULL,
            `approved_amount` decimal(12,2) DEFAULT NULL,
            `paid_amount` decimal(12,2) DEFAULT NULL,
            `payment_ref` varchar(60) DEFAULT NULL,
            `admission_date` date DEFAULT NULL,
            `discharge_date` date DEFAULT NULL,
            `diagnosis` varchar(255) DEFAULT NULL,
            `docs` text,
            `created_by` int DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `pmjay_case_patient` (`patient_id`),
            KEY `pmjay_case_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $q("CREATE TABLE IF NOT EXISTS `pmjay_case_events` (
            `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `case_id` int NOT NULL,
            `status` varchar(30) NOT NULL,
            `amount` decimal(12,2) DEFAULT NULL,
            `remarks` varchar(500) DEFAULT NULL,
            `source` varchar(10) NOT NULL DEFAULT 'manual',
            `staff_id` int DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `pmjay_ev_case` (`case_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $q("CREATE TABLE IF NOT EXISTS `pmjay_nhcx_messages` (
            `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `case_id` int NOT NULL,
            `type` varchar(30) NOT NULL,
            `correlation_id` char(36) NOT NULL,
            `mode` varchar(12) NOT NULL,
            `status` varchar(20) NOT NULL,
            `outcome` varchar(255) DEFAULT NULL,
            `request` longtext,
            `response` longtext,
            `staff_id` int DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `pmjay_nhcx_case` (`case_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (!$this->db->count_all('pmjay_packages')) {
            $this->seed_sample_packages();
        }

        $group = $this->db->get_where('permission_group', array('short_code' => 'pmjay'))->row();
        if (!$group) {
            $this->db->insert('permission_group', array('name' => 'PM-JAY', 'short_code' => 'pmjay', 'is_active' => 1, 'system' => 0, 'sort_order' => 30.6));
            $group_id = $this->db->insert_id();
        } else {
            $group_id = $group->id;
        }
        $cats = array(
            array('PM-JAY Cases (pre-auth / claims)', 'pmjay_cases', 1, 1, 1, 1),
            array('PM-JAY Beneficiaries', 'pmjay_beneficiaries', 1, 1, 1, 0),
            array('PM-JAY Packages (HBP master)', 'pmjay_packages', 1, 1, 1, 1),
            array('PM-JAY NHCX Exchange', 'pmjay_nhcx', 1, 1, 0, 0),
        );
        foreach ($cats as $c) {
            if (!$this->db->get_where('permission_category', array('short_code' => $c[1]))->row()) {
                $this->db->insert('permission_category', array('perm_group_id' => $group_id, 'name' => $c[0], 'short_code' => $c[1],
                    'enable_view' => $c[2], 'enable_add' => $c[3], 'enable_edit' => $c[4], 'enable_delete' => $c[5]));
            }
        }
        $done = true;
        if (!is_cli()) {
            $this->session->set_userdata('pmjay_schema_v1', true);
        }
    }

    /** Demo rows, clearly flagged; replace with the state's HBP master before go-live. */
    private function seed_sample_packages()
    {
        $rows = array(
            array('DEMO-SG001', 'Laparoscopic cholecystectomy', 'General Surgery', 22800, 1, 3, "USG abdomen report\nLFT report\nOperative notes"),
            array('DEMO-SG002', 'Inguinal hernia repair (open, with mesh)', 'General Surgery', 15200, 1, 2, "Clinical photo\nOperative notes"),
            array('DEMO-OR001', 'Total knee replacement (unilateral)', 'Orthopaedics', 80000, 1, 7, "Knee X-ray (pre-op)\nImplant sticker / invoice\nX-ray (post-op)"),
            array('DEMO-OB001', 'Caesarean delivery', 'Obstetrics', 12000, 1, 4, "USG (obstetric)\nOperative notes\nBirth details"),
            array('DEMO-OB002', 'Normal delivery', 'Obstetrics', 6000, 0, 2, "Partograph\nBirth details"),
            array('DEMO-MG001', 'Acute febrile illness (per day, general ward)', 'General Medicine', 1800, 1, null, "Daily progress notes\nCBC / malaria / dengue tests"),
            array('DEMO-MG002', 'Pneumonia (per day, HDU)', 'General Medicine', 2700, 1, null, "Chest X-ray\nDaily progress notes"),
            array('DEMO-CA001', 'PTCA with single drug-eluting stent', 'Cardiology', 65000, 1, 3, "ECG\nAngiography CD / report\nStent invoice / sticker"),
        );
        foreach ($rows as $r) {
            $this->db->insert('pmjay_packages', array('code' => $r[0], 'name' => $r[1], 'specialty' => $r[2], 'rate' => $r[3], 'preauth' => $r[4],
                'los_days' => $r[5], 'documents' => $r[6], 'is_sample' => 1, 'is_active' => 1));
        }
    }

    // =====================================================================================
    // Helpers
    // =====================================================================================
    public static function label($status)
    {
        return isset(self::STATUSES[$status]) ? self::STATUSES[$status][0] : $status;
    }

    public static function badge($status)
    {
        $c = isset(self::STATUSES[$status]) ? self::STATUSES[$status][1] : 'secondary';
        return '<span class="badge bg-' . $c . ($c === 'light' ? ' text-dark' : '') . '">' . html_escape(self::label($status)) . '</span>';
    }

    public function next_statuses($status)
    {
        return isset(self::STATUSES[$status]) ? self::STATUSES[$status][2] : array();
    }

    public function case_no($id)
    {
        return 'PMJ-' . str_pad((int) $id, 5, '0', STR_PAD_LEFT);
    }

    public function search_patients($q)
    {
        $q = trim((string) $q);
        if ($q === '') {
            return array();
        }
        $this->db->select('p.id, p.patient_name, p.gender, p.age, p.mobileno, b.id ben_id, b.pmjay_id, b.verified')
            ->from('patients p')->join('pmjay_beneficiaries b', 'b.patient_id = p.id', 'left')->where('p.is_active', 'yes');
        if (ctype_digit($q) && strlen($q) < 8) {
            $this->db->where('p.id', (int) $q);
        } else {
            $this->db->group_start()->like('p.patient_name', $q)->or_like('p.mobileno', $q)->or_like('b.pmjay_id', $q)->group_end();
        }
        return $this->db->order_by('p.id', 'DESC')->limit(15)->get()->result_array();
    }

    // =====================================================================================
    // Dashboard
    // =====================================================================================
    public function stats()
    {
        $by = array();
        foreach ($this->db->query('SELECT status, COUNT(*) n, COALESCE(SUM(package_amount),0) pkg, COALESCE(SUM(approved_amount),0) appr,
                COALESCE(SUM(paid_amount),0) paid FROM pmjay_cases GROUP BY status')->result_array() as $r) {
            $by[$r['status']] = $r;
        }
        $n = function ($s) use ($by) { return isset($by[$s]) ? (int) $by[$s]['n'] : 0; };
        $sum = function ($f, $list) use ($by) { $t = 0; foreach ($list as $s) { $t += isset($by[$s]) ? (float) $by[$s][$f] : 0; } return $t; };
        $open = array('registered', 'preauth_submitted', 'preauth_approved', 'discharged', 'claim_submitted', 'query', 'claim_approved');
        return array(
            'by'            => $by,
            'active'        => array_sum(array_map($n, $open)),
            'preauth_pending' => $n('preauth_submitted'),
            'queries'       => $n('query'),
            'claims_pending' => $n('claim_submitted'),
            'approved_unpaid' => $sum('appr', array('claim_approved')),
            'paid'          => $sum('paid', array('paid')),
            'pipeline_value' => $sum('pkg', $open),
            'beneficiaries' => (int) $this->db->count_all('pmjay_beneficiaries'),
            'verified'      => (int) $this->db->where('verified', 1)->count_all_results('pmjay_beneficiaries'),
        );
    }

    // =====================================================================================
    // Beneficiaries
    // =====================================================================================
    public function beneficiaries($q = '')
    {
        $this->db->select('b.*, p.patient_name, p.gender, p.age, p.mobileno, a.abha_address, a.abha_number')
            ->from('pmjay_beneficiaries b')->join('patients p', 'p.id = b.patient_id')
            ->join('abdm_patients a', 'a.patient_id = b.patient_id', 'left');
        if ($q !== '') {
            $this->db->group_start()->like('p.patient_name', $q)->or_like('b.pmjay_id', $q)->or_like('p.mobileno', $q)->group_end();
        }
        return $this->db->order_by('b.id', 'DESC')->limit(300)->get()->result_array();
    }

    public function beneficiary_by_patient($patient_id)
    {
        return $this->db->get_where('pmjay_beneficiaries', array('patient_id' => (int) $patient_id))->row_array();
    }

    public function save_beneficiary($data, $staff_id)
    {
        $row = array(
            'patient_id' => (int) $data['patient_id'], 'pmjay_id' => strtoupper(trim($data['pmjay_id'])),
            'family_id' => trim($data['family_id']), 'card_name' => trim($data['card_name']), 'scheme' => trim($data['scheme']) ?: 'AB PM-JAY',
            'state' => trim($data['state']), 'remarks' => trim($data['remarks']),
            'verified' => empty($data['verified']) ? 0 : 1,
        );
        $existing = $this->beneficiary_by_patient($row['patient_id']);
        if ($row['verified'] && (!$existing || !$existing['verified'])) {
            $row['verified_at'] = date('Y-m-d H:i:s');
            $row['verified_by'] = $staff_id;
        }
        if (!$row['verified']) {
            $row['verified_at'] = null;
            $row['verified_by'] = null;
        }
        if ($existing) {
            $this->db->where('id', $existing['id'])->update('pmjay_beneficiaries', $row);
            return (int) $existing['id'];
        }
        $this->db->insert('pmjay_beneficiaries', $row);
        return (int) $this->db->insert_id();
    }

    // =====================================================================================
    // Packages
    // =====================================================================================
    public function packages($active_only = false)
    {
        if ($active_only) {
            $this->db->where('is_active', 1);
        }
        return $this->db->order_by('specialty, name')->get('pmjay_packages')->result_array();
    }

    public function package($id)
    {
        return $this->db->get_where('pmjay_packages', array('id' => (int) $id))->row_array();
    }

    // =====================================================================================
    // Cases
    // =====================================================================================
    public function cases($status = '', $q = '')
    {
        $this->db->select('c.*, p.patient_name, p.gender, p.age, k.code pkg_code, k.name pkg_name, b.pmjay_id')
            ->from('pmjay_cases c')->join('patients p', 'p.id = c.patient_id')->join('pmjay_packages k', 'k.id = c.package_id', 'left')
            ->join('pmjay_beneficiaries b', 'b.patient_id = c.patient_id', 'left');
        if ($status !== '') {
            $this->db->where('c.status', $status);
        }
        if ($q !== '') {
            $this->db->group_start()->like('p.patient_name', $q)->or_like('c.tms_case_no', $q)->or_like('b.pmjay_id', $q)->group_end();
        }
        return $this->db->order_by('c.updated_at', 'DESC')->limit(300)->get()->result_array();
    }

    public function get_case($id)
    {
        return $this->db->query("SELECT c.*, p.patient_name, p.gender, p.age, p.dob, p.mobileno, p.address,
                k.code pkg_code, k.name pkg_name, k.specialty, k.rate pkg_rate, k.preauth pkg_preauth, k.los_days, k.documents pkg_documents, k.is_sample pkg_sample,
                b.pmjay_id, b.family_id, b.card_name, b.scheme, b.state, b.verified, b.verified_at,
                a.abha_number, a.abha_address
            FROM pmjay_cases c JOIN patients p ON p.id = c.patient_id
            LEFT JOIN pmjay_packages k ON k.id = c.package_id
            LEFT JOIN pmjay_beneficiaries b ON b.patient_id = c.patient_id
            LEFT JOIN abdm_patients a ON a.patient_id = c.patient_id
            WHERE c.id = ?", array((int) $id))->row_array();
    }

    public function cases_for_patient($patient_id)
    {
        return $this->db->select('id, status, package_amount, created_at')->where('patient_id', (int) $patient_id)
            ->order_by('id', 'DESC')->get('pmjay_cases')->result_array();
    }

    public function ipd_admissions($patient_id)
    {
        return $this->db->select('id, date, discharged')->where('patient_id', (int) $patient_id)->order_by('id', 'DESC')->get('ipd_details')->result_array();
    }

    public function create_case($data, $staff_id)
    {
        $pkg = $this->package($data['package_id']);
        $this->db->insert('pmjay_cases', array(
            'patient_id' => (int) $data['patient_id'], 'ipd_id' => !empty($data['ipd_id']) ? (int) $data['ipd_id'] : null,
            'package_id' => (int) $pkg['id'], 'package_amount' => $pkg['rate'], 'status' => 'registered',
            'admission_date' => !empty($data['admission_date']) ? $data['admission_date'] : date('Y-m-d'),
            'diagnosis' => trim($data['diagnosis']), 'docs' => json_encode(array()), 'created_by' => $staff_id,
        ));
        $id = (int) $this->db->insert_id();
        $this->add_event($id, 'registered', null, 'Case registered for ' . $pkg['code'] . ' ' . $pkg['name'], 'manual', $staff_id);
        return $id;
    }

    /** Apply a status change with its amounts; returns error string or null. */
    public function move($case, $to, $in, $staff_id, $source = 'manual')
    {
        if (!in_array($to, $this->next_statuses($case['status']), true)) {
            return 'Cannot move from "' . self::label($case['status']) . '" to "' . self::label($to) . '".';
        }
        $upd = array('status' => $to);
        $amount = isset($in['amount']) && $in['amount'] !== '' ? round((float) $in['amount'], 2) : null;
        if ($amount !== null && $amount < 0) {
            return 'Amount cannot be negative.';
        }
        if (!empty($in['tms_case_no'])) {
            $upd['tms_case_no'] = strtoupper(trim($in['tms_case_no']));
        }
        switch ($to) {
            case 'preauth_submitted':
                $upd['preauth_amount'] = $amount !== null ? $amount : $case['package_amount'];
                $amount = $upd['preauth_amount'];
                break;
            case 'preauth_approved':
                if ($amount === null) {
                    return 'Enter the approved pre-auth amount.';
                }
                $upd['preauth_amount'] = $amount;
                break;
            case 'discharged':
                $upd['discharge_date'] = !empty($in['date']) ? $in['date'] : date('Y-m-d');
                break;
            case 'claim_submitted':
                $upd['claim_amount'] = $amount !== null ? $amount : ($case['preauth_amount'] ?: $case['package_amount']);
                $amount = $upd['claim_amount'];
                break;
            case 'claim_approved':
                if ($amount === null) {
                    return 'Enter the approved claim amount.';
                }
                $upd['approved_amount'] = $amount;
                break;
            case 'paid':
                $upd['paid_amount'] = $amount !== null ? $amount : $case['approved_amount'];
                $amount = $upd['paid_amount'];
                $upd['payment_ref'] = isset($in['payment_ref']) ? trim($in['payment_ref']) : null;
                break;
        }
        $this->db->where('id', $case['id'])->update('pmjay_cases', $upd);
        $this->add_event($case['id'], $to, $amount, isset($in['remarks']) ? trim($in['remarks']) : '', $source, $staff_id);
        return null;
    }

    public function add_event($case_id, $status, $amount, $remarks, $source, $staff_id)
    {
        $this->db->insert('pmjay_case_events', array('case_id' => (int) $case_id, 'status' => $status, 'amount' => $amount,
            'remarks' => mb_substr((string) $remarks, 0, 500), 'source' => $source, 'staff_id' => $staff_id));
    }

    public function events($case_id)
    {
        return $this->db->query("SELECT e.*, TRIM(CONCAT(COALESCE(s.name,''),' ',COALESCE(s.surname,''))) staff_name
            FROM pmjay_case_events e LEFT JOIN staff s ON s.id = e.staff_id WHERE e.case_id = ? ORDER BY e.id DESC", array((int) $case_id))->result_array();
    }

    /** Required documents for a case: standard list + package-specific. */
    public function required_docs($case)
    {
        $pkg = array_filter(array_map('trim', preg_split('/\r?\n/', (string) $case['pkg_documents'])));
        return array_values(array_unique(array_merge(self::STANDARD_DOCS, $pkg)));
    }

    public function save_docs($case_id, $ticked)
    {
        $this->db->where('id', (int) $case_id)->update('pmjay_cases', array('docs' => json_encode(array_values($ticked))));
    }

    // =====================================================================================
    // Phase 2: NHCX
    // =====================================================================================
    public function messages($case_id = null, $limit = 100)
    {
        $this->db->select('m.*, p.patient_name, c.patient_id')->from('pmjay_nhcx_messages m')
            ->join('pmjay_cases c', 'c.id = m.case_id', 'left')->join('patients p', 'p.id = c.patient_id', 'left');
        if ($case_id) {
            $this->db->where('m.case_id', (int) $case_id);
        }
        return $this->db->order_by('m.id', 'DESC')->limit($limit)->get()->result_array();
    }

    public function log_message($row)
    {
        $this->db->insert('pmjay_nhcx_messages', $row);
        return (int) $this->db->insert_id();
    }

    public static function uuid()
    {
        $b = random_bytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
