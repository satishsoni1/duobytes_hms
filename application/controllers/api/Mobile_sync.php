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
        // Every staff app section syncs through here; each op checks its own web permission.
        if (empty(array_intersect(array('doctor', 'reception', 'nursing', 'lab', 'pharmacy'), $this->staff['modules']))) {
            $this->fail(403, 'module_forbidden', 'This app section is not available for your role.');
        }
        $this->load->model(array('mobiledoctor_model', 'mobilestaff_model'));
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

        $modules = $this->staff['modules'];
        $scope   = array_flip($this->mobilestaff_model->scope_patient_ids($modules, $this->staff['id']));
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
            'appointments'      => $this->mobilestaff_model->appointments($modules, $this->staff['id'], $since),
            'data'              => $data,
            'extras'            => $this->role_extras($modules),
        ));
    }

    /** Small role-specific lists sent whole with every pull (bed board, lab worklist, stock, vital types). */
    private function role_extras(array $modules)
    {
        $x = array('vital_types' => $this->mobilestaff_model->vital_types());
        if (array_intersect(array('reception', 'nursing', 'doctor'), $modules)) {
            $x['beds'] = $this->mobilestaff_model->beds();
        }
        if (in_array('lab', $modules, true) && ($this->can('pathology_test', 'can_view') || $this->can('pathology_bill', 'can_view'))) {
            $x['lab_worklist'] = $this->mobilestaff_model->lab_worklist();
        }
        if (array_intersect(array('pharmacy', 'doctor'), $modules) && $this->can('medicine', 'can_view')) {
            $x['pharmacy_stock'] = $this->mobilestaff_model->pharmacy_stock();
        }
        return $x;
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
                    case 'patient.register':
                        $r = $this->op_patient_register($payload);
                        break;
                    case 'visit.create':
                        $r = $this->op_visit_create($payload);
                        break;
                    case 'vitals.save':
                        $r = $this->op_vitals_save($payload);
                        break;
                    case 'nurse_note.save':
                        $r = $this->op_nurse_note_save($payload);
                        break;
                    case 'lab.result':
                        $r = $this->op_lab_result($payload);
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
            isset($p['qty']) ? (int) $p['qty'] : 1, isset($p['discount']) ? (float) $p['discount'] : 0, isset($p['note']) ? $p['note'] : '', $date, $this->staff['id']);
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

    /**
     * New patient registered offline. Links to an existing patient with the same mobile and name
     * instead of creating a duplicate. Returns the patient's server id and full history bundle.
     */
    private function op_patient_register(array $p)
    {
        if (!$this->can('opd_patient', 'can_add')) {
            return array('status' => 'rejected', 'message' => 'You do not have permission to register patients.');
        }
        $this->load->model('mobileregistration_model');
        list($row, $error) = $this->mobileregistration_model->validate_patient($p);
        if ($error !== null) {
            return array('status' => 'rejected', 'message' => $error);
        }
        $merged = false;
        $id     = $this->mobileregistration_model->find_duplicate($row);
        if ($id) {
            $merged = true;
        } else {
            $id = $this->mobileregistration_model->create_patient($row, $this->staff['id']);
            if (!$id) {
                return array('status' => 'error', 'message' => 'Could not register the patient; will retry.');
            }
            $this->mobileapi_model->audit($this->staff['id'], INSERT_RECORD_CONSTANT . ' On Patient id ' . $id . ' (mobile app)', $id, 'Insert');
        }
        return array(
            'status'       => 'ok',
            'patient_id'   => $id,
            'merged'       => $merged,
            'shared_mobile' => $merged ? 0 : $this->mobileregistration_model->mobile_in_use($row['mobileno'], $id),
            'data'         => $this->mobiledoctor_model->patient_data(array($id), null),
        );
    }

    /** OPD visit (case, consultation charge, payment) created offline. Returns visit/OPD ids and the token. */
    private function op_visit_create(array $p)
    {
        if (!$this->can('opd_patient', 'can_add') && !$this->can('visit', 'can_add')) {
            return array('status' => 'rejected', 'message' => 'You do not have permission to create OPD visits.');
        }
        $this->load->model(array('mobileregistration_model', 'mobileclinical_model'));
        list($c, $error) = $this->mobileregistration_model->validate_visit($p);
        if ($error !== null) {
            return array('status' => 'rejected', 'message' => $error);
        }
        $made = $this->mobileregistration_model->create_visit($c, $this->staff['id']);
        if (!$made) {
            return array('status' => 'error', 'message' => 'Could not create the visit; will retry.');
        }
        list($visit_id, $opd_id, $token) = $made;
        $this->mobileapi_model->audit($this->staff['id'], INSERT_RECORD_CONSTANT . ' On Opd Details id ' . $opd_id . ' (mobile app)', $opd_id, 'Insert');

        // Queue record (same id the app derives), so every device shows the token and state.
        $state = isset($p['queue_state']) && in_array($p['queue_state'], array('waiting', 'with_doctor', 'seen', 'skipped'), true) ? $p['queue_state'] : 'waiting';
        list($q) = $this->mobileclinical_model->validate(array(
            'uuid' => Mobileregistration_model::queue_uuid($visit_id), 'patient_id' => $c['patient_id'], 'type' => 'opd_status',
            'event_date' => substr($c['date'], 0, 10), 'status' => 'active',
            'data' => array('state' => $state, 'visit_id' => $visit_id, 'token' => $token, 'at' => date('c')),
        ));
        if ($q) {
            $this->mobileclinical_model->save($q, $this->staff['id']);
        }
        return array(
            'status'   => 'ok',
            'visit_id' => $visit_id,
            'opd_id'   => $opd_id,
            'token'    => $token,
            'data'     => $this->mobiledoctor_model->patient_data(array($c['patient_id']), null),
        );
    }

    /** Vital signs into patients_vitals (shown on the web patient profile and to every doctor). */
    private function op_vitals_save(array $p)
    {
        if (!$this->can('opd_patient', 'can_edit') && !$this->can('ipd_patient', 'can_edit') && !$this->can('nurse_note', 'can_add')) {
            return array('status' => 'rejected', 'message' => 'You do not have permission to record vitals.');
        }
        $pid = isset($p['patient_id']) ? (int) $p['patient_id'] : 0;
        $r   = $this->mobilestaff_model->save_vitals($pid, isset($p['measured_at']) ? (string) $p['measured_at'] : '',
            isset($p['values']) && is_array($p['values']) ? $p['values'] : array());
        if (is_string($r)) {
            return array('status' => 'rejected', 'message' => $r);
        }
        return array('status' => 'ok', 'data' => $this->mobiledoctor_model->patient_data(array($pid), null));
    }

    private function op_nurse_note_save(array $p)
    {
        if (!$this->can('nurse_note', 'can_add')) {
            return array('status' => 'rejected', 'message' => 'You do not have permission to add nurse notes.');
        }
        $r = $this->mobilestaff_model->save_nurse_note(isset($p['ipd_id']) ? (int) $p['ipd_id'] : 0, isset($p['date']) ? (string) $p['date'] : '',
            isset($p['note']) ? $p['note'] : '', isset($p['comment']) ? $p['comment'] : '', $this->staff['id']);
        if (is_string($r)) {
            return array('status' => 'rejected', 'message' => $r);
        }
        return array('status' => 'ok', 'server_id' => $r);
    }

    private function op_lab_result(array $p)
    {
        if (!$this->can('pathology_bill', 'can_edit') && !$this->can('pathology_test', 'can_edit')) {
            return array('status' => 'rejected', 'message' => 'You do not have permission to enter lab results.');
        }
        $r = $this->mobilestaff_model->save_lab_result(isset($p['report_id']) ? (int) $p['report_id'] : 0,
            isset($p['values']) && is_array($p['values']) ? $p['values'] : array(),
            array_key_exists('result', $p) ? $p['result'] : null, !empty($p['complete']));
        if ($r !== true) {
            return array('status' => $r === 'Could not save; will retry.' ? 'error' : 'rejected', 'message' => $r);
        }
        return array('status' => 'ok');
    }

    private function may_view_patient($patient_id)
    {
        return !$this->doctor_restricted() || $this->mobiledoctor_model->doctor_has_patient($this->staff['id'], $patient_id);
    }
}
