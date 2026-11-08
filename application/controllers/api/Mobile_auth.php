<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/Mobile_api_controller.php';

/**
 * POST api/v1/auth/login   {email, password, device_id, device_name, platform, app_version}
 * GET  api/v1/auth/me
 * POST api/v1/auth/logout
 */
class Mobile_auth extends Mobile_api_controller
{
    public function login()
    {
        $this->require_method('post');
        $this->mobileapi_model->ensure_schema();
        $this->load->library('Enc_lib'); // Staff_model::checkLogin needs it; not autoloaded

        $b        = $this->body();
        $email    = strtolower(trim((string) (isset($b['email']) ? $b['email'] : '')));
        $password = (string) (isset($b['password']) ? $b['password'] : '');
        if ($email === '' || $password === '') {
            $this->fail(422, 'validation', 'Enter your email and password.');
        }
        if ($this->mobileapi_model->too_many_attempts($email)) {
            $this->fail(429, 'too_many_attempts', 'Too many failed sign-ins. Try again in ' . Mobileapi_model::FAIL_WINDOW_MIN . ' minutes.');
        }

        $staff = $this->staff_model->checkLogin(array('email' => $email, 'password' => $password));
        if (!$staff) {
            $this->mobileapi_model->record_failed_login($email, $this->input->ip_address());
            $this->fail(401, 'bad_credentials', 'Email or password is incorrect.');
        }
        if (!$staff->is_active) {
            $this->fail(403, 'inactive', 'Your account is disabled. Contact the hospital administrator.');
        }
        $role_name = key($staff->roles);
        $modules   = $this->mobileapi_model->modules_for_role($role_name);
        if (empty($modules)) {
            $this->fail(403, 'role_not_supported', 'The mobile app is not available for the ' . $role_name . ' role yet.');
        }

        $this->mobileapi_model->clear_failed_logins($email);
        $token = $this->mobileapi_model->issue_token($staff->id, $b);

        $this->respond(array(
            'token'       => $token,
            'expires_in'  => Mobileapi_model::TOKEN_TTL_DAYS * 86400,
            'api_version' => self::API_VERSION,
            'staff'       => $this->profile($staff->id, $role_name, $modules),
            'hospital'    => $this->mobileapi_model->branding($this->settings),
        ));
    }

    public function me()
    {
        $this->require_method('get');
        $s = $this->authenticate();
        $this->respond(array(
            'api_version' => self::API_VERSION,
            'staff'       => $this->profile($s['id'], $s['role_name'], $s['modules']),
            'hospital'    => $this->mobileapi_model->branding($this->settings),
        ));
    }

    public function logout()
    {
        $this->require_method('post');
        $this->authenticate();
        $this->mobileapi_model->revoke_token($this->token_row['id']);
        $this->respond(array('ok' => true));
    }

    private function profile($staff_id, $role_name, $modules)
    {
        $p            = $this->mobileapi_model->staff_profile($staff_id);
        $p['role']    = $role_name;
        $p['modules'] = $modules;
        return $p;
    }
}
