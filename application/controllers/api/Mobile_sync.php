<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/Mobile_api_controller.php';

/**
 * Offline sync for the doctor app.
 *
 * GET  api/v1/sync/masters   Medicines, dose options, tests, ICD codes in use, templates. Supports If-None-Match.
 * POST api/v1/sync/pull      {since, known_patient_ids[], pinned_patient_ids[]}
 *                            -> full history for patients new to the device, changes since `since` for the rest.
 * POST api/v1/sync/push      {ops: [{op_id, type, payload}]}  Idempotent per op_id; ops run in order.
 */
class Mobile_sync extends Mobile_api_controller
{
    const MAX_OPS_PER_PUSH = 50;
    const MAX_PINNED       = 500;
    /** Cursor overlap so rows committed while a pull is running are not missed (upserts are idempotent). */
    const CURSOR_OVERLAP_SEC = 120;

    public function __construct()
    {
        parent::__construct();
        $this->authenticate();
        $this->require_module('doctor');
        $this->load->model('mobiledoctor_model');
    }

    public function masters()
    {
        $this->require_method('get');
        $data = $this->mobiledoctor_model->masters($this->staff['id']);
        $etag = '"' . md5(json_encode($data)) . '"';
        if (trim((string) $this->input->get_request_header('If-None-Match', true)) === $etag) {
            $this->output->set_header('ETag: ' . $etag)->set_status_header(304)->_display();
            exit;
        }
        $this->output->set_header('ETag: ' . $etag);
        $this->respond($data);
    }

    public function pull()
    {
        $this->require_method('post');
        $b      = $this->body();
        $cursor = $this->db->query("SELECT NOW() - INTERVAL " . self::CURSOR_OVERLAP_SEC . " SECOND t")->row()->t;
        $since  = isset($b['since']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $b['since']) ? (string) $b['since'] : null;
        $known  = array_flip(array_map('intval', isset($b['known_patient_ids']) ? (array) $b['known_patient_ids'] : array()));
        $pinned = array_slice(array_map('intval', isset($b['pinned_patient_ids']) ? (array) $b['pinned_patient_ids'] : array()), 0, self::MAX_PINNED);

        $scope = array_flip($this->mobiledoctor_model->scope_patient_ids($this->staff['id']));
        foreach ($pinned as $pid) {
            if ($pid > 0 && !isset($scope[$pid]) && $this->may_view_patient($pid)) {
                $scope[$pid] = true;
            }
        }
        $scope = array_keys($scope);

        $new = $old = array();
        foreach ($scope as $pid) {
            if ($since !== null && isset($known[$pid])) {
                $old[] = $pid;
            } else {
                $new[] = $pid;
            }
        }

        $full  = $this->mobiledoctor_model->patient_data($new, null);
        $delta = $this->mobiledoctor_model->patient_data($old, $since);
        $data  = array();
        foreach ($full as $k => $rows) {
            $data[$k] = array_merge($rows, $delta[$k]);
        }

        $this->respond(array(
            'cursor'            => $cursor,
            'full_sync'         => $since === null,
            'scope_patient_ids' => array_map('intval', $scope),
            'prescription_ids'  => $this->mobiledoctor_model->prescription_ids($scope),
            'appointments'      => $this->mobiledoctor_model->appointments($this->staff['id'], $since),
            'data'              => $data,
        ));
    }

    public function push()
    {
        $this->require_method('post');
        $b   = $this->body();
        $ops = isset($b['ops']) && is_array($b['ops']) ? array_values($b['ops']) : array();
        if (count($ops) > self::MAX_OPS_PER_PUSH) {
            $this->fail(413, 'too_many_ops', 'Send at most ' . self::MAX_OPS_PER_PUSH . ' changes per request.');
        }
        $results = array();
        foreach ($ops as $op) {
            $op_id = isset($op['op_id']) ? (string) $op['op_id'] : '';
            $type  = isset($op['type']) ? (string) $op['type'] : '';
            if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $op_id)) {
                $results[] = array('op_id' => $op_id, 'status' => 'rejected', 'message' => 'Invalid op id.');
                continue;
            }
            $done = $this->mobileapi_model->op_result($this->staff['id'], $op_id);
            if ($done !== null) {
                $results[] = $done; // already applied: replay the original answer
                continue;
            }
            $payload = isset($op['payload']) && is_array($op['payload']) ? $op['payload'] : array();
            try {
                switch ($type) {
                    case 'prescription.save':
                        $r = $this->op_prescription_save($payload);
                        break;
                    case 'charge.add':
                        $r = $this->op_charge_add($payload);
                        break;
                    case 'settings.save':
                        $r = $this->op_settings_save($payload);
                        break;
                    case 'record.save':
                        $r = $this->op_record_save($payload);
                        break;
                    case 'template.save':
                        $r = $this->op_template_save($payload);
                        break;
                    case 'template.delete':
                        $r = $this->op_template_delete($payload);
                        break;
                    default:
                        $r = array('status' => 'rejected', 'message' => 'Unknown change type: ' . $type);
                }
            } catch (Throwable $e) {
                log_message('error', 'Mobile push ' . $type . ' failed: ' . $e->getMessage());
                $r = array('status' => 'error', 'message' => 'Server error; will retry.');
            }
            $r = array_merge(array('op_id' => $op_id), $r);
            // Conflicts and transient errors are not final; everything else is replayed on retry.
            if ($r['status'] === 'ok' || $r['status'] === 'rejected') {
                $this->mobileapi_model->save_op_result($this->staff['id'], $op_id, $type, $r);
            }
            $results[] = $r;
        }
        $this->respond(array('results' => $results));
    }

    // ------------------------------------------------------------------ ops
    private function op_prescription_save(array $p)
    {
        $kind = isset($p['kind']) ? (string) $p['kind'] : '';
        $ctx  = $this->mobiledoctor_model->case_context($kind, isset($p['case_ref_id']) ? (int) $p['case_ref_id'] : 0);
        if (!$ctx) {
            return array('status' => 'rejected', 'message' => 'The visit or admission no longer exists.');
        }
        $existing_id = !empty($p['server_id']) ? (int) $p['server_id'] : null;
        $category    = $kind === 'ipd' ? 'ipd_prescription' : 'prescription';
        if (!$this->can($category, $existing_id ? 'can_edit' : 'can_add')) {
            return array('status' => 'rejected', 'message' => 'You do not have permission to ' . ($existing_id ? 'edit' : 'write') . ' prescriptions.');
        }
        $me = $this->staff['id'];
        if ($this->doctor_restricted() && !in_array($me, $ctx['doctor_ids'], true)) {
            return array('status' => 'rejected', 'message' => 'This patient is assigned to another doctor.');
        }

        if ($existing_id) {
            $row = $this->mobiledoctor_model->basic_row($existing_id);
            $belongs = $row && (($kind === 'opd' && (int) $row['visit_details_id'] === (int) $ctx['id']) || ($kind === 'ipd' && (int) $row['ipd_id'] === (int) $ctx['id']));
            if (!$belongs) {
                return array('status' => 'rejected', 'message' => 'This prescription was deleted on the server.');
            }
            $base = isset($p['base_version']) ? (string) $p['base_version'] : '';
            if (empty($p['force']) && $base !== '' && strcmp($row['version'], $base) > 0) {
                return array('status' => 'conflict', 'message' => 'This prescription was changed by someone else.',
                    'server' => $this->mobiledoctor_model->prescription_by_id($existing_id));
            }
        } elseif ($ctx['discharged'] === 'yes') {
            return array('status' => 'rejected', 'message' => 'The patient has been discharged; this ' . ($kind === 'ipd' ? 'admission' : 'visit') . ' is closed.');
        }

        list($clean, $errors) = $this->mobiledoctor_model->validate_prescription($p);
        if (!empty($errors)) {
            return array('status' => 'rejected', 'message' => implode(' ', $errors));
        }

        // Same rule as the web screens: OPD prescriptions are prescribed by the visit's consultant;
        // IPD uses the signed-in doctor when they are on the admission, else the consultant.
        $prescribe_by = ($kind === 'ipd' && in_array($me, $ctx['doctor_ids'], true)) ? $me : (int) $ctx['doctor_id'];
        if ($prescribe_by <= 0) {
            $prescribe_by = $me;
        }

        $id = $this->mobiledoctor_model->save_prescription($ctx, $clean, $me, $prescribe_by, $existing_id);
        if (!$id) {
            return array('status' => 'error', 'message' => 'Could not save the prescription; will retry.');
        }
        $this->mobiledoctor_model->add_diagnoses($ctx, $clean['icd_ids']);
        $this->mobileapi_model->audit($me, ($existing_id ? UPDATE_RECORD_CONSTANT : INSERT_RECORD_CONSTANT) . ' On Ipd Prescription Basic id ' . $id . ' (mobile app)',
            $id, $existing_id ? 'Update' : 'Insert');

        return array('status' => 'ok', 'server_id' => $id, 'prescription' => $this->mobiledoctor_model->prescription_by_id($id));
    }

    /** Add a service/charge to the patient's OPD case or admission (same rules as the web charge form). */
    private function op_charge_add(array $p)
    {
        $kind = isset($p['kind']) ? (string) $p['kind'] : '';
        $ctx  = $this->mobiledoctor_model->case_context($kind, isset($p['case_ref_id']) ? (int) $p['case_ref_id'] : 0);
        if (!$ctx) {
            return array('status' => 'rejected', 'message' => 'The visit or admission no longer exists.');
        }
        // Web permission codes: OPD group 'opd_charges', IPD group 'charges'.
        if (!$this->can($kind === 'ipd' ? 'charges' : 'opd_charges', 'can_add')) {
            return array('status' => 'rejected', 'message' => 'You do not have permission to add charges.');
        }
        if ($this->doctor_restricted() && !in_array($this->staff['id'], $ctx['doctor_ids'], true)) {
            return array('status' => 'rejected', 'message' => 'This patient is assigned to another doctor.');
        }
        if ($ctx['discharged'] === 'yes') {
            return array('status' => 'rejected', 'message' => 'The ' . ($kind === 'ipd' ? 'admission' : 'visit') . ' is closed; add charges at the billing counter.');
        }
        $date = isset($p['date']) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', (string) $p['date']) ? (string) $p['date'] : date('Y-m-d H:i:s');
        $this->load->model('mobilebilling_model');
        $row = $this->mobilebilling_model->add_charge($kind, $ctx['case_id'], isset($p['charge_id']) ? (int) $p['charge_id'] : 0,
            isset($p['qty']) ? (int) $p['qty'] : 1, isset($p['discount']) ? (float) $p['discount'] : 0, isset($p['note']) ? $p['note'] : '', $date);
        if (is_string($row)) {
            return array('status' => 'rejected', 'message' => $row);
        }
        if (!$row) {
            return array('status' => 'error', 'message' => 'Could not add the charge; will retry.');
        }
        $this->mobileapi_model->audit($this->staff['id'], INSERT_RECORD_CONSTANT . ' On Patient Charges id ' . $row['id'] . ' (mobile app)', $row['id'], 'Insert');
        return array('status' => 'ok', 'charge' => $row);
    }

    /** Per-doctor app settings (e.g. print layout). Only known keys, size-limited. */
    private function op_settings_save(array $p)
    {
        $key = isset($p['key']) ? (string) $p['key'] : '';
        if (!in_array($key, array('print'), true) || !isset($p['value']) || !is_array($p['value']) || strlen(json_encode($p['value'])) > 20000) {
            return array('status' => 'rejected', 'message' => 'Invalid setting.');
        }
        $this->load->model('mobilebilling_model');
        $this->mobilebilling_model->save_staff_setting($this->staff['id'], $key, $p['value']);
        return array('status' => 'ok');
    }

    /** GET api/v1/sync/print_header : the hospital's OPD prescription letterhead image (web print settings). */
    public function print_header()
    {
        $this->require_method('get');
        $this->load->model('mobilebilling_model');
        $path = $this->mobilebilling_model->prescription_header_path();
        if ($path === null) {
            $this->fail(404, 'not_found', 'No prescription header image is set in the hospital print settings.');
        }
        $this->send_file($path, basename($path));
    }

    /** Specialty record (follow-up, vaccination, growth, pregnancy). Upsert by client uuid; last write wins. */
    private function op_record_save(array $p)
    {
        $this->load->model('mobileclinical_model');
        list($row, $error) = $this->mobileclinical_model->validate($p);
        if ($error !== null) {
            return array('status' => 'rejected', 'message' => $error);
        }
        $existing = $this->mobileclinical_model->get($row['uuid']);
        $patient_id = $existing ? (int) $existing['patient_id'] : $row['patient_id'];
        if ($existing && (int) $existing['patient_id'] !== $row['patient_id']) {
            return array('status' => 'rejected', 'message' => 'Record belongs to another patient.');
        }
        if (!$this->db->query("SELECT 1 FROM patients WHERE id = ?", array($patient_id))->row_array()) {
            return array('status' => 'rejected', 'message' => 'Patient not found.');
        }
        if (!$this->may_view_patient($patient_id)) {
            return array('status' => 'rejected', 'message' => 'This patient is assigned to another doctor.');
        }
        $saved = $this->mobileclinical_model->save($row, $this->staff['id']);
        if (!$saved) {
            return array('status' => 'error', 'message' => 'Could not save; will retry.');
        }
        return array('status' => 'ok', 'record' => $saved);
    }

    /** Same template format as the web Smart Prescription screen, so templates are shared both ways. */
    private function op_template_save(array $p)
    {
        if (!$this->can('prescription', 'can_add') && !$this->can('ipd_prescription', 'can_add')) {
            return array('status' => 'rejected', 'message' => 'You do not have permission to save templates.');
        }
        $name    = trim((string) (isset($p['name']) ? $p['name'] : ''));
        $payload = isset($p['payload']) && is_array($p['payload']) ? $p['payload'] : array();
        if ($name === '' || mb_strlen($name) > 100 || empty($payload['items']) || !is_array($payload['items'])) {
            return array('status' => 'rejected', 'message' => 'Give the template a name and at least one medicine.');
        }
        $clean = array(
            'items'     => array(),
            'advice'    => isset($payload['advice']) ? mb_substr((string) $payload['advice'], 0, 2000) : '',
            'tests'     => array(
                'pathology' => array_map('intval', isset($payload['tests']['pathology']) ? (array) $payload['tests']['pathology'] : array()),
                'radiology' => array_map('intval', isset($payload['tests']['radiology']) ? (array) $payload['tests']['radiology'] : array()),
            ),
            'diagnosis' => array_map('intval', isset($payload['diagnosis']) ? (array) $payload['diagnosis'] : array()),
        );
        foreach (array_slice($payload['items'], 0, 30) as $it) {
            if (!is_array($it) || empty($it['medicineId'])) {
                continue;
            }
            $clean['items'][] = array(
                'medicineId'  => (int) $it['medicineId'],
                'name'        => (string) (isset($it['name']) ? $it['name'] : ''),
                'composition' => (string) (isset($it['composition']) ? $it['composition'] : ''),
                'categoryId'  => (int) (isset($it['categoryId']) ? $it['categoryId'] : 0),
                'dosageId'    => !empty($it['dosageId']) ? (int) $it['dosageId'] : null,
                'intervalId'  => !empty($it['intervalId']) ? (int) $it['intervalId'] : null,
                'durationId'  => !empty($it['durationId']) ? (int) $it['durationId'] : null,
                'instruction' => mb_substr((string) (isset($it['instruction']) ? $it['instruction'] : ''), 0, 300),
            );
        }
        $this->load->model('smartrx_model');
        $this->smartrx_model->ensure_schema();
        $id = $this->smartrx_model->save_template($this->staff['id'], $name, !empty($p['shared']), $clean);
        return array('status' => 'ok', 'server_id' => (int) $id);
    }

    private function op_template_delete(array $p)
    {
        $this->load->model('smartrx_model');
        $this->smartrx_model->delete_template($this->staff['id'], isset($p['server_id']) ? (int) $p['server_id'] : 0);
        return array('status' => 'ok'); // deleting an already-deleted template is fine
    }

    private function may_view_patient($patient_id)
    {
        return !$this->doctor_restricted() || $this->mobiledoctor_model->doctor_has_patient($this->staff['id'], $patient_id);
    }
}
