<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * HRMS: letters, staff documents, hospital policies, HR settings.
 *
 *  admin/hrms                  My HR: my documents, hospital policies (every staff member)
 *  admin/hrms/documents        All staff documents (Staff HR Documents permission)
 *  admin/hrms/generate/{id}    Generate a letter / MOU for a staff member from a template
 *  admin/hrms/templates        Letter templates (HR Letter Templates permission)
 *  admin/hrms/policies         Manage policies & hospital documents (Hospital Policies permission)
 *  admin/hrms/settings         Staff ID format (HR Settings permission)
 *
 * A staff document can be opened only by the staff member it belongs to or by a user with the
 * Staff HR Documents view permission. Files live in uploads/hr_documents (web access denied) and
 * are streamed by document_file().
 */
class Hrms extends Admin_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model(array('hms_settings_model', 'hrms_model'));
        $this->hrms_model->ensure_schema();
    }

    private function me()
    {
        return (int) $this->customlib->getStaffID();
    }

    private function my_role_id()
    {
        $r = json_decode($this->customlib->getStaffRole());
        return $r ? (int) $r->id : 0;
    }

    private function json($arr)
    {
        $this->output->set_content_type('application/json')->set_output(json_encode($arr));
    }

    private function page($view, array $data, $sub = 'admin/hrms', $top = 'HR')
    {
        $this->session->set_userdata('top_menu', $top);
        $this->session->set_userdata('sub_menu', $sub);
        $data['module'] = 'human_resource';
        $this->load->view('layout/header', $data);
        $this->load->view($view, $data);
        $this->load->view('layout/footer', $data);
    }

    private function can_see_staff_docs($staff_id)
    {
        return (int) $staff_id === $this->me() || $this->rbac->hasPrivilege('staff_hr_document', 'can_view');
    }

    private function staff_or_404($staff_id)
    {
        $s = $this->db->select('id, name, surname, employee_id, is_active')->where('id', (int) $staff_id)->get('staff')->row_array();
        if (!$s) {
            show_404();
        }
        return $s;
    }

    // ------------------------------------------------------------------ My HR

    public function index()
    {
        $me              = $this->me();
        $data['title']   = 'My HR';
        $data['docs']    = $this->hrms_model->documents($me);
        $super           = $this->rbac->hasPrivilege('hospital_policy', 'can_view');
        $data['policies'] = $this->hrms_model->policies($super ? null : $this->my_role_id());
        $data['categories'] = Hrms_model::$policy_categories;
        $data['doc_categories'] = Hrms_model::$doc_categories;
        $this->page('admin/hrms/mine', $data, 'admin/hrms', 'MyHR');
    }

    // ------------------------------------------------------------------ documents

    public function documents()
    {
        if (!$this->rbac->hasPrivilege('staff_hr_document', 'can_view')) {
            access_denied();
        }
        $filter = array('category' => $this->input->get('category', true), 'expiring' => $this->input->get('expiring', true));
        $data['title']      = 'Staff HR Documents';
        $data['filter']     = $filter;
        $data['rows']       = $this->hrms_model->all_documents($filter);
        $data['categories'] = Hrms_model::$doc_categories;
        $data['staff_list'] = $this->db->select('id, name, surname, employee_id')->where('is_active', 1)->order_by('name')->get('staff')->result_array();
        $data['templates']  = $this->hrms_model->templates(true);
        $this->page('admin/hrms/documents', $data, 'admin/hrms/documents');
    }

    /** Staff profile → Documents tab (HR documents block). Returns HTML. */
    public function staff_documents($staff_id)
    {
        if (!$this->can_see_staff_docs($staff_id)) {
            echo '';
            return;
        }
        $data['staff_id']   = (int) $staff_id;
        $data['docs']       = $this->hrms_model->documents($staff_id);
        $data['categories'] = Hrms_model::$doc_categories;
        $data['can_add']    = $this->rbac->hasPrivilege('staff_hr_document', 'can_add');
        $data['can_delete'] = $this->rbac->hasPrivilege('staff_hr_document', 'can_delete');
        $this->load->view('admin/hrms/_staff_documents', $data);
    }

    public function upload_document()
    {
        if (!$this->rbac->hasPrivilege('staff_hr_document', 'can_add')) {
            return $this->json(array('status' => 'fail', 'message' => $this->lang->line('access_denied')));
        }
        $staff_id = (int) $this->input->post('staff_id');
        $this->staff_or_404($staff_id);
        $this->form_validation->set_rules('title', 'Title', 'trim|required|max_length[200]|xss_clean');
        $this->form_validation->set_rules('category', 'Category', 'trim|required|in_list[' . implode(',', array_keys(Hrms_model::$doc_categories)) . ']');
        if (!$this->form_validation->run()) {
            return $this->json(array('status' => 'fail', 'error' => $this->form_validation->error_array()));
        }
        $file = Hrms_model::store_upload('file', Hrms_model::DOC_DIR . $staff_id . '/');
        if ($file === null) {
            return $this->json(array('status' => 'fail', 'error' => array('file' => 'Choose the scanned document or file to attach.')));
        }
        if (is_string($file)) {
            return $this->json(array('status' => 'fail', 'error' => array('file' => $file)));
        }
        $id = $this->hrms_model->add_document(array(
            'staff_id'   => $staff_id,
            'title'      => $this->input->post('title', true),
            'category'   => $this->input->post('category', true),
            'source'     => 'upload',
            'ref_no'     => mb_substr((string) $this->input->post('ref_no', true), 0, 60),
            'file'       => $file[0],
            'file_name'  => $file[1],
            'issued_on'  => $this->date_in('issued_on'),
            'valid_from' => $this->date_in('valid_from'),
            'valid_till' => $this->date_in('valid_till'),
            'note'       => mb_substr((string) $this->input->post('note', true), 0, 500),
        ));
        $this->json(array('status' => 'success', 'id' => $id, 'message' => $this->lang->line('success_message')));
    }

    private function date_in($field)
    {
        $v = trim((string) $this->input->post($field, true));
        return $v === '' ? null : $this->customlib->dateFormatToYYYYMMDD($v);
    }

    /** Open a staff document: generated letters print with the hospital header, uploads stream. */
    public function document($id)
    {
        $doc = $this->hrms_model->document($id);
        if (!$doc || !$this->can_see_staff_docs($doc['staff_id'])) {
            access_denied();
        }
        if ($doc['source'] === 'upload') {
            return $this->document_file($id);
        }
        $data['doc']     = $doc;
        $data['staff']   = $this->staff_or_404($doc['staff_id']);
        $data['header']  = $this->print_header();
        $data['setting'] = $this->setting_model->getSetting();
        $this->load->view('admin/hrms/print_letter', $data);
    }

    public function document_file($id)
    {
        $doc = $this->hrms_model->document($id);
        if (!$doc || !$this->can_see_staff_docs($doc['staff_id']) || empty($doc['file'])) {
            access_denied();
        }
        Hrms_model::send_file(FCPATH . Hrms_model::DOC_DIR . (int) $doc['staff_id'] . '/' . basename($doc['file']), $doc['file_name']);
    }

    public function delete_document($id)
    {
        $doc = $this->hrms_model->document($id);
        if (!$doc || !$this->rbac->hasPrivilege('staff_hr_document', 'can_delete')) {
            return $this->json(array('status' => 'fail', 'message' => $this->lang->line('access_denied')));
        }
        $this->hrms_model->delete_document($id);
        $this->json(array('status' => 'success', 'message' => $this->lang->line('delete_message')));
    }

    private function print_header()
    {
        $row = $this->db->where('setting_for', 'payslip')->get('print_setting')->row_array();
        return ($row && !empty($row['print_header']) && is_file(FCPATH . $row['print_header'])) ? base_url($row['print_header']) : '';
    }

    // ------------------------------------------------------------------ generate letters

    public function generate($staff_id)
    {
        if (!$this->rbac->hasPrivilege('staff_hr_document', 'can_add')) {
            access_denied();
        }
        $staff = $this->staff_or_404($staff_id);
        $templates = $this->hrms_model->templates(true);
        $tid       = (int) $this->input->get('template');
        $tpl       = $tid ? $this->hrms_model->template($tid) : null;
        $data['title']     = 'Generate letter';
        $data['staff']     = $staff;
        $data['templates'] = $templates;
        $data['tpl']       = $tpl;
        $data['extra']     = $tpl ? Hrms_model::extra_placeholders($tpl['body']) : array();
        $data['ref_no']    = $tpl ? $this->hrms_model->next_ref_no($tpl['letter_type']) : '';
        $data['values']    = $this->hrms_model->placeholder_values($staff_id);
        $data['types']     = Hrms_model::$letter_types;
        $this->page('admin/hrms/generate', $data, 'admin/hrms/documents');
    }

    /** Fill the template for this staff member with the posted extra values. */
    private function build_letter($staff_id, $tpl, &$ref_no)
    {
        $values = $this->hrms_model->placeholder_values($staff_id);
        $ref_no = trim(strip_tags((string) $this->input->post('ref_no')));
        if ($ref_no === '') {
            $ref_no = $this->hrms_model->next_ref_no($tpl['letter_type']);
        }
        $values['ref_no']     = $ref_no;
        // Raw values: render() HTML-escapes every value it puts into the letter.
        $values['valid_from'] = (string) $this->input->post('valid_from');
        $values['valid_till'] = (string) $this->input->post('valid_till');
        $extra = (array) $this->input->post('extra');
        foreach (Hrms_model::extra_placeholders($tpl['body']) as $key) {
            $values[$key] = isset($extra[$key]) ? mb_substr((string) $extra[$key], 0, 2000) : '';
        }
        // The body may have been edited for this one letter before saving.
        $body = $this->input->post('body', false);
        $body = ($body !== null && trim($body) !== '') ? Hrms_model::clean_html($body) : $tpl['body'];
        return Hrms_model::render($body, $values);
    }

    public function generate_preview($staff_id)
    {
        if (!$this->rbac->hasPrivilege('staff_hr_document', 'can_add')) {
            return $this->json(array('status' => 'fail', 'message' => $this->lang->line('access_denied')));
        }
        $tpl = $this->hrms_model->template($this->input->post('template_id'));
        if (!$tpl) {
            return $this->json(array('status' => 'fail', 'message' => 'Choose a template.'));
        }
        $ref = '';
        $this->json(array('status' => 'success', 'html' => $this->build_letter((int) $staff_id, $tpl, $ref), 'ref_no' => $ref));
    }

    public function generate_save($staff_id)
    {
        if (!$this->rbac->hasPrivilege('staff_hr_document', 'can_add')) {
            return $this->json(array('status' => 'fail', 'message' => $this->lang->line('access_denied')));
        }
        $this->staff_or_404($staff_id);
        $tpl = $this->hrms_model->template($this->input->post('template_id'));
        if (!$tpl) {
            return $this->json(array('status' => 'fail', 'message' => 'Choose a template.'));
        }
        $ref   = '';
        $html  = $this->build_letter((int) $staff_id, $tpl, $ref);
        $title = trim((string) $this->input->post('title', true));
        $id = $this->hrms_model->add_document(array(
            'staff_id'    => (int) $staff_id,
            'title'       => mb_substr($title !== '' ? $title : $tpl['name'], 0, 200),
            'category'    => $tpl['doc_category'],
            'source'      => 'generated',
            'template_id' => (int) $tpl['id'],
            'ref_no'      => mb_substr($ref, 0, 60),
            'body'        => $html,
            'issued_on'   => date('Y-m-d'),
            'valid_from'  => $this->date_in('valid_from'),
            'valid_till'  => $this->date_in('valid_till'),
        ));
        $this->json(array('status' => 'success', 'id' => $id, 'url' => base_url('admin/hrms/document/' . $id), 'message' => 'Letter saved to the staff member\'s documents.'));
    }

    // ------------------------------------------------------------------ templates

    public function templates()
    {
        if (!$this->rbac->hasPrivilege('hr_letter_template', 'can_view')) {
            access_denied();
        }
        $data['title']     = 'HR Letter Templates';
        $data['templates'] = $this->hrms_model->templates();
        $data['types']     = Hrms_model::$letter_types;
        $this->page('admin/hrms/templates', $data, 'admin/hrms/templates');
    }

    public function template($id = 0)
    {
        $perm = $id ? 'can_edit' : 'can_add';
        if (!$this->rbac->hasPrivilege('hr_letter_template', $perm)) {
            access_denied();
        }
        $tpl = $id ? $this->hrms_model->template($id) : null;
        if ($id && !$tpl) {
            show_404();
        }
        $data['title']        = $id ? 'Edit letter template' : 'New letter template';
        $data['tpl']          = $tpl;
        $data['types']        = Hrms_model::$letter_types;
        $data['categories']   = Hrms_model::$doc_categories;
        $data['placeholders'] = Hrms_model::$placeholders;
        $this->page('admin/hrms/template_form', $data, 'admin/hrms/templates');
    }

    public function template_save($id = 0)
    {
        $perm = $id ? 'can_edit' : 'can_add';
        if (!$this->rbac->hasPrivilege('hr_letter_template', $perm)) {
            return $this->json(array('status' => 'fail', 'message' => $this->lang->line('access_denied')));
        }
        $this->form_validation->set_rules('name', 'Name', 'trim|required|max_length[150]|xss_clean');
        $this->form_validation->set_rules('letter_type', 'Type', 'trim|required|in_list[' . implode(',', array_keys(Hrms_model::$letter_types)) . ']');
        $this->form_validation->set_rules('doc_category', 'Saved as', 'trim|required|in_list[' . implode(',', array_keys(Hrms_model::$doc_categories)) . ']');
        $this->form_validation->set_rules('subject', 'Subject', 'trim|max_length[200]|xss_clean');
        if (!$this->form_validation->run()) {
            return $this->json(array('status' => 'fail', 'error' => $this->form_validation->error_array()));
        }
        $body = Hrms_model::clean_html($this->input->post('body', false));
        if (trim(strip_tags($body)) === '') {
            return $this->json(array('status' => 'fail', 'error' => array('body' => 'Write the letter text.')));
        }
        $id = $this->hrms_model->save_template(array(
            'name'         => $this->input->post('name', true),
            'letter_type'  => $this->input->post('letter_type', true),
            'doc_category' => $this->input->post('doc_category', true),
            'subject'      => $this->input->post('subject', true),
            'body'         => $body,
            'is_active'    => $this->input->post('is_active') ? 1 : 0,
        ), (int) $id);
        $this->json(array('status' => 'success', 'id' => $id, 'message' => $this->lang->line('success_message')));
    }

    public function template_delete($id)
    {
        if (!$this->rbac->hasPrivilege('hr_letter_template', 'can_delete')) {
            return $this->json(array('status' => 'fail', 'message' => $this->lang->line('access_denied')));
        }
        $this->hrms_model->delete_template($id);
        $this->json(array('status' => 'success', 'message' => $this->lang->line('delete_message')));
    }

    // ------------------------------------------------------------------ policies

    public function policies()
    {
        if (!$this->rbac->hasPrivilege('hospital_policy', 'can_view')) {
            access_denied();
        }
        $data['title']      = 'Policies & Hospital Documents';
        $data['policies']   = $this->hrms_model->policies(null, false);
        $data['categories'] = Hrms_model::$policy_categories;
        $data['roles']      = $this->db->select('id, name')->order_by('name')->get('roles')->result_array();
        $this->page('admin/hrms/policies', $data, 'admin/hrms/policies');
    }

    public function policy_save($id = 0)
    {
        $perm = $id ? 'can_edit' : 'can_add';
        if (!$this->rbac->hasPrivilege('hospital_policy', $perm)) {
            return $this->json(array('status' => 'fail', 'message' => $this->lang->line('access_denied')));
        }
        $this->form_validation->set_rules('title', 'Title', 'trim|required|max_length[200]|xss_clean');
        $this->form_validation->set_rules('category', 'Category', 'trim|required|in_list[' . implode(',', array_keys(Hrms_model::$policy_categories)) . ']');
        $this->form_validation->set_rules('version', 'Version', 'trim|max_length[30]|xss_clean');
        $this->form_validation->set_rules('description', 'Description', 'trim|max_length[2000]|xss_clean');
        if (!$this->form_validation->run()) {
            return $this->json(array('status' => 'fail', 'error' => $this->form_validation->error_array()));
        }
        $old = $id ? $this->hrms_model->policy($id) : null;
        if ($id && !$old) {
            return $this->json(array('status' => 'fail', 'message' => 'This document no longer exists.'));
        }
        $file = Hrms_model::store_upload('file', Hrms_model::POLICY_DIR, 25);
        if (is_string($file)) {
            return $this->json(array('status' => 'fail', 'error' => array('file' => $file)));
        }
        if (!$id && $file === null) {
            return $this->json(array('status' => 'fail', 'error' => array('file' => 'Attach the policy or document file.')));
        }
        $roles = array_filter(array_map('intval', (array) $this->input->post('role_ids')));
        $row = array(
            'title'          => $this->input->post('title', true),
            'category'       => $this->input->post('category', true),
            'version'        => $this->input->post('version', true),
            'description'    => $this->input->post('description', true),
            'effective_date' => $this->date_in('effective_date'),
            'role_ids'       => $roles ? implode(',', $roles) : null,
            'is_active'      => $this->input->post('is_active') ? 1 : 0,
        );
        if ($file) {
            $row['file']      = $file[0];
            $row['file_name'] = $file[1];
            if ($old && $old['file'] && is_file(FCPATH . Hrms_model::POLICY_DIR . $old['file'])) {
                @unlink(FCPATH . Hrms_model::POLICY_DIR . $old['file']);
            }
        }
        $id = $this->hrms_model->save_policy($row, (int) $id);
        $this->json(array('status' => 'success', 'id' => $id, 'message' => $this->lang->line('success_message')));
    }

    public function policy_delete($id)
    {
        if (!$this->rbac->hasPrivilege('hospital_policy', 'can_delete')) {
            return $this->json(array('status' => 'fail', 'message' => $this->lang->line('access_denied')));
        }
        $this->hrms_model->delete_policy($id);
        $this->json(array('status' => 'success', 'message' => $this->lang->line('delete_message')));
    }

    /** Any staff member can open an active policy meant for their role. */
    public function policy_file($id)
    {
        $p = $this->hrms_model->policy($id);
        if (!$p || empty($p['file'])) {
            show_404();
        }
        $manager = $this->rbac->hasPrivilege('hospital_policy', 'can_view');
        if (!$manager && (!$p['is_active'] || !Hrms_model::policy_visible_to($p, $this->my_role_id()))) {
            access_denied();
        }
        Hrms_model::send_file(FCPATH . Hrms_model::POLICY_DIR . basename($p['file']), $p['file_name']);
    }

    // ------------------------------------------------------------------ settings

    public function settings()
    {
        if (!$this->rbac->hasPrivilege('hr_setting', 'can_view')) {
            access_denied();
        }
        $this->session->set_userdata('top_menu', 'setup');
        $this->session->set_userdata('sub_menu', 'schsettings/index');
        $this->session->set_userdata('inner_menu', 'admin/hrms/settings');
        $data['title']    = 'HR Settings';
        $data['module']   = 'setup';
        $data['fmt']      = $this->hms_settings_model->staff_id_format();
        $data['next_id']  = $this->hms_settings_model->next_staff_id();
        $data['can_edit'] = $this->rbac->hasPrivilege('hr_setting', 'can_edit');
        $data['expense_categories'] = $this->hms_settings_model->get('expense_categories', '');
        $this->load->view('layout/header', $data);
        $this->load->view('admin/hrms/settings', $data);
        $this->load->view('layout/footer', $data);
    }

    public function settings_save()
    {
        if (!$this->rbac->hasPrivilege('hr_setting', 'can_edit')) {
            return $this->json(array('status' => 'fail', 'message' => $this->lang->line('access_denied')));
        }
        $this->form_validation->set_rules('staff_id_prefix', 'Prefix', 'trim|max_length[20]|regex_match[/^[A-Za-z0-9\/\-_]*$/]');
        $this->form_validation->set_rules('staff_id_digits', 'Digits', 'trim|required|integer|greater_than[0]|less_than[11]');
        $this->form_validation->set_rules('staff_id_start', 'Start from', 'trim|required|integer|greater_than[0]');
        $this->form_validation->set_rules('expense_categories', 'Expense categories', 'trim|max_length[2000]|xss_clean');
        if (!$this->form_validation->run()) {
            return $this->json(array('status' => 'fail', 'error' => $this->form_validation->error_array()));
        }
        $s = $this->hms_settings_model;
        $s->set('staff_id_auto', $this->input->post('staff_id_auto') ? '1' : '0');
        $s->set('staff_id_prefix', (string) $this->input->post('staff_id_prefix', true));
        $s->set('staff_id_digits', (int) $this->input->post('staff_id_digits'));
        $s->set('staff_id_start', (int) $this->input->post('staff_id_start'));
        $s->set('expense_categories', (string) $this->input->post('expense_categories', true));
        $this->json(array('status' => 'success', 'next_id' => $s->next_staff_id(), 'message' => $this->lang->line('success_message')));
    }

    /** Live preview of the next ID while the settings form is edited. */
    public function settings_preview()
    {
        if (!$this->rbac->hasPrivilege('hr_setting', 'can_view')) {
            return $this->json(array('next_id' => ''));
        }
        $prefix = preg_replace('/[^A-Za-z0-9\/\-_]/', '', (string) $this->input->get('prefix'));
        $digits = max(1, min(10, (int) $this->input->get('digits')));
        $start  = max(1, (int) $this->input->get('start'));
        $this->json(array('next_id' => $this->hms_settings_model->next_staff_id(array('prefix' => $prefix, 'digits' => $digits, 'start' => $start))));
    }
}
