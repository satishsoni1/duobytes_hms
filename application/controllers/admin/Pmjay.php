<?php
if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * PM-JAY (Ayushman Bharat) module.
 * Phase 1: beneficiaries, HBP packages, IPD cases with pre-auth / claim tracking (TMS 2.0 workflow).
 * Phase 2: NHCX exchange (eligibility, pre-auth, claim, status) as FHIR bundles; simulator mode for demos.
 */
class Pmjay extends Admin_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('pmjay_model');
        $this->load->library('nhcx_client');
        $this->pmjay_model->ensure_schema();
    }

    // ------------------------------------------------------------------ helpers
    private function page($view, $data, $title, $tab)
    {
        $this->session->set_userdata('top_menu', 'pmjay');
        $data['title']       = $title;
        $data['pmjay_tab']   = $tab;
        $data['pmjay_token'] = $this->token();
        $data['nhcx_mode']   = $this->nhcx_client->mode();
        $data['flash']       = $this->session->flashdata('pmjay_flash');
        $this->load->view('layout/header', $data);
        $this->load->view('admin/pmjay/' . $view, $data);
        $this->load->view('layout/footer', $data);
    }

    private function token()
    {
        $t = $this->session->userdata('pmjay_csrf');
        if (!$t) {
            $t = bin2hex(random_bytes(24));
            $this->session->set_userdata('pmjay_csrf', $t);
        }
        return $t;
    }

    private function need($cat, $perm)
    {
        if (!$this->rbac->hasPrivilege($cat, $perm)) {
            access_denied();
        }
    }

    /** POST + per-session token (global CSRF protection is off in this install). */
    private function post_guard($cat, $perm)
    {
        $this->need($cat, $perm);
        if ($this->input->method() !== 'post' || !hash_equals($this->token(), (string) $this->input->post('pmjay_token'))) {
            $this->flash('danger', 'Invalid request token; please try again.');
            redirect('admin/pmjay');
        }
    }

    private function flash($type, $msg)
    {
        $this->session->set_flashdata('pmjay_flash', array('type' => $type, 'msg' => $msg));
    }

    private function back($path, $type, $msg)
    {
        $this->flash($type, $msg);
        redirect('admin/pmjay/' . $path);
    }

    private function staff_id()
    {
        return $this->customlib->getStaffID();
    }

    private function in($k)
    {
        return trim((string) $this->input->post($k, true));
    }

    private function valid_date($d)
    {
        return $d === '' || (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    }

    // ------------------------------------------------------------------ dashboard
    public function index()
    {
        $this->need('pmjay_cases', 'can_view');
        $data['stats']  = $this->pmjay_model->stats();
        $data['recent'] = array_slice($this->pmjay_model->cases(), 0, 8);
        $this->page('index', $data, 'PM-JAY', 'index');
    }

    // ------------------------------------------------------------------ beneficiaries
    public function beneficiaries()
    {
        $this->need('pmjay_beneficiaries', 'can_view');
        $q = trim((string) $this->input->get('q', true));
        $data['q']    = $q;
        $data['rows'] = $this->pmjay_model->beneficiaries($q);
        $pid = (int) $this->input->get('patient_id');
        $data['edit'] = null;
        if ($pid) {
            $p = $this->db->select('id, patient_name, gender, age, mobileno')->get_where('patients', array('id' => $pid))->row_array();
            if ($p) {
                $data['edit'] = array('patient' => $p, 'ben' => $this->pmjay_model->beneficiary_by_patient($pid));
            }
        }
        $this->page('beneficiaries', $data, 'PM-JAY Beneficiaries', 'beneficiaries');
    }

    public function save_beneficiary()
    {
        $pid = (int) $this->input->post('patient_id');
        $this->post_guard('pmjay_beneficiaries', $this->pmjay_model->beneficiary_by_patient($pid) ? 'can_edit' : 'can_add');
        if (!$pid || !$this->db->where('id', $pid)->count_all_results('patients')) {
            $this->back('beneficiaries', 'danger', 'Choose a patient.');
        }
        $pmjay_id = strtoupper($this->in('pmjay_id'));
        if (!preg_match('/^[A-Z0-9-]{6,40}$/', $pmjay_id)) {
            $this->back('beneficiaries?patient_id=' . $pid, 'danger', 'Enter the PM-JAY ID (6–40 letters, digits or -) as printed on the Ayushman card.');
        }
        $this->pmjay_model->save_beneficiary(array(
            'patient_id' => $pid, 'pmjay_id' => $pmjay_id, 'family_id' => $this->in('family_id'), 'card_name' => $this->in('card_name'),
            'scheme' => $this->in('scheme'), 'state' => $this->in('state'), 'remarks' => $this->in('remarks'), 'verified' => $this->input->post('verified'),
        ), $this->staff_id());
        $next = $this->input->post('next') === 'case' ? 'new_case?patient_id=' . $pid : 'beneficiaries';
        $this->back($next, 'success', 'Beneficiary saved.');
    }

    /** JSON patient search for pickers. */
    public function patients()
    {
        $this->need('pmjay_beneficiaries', 'can_view');
        $this->output->set_content_type('application/json')
            ->set_output(json_encode($this->pmjay_model->search_patients($this->input->get('q', true))));
    }

    // ------------------------------------------------------------------ packages
    public function packages()
    {
        $this->need('pmjay_packages', 'can_view');
        $data['rows'] = $this->pmjay_model->packages();
        $id = (int) $this->input->get('edit');
        $data['edit'] = $id ? $this->pmjay_model->package($id) : null;
        $this->page('packages', $data, 'PM-JAY Packages', 'packages');
    }

    public function save_package()
    {
        $id = (int) $this->input->post('id');
        $this->post_guard('pmjay_packages', $id ? 'can_edit' : 'can_add');
        $code = strtoupper($this->in('code'));
        $row = array(
            'code' => $code, 'name' => $this->in('name'), 'specialty' => $this->in('specialty'),
            'rate' => round((float) $this->input->post('rate'), 2), 'preauth' => $this->input->post('preauth') ? 1 : 0,
            'los_days' => $this->input->post('los_days') !== '' ? (int) $this->input->post('los_days') : null,
            'documents' => trim((string) $this->input->post('documents', true)), 'is_active' => $this->input->post('is_active') ? 1 : 0,
        );
        if (!preg_match('/^[A-Z0-9.\-]{2,30}$/', $code) || $row['name'] === '' || $row['rate'] <= 0) {
            $this->back('packages' . ($id ? '?edit=' . $id : ''), 'danger', 'Package code, name and a rate above zero are required.');
        }
        $dupe = $this->db->where('code', $code)->where('id !=', $id)->count_all_results('pmjay_packages');
        if ($dupe) {
            $this->back('packages' . ($id ? '?edit=' . $id : ''), 'danger', 'Package code ' . $code . ' already exists.');
        }
        if ($id) {
            $row['is_sample'] = 0;
            $this->db->where('id', $id)->update('pmjay_packages', $row);
        } else {
            $this->db->insert('pmjay_packages', $row);
        }
        $this->back('packages', 'success', 'Package saved.');
    }

    public function delete_samples()
    {
        $this->post_guard('pmjay_packages', 'can_delete');
        $used = $this->db->query('SELECT COUNT(*) n FROM pmjay_cases c JOIN pmjay_packages k ON k.id = c.package_id WHERE k.is_sample = 1')->row()->n;
        $this->db->query('DELETE FROM pmjay_packages WHERE is_sample = 1 AND id NOT IN (SELECT package_id FROM pmjay_cases)');
        $this->db->query('UPDATE pmjay_packages SET is_active = 0 WHERE is_sample = 1');
        $this->back('packages', 'success', 'Sample packages removed' . ($used ? ' (samples used by existing cases were kept and deactivated).' : '.'));
    }

    // ------------------------------------------------------------------ cases
    public function cases()
    {
        $this->need('pmjay_cases', 'can_view');
        $status = (string) $this->input->get('status', true);
        $q = trim((string) $this->input->get('q', true));
        $data['status'] = isset(Pmjay_model::STATUSES[$status]) ? $status : '';
        $data['q']      = $q;
        $data['rows']   = $this->pmjay_model->cases($data['status'], $q);
        $this->page('cases', $data, 'PM-JAY Cases', 'cases');
    }

    public function new_case()
    {
        $this->need('pmjay_cases', 'can_add');
        $pid = (int) $this->input->get('patient_id');
        $data['patient'] = $pid ? $this->db->select('id, patient_name, gender, age, mobileno')->get_where('patients', array('id' => $pid))->row_array() : null;
        $data['ben']      = $data['patient'] ? $this->pmjay_model->beneficiary_by_patient($pid) : null;
        $data['ipds']     = $data['patient'] ? $this->pmjay_model->ipd_admissions($pid) : array();
        $data['ipd_id']   = (int) $this->input->get('ipd_id');
        $data['packages'] = $this->pmjay_model->packages(true);
        $this->page('new_case', $data, 'New PM-JAY Case', 'cases');
    }

    public function create_case()
    {
        $this->post_guard('pmjay_cases', 'can_add');
        $pid = (int) $this->input->post('patient_id');
        if (!$this->pmjay_model->beneficiary_by_patient($pid)) {
            $this->back('new_case?patient_id=' . $pid, 'danger', 'Register the patient as a PM-JAY beneficiary first.');
        }
        $pkg = $this->pmjay_model->package($this->input->post('package_id'));
        if (!$pkg || !$pkg['is_active']) {
            $this->back('new_case?patient_id=' . $pid, 'danger', 'Choose a package.');
        }
        $ipd = (int) $this->input->post('ipd_id');
        if ($ipd && !$this->db->where(array('id' => $ipd, 'patient_id' => $pid))->count_all_results('ipd_details')) {
            $this->back('new_case?patient_id=' . $pid, 'danger', 'That IPD admission belongs to another patient.');
        }
        $date = $this->in('admission_date');
        if (!$this->valid_date($date)) {
            $this->back('new_case?patient_id=' . $pid, 'danger', 'Invalid admission date.');
        }
        $id = $this->pmjay_model->create_case(array('patient_id' => $pid, 'ipd_id' => $ipd, 'package_id' => $pkg['id'],
            'admission_date' => $date, 'diagnosis' => $this->in('diagnosis')), $this->staff_id());
        $this->back('view/' . $id, 'success', 'Case ' . $this->pmjay_model->case_no($id) . ' created.');
    }

    public function view($id = '')
    {
        $this->need('pmjay_cases', 'can_view');
        $c = ctype_digit((string) $id) ? $this->pmjay_model->get_case($id) : null;
        if (!$c) {
            show_404();
        }
        $data['c']        = $c;
        $data['events']   = $this->pmjay_model->events($id);
        $data['next']     = $this->pmjay_model->next_statuses($c['status']);
        $data['docs']     = $this->pmjay_model->required_docs($c);
        $data['ticked']   = json_decode((string) $c['docs'], true) ?: array();
        $data['messages'] = $this->rbac->hasPrivilege('pmjay_nhcx', 'can_view') ? $this->pmjay_model->messages($id) : array();
        $this->page('view', $data, $this->pmjay_model->case_no($id), 'cases');
    }

    public function move($id = '')
    {
        $this->post_guard('pmjay_cases', 'can_edit');
        $c = ctype_digit((string) $id) ? $this->pmjay_model->get_case($id) : null;
        if (!$c) {
            show_404();
        }
        $date = $this->in('date');
        if (!$this->valid_date($date)) {
            $this->back('view/' . $id, 'danger', 'Invalid date.');
        }
        $err = $this->pmjay_model->move($c, $this->in('to'), array('amount' => $this->in('amount'), 'tms_case_no' => $this->in('tms_case_no'),
            'remarks' => $this->in('remarks'), 'date' => $date, 'payment_ref' => $this->in('payment_ref')), $this->staff_id());
        $this->back('view/' . $id, $err ? 'danger' : 'success', $err ?: 'Status updated: ' . Pmjay_model::label($this->in('to')) . '.');
    }

    public function docs($id = '')
    {
        $this->post_guard('pmjay_cases', 'can_edit');
        $c = ctype_digit((string) $id) ? $this->pmjay_model->get_case($id) : null;
        if (!$c) {
            show_404();
        }
        $allowed = $this->pmjay_model->required_docs($c);
        $ticked = array_values(array_intersect($allowed, (array) $this->input->post('docs')));
        $this->pmjay_model->save_docs($id, $ticked);
        $this->back('view/' . $id, 'success', 'Document checklist saved (' . count($ticked) . ' of ' . count($allowed) . ').');
    }

    // ------------------------------------------------------------------ Phase 2: NHCX
    public function nhcx($id = '')
    {
        $this->post_guard('pmjay_nhcx', 'can_add');
        $c = ctype_digit((string) $id) ? $this->pmjay_model->get_case($id) : null;
        if (!$c) {
            show_404();
        }
        $type = $this->in('type');
        try {
            list($corr, $req, $res, $sum) = $this->nhcx_client->exchange($type, $c);
        } catch (Exception $e) {
            $this->back('view/' . $id, 'danger', $e->getMessage());
        }
        $this->pmjay_model->log_message(array('case_id' => $c['id'], 'type' => $type, 'correlation_id' => $corr, 'mode' => $this->nhcx_client->mode(),
            'status' => $sum['status'], 'outcome' => $sum['outcome'], 'request' => json_encode($req, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'response' => json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'staff_id' => $this->staff_id()));

        // Move the case along when the payer answered with a decision.
        $note = '';
        if ($type === 'preauth' && $c['status'] === 'registered') {
            $this->pmjay_model->move($c, 'preauth_submitted', array('remarks' => 'Sent via NHCX'), $this->staff_id(), 'nhcx');
            $c = $this->pmjay_model->get_case($id);
        }
        if ($type === 'claim' && $c['status'] === 'discharged') {
            $this->pmjay_model->move($c, 'claim_submitted', array('remarks' => 'Sent via NHCX'), $this->staff_id(), 'nhcx');
            $c = $this->pmjay_model->get_case($id);
        }
        if (!empty($sum['next']) && in_array($sum['next'], $this->pmjay_model->next_statuses($c['status']), true)) {
            $err = $this->pmjay_model->move($c, $sum['next'], array('amount' => isset($sum['amount']) ? $sum['amount'] : '',
                'tms_case_no' => isset($sum['ref']) && !$c['tms_case_no'] ? $sum['ref'] : '', 'remarks' => 'NHCX: ' . $sum['outcome']), $this->staff_id(), 'nhcx');
            $note = $err ? ' (' . $err . ')' : ' Case moved to "' . Pmjay_model::label($sum['next']) . '".';
        }
        $ok = $sum['status'] === 'complete' && (!isset($sum['eligible']) || $sum['eligible']);
        $this->back('view/' . $id, $ok ? 'success' : 'warning', Nhcx_client::TYPES[$type] . ': ' . $sum['outcome'] . $note);
    }

    public function exchange()
    {
        $this->need('pmjay_nhcx', 'can_view');
        $data['rows'] = $this->pmjay_model->messages(null, 200);
        $data['participant'] = $this->nhcx_client->cfg('pmjay_participant_code');
        $data['payer'] = $this->nhcx_client->cfg('pmjay_payer_code');
        $this->page('exchange', $data, 'PM-JAY NHCX Exchange', 'exchange');
    }
}
