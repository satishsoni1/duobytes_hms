<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Staff mobile app: device tokens, login throttling, idempotent sync-op log, branding.
 * Tables are created on first login (see ensure_schema / mobile/backend/mobile_api_schema.sql).
 */
class Mobileapi_model extends CI_Model
{
    const TOKEN_TTL_DAYS   = 30;  // sliding: every authenticated call extends it
    const MAX_FAILED_LOGIN = 5;
    const FAIL_WINDOW_MIN  = 15;

    /**
     * App sections each role may use. Add 'Nurse' => array('nursing') etc. in later phases.
     * Keys are role names as stored in `roles`.
     */
    public static $role_modules = array(
        'Doctor'       => array('doctor'),
        'Receptionist' => array('reception'),
        'Nurse'        => array('nursing'),
        'Pathologist'  => array('lab'),
        'Pharmacist'   => array('pharmacy'),
        // Small clinics often run everything from the owner's account.
        'Admin'        => array('reception'),
        'Super Admin'  => array('reception'),
    );

    const SCHEMA_VERSION = 4;

    /**
     * Create/upgrade the API tables. Cheap to call on every request: after the first run a marker
     * file in application/cache short-circuits it.
     */
    public function ensure_schema()
    {
        static $done = false;
        $flag = APPPATH . 'cache/mobile_api_schema_v' . self::SCHEMA_VERSION;
        if ($done || is_file($flag)) {
            return;
        }
        $this->db->query("CREATE TABLE IF NOT EXISTS `mobile_api_tokens` (
            `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `staff_id` int NOT NULL,
            `subject_type` varchar(10) NOT NULL DEFAULT 'staff',
            `token_hash` char(64) NOT NULL,
            `device_id` varchar(100) NOT NULL DEFAULT '',
            `device_name` varchar(150) NOT NULL DEFAULT '',
            `platform` varchar(30) NOT NULL DEFAULT '',
            `app_version` varchar(30) NOT NULL DEFAULT '',
            `created_at` datetime NOT NULL,
            `last_used_at` datetime NOT NULL,
            `expires_at` datetime NOT NULL,
            `revoked_at` datetime DEFAULT NULL,
            UNIQUE KEY `mobile_api_tokens_hash` (`token_hash`),
            KEY `mobile_api_tokens_staff` (`staff_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS `mobile_login_attempts` (
            `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `email` varchar(200) NOT NULL,
            `ip_address` varchar(45) NOT NULL,
            `attempted_at` datetime NOT NULL,
            KEY `mobile_login_attempts_email` (`email`, `attempted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS `mobile_sync_ops` (
            `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `staff_id` int NOT NULL,
            `client_op_id` varchar(64) NOT NULL,
            `op_type` varchar(50) NOT NULL,
            `status` varchar(20) NOT NULL,
            `result` mediumtext NOT NULL,
            `created_at` datetime NOT NULL,
            UNIQUE KEY `mobile_sync_ops_op` (`staff_id`, `client_op_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // v2: tokens for patients too. staff_id then holds users.id of the patient account.
        if (!$this->db->query("SHOW COLUMNS FROM `mobile_api_tokens` LIKE 'subject_type'")->row_array()) {
            $this->db->query("ALTER TABLE `mobile_api_tokens` ADD `subject_type` varchar(10) NOT NULL DEFAULT 'staff' AFTER `staff_id`");
        }
        // v3: specialty clinical records (follow-ups, vaccinations, growth, pregnancy).
        $this->load->model('mobileclinical_model');
        $this->mobileclinical_model->ensure_schema();
        // v4: per-doctor app settings (print layout and header languages).
        $this->load->model('mobilebilling_model');
        $this->mobilebilling_model->ensure_schema();
        $done = true;
        @file_put_contents($flag, date('c'));
    }

    public function modules_for_role($role_name)
    {
        return isset(self::$role_modules[$role_name]) ? self::$role_modules[$role_name] : array();
    }

    // ------------------------------------------------------------------ login throttling
    public function too_many_attempts($email)
    {
        $n = $this->db->query("SELECT COUNT(*) n FROM mobile_login_attempts WHERE email = ? AND attempted_at > NOW() - INTERVAL " . self::FAIL_WINDOW_MIN . " MINUTE",
            array($email))->row()->n;
        return (int) $n >= self::MAX_FAILED_LOGIN;
    }

    public function record_failed_login($email, $ip)
    {
        $this->db->insert('mobile_login_attempts', array('email' => $email, 'ip_address' => (string) $ip, 'attempted_at' => date('Y-m-d H:i:s')));
    }

    public function clear_failed_logins($email)
    {
        $this->db->delete('mobile_login_attempts', array('email' => $email));
        $this->db->query("DELETE FROM mobile_login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY");
    }

    // ------------------------------------------------------------------ tokens
    /** Issue a new token for this device; any older token for the same device is revoked. Returns the plain token. */
    /**
     * Issue a token for a staff member ($subject_type 'staff', id = staff.id) or a patient account
     * ('patient', id = users.id). A device holds one signed-in account, so older tokens of the device are revoked.
     */
    public function issue_token($staff_id, array $device, $subject_type = 'staff')
    {
        $device_id = substr((string) (isset($device['device_id']) ? $device['device_id'] : ''), 0, 100);
        if ($device_id !== '') {
            $this->db->query("UPDATE mobile_api_tokens SET revoked_at = NOW() WHERE device_id = ? AND revoked_at IS NULL", array($device_id));
        }
        $plain = bin2hex(random_bytes(32));
        $now   = date('Y-m-d H:i:s');
        $this->db->insert('mobile_api_tokens', array(
            'staff_id'     => (int) $staff_id,
            'subject_type' => $subject_type === 'patient' ? 'patient' : 'staff',
            'token_hash'   => hash('sha256', $plain),
            'device_id'    => $device_id,
            'device_name'  => substr((string) (isset($device['device_name']) ? $device['device_name'] : ''), 0, 150),
            'platform'     => substr((string) (isset($device['platform']) ? $device['platform'] : ''), 0, 30),
            'app_version'  => substr((string) (isset($device['app_version']) ? $device['app_version'] : ''), 0, 30),
            'created_at'   => $now,
            'last_used_at' => $now,
            'expires_at'   => date('Y-m-d H:i:s', strtotime('+' . self::TOKEN_TTL_DAYS . ' days')),
        ));
        return $plain;
    }

    /** Valid (not revoked, not expired) token joined with an active staff account. */
    public function find_token($plain)
    {
        return $this->db->query("SELECT t.id, t.staff_id, t.last_used_at, s.name, s.surname, s.employee_id, s.email
            FROM mobile_api_tokens t JOIN staff s ON s.id = t.staff_id
            WHERE t.token_hash = ? AND t.subject_type = 'staff' AND t.revoked_at IS NULL AND t.expires_at > NOW() AND s.is_active = 1",
            array(hash('sha256', $plain)))->row_array();
    }

    /** Valid patient token joined with an active patient login (users.role = 'patient'). */
    public function find_patient_token($plain)
    {
        return $this->db->query("SELECT t.id, t.staff_id user_id, t.last_used_at, u.username, p.id patient_id, p.patient_name
            FROM mobile_api_tokens t JOIN users u ON u.id = t.staff_id JOIN patients p ON p.id = u.user_id
            WHERE t.token_hash = ? AND t.subject_type = 'patient' AND t.revoked_at IS NULL AND t.expires_at > NOW()
              AND u.role = 'patient' AND u.is_active = 'yes'",
            array(hash('sha256', $plain)))->row_array();
    }

    /** Slide expiry, at most once every 10 minutes per token to avoid a write on every call. */
    public function touch_token(array $row)
    {
        if (strtotime($row['last_used_at']) < time() - 600) {
            $this->db->query("UPDATE mobile_api_tokens SET last_used_at = NOW(), expires_at = NOW() + INTERVAL " . self::TOKEN_TTL_DAYS . " DAY WHERE id = ?",
                array((int) $row['id']));
        }
    }

    public function revoke_token($token_id)
    {
        $this->db->query("UPDATE mobile_api_tokens SET revoked_at = NOW() WHERE id = ?", array((int) $token_id));
    }

    // ------------------------------------------------------------------ profile & branding
    public function staff_profile($staff_id)
    {
        $r = $this->db->query("SELECT s.id, s.name, s.surname, s.employee_id, s.email, s.contact_no, s.qualification, s.specialization, s.image,
                d.department_name department, sd.designation
            FROM staff s LEFT JOIN department d ON d.id = s.department_id LEFT JOIN staff_designation sd ON sd.id = s.staff_designation_id
            WHERE s.id = ?", array((int) $staff_id))->row_array();
        if (!$r) {
            return null;
        }
        $r['id']    = (int) $r['id'];
        $r['image'] = !empty($r['image']) ? base_url('uploads/staff_images/' . $r['image']) : null;
        $this->load->model('mobileclinical_model');
        $r['specialties'] = $this->mobileclinical_model->staff_specialties($staff_id);
        return $r;
    }

    public function branding(array $s)
    {
        $logo = !empty($s['app_logo']) ? $s['app_logo'] : (!empty($s['image']) ? $s['image'] : '');
        return array(
            'hospital_name'   => isset($s['name']) ? $s['name'] : '',
            'address'         => isset($s['address']) ? $s['address'] : '',
            'phone'           => isset($s['phone']) ? $s['phone'] : '',
            'email'           => isset($s['email']) ? $s['email'] : '',
            'logo_url'        => $logo !== '' ? base_url('uploads/hospital_content/logo/' . $logo) : null,
            'primary_color'   => !empty($s['app_primary_color_code']) ? $s['app_primary_color_code'] : null,
            'secondary_color' => !empty($s['app_secondary_color_code']) ? $s['app_secondary_color_code'] : null,
            'date_format'     => isset($s['date_format']) ? $s['date_format'] : 'd-m-Y',
            'time_format'     => isset($s['time_format']) ? $s['time_format'] : '24-hour',
            'timezone'        => isset($s['timezone']) ? $s['timezone'] : 'UTC',
        );
    }

    // ------------------------------------------------------------------ idempotent sync ops
    public function op_result($staff_id, $client_op_id)
    {
        $r = $this->db->query("SELECT result FROM mobile_sync_ops WHERE staff_id = ? AND client_op_id = ?",
            array((int) $staff_id, (string) $client_op_id))->row_array();
        return $r ? json_decode($r['result'], true) : null;
    }

    public function save_op_result($staff_id, $client_op_id, $op_type, array $result)
    {
        $this->db->query("INSERT IGNORE INTO mobile_sync_ops (staff_id, client_op_id, op_type, status, result, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
            array((int) $staff_id, (string) $client_op_id, (string) $op_type, (string) $result['status'], json_encode($result)));
    }

    public function audit($staff_id, $message, $record_id, $action)
    {
        $this->load->library('user_agent');
        $this->db->insert('logs', array(
            'message'    => $message,
            'user_id'    => (int) $staff_id,
            'record_id'  => $record_id,
            'ip_address' => $this->input->ip_address(),
            'platform'   => 'Mobile app',
            'agent'      => substr((string) $this->agent->agent_string(), 0, 50),
            'action'     => $action,
            'time'       => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d'),
        ));
    }
}
