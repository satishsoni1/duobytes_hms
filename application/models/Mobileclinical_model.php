<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Specialty clinical records written from the mobile app (follow-ups, child vaccinations, growth,
 * pregnancy). One generic table keyed by a client-generated UUID, so records created offline can be
 * retried safely and new specialties need no new tables. Rows are soft-deleted so deletions sync.
 *
 * The web antenatal module's obstetric_history rows are also exposed (read-only) as type
 * 'obstetric_history' with uuid 'oh-<id>'.
 */
class Mobileclinical_model extends CI_Model
{
    /** Writable record types and the keys allowed in their data. */
    const TYPES = array(
        'followup'    => array('reason', 'visit_kind', 'visit_ref_id', 'done_on', 'note', 'reminded_on'),
        'vaccination' => array('vaccine', 'dose', 'given_on', 'batch_no', 'site', 'note', 'external'),
        'growth'      => array('weight_kg', 'height_cm', 'head_cm', 'muac_cm', 'note'),
        // OPD queue state for a visit (waiting / with_doctor / seen / skipped), shared across the doctor's devices.
        'opd_status'  => array('state', 'visit_id', 'token', 'at'),
        'pregnancy'   => array('lmp', 'edd_usg', 'gravida', 'para', 'abortions', 'living', 'risk_factors', 'blood_group', 'outcome', 'outcome_on', 'note'),
    );
    /**
     * Configurable specialty forms (dental, eye, ortho, derma, physio, AYUSH, diabetes, ...). Types with this
     * prefix accept any field keys matching OPEN_KEY (scalars only, tags stripped, size-limited), so new
     * specialty forms in the app need no server change.
     */
    const OPEN_PREFIX   = 'sp_';
    const OPEN_TYPE     = '/^sp_[a-z0-9_]{1,36}$/';
    const OPEN_KEY      = '/^[a-z][a-z0-9_]{0,39}$/';
    const OPEN_MAX_KEYS = 60;
    const STATUSES  = array('active', 'done', 'cancelled', 'given', 'due', 'closed');
    const MAX_DATA  = 8000; // bytes of JSON per record

    public function ensure_schema()
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS `clinical_records` (
            `uuid` char(36) NOT NULL PRIMARY KEY,
            `patient_id` int NOT NULL,
            `record_type` varchar(40) NOT NULL,
            `event_date` date DEFAULT NULL,
            `status` varchar(20) NOT NULL DEFAULT 'active',
            `data` mediumtext NOT NULL,
            `deleted` tinyint(1) NOT NULL DEFAULT 0,
            `created_by` int DEFAULT NULL,
            `updated_by` int DEFAULT NULL,
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            KEY `clinical_records_patient` (`patient_id`, `record_type`),
            KEY `clinical_records_updated` (`updated_at`),
            KEY `clinical_records_due` (`record_type`, `status`, `event_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** Records (including soft-deleted ones, so the phone can drop them) for these patients. */
    public function for_patients($in, $since = null, $include_deleted = true)
    {
        $sql  = "SELECT r.uuid, r.patient_id, r.record_type type, r.event_date, r.status, r.data, r.deleted, r.updated_at,
                TRIM(CONCAT(COALESCE(s.name, ''), ' ', COALESCE(s.surname, ''))) updated_by_name
            FROM clinical_records r LEFT JOIN staff s ON s.id = r.updated_by WHERE r.patient_id IN ($in)";
        $args = array();
        if ($since !== null) {
            $sql .= " AND r.updated_at > ?";
            $args[] = $since;
        }
        if (!$include_deleted) {
            $sql .= " AND r.deleted = 0";
        }
        $rows = $this->db->query($sql, $args)->result_array();
        foreach ($rows as &$r) {
            $r['patient_id'] = (int) $r['patient_id'];
            $r['deleted']    = (bool) $r['deleted'];
            $r['data']       = json_decode($r['data'], true) ?: new stdClass();
        }
        unset($r);

        // Obstetric history from the web antenatal module, read-only.
        $args = array();
        $sql  = "SELECT id, patient_id, place_of_delivery, pregnancy_duration, pregnancy_complications, birth_weight, gender, infant_feeding,
                alive_dead, date, death_cause, previous_medical_history, special_instruction, updated_at
            FROM obstetric_history WHERE patient_id IN ($in)";
        if ($since !== null) {
            $sql .= " AND updated_at > ?";
            $args[] = $since;
        }
        foreach ($this->db->query($sql, $args)->result_array() as $o) {
            $id = $o['id'];
            $pid = (int) $o['patient_id'];
            $date = $o['date'];
            $updated = $o['updated_at'];
            unset($o['id'], $o['patient_id'], $o['date'], $o['updated_at']);
            $rows[] = array('uuid' => 'oh-' . $id, 'patient_id' => $pid, 'type' => 'obstetric_history', 'event_date' => $date,
                'status' => 'closed', 'data' => $o, 'deleted' => false, 'updated_at' => $updated, 'updated_by_name' => null, 'read_only' => true);
        }
        return $rows;
    }

    public function get($uuid)
    {
        return $this->db->query("SELECT * FROM clinical_records WHERE uuid = ?", array((string) $uuid))->row_array();
    }

    /**
     * Validate a record from the app. Returns array(clean_row, error_message|null).
     * Saves are last-write-wins per record; records are small and owned by one patient.
     */
    public function validate(array $p)
    {
        $uuid = isset($p['uuid']) ? (string) $p['uuid'] : '';
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return array(null, 'Invalid record id.');
        }
        $type = isset($p['type']) ? (string) $p['type'] : '';
        $open = strpos($type, self::OPEN_PREFIX) === 0 && preg_match(self::OPEN_TYPE, $type);
        if (!$open && !isset(self::TYPES[$type])) {
            return array(null, 'This kind of record cannot be saved from the app.');
        }
        $status = isset($p['status']) ? (string) $p['status'] : 'active';
        if (!in_array($status, self::STATUSES, true)) {
            return array(null, 'Invalid status.');
        }
        $date = isset($p['event_date']) ? (string) $p['event_date'] : '';
        if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date))) {
            return array(null, 'Invalid date.');
        }
        $data = array();
        $in   = isset($p['data']) && is_array($p['data']) ? $p['data'] : array();
        $allowed = $open ? array_slice(array_values(array_filter(array_keys($in), function ($k) {
            return is_string($k) && preg_match(Mobileclinical_model::OPEN_KEY, $k);
        })), 0, self::OPEN_MAX_KEYS) : self::TYPES[$type];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $in) && (is_scalar($in[$k]) || $in[$k] === null)) {
                $data[$k] = is_string($in[$k]) ? mb_substr(strip_tags($in[$k]), 0, 1000) : $in[$k];
            }
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (strlen($json) > self::MAX_DATA) {
            return array(null, 'Record is too large.');
        }
        return array(array(
            'uuid'        => strtolower($uuid),
            'patient_id'  => (int) (isset($p['patient_id']) ? $p['patient_id'] : 0),
            'record_type' => $type,
            'event_date'  => $date === '' ? null : $date,
            'status'      => $status,
            'data'        => $json,
            'deleted'     => !empty($p['deleted']) ? 1 : 0,
        ), null);
    }

    public function save(array $row, $staff_id)
    {
        $now      = date('Y-m-d H:i:s');
        $existing = $this->get($row['uuid']);
        if ($existing) {
            $row['updated_by'] = (int) $staff_id;
            $row['updated_at'] = $now;
            unset($row['patient_id']); // a record never moves to another patient
            $this->db->where('uuid', $existing['uuid'])->update('clinical_records', $row);
        } else {
            $row['created_by'] = $row['updated_by'] = (int) $staff_id;
            $row['created_at'] = $row['updated_at'] = $now;
            $this->db->insert('clinical_records', $row);
        }
        $saved = $this->for_patients((int) ($existing ? $existing['patient_id'] : $row['patient_id']), null);
        foreach ($saved as $r) {
            if ($r['uuid'] === $row['uuid']) {
                return $r;
            }
        }
        return null;
    }

    /** Names of the doctor's specialties (staff.specialist holds comma-separated specialist ids). */
    public function staff_specialties($staff_id)
    {
        $row = $this->db->query("SELECT specialist FROM staff WHERE id = ?", array((int) $staff_id))->row_array();
        $ids = array_filter(array_map('intval', explode(',', isset($row['specialist']) ? (string) $row['specialist'] : '')));
        if (empty($ids)) {
            return array();
        }
        return array_column($this->db->query("SELECT specialist_name FROM specialist WHERE id IN (" . implode(',', $ids) . ")")->result_array(), 'specialist_name');
    }
}
