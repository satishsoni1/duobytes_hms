<?php
if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * ABDM: ABHA (M1), health-record sharing (M2) and consent-based record access (M3).
 * Pages render inside the normal Smart Hospital layout; AJAX actions proxy to the ABDM bridge.
 */
class Abdm extends Admin_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('abdm_model');
        $this->load->library('abdm_client');
        $this->abdm_model->ensure_schema();
    }

    // ------------------------------------------------------------------ helpers
    private function page($view, $data, $title)
    {
        $this->session->set_userdata('top_menu', 'abdm');
        $data['title']      = $title;
        $data['abdm_token'] = $this->csrf_token();
        $data['configured'] = $this->abdm_client->configured();
        $this->load->view('layout/header', $data);
        $this->load->view('admin/abdm/' . $view, $data);
        $this->load->view('layout/footer', $data);
    }

    private function csrf_token()
    {
        $t = $this->session->userdata('abdm_csrf');
        if (!$t) {
            $t = bin2hex(random_bytes(24));
            $this->session->set_userdata('abdm_csrf', $t);
        }
        return $t;
    }

    /** Gate for AJAX actions: permission + POST + per-session token. */
    private function guard($category, $perm, $post = true)
    {
        if (!$this->rbac->hasPrivilege($category, $perm)) {
            $this->json(array('error' => 'You do not have permission for this ABDM action.'), 403);
        }
        if ($post) {
            $t = (string) $this->input->get_request_header('X-ABDM-Token', true);
            if ($this->input->method() !== 'post' || !hash_equals($this->csrf_token(), $t)) {
                $this->json(array('error' => 'Invalid request token; reload the page.'), 403);
            }
        }
    }

    private function body()
    {
        $b = json_decode($this->input->raw_input_stream, true);
        return is_array($b) ? $b : array();
    }

    private function json($data, $status = 200)
    {
        $this->output->set_status_header($status)->set_content_type('application/json')->set_output(json_encode($data))->_display();
        exit;
    }

    private function call($fn)
    {
        try {
            $this->json($fn());
        } catch (Exception $e) {
            $code = (int) $e->getCode();
            $this->json(array('error' => $e->getMessage()), $code >= 400 && $code < 600 ? $code : 502);
        }
    }

    private function staff_id()
    {
        return $this->customlib->getStaffID();
    }

    // ------------------------------------------------------------------ pages
    /** Dashboard: connection, sync status, errors. */
    public function index()
    {
        if (!$this->rbac->hasPrivilege('abdm_settings', 'can_view')) {
            access_denied();
        }
        $data['stats'] = $this->abdm_model->stats();
        $data['ping']  = null;
        try {
            $data['ping'] = $this->abdm_client->get('/hip/ping');
        } catch (Exception $e) {
            $data['ping_error'] = $e->getMessage();
        }
        $this->page('index', $data, 'ABDM');
    }

    /** Per-patient ABDM page: ABHA + record sharing. */
    public function patient($id = '')
    {
        if (!ctype_digit((string) $id)) {
            show_404();
        }
        if (!$this->rbac->hasPrivilege('abdm_abha', 'can_view') && !$this->rbac->hasPrivilege('abdm_records', 'can_view')) {
            access_denied();
        }
        $p = $this->abdm_model->get_patient($id);
        if (!$p) {
            show_404();
        }
        $data['patient'] = $p;
        $data['mrn']     = $this->abdm_model->mrn($p['id']);
        $data['records'] = $this->abdm_model->records_for_patient($p['id']);
        $this->page('patient', $data, 'ABDM - ' . $p['patient_name']);
    }

    /** HIU: request & view records from other facilities. */
    public function consent()
    {
        if (!$this->rbac->hasPrivilege('abdm_consent', 'can_view')) {
            access_denied();
        }
        $data['prefill'] = (string) $this->input->get('abha', true);
        $this->page('consent', $data, 'ABDM Consent Requests');
    }

    /** Scan & Share token queue. */
    public function share()
    {
        if (!$this->rbac->hasPrivilege('abdm_abha', 'can_view')) {
            access_denied();
        }
        $this->page('share', array(), 'ABHA Scan & Share');
    }

    // ------------------------------------------------------------------ M1: ABHA (proxy)
    public function m1($action = '')
    {
        $this->guard('abdm_abha', 'can_add');
        $allowed = array(
            'enrol_otp' => '/m1/enrol/aadhaar/otp', 'enrol_verify' => '/m1/enrol/aadhaar/verify',
            'mobile_otp' => '/m1/enrol/mobile/otp', 'mobile_verify' => '/m1/enrol/mobile/verify',
            'address_create' => '/m1/enrol/address', 'login_otp' => '/m1/login/otp', 'login_verify' => '/m1/login/verify',
            'login_select' => '/m1/login/select', 'profile' => '/m1/profile',
        );
        $b = $this->body();
        if ($action === 'address_suggestions') {
            $this->call(function () use ($b) {
                return $this->abdm_client->get('/m1/enrol/address/suggestions?txnId=' . rawurlencode(isset($b['txnId']) ? $b['txnId'] : ''));
            });
        }
        if ($action === 'card' || $action === 'qr') {
            try {
                list($ctype, $bytes) = $this->abdm_client->post_binary('/m1/profile/' . $action, array('token' => isset($b['token']) ? $b['token'] : ''));
                $this->output->set_header('Cache-Control: no-store')->set_content_type($ctype ?: 'image/png')->set_output($bytes)->_display();
                exit;
            } catch (Exception $e) {
                $this->json(array('error' => $e->getMessage()), 502);
            }
        }
        if (!isset($allowed[$action])) {
            $this->json(array('error' => 'Unknown action'), 404);
        }
        $this->call(function () use ($allowed, $action, $b) {
            return $this->abdm_client->post($allowed[$action], $b);
        });
    }

    /**
     * Attach a verified ABHA to a patient. The profile is fetched from ABDM with the user token,
     * so the ABHA saved is the one the patient actually authenticated.
     */
    public function save_abha($patient_id = '')
    {
        if (!ctype_digit((string) $patient_id)) {
            $this->json(array('error' => 'Invalid patient id'), 400);
        }
        $this->guard('abdm_abha', 'can_add');
        $b = $this->body();
        $this->call(function () use ($patient_id, $b) {
            if (!$this->abdm_model->get_patient($patient_id)) {
                throw new Exception('Patient not found', 404);
            }
            $prof    = $this->abdm_client->post('/m1/profile', array('token' => isset($b['token']) ? $b['token'] : ''));
            $number  = isset($prof['ABHANumber']) ? $prof['ABHANumber'] : null;
            $address = isset($prof['preferredAbhaAddress']) ? $prof['preferredAbhaAddress'] : (isset($prof['phrAddress'][0]) ? $prof['phrAddress'][0] : null);
            if (!$number && !$address) {
                throw new Exception('Could not read the ABHA profile', 502);
            }
            $other = $this->abdm_model->find_patient_by_abha($address, $number);
            if ($other && (int) $other['patient_id'] !== (int) $patient_id) {
                throw new Exception('This ABHA is already attached to patient #' . $other['patient_id'], 409);
            }
            $name = isset($prof['name']) ? $prof['name'] : trim((isset($prof['firstName']) ? $prof['firstName'] : '') . ' ' . (isset($prof['lastName']) ? $prof['lastName'] : ''));
            $this->abdm_model->save_abha($patient_id, $number, $address, $name, $this->staff_id());
            $this->abdm_model->sync_patient($this->abdm_client, $patient_id);
            return array('abhaNumber' => $number, 'abhaAddress' => $address, 'name' => $name);
        });
    }

    // ------------------------------------------------------------------ M2: records
    public function sync_patient($patient_id = '')
    {
        if (!ctype_digit((string) $patient_id)) {
            $this->json(array('error' => 'Invalid patient id'), 400);
        }
        $this->guard('abdm_records', 'can_add');
        $this->call(function () use ($patient_id) {
            $this->abdm_model->sync_patient($this->abdm_client, $patient_id);
            return $this->abdm_model->sync($this->abdm_client, $patient_id, 500);
        });
    }

    public function link($patient_id = '')
    {
        if (!ctype_digit((string) $patient_id)) {
            $this->json(array('error' => 'Invalid patient id'), 400);
        }
        $this->guard('abdm_records', 'can_add');
        $this->call(function () use ($patient_id) {
            $p = $this->abdm_model->get_patient($patient_id);
            if (!$p || !$p['abha_address']) {
                throw new Exception('Attach a verified ABHA to this patient first', 400);
            }
            $this->abdm_model->sync_patient($this->abdm_client, $patient_id);
            $this->abdm_model->sync($this->abdm_client, $patient_id, 500);
            return $this->abdm_client->post('/hip/patients/' . rawurlencode($this->abdm_model->mrn($patient_id)) . '/link', array());
        });
    }

    public function link_status($job_id = '')
    {
        $this->guard('abdm_records', 'can_view', false);
        $this->call(function () use ($job_id) {
            if (!preg_match('/^[0-9a-f-]{36}$/', $job_id)) {
                throw new Exception('Invalid job id', 400);
            }
            return $this->abdm_client->get('/hip/link-jobs/' . $job_id);
        });
    }

    public function bridge_status($patient_id = '')
    {
        if (!ctype_digit((string) $patient_id)) {
            $this->json(array('error' => 'Invalid patient id'), 400);
        }
        $this->guard('abdm_records', 'can_view', false);
        $this->call(function () use ($patient_id) {
            try {
                return $this->abdm_client->get('/hip/patients/' . rawurlencode($this->abdm_model->mrn($patient_id)));
            } catch (Exception $e) {
                if ($e->getCode() == 404) {
                    return array('careContexts' => array(), 'linkJobs' => array());
                }
                throw $e;
            }
        });
    }

    public function sync_all()
    {
        $this->guard('abdm_settings', 'can_edit');
        $this->call(function () {
            return $this->abdm_model->sync($this->abdm_client);
        });
    }

    // ------------------------------------------------------------------ M3: HIU (proxy)
    public function hiu($action = '', $id = '')
    {
        $read = in_array($action, array('meta', 'requests', 'artefacts', 'records'), true);
        $this->guard('abdm_consent', $read ? 'can_view' : 'can_add', !$read);
        $this->call(function () use ($action, $id) {
            $safe = preg_replace('/[^A-Za-z0-9-]/', '', $id);
            switch ($action) {
                case 'meta':      return $this->abdm_client->get('/hiu/meta');
                case 'requests':  return $this->abdm_client->get('/hiu/consent-requests');
                case 'artefacts': return $this->abdm_client->get('/hiu/artefacts');
                case 'records':   return $this->abdm_client->get('/hiu/records' . ($safe ? '?consentId=' . $safe : ''));
                case 'create':    return $this->abdm_client->post('/hiu/consent-requests', $this->body());
                case 'status':    return $this->abdm_client->post('/hiu/consent-requests/' . $safe . '/status', array());
                case 'fetch':     return $this->abdm_client->post('/hiu/artefacts/' . $safe . '/fetch', array());
                case 'request':   return $this->abdm_client->post('/hiu/artefacts/' . $safe . '/request-data', array());
            }
            throw new Exception('Unknown action', 404);
        });
    }

    // ------------------------------------------------------------------ Scan & Share
    public function share_queue()
    {
        $this->guard('abdm_abha', 'can_view', false);
        $this->call(function () {
            $rows = $this->abdm_client->get('/m1/share/queue');
            foreach ($rows as &$r) {
                $m = $this->abdm_model->find_patient_by_abha($r['abha_address'], isset($r['abhaNumber']) ? $r['abhaNumber'] : null);
                $r['hms_patient_id'] = $m ? (int) $m['patient_id'] : null;
            }
            return $rows;
        });
    }

    public function share_register()
    {
        $this->guard('abdm_abha', 'can_add');
        $b = $this->body();
        $this->call(function () use ($b) {
            $rows = $this->abdm_client->get('/m1/share/queue');
            foreach ($rows as $r) {
                if ((string) $r['id'] === (string) (isset($b['id']) ? $b['id'] : '')) {
                    $m = $this->abdm_model->find_patient_by_abha($r['abha_address'], isset($r['abhaNumber']) ? $r['abhaNumber'] : null);
                    if ($m) {
                        return array('patientId' => (int) $m['patient_id'], 'existing' => true);
                    }
                    $profile = array_merge(array('abhaAddress' => $r['abha_address']), isset($r['profile']) ? $r['profile'] : array());
                    $pid = $this->abdm_model->create_patient_from_abha($profile, $this->staff_id());
                    $this->abdm_model->sync_patient($this->abdm_client, $pid);
                    return array('patientId' => $pid, 'existing' => false);
                }
            }
            throw new Exception('Token not found in today\'s queue', 404);
        });
    }
}
