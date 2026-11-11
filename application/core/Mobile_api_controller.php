<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Base class for the staff mobile app API (routes: api/v1/*).
 *
 * Auth is a bearer token from mobile_api_tokens, not the browser session cookie. Once the token is
 * verified, the staff member's "hospitaladmin" session array is rebuilt in memory only (the session is
 * closed first, so nothing is ever written to the session store). Existing models, Customlib and the
 * Rbac permission checks therefore behave exactly as they do for a logged-in web user.
 *
 * Feature modules are granted per role (Mobileapi_model::$role_modules). Phase 1 ships "doctor";
 * later roles (e.g. Nurse => "nursing") only need an entry there plus their own controllers.
 */
abstract class Mobile_api_controller extends CI_Controller
{
    const API_VERSION = 1;

    /** @var array|null Authenticated staff: id, name, surname, employee_id, email, role_id, role_name, modules */
    protected $staff = null;
    /** @var array|null Row from mobile_api_tokens */
    protected $token_row = null;
    /** @var array sch_settings row */
    protected $settings = array();

    public function __construct()
    {
        parent::__construct();

        // API calls must never read or persist the browser session.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = array();

        $this->load->model(array('mobileapi_model'));
        $this->mobileapi_model->ensure_schema();
        $rows           = $this->setting_model->get();
        $this->settings = !empty($rows) ? $rows[0] : array();
        if (!empty($this->settings['timezone'])) {
            @date_default_timezone_set($this->settings['timezone']);
        }
        $this->load_web_environment();
        $this->output->set_header('Cache-Control: no-store');
    }

    /**
     * Same helpers and language files MY_Controller loads for web pages. Existing libraries and models
     * (Rbac -> config/mailsms.php calls lang(), Customlib, ...) rely on them. View/theme setup is skipped.
     */
    private function load_web_environment()
    {
        $this->load->helper(array('lang', 'language', 'directory', 'custom_helper', 'mime_helper', 'mask'));
        $this->load->config('license');
        $this->load->library('module_lib');

        $language = !empty($this->settings['language']) ? $this->settings['language'] : 'English';
        $this->config->set_item('language', $language);
        $files = array();
        foreach ((array) directory_map(APPPATH . 'language/' . $language . '/app_files') as $file) {
            if (is_string($file) && substr($file, -4) === '.php') {
                $files[] = 'app_files/' . substr($file, 0, -4);
            }
        }
        if (!empty($files)) {
            $this->load->language($files, $language);
        }
    }

    // ------------------------------------------------------------------ responses
    protected function respond($data, $status = 200)
    {
        $this->output
            ->set_status_header($status)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR))
            ->_display();
        exit;
    }

    /** Errors always carry a stable machine code the app can branch on. */
    protected function fail($status, $code, $message, $extra = array())
    {
        $this->respond(array_merge(array('error' => array('code' => $code, 'message' => $message)), $extra), $status);
    }

    protected function require_method($method)
    {
        if ($this->input->method() !== strtolower($method)) {
            $this->fail(405, 'method_not_allowed', 'Use ' . strtoupper($method) . ' for this endpoint.');
        }
    }

    /** JSON request body (the app always sends application/json). */
    protected function body()
    {
        static $body = null;
        if ($body === null) {
            $raw  = (string) $this->input->raw_input_stream;
            $body = $raw === '' ? array() : json_decode($raw, true);
            if (!is_array($body)) {
                $this->fail(400, 'bad_json', 'Request body must be a JSON object.');
            }
        }
        return $body;
    }

    // ------------------------------------------------------------------ auth
    protected function bearer_token()
    {
        // X-Auth-Token is checked first: some PHP-FPM/proxy setups strip the Authorization header.
        $t = (string) $this->input->get_request_header('X-Auth-Token', true);
        if ($t === '') {
            $h = (string) $this->input->get_request_header('Authorization', true);
            if (stripos($h, 'Bearer ') === 0) {
                $t = trim(substr($h, 7));
            }
        }
        return preg_match('/^[a-f0-9]{64}$/', $t) ? $t : '';
    }

    /** Verify the token, rebuild the staff session in memory and load Rbac. */
    protected function authenticate()
    {
        $plain = $this->bearer_token();
        if ($plain === '') {
            $this->fail(401, 'token_missing', 'Sign in to continue.');
        }
        $row = $this->mobileapi_model->find_token($plain);
        if (!$row) {
            $this->fail(401, 'token_invalid', 'Your session has expired. Sign in again.');
        }
        $roles = $this->staffroles_model->getStaffRoles($row['staff_id']);
        if (empty($roles)) {
            $this->fail(403, 'no_role', 'Your account has no role assigned.');
        }
        $role_name = $roles[0]->name;
        $role_id   = (int) $roles[0]->role_id;

        $this->token_row = $row;
        $this->staff     = array(
            'id'          => (int) $row['staff_id'],
            'name'        => $row['name'],
            'surname'     => $row['surname'],
            'employee_id' => $row['employee_id'],
            'email'       => $row['email'],
            'role_id'     => $role_id,
            'role_name'   => $role_name,
            'modules'     => $this->mobileapi_model->modules_for_role($role_name),
        );
        $this->hydrate_session($row, array($role_name => $role_id));
        $this->load->library('rbac');
        $this->mobileapi_model->touch_token($row);
        return $this->staff;
    }

    /** Same shape Site::login stores, so Customlib/Rbac helpers work unchanged. In memory only. */
    private function hydrate_session($row, $roles)
    {
        $s = $this->settings;
        $_SESSION['hospitaladmin'] = array(
            'id'                     => (int) $row['staff_id'],
            'username'               => trim($row['name'] . ' ' . $row['surname']),
            'email'                  => $row['email'],
            'roles'                  => $roles,
            'date_format'            => isset($s['date_format']) ? $s['date_format'] : 'd-m-Y',
            'currency_symbol'        => isset($s['currency_symbol']) ? $s['currency_symbol'] : '',
            'start_month'            => isset($s['start_month']) ? $s['start_month'] : '',
            'timezone'               => isset($s['timezone']) ? $s['timezone'] : 'UTC',
            'sch_name'               => isset($s['name']) ? $s['name'] : '',
            'language'               => array('lang_id' => isset($s['lang_id']) ? $s['lang_id'] : '', 'language' => isset($s['language']) ? $s['language'] : 'English'),
            'is_rtl'                 => 'no',
            'doctor_restriction'     => isset($s['doctor_restriction']) ? $s['doctor_restriction'] : 'disabled',
            'superadmin_restriction' => isset($s['superadmin_restriction']) ? $s['superadmin_restriction'] : '',
            'theme'                  => isset($s['theme']) ? $s['theme'] : '',
            'base_url'               => isset($s['base_url']) ? $s['base_url'] : '',
            'folder_path'            => isset($s['folder_path']) ? $s['folder_path'] : '',
            'time_format'            => !(isset($s['time_format']) && $s['time_format'] == '12-hour'),
            'prefix'                 => $this->prefix_model->getPrefixArray(),
            'db_array'               => array('base_url' => isset($s['base_url']) ? $s['base_url'] : '', 'folder_path' => isset($s['folder_path']) ? $s['folder_path'] : '', 'db_group' => 'default'),
        );
    }

    /** @var array|null Authenticated patient account: user_id, patient_id, username, patient_name */
    protected $patient_account = null;

    /** Patient-app calls: verify a patient token. No staff session or RBAC is involved. */
    protected function authenticate_patient()
    {
        if (isset($this->settings['patient_panel']) && $this->settings['patient_panel'] === 'disabled') {
            $this->fail(403, 'patient_panel_disabled', 'Patient login is turned off by the hospital.');
        }
        $plain = $this->bearer_token();
        if ($plain === '') {
            $this->fail(401, 'token_missing', 'Sign in to continue.');
        }
        $row = $this->mobileapi_model->find_patient_token($plain);
        if (!$row) {
            $this->fail(401, 'token_invalid', 'Your session has expired. Sign in again.');
        }
        $this->token_row       = $row;
        $this->patient_account = array(
            'user_id'      => (int) $row['user_id'],
            'patient_id'   => (int) $row['patient_id'],
            'username'     => $row['username'],
            'patient_name' => $row['patient_name'],
        );
        $this->mobileapi_model->touch_token($row);
        return $this->patient_account;
    }

    /** Stream a file to the app (inline, private, never cached). */
    protected function send_file($path, $name)
    {
        $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($name)) . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    protected function require_module($module)
    {
        if (!in_array($module, $this->staff['modules'], true)) {
            $this->fail(403, 'module_forbidden', 'This app section is not available for your role.');
        }
    }

    protected function can($category, $permission)
    {
        return $this->rbac->hasPrivilege($category, $permission);
    }

    /** The web "doctor restriction" setting limits doctors to their own patients; other roles are not affected. */
    protected function doctor_restricted()
    {
        return isset($this->settings['doctor_restriction']) && $this->settings['doctor_restriction'] === 'enabled'
            && !empty($this->staff) && in_array('doctor', $this->staff['modules'], true);
    }
}
