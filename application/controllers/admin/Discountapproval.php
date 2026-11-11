<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Discount approvals.
 *
 *  admin/discountapproval              requests for me to approve / asked by me / all
 *  admin/discountapproval/settings     switch on, choose approvers, free limit (Setup > Settings)
 *
 * See Discountapproval_model for how a discount is held back and applied.
 */
class Discountapproval extends Admin_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model(array('hms_settings_model', 'discountapproval_model'));
        $this->discountapproval_model->ensure_schema();
    }

    private function me()
    {
        return (int) $this->customlib->getStaffID();
    }

    private function json($arr)
    {
        $this->output->set_content_type('application/json')->set_output(json_encode($arr));
    }

    public function index()
    {
        $me       = $this->me();
        $approver = $this->discountapproval_model->is_approver($me);
        $see_all  = $this->rbac->hasPrivilege('discount_approval', 'can_view');
        $scope    = $this->input->get('scope', true) ?: ($approver ? 'mine' : 'asked');
        if (!in_array($scope, array('mine', 'asked', 'all'), true) || ($scope === 'all' && !$see_all && !$approver) || ($scope === 'mine' && !$approver)) {
            $scope = 'asked';
        }
        $status = $this->input->get('status', true);
        if ($status === null) {
            $status = $scope === 'mine' ? 'pending' : '';
        }
        $f = array(
            'scope' => $scope, 'staff_id' => $me, 'status' => isset(Discountapproval_model::$statuses[$status]) ? $status : '',
            'module' => (string) $this->input->get('module', true),
            'from' => $this->ymd($this->input->get('from', true)), 'to' => $this->ymd($this->input->get('to', true)),
        );
        $this->session->set_userdata('top_menu', 'discountapproval');
        $data['title']     = 'Discount approvals';
        $data['module']    = 'billing';
        $data['filter']    = $f;
        $data['rows']      = $this->discountapproval_model->search($f);
        $data['settings']  = $this->discountapproval_model->settings();
        $data['approver']  = $approver;
        $data['see_all']   = $see_all || $approver;
        $data['me']        = $me;
        $data['modules']   = Discountapproval_model::$modules;
        $data['statuses']  = Discountapproval_model::$statuses;
        $data['approvers'] = $this->discountapproval_model->approver_list();
        $this->load->view('layout/header', $data);
        $this->load->view('admin/discountapproval/index', $data);
        $this->load->view('layout/footer', $data);
    }

    private function ymd($v)
    {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }

    public function decide($id)
    {
        $action = $this->input->post('action');
        $note   = trim(strip_tags((string) $this->input->post('note')));
        if ($action === 'approve') {
            $r = $this->discountapproval_model->approve($id, $this->me(), $note, $this->input->post('discount_pct'));
        } elseif ($action === 'reject') {
            if ($note === '') {
                return $this->json(array('status' => 'fail', 'message' => 'Say why the discount is rejected.'));
            }
            $r = $this->discountapproval_model->reject($id, $this->me(), $note);
        } elseif ($action === 'withdraw') {
            $r = $this->discountapproval_model->withdraw($id, $this->me());
        } else {
            $r = 'Choose approve or reject.';
        }
        if ($r === true) {
            $msg = array('approve' => 'Discount approved and applied to the bill.', 'reject' => 'Discount rejected. The bill stays at the full amount.', 'withdraw' => 'Request withdrawn.');
            return $this->json(array('status' => 'success', 'message' => $msg[$action]));
        }
        $this->json(array('status' => 'fail', 'message' => $r));
    }

    /** Badge count for the menu (approvers). */
    public function pending_count()
    {
        $this->json(array('count' => $this->discountapproval_model->pending_count_for($this->me())));
    }

    public function settings()
    {
        if (!$this->rbac->hasPrivilege('discount_approval_setting', 'can_view')) {
            access_denied();
        }
        $this->session->set_userdata('top_menu', 'setup');
        $this->session->set_userdata('sub_menu', 'schsettings/index');
        $this->session->set_userdata('inner_menu', 'admin/discountapproval/settings');
        $data['title']    = 'Discount Approval';
        $data['module']   = 'setup';
        $data['set']      = $this->discountapproval_model->settings();
        $data['can_edit'] = $this->rbac->hasPrivilege('discount_approval_setting', 'can_edit');
        $data['staff']    = $this->db->select('s.id, s.name, s.surname, s.employee_id, r.name as role_name', false)
            ->from('staff s')->join('staff_roles sr', 'sr.staff_id = s.id', 'left')->join('roles r', 'r.id = sr.role_id', 'left')
            ->where('s.is_active', 1)->order_by('r.name, s.name')->get()->result_array();
        $this->load->view('layout/header', $data);
        $this->load->view('admin/discountapproval/settings', $data);
        $this->load->view('layout/footer', $data);
    }

    public function settings_save()
    {
        if (!$this->rbac->hasPrivilege('discount_approval_setting', 'can_edit')) {
            return $this->json(array('status' => 'fail', 'message' => $this->lang->line('access_denied')));
        }
        $this->form_validation->set_rules('free_pct', 'Free limit', 'trim|numeric|greater_than_equal_to[0]|less_than_equal_to[100]');
        if (!$this->form_validation->run()) {
            return $this->json(array('status' => 'fail', 'error' => $this->form_validation->error_array()));
        }
        $valid = array_column($this->db->select('id')->where('is_active', 1)->get('staff')->result_array(), 'id');
        $ids   = array_values(array_intersect(array_map('intval', (array) $this->input->post('approvers')), array_map('intval', $valid)));
        $on    = (bool) $this->input->post('enabled');
        if ($on && !$ids) {
            return $this->json(array('status' => 'fail', 'error' => array('approvers[]' => 'Choose at least one approver before switching this on.')));
        }
        $s = $this->hms_settings_model;
        $s->set('discount_approval_enabled', $on ? '1' : '0');
        $s->set('discount_approvers', json_encode($ids));
        $s->set('discount_free_pct', (string) round((float) $this->input->post('free_pct'), 2));
        $this->json(array('status' => 'success', 'message' => $this->lang->line('success_message')));
    }
}
