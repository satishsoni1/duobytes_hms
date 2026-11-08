<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/Mobile_api_controller.php';

/**
 * Patient side of the mobile app. Same accounts and rules as the web patient panel (site/userlogin).
 *
 * POST api/v1/patient/login    {username, password, device_id, device_name, platform, app_version}
 * GET  api/v1/patient/me
 * GET  api/v1/patient/data     The patient's own records (supports If-None-Match -> 304)
 * POST api/v1/patient/logout
 */
class Mobile_patient extends Mobile_api_controller
{
    public function login()
    {
        $this->require_method('post');
        if (isset($this->settings['patient_panel']) && $this->settings['patient_panel'] === 'disabled') {
            $this->fail(403, 'patient_panel_disabled', 'Patient login is turned off by the hospital.');
        }
        $b        = $this->body();
        $username = trim((string) (isset($b['username']) ? $b['username'] : ''));
        $password = (string) (isset($b['password']) ? $b['password'] : '');
        if ($username === '' || $password === '') {
            $this->fail(422, 'validation', 'Enter your username and password.');
        }
        $throttle_key = 'patient:' . strtolower($username);
        if ($this->mobileapi_model->too_many_attempts($throttle_key)) {
            $this->fail(429, 'too_many_attempts', 'Too many failed sign-ins. Try again in ' . Mobileapi_model::FAIL_WINDOW_MIN . ' minutes.');
        }

        // Same check as the web patient login (User_model::checkLogin).
        $rows = $this->user_model->checkLogin(array('username' => $username, 'password' => $password));
        $user = !empty($rows) ? $rows[0] : null;
        if (!$user || $user->role !== 'patient') {
            $this->mobileapi_model->record_failed_login($throttle_key, $this->input->ip_address());
            $this->fail(401, 'bad_credentials', 'Username or password is incorrect.');
        }
        if ($user->is_active !== 'yes') {
            $this->fail(403, 'inactive', 'Your account is disabled. Please contact the hospital.');
        }
        $this->load->model('mobilepatient_model');
        $account = $this->mobilepatient_model->account($user->id);
        if (!$account) {
            $this->fail(403, 'no_patient', 'This login is not linked to a patient record. Please contact the hospital.');
        }

        $this->mobileapi_model->clear_failed_logins($throttle_key);
        $token = $this->mobileapi_model->issue_token($user->id, $b, 'patient');

        $this->respond(array(
            'token'       => $token,
            'expires_in'  => Mobileapi_model::TOKEN_TTL_DAYS * 86400,
            'api_version' => self::API_VERSION,
            'patient'     => $account,
            'modules'     => $this->mobilepatient_model->modules(),
            'hospital'    => $this->mobileapi_model->branding($this->settings),
        ));
    }

    public function me()
    {
        $this->require_method('get');
        $a = $this->authenticate_patient();
        $this->load->model('mobilepatient_model');
        $this->respond(array(
            'api_version' => self::API_VERSION,
            'patient'     => $this->mobilepatient_model->account($a['user_id']),
            'modules'     => $this->mobilepatient_model->modules(),
            'hospital'    => $this->mobileapi_model->branding($this->settings),
        ));
    }

    public function data()
    {
        $this->require_method('get');
        $a = $this->authenticate_patient();
        $this->load->model('mobilepatient_model');
        $modules = $this->mobilepatient_model->modules();
        $data    = array('modules' => $modules, 'data' => $this->mobilepatient_model->snapshot($a['patient_id'], $modules));
        $etag    = '"' . md5(json_encode($data)) . '"';
        $this->output->set_header('ETag: ' . $etag);
        if (trim((string) $this->input->get_request_header('If-None-Match', true)) === $etag) {
            $this->output->set_status_header(304)->_display();
            exit;
        }
        $this->respond($data);
    }

    /** GET api/v1/patient/report_file?kind=pathology|radiology&id= : the patient's own report file. */
    public function report_file()
    {
        $this->require_method('get');
        $a    = $this->authenticate_patient();
        $kind = (string) $this->input->get('kind', true);
        $this->load->model(array('mobilepatient_model', 'mobiledoctor_model'));
        $modules = $this->mobilepatient_model->modules();
        if (!in_array($kind, array('pathology', 'radiology'), true) || empty($modules[$kind])) {
            $this->fail(404, 'not_found', 'Report not found.');
        }
        $file = $this->mobiledoctor_model->lab_report_file($kind, (int) $this->input->get('id', true));
        if (!$file || $file[0] !== $a['patient_id']) {
            $this->fail(404, 'not_found', 'Report not found.');
        }
        $this->send_file($file[1], $file[2]);
    }

    // ------------------------------------------------------------------ appointments
    private function require_appointments()
    {
        $a = $this->authenticate_patient();
        $this->load->model(array('mobilepatient_model', 'mobilebooking_model'));
        $m = $this->mobilepatient_model->modules();
        if (empty($m['my_appointments'])) {
            $this->fail(403, 'module_disabled', 'Online appointments are not available at this hospital.');
        }
        return $a;
    }

    /** GET api/v1/patient/doctors : doctors who have OPD shifts, with specialties, fee and working days. */
    public function doctors()
    {
        $this->require_method('get');
        $this->require_appointments();
        $this->respond(array('doctors' => $this->mobilebooking_model->doctors()));
    }

    /** GET api/v1/patient/slots?doctor_id=&date=YYYY-MM-DD */
    public function slots()
    {
        $this->require_method('get');
        $this->require_appointments();
        $date = (string) $this->input->get('date', true);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->fail(422, 'validation', 'Choose a date.');
        }
        $this->respond(array('date' => $date, 'shifts' => $this->mobilebooking_model->slots((int) $this->input->get('doctor_id', true), $date)));
    }

    /** POST api/v1/patient/book {doctor_id, shift_id, date, time, message} */
    public function book()
    {
        $this->require_method('post');
        $a = $this->require_appointments();
        $b = $this->body();
        $r = $this->mobilebooking_model->book($a['patient_id'], (int) (isset($b['doctor_id']) ? $b['doctor_id'] : 0), (int) (isset($b['shift_id']) ? $b['shift_id'] : 0),
            (string) (isset($b['date']) ? $b['date'] : ''), (string) (isset($b['time']) ? $b['time'] : ''), isset($b['message']) ? $b['message'] : '');
        if (isset($r['error'])) {
            $this->fail(409, 'slot_unavailable', $r['error']);
        }
        $this->respond(array('ok' => true, 'appointment_id' => $r['appointment_id'], 'status' => 'pending'));
    }

    /** POST api/v1/patient/cancel {appointment_id} */
    public function cancel()
    {
        $this->require_method('post');
        $a = $this->require_appointments();
        $b = $this->body();
        $err = $this->mobilebooking_model->cancel($a['patient_id'], (int) (isset($b['appointment_id']) ? $b['appointment_id'] : 0));
        if ($err !== null) {
            $this->fail(409, 'cannot_cancel', $err);
        }
        $this->respond(array('ok' => true));
    }

    /** GET api/v1/patient/queue : today's queue position for each of the patient's appointments. */
    public function queue()
    {
        $this->require_method('get');
        $a = $this->require_appointments();
        $this->respond(array('queue' => $this->mobilebooking_model->queue($a['patient_id']), 'as_of' => date('Y-m-d H:i:s')));
    }

    public function logout()
    {
        $this->require_method('post');
        $this->authenticate_patient();
        $this->mobileapi_model->revoke_token($this->token_row['id']);
        $this->respond(array('ok' => true));
    }
}
