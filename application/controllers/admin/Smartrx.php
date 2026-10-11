<?php
if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * Smart Prescription: JSON endpoints for the fast prescription screen (backend/smartrx/smartrx.js).
 * The prescription itself is saved by the existing admin/patient/add_opd_prescription and
 * admin/patient/add_ipdprescription actions, so validation, notifications and billing hooks are unchanged.
 */
class Smartrx extends Admin_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model(array('smartrx_model', 'icd10_model'));
        $this->smartrx_model->ensure_schema();
    }

    private function json($data, $status = 200)
    {
        $this->output->set_status_header($status)->set_content_type('application/json')->set_output(json_encode($data))->_display();
        exit;
    }

    private function token()
    {
        $t = $this->session->userdata('smartrx_csrf');
        if (!$t) {
            $t = bin2hex(random_bytes(24));
            $this->session->set_userdata('smartrx_csrf', $t);
        }
        return $t;
    }

    /** State-changing calls must be POST with the per-session token (Smart Hospital has CSRF protection off). */
    private function check_post()
    {
        $t = (string) $this->input->get_request_header('X-SmartRx-Token', true);
        if ($this->input->method() !== 'post' || !hash_equals($this->token(), $t)) {
            $this->json(array('error' => 'Invalid request token; reload the page.'), 403);
        }
    }

    private function require_rx($type, $perm = 'can_add')
    {
        $cat = $type === 'ipd' ? 'ipd_prescription' : 'prescription';
        if (!in_array($type, array('opd', 'ipd'), true) || !$this->rbac->hasPrivilege($cat, $perm)) {
            $this->json(array('error' => 'You do not have permission to write prescriptions.'), 403);
        }
    }

    private function any_rx()
    {
        if (!$this->rbac->hasPrivilege('prescription', 'can_add') && !$this->rbac->hasPrivilege('ipd_prescription', 'can_add')) {
            $this->json(array('error' => 'You do not have permission to write prescriptions.'), 403);
        }
    }

    /** Everything the screen needs in one call. GET ?type=opd&id=<visit_details_id> | type=ipd&id=<ipd_id> */
    public function context()
    {
        $type = (string) $this->input->get('type', true);
        $id   = (int) $this->input->get('id', true);
        $this->require_rx($type);
        $ctx = $this->smartrx_model->context($type, $id);
        if (!$ctx) {
            $this->json(array('error' => 'Visit not found'), 404);
        }
        $staff = $this->customlib->getStaffID();
        $ctx['masters']   = $this->smartrx_model->masters();
        $ctx['templates'] = $this->smartrx_model->templates($staff);
        $ctx['frequent']  = $this->smartrx_model->frequent_medicines($staff);
        $ctx['token']     = $this->token();
        $ctx['saveUrl']   = site_url($type === 'opd' ? 'admin/patient/add_opd_prescription' : 'admin/patient/add_ipdprescription');
        $this->json($ctx);
    }

    public function medicines()
    {
        $this->any_rx();
        $this->json($this->smartrx_model->search_medicines((string) $this->input->get('q', true)));
    }

    public function icd()
    {
        $this->any_rx();
        $this->json($this->smartrx_model->search_icd((string) $this->input->get('q', true)));
    }

    /** Add ICD-10 codes to the OPD case / IPD admission (keeps existing codes). POST type, id, codes[] */
    public function diagnosis()
    {
        $this->check_post();
        $type = (string) $this->input->post('type', true);
        $this->require_rx($type);
        $ctx = $this->smartrx_model->context($type, (int) $this->input->post('id', true));
        if (!$ctx) {
            $this->json(array('error' => 'Visit not found'), 404);
        }
        $codes = array_map('intval', (array) $this->input->post('codes', true));
        $existing = array_map(function ($c) { return (int) $c['id']; }, $ctx['diagnosis']);
        $all = array_values(array_unique(array_filter(array_merge($existing, $codes))));
        $ok = $type === 'opd' ? $this->icd10_model->saveOpdCodes($ctx['caseId'], $all) : $this->icd10_model->saveIpdCodes($ctx['caseId'], $all);
        $this->json(array('ok' => (bool) $ok));
    }

    // ------------------------------------------------------------------ templates
    public function save_template()
    {
        $this->check_post();
        $this->any_rx();
        $name = trim((string) $this->input->post('name', true));
        $payload = json_decode((string) $this->input->post('payload'), true);
        if ($name === '' || strlen($name) > 100 || !is_array($payload) || empty($payload['items'])) {
            $this->json(array('error' => 'Give the template a name and at least one medicine.'), 400);
        }
        // Keep only known fields.
        $clean = array('items' => array(), 'advice' => isset($payload['advice']) ? substr((string) $payload['advice'], 0, 2000) : '',
            'tests' => array('pathology' => array_map('intval', isset($payload['tests']['pathology']) ? (array) $payload['tests']['pathology'] : array()),
                'radiology' => array_map('intval', isset($payload['tests']['radiology']) ? (array) $payload['tests']['radiology'] : array())),
            'diagnosis' => array_map('intval', isset($payload['diagnosis']) ? (array) $payload['diagnosis'] : array()));
        foreach (array_slice($payload['items'], 0, 30) as $it) {
            $clean['items'][] = array('medicineId' => (int) $it['medicineId'], 'name' => (string) $it['name'],
                'composition' => isset($it['composition']) ? (string) $it['composition'] : '', 'categoryId' => (int) $it['categoryId'],
                'dosageId' => isset($it['dosageId']) ? (int) $it['dosageId'] : null, 'intervalId' => isset($it['intervalId']) ? (int) $it['intervalId'] : null,
                'durationId' => isset($it['durationId']) ? (int) $it['durationId'] : null, 'instruction' => substr((string) (isset($it['instruction']) ? $it['instruction'] : ''), 0, 300));
        }
        $id = $this->smartrx_model->save_template($this->customlib->getStaffID(), $name, $this->input->post('shared') === '1', $clean);
        $this->json(array('id' => $id));
    }

    public function delete_template($id = 0)
    {
        $this->check_post();
        $this->any_rx();
        $this->json(array('ok' => $this->smartrx_model->delete_template($this->customlib->getStaffID(), (int) $id)));
    }

    public function used_template($id = 0)
    {
        $this->check_post();
        $this->any_rx();
        $this->smartrx_model->used_template((int) $id);
        $this->json(array('ok' => true));
    }
}
