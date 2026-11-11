<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Staff travel expense claims.
 *
 *  admin/travelexpense              my claims (every staff member)
 *  admin/travelexpense/form/{id}    new / edit a claim (draft or rejected)
 *  admin/travelexpense/approvals    all claims for approvers (Travel Expense Approval permission)
 *  admin/travelexpense/view/{id}    claim detail (owner or approver)
 *
 * Approved claims are paid through payroll: Payroll::create lists them as earnings and
 * Payroll::payslip marks them paid.
 */
class Travelexpense extends Admin_Controller
{
    const MAX_LINES = 60;

    public function __construct()
    {
        parent::__construct();
        $this->load->model(array('hms_settings_model', 'hrms_model', 'travelexpense_model'));
        $this->travelexpense_model->ensure_schema();
    }

    private function me()
    {
        return (int) $this->customlib->getStaffID();
    }

    private function is_approver()
    {
        return $this->rbac->hasPrivilege('travel_expense_approval', 'can_view');
    }

    private function json($arr)
    {
        $this->output->set_content_type('application/json')->set_output(json_encode($arr));
    }

    private function page($view, array $data, $sub)
    {
        $this->session->set_userdata('top_menu', $sub === 'admin/travelexpense' ? 'MyHR' : 'HR');
        $this->session->set_userdata('sub_menu', $sub);
        $data['module'] = 'human_resource';
        $this->load->view('layout/header', $data);
        $this->load->view($view, $data);
        $this->load->view('layout/footer', $data);
    }

    private function own_claim_or_deny($id, $editable = false)
    {
        $c = $this->travelexpense_model->claim($id);
        if (!$c || (int) $c['staff_id'] !== $this->me()) {
            return null;
        }
        if ($editable && !in_array($c['status'], array('draft', 'rejected'), true)) {
            return null;
        }
        return $c;
    }

    // ------------------------------------------------------------------ staff

    public function index()
    {
        $data['title']    = 'My travel claims';
        $data['claims']   = $this->travelexpense_model->claims_of($this->me());
        $data['statuses'] = Travelexpense_model::$statuses;
        $this->page('admin/travelexpense/index', $data, 'admin/travelexpense');
    }

    public function form($id = 0)
    {
        $claim = null;
        if ($id) {
            $claim = $this->own_claim_or_deny($id, true);
            if (!$claim) {
                access_denied();
            }
        }
        $data['title']      = $id ? 'Edit travel claim' : 'New travel claim';
        $data['claim']      = $claim;
        $data['categories'] = $this->travelexpense_model->categories();
        $data['modes']      = Travelexpense_model::$modes;
        $this->page('admin/travelexpense/form', $data, 'admin/travelexpense');
    }

    public function save($id = 0)
    {
        $id  = (int) $id;
        $old = null;
        if ($id) {
            $old = $this->own_claim_or_deny($id, true);
            if (!$old) {
                return $this->json(array('status' => 'fail', 'message' => 'This claim can no longer be changed.'));
            }
        }
        $this->form_validation->set_rules('title', 'Trip / purpose', 'trim|required|max_length[200]|xss_clean');
        $this->form_validation->set_rules('purpose', 'Details', 'trim|max_length[2000]|xss_clean');
        $this->form_validation->set_rules('advance_taken', 'Advance taken', 'trim|numeric|greater_than_equal_to[0]');
        if (!$this->form_validation->run()) {
            return $this->json(array('status' => 'fail', 'error' => $this->form_validation->error_array()));
        }

        $old_lines = array();
        if ($old) {
            foreach ($old['items'] as $it) {
                $old_lines[(int) $it['id']] = $it;
            }
        }
        $cats   = $this->travelexpense_model->categories();
        $posted = (array) $this->input->post('items');
        if (count($posted) > self::MAX_LINES) {
            return $this->json(array('status' => 'fail', 'message' => 'A claim can have up to ' . self::MAX_LINES . ' lines. Split it into two claims.'));
        }
        $items  = array();
        $errors = array();
        $new_files = array();
        foreach ($posted as $key => $row) {
            $key    = preg_replace('/[^0-9a-z_]/i', '', (string) $key);
            $amount = trim((string) ($row['amount'] ?? ''));
            $cat    = trim((string) ($row['category'] ?? ''));
            $desc   = trim(strip_tags((string) ($row['description'] ?? '')));
            if ($amount === '' && $desc === '' && empty($_FILES['item_file_' . $key]['name'])) {
                continue; // empty row
            }
            if (!is_numeric($amount) || (float) $amount <= 0) {
                $errors['items[' . $key . '][amount]'] = 'Enter the amount.';
                continue;
            }
            if (!in_array($cat, $cats, true)) {
                $cat = end($cats);
            }
            $line_id = (int) ($row['id'] ?? 0);
            if ($line_id && !isset($old_lines[$line_id])) {
                $line_id = 0;
            }
            $date = trim((string) ($row['date'] ?? ''));
            $km   = trim((string) ($row['distance_km'] ?? ''));
            $item = array(
                'id'           => $line_id,
                'expense_date' => $date === '' ? null : $this->customlib->dateFormatToYYYYMMDD($date),
                'category'     => $cat,
                'description'  => mb_substr($desc, 0, 300),
                'from_place'   => mb_substr(trim(strip_tags((string) ($row['from_place'] ?? ''))), 0, 100),
                'to_place'     => mb_substr(trim(strip_tags((string) ($row['to_place'] ?? ''))), 0, 100),
                'travel_mode'  => in_array($row['travel_mode'] ?? '', Travelexpense_model::$modes, true) ? $row['travel_mode'] : null,
                'distance_km'  => is_numeric($km) ? round((float) $km, 1) : null,
                'amount'       => round((float) $amount, 2),
            );
            $file = Hrms_model::store_upload('item_file_' . $key, Travelexpense_model::FILE_DIR);
            if (is_string($file)) {
                $errors['item_file_' . $key] = $file;
                continue;
            }
            if ($file) {
                $item['attachment']      = $file[0];
                $item['attachment_name'] = $file[1];
                $new_files[]             = $file[0];
                if ($line_id && !empty($old_lines[$line_id]['attachment'])) {
                    $this->travelexpense_model->remove_file($old_lines[$line_id]['attachment']);
                }
            } elseif ($line_id && !empty($row['remove_file']) && !empty($old_lines[$line_id]['attachment'])) {
                $this->travelexpense_model->remove_file($old_lines[$line_id]['attachment']);
                $item['attachment'] = $item['attachment_name'] = null;
            }
            $items[] = $item;
        }
        if ($errors || !$items) {
            foreach ($new_files as $f) {
                $this->travelexpense_model->remove_file($f);
            }
            if (!$items && !$errors) {
                $errors['items'] = 'Add at least one expense line.';
            }
            return $this->json(array('status' => 'fail', 'error' => $errors));
        }
        $dates = array_filter(array_column($items, 'expense_date'));
        $head  = array(
            'title'         => $this->input->post('title', true),
            'purpose'       => $this->input->post('purpose', true),
            'advance_taken' => round((float) $this->input->post('advance_taken'), 2),
            'from_date'     => $dates ? min($dates) : null,
            'to_date'       => $dates ? max($dates) : null,
        );
        $submit = (bool) $this->input->post('submit_now');
        if ($submit) {
            $head += array('status' => 'submitted', 'submitted_at' => date('Y-m-d H:i:s'), 'decided_by' => null, 'decided_at' => null, 'approved_amount' => null);
        }
        $id = $this->travelexpense_model->save_claim($this->me(), $head, $items, $id);
        $this->json(array('status' => 'success', 'id' => $id, 'message' => $submit ? 'Claim sent for approval.' : 'Saved as draft.'));
    }

    public function submit($id)
    {
        $c = $this->own_claim_or_deny($id, true);
        if (!$c || empty($c['items'])) {
            return $this->json(array('status' => 'fail', 'message' => 'This claim cannot be sent.'));
        }
        $this->travelexpense_model->set_status($id, array('status' => 'submitted', 'submitted_at' => date('Y-m-d H:i:s'), 'decided_by' => null, 'decided_at' => null, 'approved_amount' => null));
        $this->json(array('status' => 'success', 'message' => 'Claim sent for approval.'));
    }

    public function delete($id)
    {
        $c = $this->own_claim_or_deny($id);
        if (!$c || $c['status'] !== 'draft') {
            return $this->json(array('status' => 'fail', 'message' => 'Only draft claims can be deleted.'));
        }
        $this->travelexpense_model->delete_claim($id);
        $this->json(array('status' => 'success', 'message' => $this->lang->line('delete_message')));
    }

    /** Claim detail; ?print=1 for the printable voucher. */
    public function view($id)
    {
        $c = $this->travelexpense_model->claim($id);
        if (!$c || ((int) $c['staff_id'] !== $this->me() && !$this->is_approver())) {
            access_denied();
        }
        $data['claim']       = $c;
        $data['statuses']    = Travelexpense_model::$statuses;
        $data['can_decide']  = $this->rbac->hasPrivilege('travel_expense_approval', 'can_edit') && $c['status'] === 'submitted' && (int) $c['staff_id'] !== $this->me();
        if ($this->input->get('print')) {
            $data['setting'] = $this->setting_model->getSetting();
            return $this->load->view('admin/travelexpense/print', $data);
        }
        if ($this->input->is_ajax_request()) {
            return $this->load->view('admin/travelexpense/_detail', $data);
        }
        $data['title'] = 'Travel claim ' . $c['claim_no'];
        $this->page('admin/travelexpense/view', $data, (int) $c['staff_id'] === $this->me() ? 'admin/travelexpense' : 'admin/travelexpense/approvals');
    }

    public function file($item_id)
    {
        $it = $this->db->where('id', (int) $item_id)->get('hr_expense_items')->row_array();
        $c  = $it ? $this->db->select('staff_id')->where('id', (int) $it['claim_id'])->get('hr_expense_claims')->row_array() : null;
        if (!$it || !$c || empty($it['attachment']) || ((int) $c['staff_id'] !== $this->me() && !$this->is_approver())) {
            access_denied();
        }
        Hrms_model::send_file(FCPATH . Travelexpense_model::FILE_DIR . basename($it['attachment']), $it['attachment_name']);
    }

    // ------------------------------------------------------------------ approvers

    public function approvals()
    {
        if (!$this->is_approver()) {
            access_denied();
        }
        $f = array(
            'status'   => $this->input->get('status', true) ?? 'submitted',
            'staff_id' => (int) $this->input->get('staff_id'),
            'month'    => (string) $this->input->get('month', true),
        );
        if (!isset(Travelexpense_model::$statuses[$f['status']]) && $f['status'] !== '') {
            $f['status'] = 'submitted';
        }
        $data['title']      = 'Travel expense approvals';
        $data['filter']     = $f;
        $data['claims']     = $this->travelexpense_model->search($f);
        $data['counts']     = $this->travelexpense_model->counts();
        $data['statuses']   = Travelexpense_model::$statuses;
        $data['staff_list'] = $this->db->select('id, name, surname, employee_id')->where('is_active', 1)->order_by('name')->get('staff')->result_array();
        $data['can_decide'] = $this->rbac->hasPrivilege('travel_expense_approval', 'can_edit');
        $this->page('admin/travelexpense/approvals', $data, 'admin/travelexpense/approvals');
    }

    public function decide($id)
    {
        if (!$this->rbac->hasPrivilege('travel_expense_approval', 'can_edit')) {
            return $this->json(array('status' => 'fail', 'message' => $this->lang->line('access_denied')));
        }
        $c = $this->travelexpense_model->claim($id);
        if (!$c || $c['status'] !== 'submitted') {
            return $this->json(array('status' => 'fail', 'message' => 'This claim is not waiting for approval any more.'));
        }
        if ((int) $c['staff_id'] === $this->me()) {
            return $this->json(array('status' => 'fail', 'message' => 'You cannot approve your own claim.'));
        }
        $action = $this->input->post('action');
        $note   = mb_substr(trim(strip_tags((string) $this->input->post('note'))), 0, 500);
        $row    = array('decided_by' => $this->me(), 'decided_at' => date('Y-m-d H:i:s'), 'decision_note' => $note);
        if ($action === 'reject') {
            if ($note === '') {
                return $this->json(array('status' => 'fail', 'error' => array('note' => 'Say why the claim is rejected.')));
            }
            $row['status'] = 'rejected';
        } elseif ($action === 'approve') {
            $amount = $this->input->post('approved_amount');
            $amount = ($amount === null || $amount === '') ? (float) $c['total_amount'] : (float) $amount;
            if ($amount <= 0 || $amount > (float) $c['total_amount']) {
                return $this->json(array('status' => 'fail', 'error' => array('approved_amount' => 'Approve between 0.01 and the claimed ' . number_format((float) $c['total_amount'], 2) . '.')));
            }
            if ($amount < (float) $c['total_amount'] && $note === '') {
                return $this->json(array('status' => 'fail', 'error' => array('note' => 'Say why the amount is reduced.')));
            }
            $row['status']          = 'approved';
            $row['approved_amount'] = round($amount, 2);
        } else {
            return $this->json(array('status' => 'fail', 'message' => 'Choose approve or reject.'));
        }
        $this->travelexpense_model->set_status($id, $row);
        $this->json(array('status' => 'success', 'message' => $row['status'] === 'approved' ? 'Claim approved. It will be added to the next payslip.' : 'Claim rejected.'));
    }
}
