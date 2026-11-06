<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Brand Kit — Setup > Settings > Brand Kit.
 * Lets the hospital admin edit the identity shown on the login screens, the
 * dashboard welcome banner and printouts without touching code.
 * Uses the existing Theme Studio permission (superadmin by default).
 */
class Brandkit extends Admin_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('hospital_brand_model');
    }

    public function index()
    {
        if (!$this->rbac->hasPrivilege('theme_studio', 'can_view')) {
            access_denied();
        }
        $this->session->set_userdata('top_menu', 'setup');
        $this->session->set_userdata('sub_menu', 'schsettings/index');
        $this->session->set_userdata('inner_menu', 'admin/brandkit');

        $data['title']    = 'Brand Kit';
        $data['module']   = 'setup';
        $data['brand']    = $this->hospital_brand_model->get();
        $data['setting']  = $this->setting_model->getSetting();
        $data['can_edit'] = $this->rbac->hasPrivilege('theme_studio', 'can_edit');

        $this->load->view('layout/header', $data);
        $this->load->view('admin/brandkit/index', $data);
        $this->load->view('layout/footer', $data);
    }

    public function save()
    {
        if (!$this->rbac->hasPrivilege('theme_studio', 'can_edit')) {
            echo json_encode(array('status' => 'fail', 'error' => 'access_denied', 'message' => $this->lang->line('access_denied')));
            return;
        }

        $payload = array();
        foreach (Hospital_brand_model::$fields as $field => $max) {
            if ($max === 1) {
                $payload[$field] = $this->input->post($field) ? 1 : 0;
                continue;
            }
            $rules = 'trim|xss_clean' . ($max > 0 ? '|max_length[' . $max . ']' : '|max_length[2000]');
            if ($field === 'website') {
                $rules .= '|callback__valid_website';
            }
            $this->form_validation->set_rules($field, ucwords(str_replace('_', ' ', $field)), $rules);
        }

        if ($this->form_validation->run() == false) {
            echo json_encode(array('status' => 'fail', 'error' => $this->form_validation->error_array(), 'message' => ''));
            return;
        }

        foreach (Hospital_brand_model::$fields as $field => $max) {
            if ($max !== 1) {
                $payload[$field] = (string) $this->input->post($field, true);
            }
        }

        if ($this->hospital_brand_model->update($payload)) {
            echo json_encode(array('status' => 'success', 'error' => '', 'message' => $this->lang->line('success_message')));
        } else {
            echo json_encode(array('status' => 'fail', 'error' => 'db_error', 'message' => ''));
        }
    }

    /** Website is optional; when given it must be a bare domain or http(s) URL (it is rendered as a link). */
    public function _valid_website($value)
    {
        if ($value === '' || preg_match('#^(https?://)?[a-z0-9.-]+\.[a-z]{2,}(/[^\s"<>]*)?$#i', $value)) {
            return true;
        }
        $this->form_validation->set_message('_valid_website', 'Website must look like www.example.com');
        return false;
    }
}
