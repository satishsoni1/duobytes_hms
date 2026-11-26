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
            $out = array('error' => $e->getMessage());
            if ($e instanceof Abdm_exception && $e->abdm_code) {
                $out['code'] = $e->abdm_code;
            }
            $this->json($out, $code >= 400 && $code < 600 ? $code : 502);
        }
    }

    private function kind($b)
    {
        return isset($b['kind']) && $b['kind'] === 'phr' ? 'phr' : 'abha';
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
            'login_select' => '/m1/login/select',
            // ABHA address login: methods from ABDM (VRFY_ABHA_102/202)
            'address_search' => '/m1/address/search', 'address_otp' => '/m1/address/otp', 'address_verify' => '/m1/address/verify',
            // Find a forgotten ABHA by mobile: search, pick, OTP (VRFY_ABHA_301-308)
            'find_search' => '/m1/find/search', 'find_otp' => '/m1/find/otp', 'find_verify' => '/m1/find/verify',
            // Update the mobile on the ABHA when the hospital holds another (VRFY_ABHA_101/201 step 5)
            'mobile_update_otp' => '/m1/profile/mobile/otp', 'mobile_update_verify' => '/m1/profile/mobile/verify',
        );
        $b = $this->body();
        if ($action === 'address_suggestions') {
            $this->call(function () use ($b) {
                return $this->abdm_client->get('/m1/enrol/address/suggestions?txnId=' . rawurlencode(isset($b['txnId']) ? $b['txnId'] : ''));
            });
        }
        if ($action === 'address_rules') {
            $this->call(function () {
                return $this->abdm_client->get('/m1/enrol/address/rules');
            });
        }
        if ($action === 'enrol_otp') {
            // Consent is recorded by the hospital before the Aadhaar OTP goes out (CRT_ABHA_102).
            $this->call(function () use ($b) {
                if (empty($b['consent'])) {
                    throw new Exception('Beneficiary consent is required', 400);
                }
                $out = $this->abdm_client->post('/m1/enrol/aadhaar/otp', array('aadhaar' => isset($b['aadhaar']) ? (string) $b['aadhaar'] : '',
                    'consent' => true, 'consentVersion' => Abdm_model::CONSENT_VERSION));
                $this->abdm_model->record_consent('abha-enrollment', Abdm_model::CONSENT_VERSION, Abdm_model::CONSENT_EN,
                    isset($out['txnId']) ? $out['txnId'] : null, $this->staff_id());
                return $out;
            });
        }
        if ($action === 'enrol_verify') {
            $this->call(function () use ($b) {
                $out = $this->abdm_client->post('/m1/enrol/aadhaar/verify', $b);
                $this->abdm_model->consent_abha(isset($b['txnId']) ? $b['txnId'] : null,
                    isset($out['ABHAProfile']['ABHANumber']) ? $out['ABHAProfile']['ABHANumber'] : null);
                return $out;
            });
        }
        if ($action === 'card' || $action === 'qr') {
            try {
                list($ctype, $bytes) = $this->abdm_client->post_binary('/m1/profile/' . $action, array('token' => isset($b['token']) ? $b['token'] : '', 'kind' => $this->kind($b)));
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
            $patient = $this->abdm_model->get_patient($patient_id);
            if (!$patient) {
                throw new Exception('Patient not found', 404);
            }
            $p       = $this->abha_profile(isset($b['token']) ? $b['token'] : '', $this->kind($b));
            $prof    = $p['raw'];
            $number  = $p['number'];
            $address = $p['address'];
            $other = $this->abdm_model->find_patient_by_abha($address, $number);
            if ($other && (int) $other['patient_id'] !== (int) $patient_id) {
                throw new Exception('This ABHA profile is already linked to HMIS Beneficiary ID #' . $other['patient_id'], 409);
            }
            $name = $p['name'];
            // ABDM: the HIP must make sure the ABHA belongs to this patient before linking records to it.
            $mismatch = $this->abdm_model->demographic_mismatches($patient, $prof);
            if ($mismatch && empty($b['confirmMismatch'])) {
                return array('mismatch' => $mismatch, 'abhaName' => $name);
            }
            if ($mismatch) {
                log_message('info', 'ABDM: ABHA attached despite mismatch for patient ' . (int) $patient_id . ' by staff ' . $this->staff_id() . ': ' . implode('; ', $mismatch));
            }
            $this->abdm_model->save_abha($patient_id, $number, $address, $name, $this->staff_id());
            $this->abdm_model->sync_patient($this->abdm_client, $patient_id);
            // The hospital's mobile, so the screen can offer to update the ABHA when they differ.
            return array('abhaNumber' => $number, 'abhaAddress' => $address, 'name' => $name,
                'hmsMobile' => (string) $patient['mobileno'], 'abhaMobile' => $p['mobile']);
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

    public function sms_link($patient_id = '')
    {
        if (!ctype_digit((string) $patient_id)) {
            $this->json(array('error' => 'Invalid patient id'), 400);
        }
        $this->guard('abdm_records', 'can_add');
        $this->call(function () use ($patient_id) {
            $this->abdm_model->sync_patient($this->abdm_client, $patient_id);
            return $this->abdm_client->post('/hip/patients/' . rawurlencode($this->abdm_model->mrn($patient_id)) . '/sms', array());
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
            $out = $this->abdm_model->sync($this->abdm_client);
            $out['linking'] = $this->abdm_model->auto_link($this->abdm_client);
            return $out;
        });
    }

    // ------------------------------------------------------------------ M2: LOINC codes for lab results
    /** Lab codes: a LOINC code per pathology parameter, so lab results are shared coded (Health_RECORD_CREATION_101). */
    public function loinc()
    {
        if (!$this->rbac->hasPrivilege('abdm_settings', 'can_view')) {
            access_denied();
        }
        $data['rows'] = $this->abdm_model->loinc_rows();
        $data['can_edit'] = $this->rbac->hasPrivilege('abdm_settings', 'can_edit');
        $this->page('loinc', $data, 'ABDM Lab codes (LOINC)');
    }

    public function loinc_save()
    {
        $this->guard('abdm_settings', 'can_edit');
        $b = $this->body();
        $this->call(function () use ($b) {
            $items = isset($b['items']) && is_array($b['items']) ? array_slice($b['items'], 0, 500) : array();
            $saved = 0;
            foreach ($items as $it) {
                $this->abdm_model->save_loinc(isset($it['id']) ? $it['id'] : 0, isset($it['code']) ? $it['code'] : '',
                    isset($it['display']) ? $it['display'] : '', $this->staff_id());
                $saved++;
            }
            return array('saved' => $saved);
        });
    }

    // ------------------------------------------------------------------ M3: HIU (proxy)
    public function hiu($action = '', $id = '')
    {
        $read = in_array($action, array('meta', 'requests', 'artefacts', 'records', 'patient'), true);
        $this->guard('abdm_consent', $read ? 'can_view' : 'can_add', !$read);
        $this->call(function () use ($action, $id) {
            $safe = preg_replace('/[^A-Za-z0-9-]/', '', $id);
            switch ($action) {
                case 'meta':      return $this->abdm_client->get('/hiu/meta');
                case 'patient':   return $this->hiu_patient((string) $this->input->get('abha', true));
                case 'requests':
                    // HIU_FLOW_102/104: show the patient's name and ABHA number beside the ABHA address the request names.
                    $rows = $this->abdm_client->get('/hiu/consent-requests');
                    $known = array();
                    foreach ($rows as &$r) {
                        $a = strtolower((string) $r['patient']);
                        if (!array_key_exists($a, $known)) {
                            $known[$a] = $this->hiu_patient($a);
                        }
                        $r['hms'] = $known[$a];
                    }
                    return $rows;
                case 'create':
                    // The patient must be known here by a verified ABHA address before records are requested.
                    $b = $this->body();
                    if (!$this->hiu_patient(isset($b['patient']) ? (string) $b['patient'] : '')) {
                        throw new Exception('This ABHA address is not on any patient here. Verify the patient\'s ABHA first (ABDM / ABHA → Create / Verify ABHA), then request records.', 400);
                    }
                    return $this->abdm_client->post('/hiu/consent-requests', $b);
                case 'artefacts': return $this->abdm_client->get('/hiu/artefacts');
                case 'records':   return $this->abdm_client->get('/hiu/records' . ($safe ? '?consentId=' . $safe : ''));
                case 'status':    return $this->abdm_client->post('/hiu/consent-requests/' . $safe . '/status', array());
                case 'fetch':     return $this->abdm_client->post('/hiu/artefacts/' . $safe . '/fetch', array());
                case 'request':   return $this->abdm_client->post('/hiu/artefacts/' . $safe . '/request-data', array());
            }
            throw new Exception('Unknown action', 404);
        });
    }

    /** The hospital patient tagged with this ABHA address: id, name, ABHA number. Null when none. */
    private function hiu_patient($abha_address)
    {
        $a = strtolower(trim($abha_address));
        if ($a === '') {
            return null;
        }
        $hit = $this->abdm_model->find_patient_by_abha($a, null);
        if (!$hit) {
            return null;
        }
        $p = $this->abdm_model->patient_summary($hit['patient_id']);
        return $p ? array('id' => $p['id'], 'name' => $p['name'], 'abhaNumber' => $p['abhaNumber'], 'gender' => $p['gender'], 'dob' => $p['dob']) : null;
    }

    // ------------------------------------------------------------------ Scan & Share
    public function share_queue()
    {
        $this->guard('abdm_abha', 'can_view', false);
        $this->call(function () {
            // Shared profiles are registered with no manual step (SHARE_PATIENT_PROFILE_701); the cron does the same.
            return $this->abdm_model->process_share_queue($this->abdm_client, $this->staff_id());
        });
    }

    /** Manual retry for a token whose automatic registration failed. */
    public function share_register()
    {
        $this->guard('abdm_abha', 'can_add');
        $b = $this->body();
        $this->call(function () use ($b) {
            $rows = $this->abdm_client->get('/m1/share/queue');
            foreach ($rows as $r) {
                if ((string) $r['id'] === (string) (isset($b['id']) ? $b['id'] : '')) {
                    list($pid, $how) = $this->abdm_model->register_shared($r, $this->staff_id());
                    $this->abdm_model->sync_patient($this->abdm_client, $pid);
                    return array('patientId' => $pid, 'existing' => $how !== 'new', 'how' => $how);
                }
            }
            throw new Exception('Token not found in today\'s queue', 404);
        });
    }

    // ------------------------------------------------------------------ M1: standalone create / verify
    /** Create or verify an ABHA first, then register a new patient or attach it to an existing one. */
    public function abha()
    {
        if (!$this->rbac->hasPrivilege('abdm_abha', 'can_add')) {
            access_denied();
        }
        $this->page('abha', array(), 'Create / Verify ABHA');
    }

    /**
     * Profile read from ABDM with the user token (never trust a profile sent by the browser).
     * $kind: 'abha' for an enrolment / ABHA number / mobile / Aadhaar token, 'phr' for an ABHA address login.
     */
    private function abha_profile($token, $kind = 'abha')
    {
        $prof = $this->abdm_client->post('/m1/profile', array('token' => (string) $token, 'kind' => $kind));
        $number  = isset($prof['ABHANumber']) ? $prof['ABHANumber'] : null;
        $address = isset($prof['preferredAbhaAddress']) ? $prof['preferredAbhaAddress'] : (isset($prof['phrAddress'][0]) ? $prof['phrAddress'][0] : null);
        if (!$number && !$address) {
            throw new Exception('Could not read the ABHA profile', 502);
        }
        $name = isset($prof['name']) && trim($prof['name']) !== '' ? trim($prof['name'])
            : trim(preg_replace('/\s+/', ' ', implode(' ', array_filter(array(@$prof['firstName'], @$prof['middleName'], @$prof['lastName'])))));
        $y = isset($prof['yearOfBirth']) ? (int) $prof['yearOfBirth'] : 0;
        $m = isset($prof['monthOfBirth']) ? (int) $prof['monthOfBirth'] : 0;
        $d = isset($prof['dayOfBirth']) ? (int) $prof['dayOfBirth'] : 0;
        $dob_raw = isset($prof['dateOfBirth']) ? $prof['dateOfBirth'] : (isset($prof['dob']) ? $prof['dob'] : '');
        if (!$y && preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', (string) $dob_raw, $mm)) {
            list(, $d, $m, $y) = array_map('intval', $mm);
        }
        $str = function ($k) use ($prof) {
            return isset($prof[$k]) && !is_array($prof[$k]) ? trim((string) $prof[$k]) : '';
        };
        $line = trim(implode(', ', array_filter(array($str('address'), $str('townName'), $str('districtName'), $str('stateName'), $str('pinCode')))));
        return array(
            'raw'      => $prof,
            'kind'     => $kind,
            'number'   => $number,
            'address'  => $address,
            'addresses' => isset($prof['phrAddress']) && is_array($prof['phrAddress']) ? array_values($prof['phrAddress']) : ($address ? array($address) : array()),
            'name'     => $name,
            'gender'   => isset($prof['gender']) ? strtoupper(substr($prof['gender'], 0, 1)) : '',
            'dob'      => $y ? ($d && $m ? sprintf('%02d-%02d-%04d', $d, $m, $y) : (string) $y) : '',
            'mobile'   => $str('mobile'),
            'email'    => $str('email'),
            'photo'    => isset($prof['profilePhoto']) ? $prof['profilePhoto'] : (isset($prof['photo']) ? $prof['photo'] : ''),
            'line'     => $line,
            'street'   => $str('address'),
            'city'     => $str('townName') !== '' ? $str('townName') : $str('villageName'),
            'district' => $str('districtName'),
            'state'    => $str('stateName'),
            'pincode'  => $str('pinCode'),
            'kycVerified' => isset($prof['kycVerified']) ? filter_var($prof['kycVerified'], FILTER_VALIDATE_BOOLEAN) : null,
            'y' => $y, 'm' => $m, 'd' => $d,
        );
    }

    private function match_input($p)
    {
        return array('name' => $p['name'], 'gender' => $p['gender'], 'yearOfBirth' => $p['y'] ?: null, 'mobile' => $p['mobile']);
    }

    /**
     * After a verify or create: the profile, the patient already tagged with this ABHA with both sides for the comparison
     * screen (VRFY_ABHA_308), and otherwise the patients who may be this person (CRT_ABHA_116).
     */
    public function abha_lookup()
    {
        $this->guard('abdm_abha', 'can_add');
        $b = $this->body();
        $this->call(function () use ($b) {
            $p = $this->abha_profile(isset($b['token']) ? $b['token'] : '', $this->kind($b));
            $hit = $this->abdm_model->find_patient_by_abha($p['address'], $p['number']);
            $p['hms_patient_id'] = $hit ? (int) $hit['patient_id'] : null;
            $p['hms'] = $hit ? $this->abdm_model->patient_summary($hit['patient_id']) : null;
            $p['candidates'] = $hit ? array() : $this->abdm_model->match_candidates($this->match_input($p));
            unset($p['raw'], $p['y'], $p['m'], $p['d']);
            return $p;
        });
    }

    /**
     * Register a new HMS patient from the verified ABHA, after staff confirm the filled form (VRFY_ABHA_306).
     * Name, date of birth and gender always come from ABDM; mobile, email and address are taken from the form.
     * A patient who matches on every demographic field is reused unless staff confirm this is someone else.
     */
    public function abha_register()
    {
        $this->guard('abdm_abha', 'can_add');
        if (!$this->rbac->hasPrivilege('patient', 'can_add')) {
            $this->json(array('error' => 'You do not have permission to add patients.'), 403);
        }
        $b = $this->body();
        $this->call(function () use ($b) {
            $p = $this->abha_profile(isset($b['token']) ? $b['token'] : '', $this->kind($b));
            $hit = $this->abdm_model->find_patient_by_abha($p['address'], $p['number']);
            if ($hit) {
                return array('patientId' => (int) $hit['patient_id'], 'existing' => true);
            }
            $f = isset($b['form']) && is_array($b['form']) ? $b['form'] : array();
            $val = function ($k, $max, $fallback = '') use ($f) {
                $v = isset($f[$k]) && is_scalar($f[$k]) ? trim((string) $f[$k]) : $fallback;
                return mb_substr($v, 0, $max);
            };
            $mobile = preg_replace('/\D/', '', $val('mobile', 15, $p['mobile']));
            if ($mobile !== '' && !preg_match('/^[6-9]\d{9}$/', substr($mobile, -10))) {
                throw new Exception('Please enter a valid mobile number.', 400);
            }
            $email = $val('email', 100, $p['email']);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.', 400);
            }
            if (empty($b['forceNew'])) {
                foreach ($this->abdm_model->match_candidates(array_merge($this->match_input($p), array('mobile' => $mobile)), 3) as $c) {
                    if ($c['full'] && !$c['abha_number']) {
                        return array('match' => $c);
                    }
                }
            }
            $pid = $this->abdm_model->create_patient_from_abha(array(
                'name' => $p['name'], 'gender' => $p['gender'], 'phoneNumber' => $mobile === '' ? '' : substr($mobile, -10), 'email' => $email,
                'yearOfBirth' => $p['y'] ?: null, 'monthOfBirth' => $p['m'] ?: null, 'dayOfBirth' => $p['d'] ?: null,
                'address' => array('line' => $val('street', 250, $p['street']), 'city' => $val('city', 120, $p['city']),
                    'district' => $val('district', 80, $p['district']), 'state' => $val('state', 60, $p['state']),
                    'pincode' => preg_replace('/\D/', '', $val('pincode', 10, $p['pincode']))),
                'abhaNumber' => $p['number'], 'abhaAddress' => $p['address'],
            ), $this->staff_id(), 'Registered via ABHA (verified by OTP)');
            $this->abdm_model->sync_patient($this->abdm_client, $pid);
            return array('patientId' => $pid, 'existing' => false);
        });
    }

    /** Patient picker for "attach to existing patient": id, name or mobile. */
    public function abha_patients()
    {
        $this->guard('abdm_abha', 'can_add', false);
        $q = trim((string) $this->input->get('q', true));
        $this->call(function () use ($q) {
            return $this->abdm_model->search_patients($q);
        });
    }

    // ------------------------------------------------------------------ M2: HIP monitor
    /** Consents other facilities/patients granted for our records, and the data transfers that followed. */
    public function hip_monitor()
    {
        if (!$this->rbac->hasPrivilege('abdm_records', 'can_view')) {
            access_denied();
        }
        $this->page('hip', array(), 'ABDM Shared Records');
    }

    public function hip($action = '')
    {
        $this->guard('abdm_records', 'can_view', false);
        $this->call(function () use ($action) {
            switch ($action) {
                case 'consents':  return $this->abdm_client->get('/hip/consents');
                case 'transfers': return $this->abdm_client->get('/hip/transfers');
            }
            throw new Exception('Unknown action', 404);
        });
    }

    // ------------------------------------------------------------------ Bridge activity: jobs + audit
    public function activity()
    {
        if (!$this->rbac->hasPrivilege('abdm_settings', 'can_view')) {
            access_denied();
        }
        $this->page('activity', array(), 'ABDM Activity');
    }

    public function bridge($action = '', $id = '')
    {
        $write = $action === 'retry';
        $this->guard('abdm_settings', $write ? 'can_edit' : 'can_view', $write);
        $this->call(function () use ($action, $id) {
            switch ($action) {
                case 'jobs':
                    return $this->abdm_client->get('/admin/jobs');
                case 'audit':
                    $before = (string) $this->input->get('before', true);
                    return $this->abdm_client->get('/admin/audit' . (ctype_digit($before) ? '?before=' . $before : ''));
                case 'retry':
                    if (!ctype_digit((string) $id)) {
                        throw new Exception('Invalid job id', 400);
                    }
                    return $this->abdm_client->post('/admin/jobs/' . $id . '/retry', array());
            }
            throw new Exception('Unknown action', 404);
        });
    }
}
