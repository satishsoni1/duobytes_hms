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
        if ($done || (!is_cli() && $this->session->userdata('abdm_schema_v1'))) {
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
        $done = true;
        if (!is_cli()) {
            $this->session->set_userdata('abdm_schema_v1', true);
        }
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

    public function save_abha($patient_id, $abha_number, $abha_address, $abha_name, $staff_id)
    {
        $row = array(
            'patient_id'   => (int) $patient_id,
            'abha_number'  => $abha_number ? preg_replace('/^(\d{2})(\d{4})(\d{4})(\d{4})$/', '$1-$2-$3-$4', preg_replace('/\D/', '', $abha_number)) : null,
            'abha_address' => $abha_address ? strtolower($abha_address) : null,
            'abha_name'    => $abha_name,
            'verified_by'  => $staff_id,
        );
        $this->db->set('verified_at', 'NOW()', false);
        $this->db->replace('abdm_patients', $row);
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
                foreach ($this->db->query("SELECT id FROM ipd_prescription_basic WHERE visit_details_id = ?", array((int) $id))->result_array() as $b) {
                    $meds = array_merge($meds, $this->prescription_meds($b['id']));
                }
                $complaints = trim(strip_tags((string) $r['symptoms']));
                $date = $r['appointment_date'] ?: ($r['date'] ?: $r['created_at']);
                return array('patient_id' => $r['patient_id'], 'reference' => 'OPD-' . $r['id'], 'hiType' => 'OPConsultation',
                    'display' => 'OPD consultation' . ($doctor ? ' - Dr. ' . $doctor : ''), 'recordDate' => $this->iso($date),
                    'data' => array('doctor' => array('name' => $doctor ?: 'Consultant'),
                        'chiefComplaints' => $complaints !== '' ? array($complaints) : array(),
                        'observations' => $this->vitals($r), 'diagnoses' => $this->icd_codes('opd_icd10_codes', 'opd_id', $r['opd_id']),
                        'medications' => $meds, 'notes' => trim(strip_tags((string) $r['note']))));

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
                    'display' => ($b['ipd_id'] ? 'IPD' : 'OPD') . ' prescription' . ($doctor ? ' - Dr. ' . $doctor : ''),
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
                $params = $this->db->query("SELECT p.parameter_name, COALESCE(u.unit_name, '') unit, p.reference_range, v.{$t}_report_value val
                    FROM {$t}_report_parameterdetails v
                    JOIN {$t}_parameterdetails pd ON pd.id = v.{$t}_parameterdetail_id
                    JOIN {$t}_parameter p ON p.id = pd.{$t}_parameter_id
                    LEFT JOIN unit u ON u.id = p.unit
                    WHERE v.{$t}_report_id = ?", array((int) $id))->result_array();
                $obs = array();
                foreach ($params as $p) {
                    if (trim((string) $p['val']) === '') {
                        continue;
                    }
                    $obs[] = array('name' => $p['parameter_name'], 'value' => $this->num_or_str($p['val']), 'unit' => (string) $p['unit'],
                        'referenceRange' => $p['reference_range'] ?: null);
                }
                $conclusion = trim(strip_tags((string) ($r[$t . '_result'] ?: $r['description'])));
                if (!$obs && $conclusion === '') {
                    return null;
                }
                $doctor = $this->staff_name($r['approved_by']);
                $name   = $r['test_name'] ?: ($r['report_name'] ?: ($lab ? 'Laboratory report' : 'Radiology report'));
                return array('patient_id' => $r['patient_id'], 'reference' => strtoupper($entity) . '-' . $r['id'], 'hiType' => 'DiagnosticReport',
                    'display' => ($lab ? 'Lab: ' : 'Radiology: ') . $name,
                    'recordDate' => $this->iso($r['reporting_date'] ?: ($r['collection_date'] ?: $r['created_at'])),
                    'data' => array('doctor' => array('name' => $doctor ?: 'Pathologist'), 'reportName' => $name, 'observations' => $obs, 'conclusion' => $conclusion));

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
                    'display' => 'Discharge summary', 'recordDate' => $this->iso($d['discharge_date'] ?: $d['created_at']),
                    'data' => array('doctor' => array('name' => $this->staff_name($d['discharge_by']) ?: 'Consultant'),
                        'chiefComplaints' => $complaints !== '' ? array($complaints) : array(), 'diagnoses' => $diag,
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

        $res = $client->post('/hip/sync', array('patients' => array_values($patients), 'careContexts' => $ccs));
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
    public function create_patient_from_abha($profile, $staff_id)
    {
        $dob = null;
        if (!empty($profile['yearOfBirth'])) {
            $dob = sprintf('%04d-%02d-%02d', $profile['yearOfBirth'], !empty($profile['monthOfBirth']) ? $profile['monthOfBirth'] : 1, !empty($profile['dayOfBirth']) ? $profile['dayOfBirth'] : 1);
        }
        $age = $dob ? (int) date_diff(date_create($dob), date_create('today'))->y : 0;
        $gender = array('M' => 'Male', 'F' => 'Female', 'O' => 'Other');
        $this->db->insert('patients', array(
            'patient_name' => $profile['name'], 'dob' => $dob, 'age' => $age, 'month' => 0, 'day' => 0, 'as_of_date' => date('Y-m-d'),
            'mobileno' => isset($profile['phoneNumber']) ? $profile['phoneNumber'] : '', 'gender' => isset($gender[$profile['gender']]) ? $gender[$profile['gender']] : 'Other',
            'marital_status' => '', 'blood_group' => '', 'address' => isset($profile['address']['line']) ? $profile['address']['line'] : '',
            'patient_type' => '', 'identification_number' => '', 'known_allergies' => '', 'note' => 'Registered via ABHA Scan & Share',
            'is_ipd' => '', 'app_key' => '', 'is_antenatal' => 0, 'is_active' => 'yes', 'generated_by' => $staff_id,
        ));
        $id = $this->db->insert_id();
        $this->save_abha($id, isset($profile['abhaNumber']) ? $profile['abhaNumber'] : null, isset($profile['abhaAddress']) ? $profile['abhaAddress'] : null, $profile['name'], $staff_id);
        return $id;
    }
}
