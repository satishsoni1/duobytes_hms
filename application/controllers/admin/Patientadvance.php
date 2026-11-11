<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Patient advance (deposit) collection for OPD / IPD, settled later against the case's charges.
 *
 *  admin/patientadvance                  advance register (+ collect)
 *  admin/patientadvance/patient/{id}     one patient: advances, balance, open cases, settle / refund
 *  admin/patientadvance/receipt/{id}     printable receipt
 *
 * Permission: Billing > Patient Advance (view / add = collect & settle / edit = refund & reverse / delete = cancel).
 */
class Patientadvance extends Admin_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model(array('hms_settings_model', 'patientadvance_model'));
        $this->patientadvance_model->ensure_schema();
        $this->config->load('payroll');
    }

    private function json($arr)
    {
        $this->output->set_content_type('application/json')->set_output(json_encode($arr));
    }

    private function need($perm, $json = true)
    {
        if ($this->rbac->hasPrivilege('patient_advance', $perm)) {
            return true;
        }
        if ($json) {
            $this->json(array('status' => 'fail', 'message' => $this->lang->line('access_denied')));
            return false;
        }
        access_denied();
    }

    private function modes()
    {
        $m = (array) $this->config->item('payment_mode');
        return $m ?: array('Cash' => 'Cash', 'UPI' => 'UPI', 'Cheque' => 'Cheque', 'Other' => 'Other');
    }

    private function page($view, array $data)
    {
        $this->session->set_userdata('top_menu', 'patientadvance');
        $data['module'] = 'billing';
        $data['modes']  = $this->modes();
        $this->load->view('layout/header', $data);
        $this->load->view($view, $data);
        $this->load->view('layout/footer', $data);
    }

    private function ymd($v, $default = '')
    {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $default;
    }

    public function index()
    {
        $this->need('can_view', false);
        $f = array(
            'from' => $this->ymd($this->input->get('from'), date('Y-m-01')),
            'to'   => $this->ymd($this->input->get('to'), date('Y-m-d')),
            'mode' => (string) $this->input->get('mode', true),
            'open_only' => (bool) $this->input->get('open'),
        );
        if ($f['open_only']) {
            $f['from'] = $f['to'] = '';
        }
        $data['title']  = 'Patient advances';
        $data['filter'] = $f;
        $data['rows']   = $this->patientadvance_model->register($f);
        $this->page('admin/patientadvance/index', $data);
    }

    /** Patient lookup for the collect form: name, mobile or patient id. */
    public function search()
    {
        $this->need('can_view', false);
        $q = trim((string) $this->input->get('q', true));
        if (mb_strlen($q) < 2) {
            return $this->json(array());
        }
        $this->db->select('id, patient_name, mobileno, guardian_name, age, gender')->from('patients')->where('is_active', 'yes');
        if (ctype_digit($q)) {
            $this->db->group_start()->where('id', (int) $q)->or_like('mobileno', $q)->group_end();
        } else {
            $this->db->like('patient_name', $q);
        }
        $rows = $this->db->order_by('id', 'desc')->limit(15)->get()->result_array();
        $this->json(array_map(function ($r) {
            return array('id' => (int) $r['id'], 'text' => $r['patient_name'] . ' (' . $r['id'] . ')' . ($r['mobileno'] ? ' · ' . $r['mobileno'] : '') . ($r['guardian_name'] ? ' · ' . $r['guardian_name'] : ''));
        }, $rows));
    }

    public function patient($patient_id)
    {
        $this->need('can_view', false);
        $p = $this->db->select('id, patient_name, mobileno, guardian_name, age, gender, address')->where('id', (int) $patient_id)->get('patients')->row_array();
        if (!$p) {
            show_404();
        }
        $data['title']    = 'Advances · ' . $p['patient_name'];
        $data['patient']  = $p;
        $data['advances'] = $this->patientadvance_model->for_patient($patient_id);
        $data['balance']  = $this->patientadvance_model->patient_balance($patient_id);
        $data['cases']    = $this->patientadvance_model->open_cases($patient_id);
        $data['entries']  = array();
        foreach ($data['advances'] as $a) {
            $full = $this->patientadvance_model->advance($a['id']);
            foreach ($full['entries'] as $e) {
                $e['receipt_no'] = $a['receipt_no'];
                $data['entries'][] = $e;
            }
        }
        usort($data['entries'], function ($x, $y) { return strcmp($y['entry_date'], $x['entry_date']); });
        $this->page('admin/patientadvance/patient', $data);
    }

    public function collect()
    {
        if (!$this->need('can_add')) {
            return;
        }
        $this->form_validation->set_rules('patient_id', 'Patient', 'required|integer');
        $this->form_validation->set_rules('amount', 'Amount', 'trim|required|numeric|greater_than[0]');
        $this->form_validation->set_rules('payment_mode', 'Payment mode', 'trim|required|in_list[' . implode(',', array_keys($this->modes())) . ']');
        $this->form_validation->set_rules('purpose', 'For', 'trim|required|in_list[' . implode(',', array_keys(Patientadvance_model::$purposes)) . ']');
        $this->form_validation->set_rules('reference_no', 'Reference', 'trim|max_length[100]|xss_clean');
        $this->form_validation->set_rules('note', 'Note', 'trim|max_length[500]|xss_clean');
        if (!$this->form_validation->run()) {
            return $this->json(array('status' => 'fail', 'error' => $this->form_validation->error_array()));
        }
        $pid = (int) $this->input->post('patient_id');
        if (!$this->db->where('id', $pid)->count_all_results('patients')) {
            return $this->json(array('status' => 'fail', 'error' => array('patient_id' => 'Choose the patient.')));
        }
        $date = trim((string) $this->input->post('payment_date'));
        $id = $this->patientadvance_model->collect(array(
            'patient_id'   => $pid,
            'purpose'      => $this->input->post('purpose', true),
            'amount'       => round((float) $this->input->post('amount'), 2),
            'payment_mode' => $this->input->post('payment_mode', true),
            'reference_no' => $this->input->post('reference_no', true),
            'note'         => $this->input->post('note', true),
            'payment_date' => $date !== '' ? $this->customlib->dateFormatToYYYYMMDD($date) . ' ' . date('H:i:s') : date('Y-m-d H:i:s'),
        ));
        $this->json(array('status' => 'success', 'id' => $id, 'patient_id' => $pid, 'receipt' => base_url('admin/patientadvance/receipt/' . $id), 'message' => 'Advance collected.'));
    }

    public function settle()
    {
        if (!$this->need('can_add')) {
            return;
        }
        list($kind, $case_id) = array_pad(explode(':', (string) $this->input->post('case')), 2, 0);
        if (!in_array($kind, array('opd', 'ipd'), true)) {
            return $this->json(array('status' => 'fail', 'error' => array('case' => 'Choose the visit or admission.')));
        }
        $r = $this->patientadvance_model->settle((int) $this->input->post('advance_id'), $kind, (int) $case_id, $this->input->post('amount'), trim(strip_tags((string) $this->input->post('note'))));
        if (is_string($r)) {
            return $this->json(array('status' => 'fail', 'message' => $r));
        }
        $this->json(array('status' => 'success', 'message' => 'Advance adjusted against the bill.'));
    }

    public function refund()
    {
        if (!$this->need('can_edit')) {
            return;
        }
        $mode = (string) $this->input->post('payment_mode');
        if (!isset($this->modes()[$mode])) {
            return $this->json(array('status' => 'fail', 'error' => array('payment_mode' => 'Choose how the money is returned.')));
        }
        $r = $this->patientadvance_model->refund((int) $this->input->post('advance_id'), $this->input->post('amount'), $mode, trim(strip_tags((string) $this->input->post('note'))));
        if (is_string($r)) {
            return $this->json(array('status' => 'fail', 'message' => $r));
        }
        $this->json(array('status' => 'success', 'message' => 'Refund recorded.'));
    }

    public function reverse($entry_id)
    {
        if (!$this->need('can_edit')) {
            return;
        }
        $r = $this->patientadvance_model->reverse($entry_id);
        $this->json($r === true ? array('status' => 'success', 'message' => 'Reversed. The amount is back in the advance.') : array('status' => 'fail', 'message' => $r));
    }

    public function cancel($id)
    {
        if (!$this->need('can_delete')) {
            return;
        }
        $note = trim(strip_tags((string) $this->input->post('note')));
        if ($note === '') {
            return $this->json(array('status' => 'fail', 'message' => 'Say why the advance is cancelled.'));
        }
        $r = $this->patientadvance_model->cancel($id, $note);
        $this->json($r === true ? array('status' => 'success', 'message' => 'Advance cancelled.') : array('status' => 'fail', 'message' => $r));
    }

    public function receipt($id)
    {
        $this->need('can_view', false);
        $a = $this->patientadvance_model->advance($id);
        if (!$a) {
            show_404();
        }
        $row = $this->db->where('setting_for', 'paymentreceipt')->get('print_setting')->row_array();
        $data['header']  = ($row && !empty($row['print_header']) && is_file(FCPATH . $row['print_header'])) ? base_url($row['print_header']) : '';
        $data['a']       = $a;
        $data['setting'] = $this->setting_model->getSetting();
        $data['modes']   = $this->modes();
        $this->load->view('admin/patientadvance/receipt', $data);
    }
}
