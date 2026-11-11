<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * HRMS additions on top of Human Resource:
 *  - HR letter templates (joining, appointment, offer, experience, relieving, MOU...) and letters
 *    generated from them for a staff member,
 *  - staff HR documents (generated letters, MOUs/agreements, scanned uploads) visible only to that
 *    staff member and to users with the Staff HR Documents permission,
 *  - hospital policies & documents every staff member can read,
 *  - medical registration number on the staff record.
 *
 * Tables create themselves on first use (see ensure_schema()).
 */
class Hrms_model extends MY_Model
{
    const DOC_DIR    = 'uploads/hr_documents/';
    const POLICY_DIR = 'uploads/hr_policies/';
    const SCHEMA     = 1; // bump when ensure_schema() changes

    public static $letter_types = array(
        'joining'     => 'Joining letter',
        'appointment' => 'Appointment letter',
        'offer'       => 'Offer letter',
        'confirmation'=> 'Confirmation letter',
        'increment'   => 'Increment letter',
        'experience'  => 'Experience certificate',
        'relieving'   => 'Relieving letter',
        'warning'     => 'Warning / show-cause letter',
        'mou'         => 'MOU with doctor',
        'agreement'   => 'Agreement / contract',
        'other'       => 'Other',
    );

    public static $doc_categories = array(
        'letter'      => 'HR letter',
        'mou'         => 'MOU',
        'agreement'   => 'Agreement / contract',
        'certificate' => 'Certificate / degree',
        'registration'=> 'Medical registration',
        'id_proof'    => 'ID proof',
        'other'       => 'Other',
    );

    public static $policy_categories = array(
        'hr'        => 'HR policy',
        'clinical'  => 'Clinical / SOP',
        'safety'    => 'Safety & infection control',
        'nabh'      => 'NABH / accreditation',
        'legal'     => 'Licences & legal',
        'circular'  => 'Circular / notice',
        'other'     => 'Other',
    );

    /** Placeholders filled from the staff record and hospital settings. */
    public static $placeholders = array(
        'staff_name'        => 'Full name with title',
        'first_name'        => 'First name',
        'employee_id'       => 'Staff ID',
        'designation'       => 'Designation',
        'department'        => 'Department',
        'role'              => 'Role',
        'date_of_joining'   => 'Date of joining',
        'date_of_leaving'   => 'Date of leaving',
        'basic_salary'      => 'Basic salary',
        'contract_type'     => 'Contract type',
        'work_shift'        => 'Work shift',
        'work_location'     => 'Work location',
        'qualification'     => 'Qualification',
        'specialization'    => 'Specialization',
        'medical_reg_no'    => 'Medical registration no.',
        'medical_council'   => 'Medical council',
        'father_name'       => 'Father\'s name',
        'address'           => 'Current address',
        'permanent_address' => 'Permanent address',
        'phone'             => 'Phone',
        'email'             => 'Email',
        'pan_number'        => 'PAN',
        'hospital_name'     => 'Hospital name',
        'hospital_address'  => 'Hospital address',
        'hospital_phone'    => 'Hospital phone',
        'today'             => 'Today\'s date',
        'ref_no'            => 'Letter reference no. (given when generated)',
        'valid_from'        => 'Valid from (document dates)',
        'valid_till'        => 'Valid till (document dates)',
    );

    public function ensure_schema()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $this->load->model('hms_settings_model');
        if ($this->hms_settings_model->schema_is('hrms', self::SCHEMA)) {
            return;
        }

        // Medical registration on the staff record.
        $staff_cols = array(
            'medical_reg_no'         => "varchar(60) DEFAULT NULL",
            'medical_council'        => "varchar(150) DEFAULT NULL",
            'medical_reg_valid_till' => "date DEFAULT NULL",
        );
        foreach ($staff_cols as $col => $def) {
            if (!$this->db->field_exists($col, 'staff')) {
                $this->db->query("ALTER TABLE `staff` ADD `$col` $def");
            }
        }

        if (!$this->db->table_exists('hr_letter_templates')) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `hr_letter_templates` (
                `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `name` varchar(150) NOT NULL,
                `letter_type` varchar(30) NOT NULL DEFAULT 'other',
                `doc_category` varchar(30) NOT NULL DEFAULT 'letter',
                `subject` varchar(200) DEFAULT NULL,
                `body` mediumtext NOT NULL,
                `is_active` tinyint(1) NOT NULL DEFAULT 1,
                `created_by` int DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $this->seed_templates();
        }

        if (!$this->db->table_exists('hr_staff_documents')) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `hr_staff_documents` (
                `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `staff_id` int NOT NULL,
                `title` varchar(200) NOT NULL,
                `category` varchar(30) NOT NULL DEFAULT 'other',
                `source` varchar(12) NOT NULL DEFAULT 'upload',
                `template_id` int DEFAULT NULL,
                `ref_no` varchar(60) DEFAULT NULL,
                `body` mediumtext,
                `file` varchar(300) DEFAULT NULL,
                `file_name` varchar(255) DEFAULT NULL,
                `issued_on` date DEFAULT NULL,
                `valid_from` date DEFAULT NULL,
                `valid_till` date DEFAULT NULL,
                `note` varchar(500) DEFAULT NULL,
                `created_by` int DEFAULT NULL,
                `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                KEY `staff_id` (`staff_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        }

        if (!$this->db->table_exists('hr_policies')) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `hr_policies` (
                `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `title` varchar(200) NOT NULL,
                `category` varchar(30) NOT NULL DEFAULT 'other',
                `description` text,
                `version` varchar(30) DEFAULT NULL,
                `effective_date` date DEFAULT NULL,
                `file` varchar(300) DEFAULT NULL,
                `file_name` varchar(255) DEFAULT NULL,
                `role_ids` varchar(255) DEFAULT NULL,
                `is_active` tinyint(1) NOT NULL DEFAULT 1,
                `created_by` int DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        }

        foreach (array(self::DOC_DIR, self::POLICY_DIR) as $dir) {
            $path = FCPATH . $dir;
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            // Files are served only through admin/hrms/... which checks who is asking.
            if (is_dir($path) && !file_exists($path . '.htaccess')) {
                @file_put_contents($path . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            }
            if (is_dir($path) && !file_exists($path . 'index.html')) {
                @file_put_contents($path . 'index.html', '');
            }
        }

        $s = $this->hms_settings_model;
        $s->ensure_permission('human_resource', 'hr_letter_template', 'HR Letter Templates', array(1, 1, 1, 1));
        $s->ensure_permission('human_resource', 'staff_hr_document', 'Staff HR Documents', array(1, 1, 1, 1));
        $s->ensure_permission('human_resource', 'hospital_policy', 'Hospital Policies & Documents', array(1, 1, 1, 1));
        $s->ensure_permission('human_resource', 'travel_expense_approval', 'Travel Expense Approval', array(1, 0, 1, 0));
        $s->ensure_permission('human_resource', 'hr_setting', 'HR Settings', array(1, 0, 1, 0));
        $s->schema_done('hrms', self::SCHEMA);
    }

    // ------------------------------------------------------------------ templates

    public function templates($active_only = false)
    {
        if ($active_only) {
            $this->db->where('is_active', 1);
        }
        return $this->db->order_by('letter_type, name')->get('hr_letter_templates')->result_array();
    }

    public function template($id)
    {
        return $this->db->where('id', (int) $id)->get('hr_letter_templates')->row_array();
    }

    public function save_template(array $row, $id = 0)
    {
        if ($id) {
            $this->db->where('id', (int) $id)->update('hr_letter_templates', $row);
            $this->log('HR letter template updated', $id, 'Update');
            return (int) $id;
        }
        $row['created_by'] = $this->customlib->getStaffID();
        $this->db->insert('hr_letter_templates', $row);
        $id = (int) $this->db->insert_id();
        $this->log('HR letter template added', $id, 'Insert');
        return $id;
    }

    public function delete_template($id)
    {
        $this->db->where('id', (int) $id)->delete('hr_letter_templates');
        $this->log('HR letter template deleted', $id, 'Delete');
    }

    /** Placeholders a template uses that are not filled automatically (asked when generating). */
    public static function extra_placeholders($body)
    {
        preg_match_all('/\{([a-z][a-z0-9_]{0,40})\}/', (string) $body, $m);
        $extra = array();
        foreach (array_unique($m[1]) as $key) {
            if (!isset(self::$placeholders[$key])) {
                $extra[] = $key;
            }
        }
        return $extra;
    }

    /** Values for the standard placeholders for one staff member. */
    public function placeholder_values($staff_id)
    {
        $s = $this->db->select('staff.*, staff_designation.designation, department.department_name, roles.name as role_name')
            ->join('staff_designation', 'staff_designation.id = staff.staff_designation_id', 'left')
            ->join('department', 'department.id = staff.department_id', 'left')
            ->join('staff_roles', 'staff_roles.staff_id = staff.id', 'left')
            ->join('roles', 'roles.id = staff_roles.role_id', 'left')
            ->where('staff.id', (int) $staff_id)->get('staff')->row_array();
        if (!$s) {
            return null;
        }
        $setting = $this->setting_model->getSetting();
        $date    = function ($d) {
            return (empty($d) || substr($d, 0, 10) === '0000-00-00') ? '' : $this->customlib->YYYYMMDDTodateFormat($d);
        };
        $is_doctor = stripos((string) $s['role_name'], 'doctor') !== false;
        $title     = $is_doctor ? 'Dr. ' : (strtolower((string) $s['gender']) === 'female' ? 'Ms. ' : 'Mr. ');
        $name      = trim($s['name'] . ' ' . $s['surname']);
        if (stripos($name, 'dr.') === 0 || stripos($name, 'dr ') === 0) {
            $title = '';
        }
        $currency = isset($setting->currency_symbol) ? $setting->currency_symbol : '';
        return array(
            'staff_name'        => $title . $name,
            'first_name'        => (string) $s['name'],
            'employee_id'       => (string) $s['employee_id'],
            'designation'       => (string) $s['designation'],
            'department'        => (string) $s['department_name'],
            'role'              => (string) $s['role_name'],
            'date_of_joining'   => $date($s['date_of_joining']),
            'date_of_leaving'   => $date($s['date_of_leaving']),
            'basic_salary'      => $s['basic_salary'] !== '' ? $currency . $s['basic_salary'] : '',
            'contract_type'     => (string) $s['contract_type'],
            'work_shift'        => (string) $s['shift'],
            'work_location'     => (string) $s['location'],
            'qualification'     => (string) $s['qualification'],
            'specialization'    => (string) $s['specialization'],
            'medical_reg_no'    => (string) ($s['medical_reg_no'] ?? ''),
            'medical_council'   => (string) ($s['medical_council'] ?? ''),
            'father_name'       => (string) $s['father_name'],
            'address'           => (string) $s['local_address'],
            'permanent_address' => (string) $s['permanent_address'],
            'phone'             => (string) $s['contact_no'],
            'email'             => (string) $s['email'],
            'pan_number'        => (string) $s['pan_number'],
            'hospital_name'     => (string) $setting->name,
            'hospital_address'  => (string) $setting->address,
            'hospital_phone'    => (string) $setting->phone,
            'today'             => $this->customlib->YYYYMMDDTodateFormat(date('Y-m-d')),
        );
    }

    /** Fill {placeholders}; values are HTML-escaped, the template body is trusted admin HTML. */
    public static function render($body, array $values)
    {
        return preg_replace_callback('/\{([a-z][a-z0-9_]{0,40})\}/', function ($m) use ($values) {
            if (!array_key_exists($m[1], $values)) {
                return $m[0];
            }
            return nl2br(htmlspecialchars((string) $values[$m[1]], ENT_QUOTES, 'UTF-8'));
        }, (string) $body);
    }

    /**
     * Template bodies are written by HR admins in the editor. Strip anything that could run
     * script when another user opens the letter.
     */
    public static function clean_html($html)
    {
        $html = (string) $html;
        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|textarea|select|link|meta|base)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|textarea|select|link|meta|base)\b[^>]*/?>#is', '', $html);
        $html = preg_replace('#\s(on[a-z]+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = preg_replace('#(href|src)\s*=\s*(["\']?)\s*(javascript|vbscript|data):[^"\'>\s]*\2#i', '$1="#"', $html);
        $html = preg_replace('#expression\s*\(#i', '', $html);
        return $html;
    }

    // ------------------------------------------------------------------ staff documents

    public function documents($staff_id)
    {
        return $this->db->select('d.*, t.name as template_name, CONCAT(c.name, " ", c.surname) as created_by_name', false)
            ->from('hr_staff_documents d')
            ->join('hr_letter_templates t', 't.id = d.template_id', 'left')
            ->join('staff c', 'c.id = d.created_by', 'left')
            ->where('d.staff_id', (int) $staff_id)->where('d.is_deleted', 0)
            ->order_by('d.id', 'desc')->get()->result_array();
    }

    public function document($id)
    {
        return $this->db->where('id', (int) $id)->where('is_deleted', 0)->get('hr_staff_documents')->row_array();
    }

    public function all_documents(array $filter = array())
    {
        $this->db->select('d.id, d.staff_id, d.title, d.category, d.source, d.ref_no, d.issued_on, d.valid_till, d.created_at, s.name, s.surname, s.employee_id', false)
            ->from('hr_staff_documents d')->join('staff s', 's.id = d.staff_id')
            ->where('d.is_deleted', 0);
        if (!empty($filter['category'])) {
            $this->db->where('d.category', $filter['category']);
        }
        if (!empty($filter['expiring'])) {
            $this->db->where('d.valid_till IS NOT NULL', null, false)->where('d.valid_till <=', date('Y-m-d', strtotime('+60 days')));
        }
        return $this->db->order_by('d.id', 'desc')->limit(500)->get()->result_array();
    }

    public function add_document(array $row)
    {
        $row['created_by'] = $this->customlib->getStaffID();
        $this->db->insert('hr_staff_documents', $row);
        $id = (int) $this->db->insert_id();
        $this->log('Staff HR document added for staff id ' . $row['staff_id'], $id, 'Insert');
        return $id;
    }

    public function update_document($id, array $row)
    {
        $this->db->where('id', (int) $id)->update('hr_staff_documents', $row);
        $this->log('Staff HR document updated', $id, 'Update');
    }

    public function delete_document($id)
    {
        $this->db->where('id', (int) $id)->update('hr_staff_documents', array('is_deleted' => 1));
        $this->log('Staff HR document deleted', $id, 'Delete');
    }

    public function next_ref_no($letter_type)
    {
        $prefix = strtoupper(substr(preg_replace('/[^a-z]/i', '', $letter_type), 0, 3)) ?: 'HR';
        $year   = date('Y');
        $n      = (int) $this->db->where('source', 'generated')->where('YEAR(created_at)', $year, false)->count_all_results('hr_staff_documents');
        return 'HR/' . $prefix . '/' . $year . '/' . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------ policies

    public function policies($for_role_id = null, $active_only = true)
    {
        if ($active_only) {
            $this->db->where('p.is_active', 1);
        }
        $rows = $this->db->select('p.*, CONCAT(s.name, " ", s.surname) as created_by_name', false)
            ->from('hr_policies p')->join('staff s', 's.id = p.created_by', 'left')
            ->order_by('p.category, p.title')->get()->result_array();
        if ($for_role_id === null) {
            return $rows;
        }
        return array_values(array_filter($rows, function ($r) use ($for_role_id) {
            return self::policy_visible_to($r, $for_role_id);
        }));
    }

    public static function policy_visible_to(array $p, $role_id)
    {
        $roles = array_filter(array_map('intval', explode(',', (string) $p['role_ids'])));
        return empty($roles) || in_array((int) $role_id, $roles, true);
    }

    public function policy($id)
    {
        return $this->db->where('id', (int) $id)->get('hr_policies')->row_array();
    }

    public function save_policy(array $row, $id = 0)
    {
        if ($id) {
            $this->db->where('id', (int) $id)->update('hr_policies', $row);
            $this->log('Hospital policy updated', $id, 'Update');
            return (int) $id;
        }
        $row['created_by'] = $this->customlib->getStaffID();
        $this->db->insert('hr_policies', $row);
        $id = (int) $this->db->insert_id();
        $this->log('Hospital policy added', $id, 'Insert');
        return $id;
    }

    public function delete_policy($id)
    {
        $p = $this->policy($id);
        $this->db->where('id', (int) $id)->delete('hr_policies');
        if ($p && $p['file'] && is_file(FCPATH . self::POLICY_DIR . $p['file'])) {
            @unlink(FCPATH . self::POLICY_DIR . $p['file']);
        }
        $this->log('Hospital policy deleted', $id, 'Delete');
    }

    // ------------------------------------------------------------------ files

    /** Allowed upload types for HR documents, policies and expense bills. */
    public static $allowed_ext = array('pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'txt');

    /**
     * Store one uploaded file under $dir (relative to FCPATH). Returns array(stored_name, original_name)
     * or a string error, or null when no file was sent.
     */
    public static function store_upload($field, $dir, $max_mb = 10)
    {
        if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE || empty($_FILES[$field]['name'])) {
            return null;
        }
        $f = $_FILES[$field];
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            return 'The file could not be uploaded. Try again.';
        }
        if ($f['size'] > $max_mb * 1024 * 1024) {
            return 'The file is larger than ' . $max_mb . ' MB.';
        }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::$allowed_ext, true)) {
            return 'Only PDF, image, Word, Excel or text files can be attached.';
        }
        $path = FCPATH . $dir;
        if (!is_dir($path) && !@mkdir($path, 0755, true)) {
            return 'The upload folder is not writable.';
        }
        self::protect_dir(FCPATH . preg_replace('#^(uploads/[^/]+/).*$#', '$1', $dir));
        $stored = date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $path . $stored)) {
            return 'The file could not be saved on the server.';
        }
        $original = mb_substr(preg_replace('/[^\w .()\-]+/u', '_', basename($f['name'])), 0, 200);
        return array($stored, $original);
    }

    /** Block direct web access to an upload folder; files are served by the controllers after an access check. */
    public static function protect_dir($path)
    {
        $path = rtrim($path, '/') . '/';
        if (is_dir($path) && !file_exists($path . '.htaccess')) {
            @file_put_contents($path . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        if (is_dir($path) && !file_exists($path . 'index.html')) {
            @file_put_contents($path . 'index.html', '');
        }
    }

    /** Send a stored file inline (images/PDF) or as a download. */
    public static function send_file($abs_path, $download_name)
    {
        if (!is_file($abs_path)) {
            show_404();
        }
        $ext   = strtolower(pathinfo($abs_path, PATHINFO_EXTENSION));
        $types = array('pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'txt' => 'text/plain');
        $inline = isset($types[$ext]);
        header('Content-Type: ' . ($inline ? $types[$ext] : 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $download_name ?: basename($abs_path)) . '"');
        header('Content-Length: ' . filesize($abs_path));
        header('Cache-Control: private, no-store');
        readfile($abs_path);
        exit;
    }

    // ------------------------------------------------------------------ seed

    private function seed_templates()
    {
        $p = '<p>';
        $templates = array(
            array('Appointment letter', 'appointment', 'letter', 'Appointment as {designation}',
                $p . 'Ref: {ref_no}<br>Date: {today}</p>'
                . '<p>To,<br>{staff_name}<br>{address}</p>'
                . '<p><strong>Subject: Appointment as {designation}</strong></p>'
                . '<p>Dear {staff_name},</p>'
                . '<p>With reference to your application and the subsequent interview, we are pleased to appoint you as <strong>{designation}</strong> in the {department} department of {hospital_name} on the following terms:</p>'
                . '<ol><li>Your appointment is effective from <strong>{date_of_joining}</strong>.</li>'
                . '<li>You will be on probation for {probation_months} months, after which your services may be confirmed in writing.</li>'
                . '<li>Your basic salary will be {basic_salary} per month, plus allowances as per hospital policy.</li>'
                . '<li>Your working hours and shift ({work_shift}) will be as assigned by your reporting head.</li>'
                . '<li>Either side may end this appointment with {notice_days} days\' written notice or salary in lieu of notice.</li>'
                . '<li>You will follow the rules, policies and code of conduct of the hospital as amended from time to time, and keep patient information confidential.</li></ol>'
                . '<p>Please sign and return the duplicate copy of this letter as your acceptance.</p>'
                . '<p>For {hospital_name}<br><br><br>Authorised signatory</p>'
                . '<p>I accept the above terms.<br><br>Signature: ____________________ Date: __________</p>'),
            array('Joining letter', 'joining', 'letter', 'Joining report',
                $p . 'Ref: {ref_no}<br>Date: {today}</p>'
                . '<p>To,<br>The Administrator,<br>{hospital_name}</p>'
                . '<p><strong>Subject: Joining report</strong></p>'
                . '<p>This is to confirm that {staff_name} (Staff ID {employee_id}) has joined {hospital_name} as <strong>{designation}</strong>, {department} department, on <strong>{date_of_joining}</strong>, in line with the appointment letter issued.</p>'
                . '<p>The staff member has submitted the required documents and has been briefed on hospital policies.</p>'
                . '<p><br>Signature of staff: ____________________</p>'
                . '<p>For {hospital_name}<br><br><br>HR / Administrator</p>'),
            array('Offer letter', 'offer', 'letter', 'Offer of employment',
                $p . 'Ref: {ref_no}<br>Date: {today}</p>'
                . '<p>Dear {staff_name},</p>'
                . '<p>We are pleased to offer you the position of <strong>{designation}</strong> at {hospital_name}. Your monthly gross salary will be {offered_salary}. We would like you to join on or before <strong>{joining_date}</strong>.</p>'
                . '<p>This offer is subject to verification of your certificates and references. Please confirm your acceptance by signing a copy of this letter.</p>'
                . '<p>For {hospital_name}<br><br><br>Authorised signatory</p>'),
            array('Experience certificate', 'experience', 'letter', 'Experience certificate',
                $p . 'Ref: {ref_no}<br>Date: {today}</p>'
                . '<h3 style="text-align:center">TO WHOMSOEVER IT MAY CONCERN</h3>'
                . '<p>This is to certify that {staff_name} worked with {hospital_name} as <strong>{designation}</strong> in the {department} department from <strong>{date_of_joining}</strong> to <strong>{date_of_leaving}</strong>.</p>'
                . '<p>During this period we found the work and conduct to be good. We wish {first_name} success in future.</p>'
                . '<p>For {hospital_name}<br><br><br>Authorised signatory</p>'),
            array('Relieving letter', 'relieving', 'letter', 'Relieving letter',
                $p . 'Ref: {ref_no}<br>Date: {today}</p>'
                . '<p>Dear {staff_name},</p>'
                . '<p>This is with reference to your resignation dated {resignation_date}. You are relieved from your duties as {designation} at the close of working hours on <strong>{date_of_leaving}</strong>. Your full and final settlement will be processed as per hospital policy.</p>'
                . '<p>We thank you for your service and wish you the best.</p>'
                . '<p>For {hospital_name}<br><br><br>Authorised signatory</p>'),
            array('Increment letter', 'increment', 'letter', 'Revision of salary',
                $p . 'Ref: {ref_no}<br>Date: {today}</p>'
                . '<p>Dear {staff_name},</p>'
                . '<p>In recognition of your performance, your monthly salary is revised to <strong>{new_salary}</strong> with effect from <strong>{effective_date}</strong>. All other terms of your appointment remain unchanged.</p>'
                . '<p>For {hospital_name}<br><br><br>Authorised signatory</p>'),
            array('MOU with visiting consultant', 'mou', 'mou', 'Memorandum of understanding',
                '<h3 style="text-align:center">MEMORANDUM OF UNDERSTANDING</h3>'
                . '<p>This MOU is made on {today} between <strong>{hospital_name}</strong>, {hospital_address} (the "Hospital"), and <strong>{staff_name}</strong>, {qualification}, Medical Registration No. {medical_reg_no} ({medical_council}) (the "Consultant").</p>'
                . '<p><strong>1. Services.</strong> The Consultant will provide {specialization} consultation, procedures and on-call services to patients of the Hospital on {visiting_days}.</p>'
                . '<p><strong>2. Professional fees.</strong> {fee_terms}</p>'
                . '<p><strong>3. Term.</strong> This MOU is valid from {valid_from} to {valid_till} and may be renewed by mutual consent. Either party may end it with {notice_days} days\' written notice.</p>'
                . '<p><strong>4. Professional responsibility.</strong> The Consultant holds a valid registration and professional indemnity cover, and is responsible for the clinical decisions made. The Hospital provides facilities, nursing and support staff.</p>'
                . '<p><strong>5. Records and confidentiality.</strong> Both parties will keep patient records as per law and keep patient information confidential.</p>'
                . '<p><strong>6. Disputes.</strong> Disputes will first be resolved by discussion, failing which they are subject to the jurisdiction of courts at {jurisdiction}.</p>'
                . '<table style="width:100%;margin-top:40px"><tr><td>For {hospital_name}<br><br><br>Authorised signatory</td><td style="text-align:right">{staff_name}<br><br><br>Consultant</td></tr></table>'),
        );
        foreach ($templates as $t) {
            $this->db->insert('hr_letter_templates', array(
                'name' => $t[0], 'letter_type' => $t[1], 'doc_category' => $t[2], 'subject' => $t[3], 'body' => $t[4], 'is_active' => 1,
            ));
        }
    }
}
