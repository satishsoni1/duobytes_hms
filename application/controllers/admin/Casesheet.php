<?php
if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * IPD Case Sheet (admin/casesheet/index/<ipd_id>): admission + consent, doctor's notes, daily nursing chart,
 * medication administration chart, discharge summary + discharge prescription, and their print formats
 * (admin/casesheet/printout/<kind>/<ipd_id>[/<date>]).
 */
class Casesheet extends Admin_Controller
{
    private $tabs = array('admission' => 'Admission & Consent', 'notes' => "Doctor's Notes", 'nursing' => 'Nursing Chart',
        'medication' => 'Medication Chart', 'discharge' => 'Discharge Summary');

    public function __construct()
    {
        parent::__construct();
        $this->load->model('casesheet_model');
        $this->casesheet_model->ensure_schema();
    }

    // ------------------------------------------------------------------ helpers
    private function token()
    {
        $t = $this->session->userdata('casesheet_csrf');
        if (!$t) {
            $t = bin2hex(random_bytes(24));
            $this->session->set_userdata('casesheet_csrf', $t);
        }
        return $t;
    }

    /** Per-area write permission, mapped onto Smart Hospital's existing IPD permission groups. */
    private function can($area)
    {
        $r = $this->rbac;
        switch ($area) {
            case 'view':
                return $r->hasPrivilege('ipd_patient', 'can_view');
            case 'admission':
            case 'notes':
                return $r->hasPrivilege('ipd_patient', 'can_edit') || $r->hasPrivilege('ipd_prescription', 'can_add');
            case 'nursing':
                return $r->hasPrivilege('nurse_note', 'can_add') || $r->hasPrivilege('ipd_vitals', 'can_add') || $r->hasPrivilege('ipd_patient', 'can_edit');
            case 'medication':
                return $r->hasPrivilege('ipd_medication', 'can_add') || $r->hasPrivilege('ipd_prescription', 'can_add');
            case 'discharge':
                return $r->hasPrivilege('ipd_patient_discharge', 'can_view') || $r->hasPrivilege('generate_discharge_card', 'can_view')
                    || $r->hasPrivilege('ipd_prescription', 'can_add');
        }
        return false;
    }

    private function context_or_404($ipd_id)
    {
        if (!$this->can('view')) {
            access_denied();
        }
        $ctx = $this->casesheet_model->context($ipd_id);
        if (!$ctx) {
            show_404();
        }
        return $ctx;
    }

    /** Form POSTs: right method, session token, area permission. */
    private function check_post($area)
    {
        $t = (string) $this->input->post('cs_token');
        if ($this->input->method() !== 'post' || !hash_equals($this->token(), $t)) {
            $this->session->set_flashdata('cs_msg', array('danger', 'Your session expired; please try again.'));
            return false;
        }
        if (!$this->can($area)) {
            $this->session->set_flashdata('cs_msg', array('danger', 'You do not have permission to change this section.'));
            return false;
        }
        return true;
    }

    private function back($ipd_id, $tab, $extra = '')
    {
        redirect('admin/casesheet/index/' . (int) $ipd_id . '/' . $tab . $extra);
    }

    private function json($data, $status = 200)
    {
        $this->output->set_status_header($status)->set_content_type('application/json')->set_output(json_encode($data))->_display();
        exit;
    }

    private function valid_date($d)
    {
        return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    }

    private function datetime_in($v)
    {
        $v = trim((string) $v);
        if ($v === '' || ($ts = strtotime($v)) === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    // ------------------------------------------------------------------ page
    public function index($ipd_id = 0, $tab = 'admission')
    {
        $ctx = $this->context_or_404($ipd_id);
        if (!isset($this->tabs[$tab])) {
            $tab = 'admission';
        }
        $sheet = $this->casesheet_model->sheet($ipd_id);
        $data = array(
            'title'     => 'Case Sheet',
            'ctx'       => $ctx,
            'sheet'     => $sheet,
            'tab'       => $tab,
            'tabs'      => $this->tabs,
            'cs_token'  => $this->token(),
            'can'       => array(),
            'msg'       => $this->session->flashdata('cs_msg'),
        );
        foreach (array_keys($this->tabs) as $t) {
            $data['can'][$t] = $this->can($t);
        }
        if ($tab === 'nursing') {
            $dates = $this->casesheet_model->chart_dates($ipd_id);
            $date = $this->input->get('date', true);
            if (!$this->valid_date($date)) {
                $date = $dates ? end($dates) : date('Y-m-d');
            }
            $data['chart_dates'] = $dates;
            $data['chart_date'] = $date;
            $data['chart'] = $this->casesheet_model->chart($ipd_id, $date);
        } elseif ($tab === 'medication') {
            $data['meds'] = $this->casesheet_model->meds($ipd_id);
            $range = $this->casesheet_model->chart_range($ctx, $sheet);
            $data['med_dates'] = array_slice($range, -10);
            $data['staff_id'] = $this->customlib->getStaffID();
        }
        $this->session->set_userdata('top_menu', 'IPD_in_patient');
        $this->load->view('layout/header', $data);
        $this->load->view('admin/casesheet/index', $data);
        $this->load->view('layout/footer', $data);
    }

    // ------------------------------------------------------------------ saves
    public function save_admission($ipd_id = 0)
    {
        $this->context_or_404($ipd_id);
        if ($this->check_post('admission')) {
            $p = $this->input->post();
            $this->casesheet_model->save_sheet($ipd_id, array(
                'admitted_by_name'     => $p['admitted_by_name'],
                'admitted_by_relation' => $p['admitted_by_relation'],
                'admitted_by_phone'    => $p['admitted_by_phone'],
                'consent_language'     => in_array($p['consent_language'], array('mr', 'hi', 'en'), true) ? $p['consent_language'] : 'mr',
                'consent_signed_by'    => $p['consent_signed_by'],
                'consent_witness1'     => $p['consent_witness1'],
                'consent_witness2'     => $p['consent_witness2'],
                'consent_at'           => $this->datetime_in($p['consent_at']),
            ));
            $this->session->set_flashdata('cs_msg', array('success', 'Admission & consent saved.'));
        }
        $this->back($ipd_id, 'admission');
    }

    public function save_notes($ipd_id = 0)
    {
        $this->context_or_404($ipd_id);
        if ($this->check_post('notes')) {
            $p = $this->input->post();
            $vitals = array();
            foreach (array('pulse', 'bp', 'temp', 'spo2', 'rr', 'weight', 'rbs') as $k) {
                $v = isset($p['admission_vitals'][$k]) ? trim($p['admission_vitals'][$k]) : '';
                if ($v !== '') {
                    $vitals[$k] = mb_substr($v, 0, 20);
                }
            }
            $this->casesheet_model->save_sheet($ipd_id, array(
                'chief_complaints'      => $p['chief_complaints'],
                'history'               => $p['history'],
                'examination'           => $p['examination'],
                'admission_vitals'      => $vitals,
                'provisional_diagnosis' => $p['provisional_diagnosis'],
                'lab_findings'          => $p['lab_findings'],
                'initial_advice'        => $p['initial_advice'],
            ));
            $this->session->set_flashdata('cs_msg', array('success', "Doctor's notes saved."));
        }
        $this->back($ipd_id, 'notes');
    }

    public function save_chart($ipd_id = 0)
    {
        $this->context_or_404($ipd_id);
        $date = $this->input->post('chart_date');
        if (!$this->valid_date($date)) {
            $this->session->set_flashdata('cs_msg', array('danger', 'Pick a valid chart date.'));
            $this->back($ipd_id, 'nursing');
        }
        if ($this->check_post('nursing')) {
            $this->casesheet_model->save_chart($ipd_id, $date, $this->input->post(), $this->customlib->getStaffID());
            $this->session->set_flashdata('cs_msg', array('success', 'Nursing chart for ' . date('d-m-Y', strtotime($date)) . ' saved.'));
        }
        $this->back($ipd_id, 'nursing', '?date=' . $date);
    }

    public function add_med($ipd_id = 0)
    {
        $this->context_or_404($ipd_id);
        if ($this->check_post('medication')) {
            $p = $this->input->post();
            if (trim((string) $p['drug']) === '' || !$this->valid_date($p['start_date']) || (!empty($p['stop_date']) && !$this->valid_date($p['stop_date']))) {
                $this->session->set_flashdata('cs_msg', array('danger', 'Drug name and a valid start date are required.'));
            } else {
                $this->casesheet_model->add_med($ipd_id, $p, $this->customlib->getStaffID());
                $this->session->set_flashdata('cs_msg', array('success', 'Added ' . html_escape($p['drug']) . ' to the medication chart.'));
            }
        }
        $this->back($ipd_id, 'medication');
    }

    public function med_action($ipd_id = 0)
    {
        $this->context_or_404($ipd_id);
        if ($this->check_post('medication')) {
            $med = (int) $this->input->post('med_id');
            if ($this->input->post('action') === 'stop') {
                $d = $this->input->post('date');
                $this->casesheet_model->stop_med($ipd_id, $med, $this->valid_date($d) ? $d : date('Y-m-d'));
                $this->session->set_flashdata('cs_msg', array('success', 'Medication stopped.'));
            } elseif ($this->input->post('action') === 'delete') {
                $this->casesheet_model->delete_med($ipd_id, $med);
                $this->session->set_flashdata('cs_msg', array('success', 'Medication removed from the chart.'));
            }
        }
        $this->back($ipd_id, 'medication');
    }

    /** AJAX: tick / untick one dose on the medication chart. */
    public function toggle_dose($ipd_id = 0)
    {
        if (!$this->can('view') || !$this->can('medication')) {
            $this->json(array('error' => 'You do not have permission to chart medication.'), 403);
        }
        $t = (string) $this->input->get_request_header('X-Casesheet-Token', true);
        if ($this->input->method() !== 'post' || !hash_equals($this->token(), $t)) {
            $this->json(array('error' => 'Invalid request token; reload the page.'), 403);
        }
        $date = $this->input->post('date');
        $slots = $this->casesheet_model->clean_slots($this->input->post('slot'));
        if (!$this->valid_date($date) || count($slots) !== 1) {
            $this->json(array('error' => 'Bad dose reference.'), 400);
        }
        $res = $this->casesheet_model->toggle_dose($ipd_id, (int) $this->input->post('med_id'), $date, $slots[0], $this->customlib->getStaffID());
        if ($res === false) {
            $this->json(array('error' => 'Medication not found.'), 404);
        }
        $this->json(array('given' => $res !== null, 'at' => $res ? date('h:i A', strtotime($res['given_at'])) : null));
    }

    public function save_discharge($ipd_id = 0)
    {
        $this->context_or_404($ipd_id);
        if ($this->check_post('discharge')) {
            $p = $this->input->post();
            $this->casesheet_model->save_sheet($ipd_id, array(
                'final_diagnosis'        => $p['final_diagnosis'],
                'procedures'             => $p['procedures'],
                'course_in_hospital'     => $p['course_in_hospital'],
                'condition_at_discharge' => $p['condition_at_discharge'],
                'discharge_advice'       => $p['discharge_advice'],
                'diet_advice'            => $p['diet_advice'],
                'follow_up_date'         => $this->valid_date($p['follow_up_date']) ? $p['follow_up_date'] : null,
                'follow_up_note'         => $p['follow_up_note'],
                'emergency_note'         => $p['emergency_note'],
                'discharge_at'           => $this->datetime_in($p['discharge_at']),
                'summary_by'             => $this->customlib->getStaffID(),
                'discharge_rx'           => $this->casesheet_model->clean_rx(isset($p['rx']) ? $p['rx'] : array()),
            ));
            $this->session->set_flashdata('cs_msg', array('success', 'Discharge summary saved.'));
        }
        $this->back($ipd_id, 'discharge');
    }

    /** AJAX: a first draft of "course in hospital" from the notes, medication chart and nursing chart. */
    public function draft_course($ipd_id = 0)
    {
        $ctx = $this->context_or_404($ipd_id);
        $sheet = $this->casesheet_model->sheet($ipd_id);
        $meds = $this->casesheet_model->meds($ipd_id);
        $charts = $this->casesheet_model->all_charts($ipd_id);
        $parts = array();
        $parts[] = 'Patient was admitted on ' . date('d-m-Y', strtotime($ctx['admission_date']))
            . ($sheet['chief_complaints'] ? ' with complaints of ' . rtrim(preg_replace('/\s*\n\s*/', '; ', $sheet['chief_complaints']), '.') : '') . '.';
        if ($sheet['provisional_diagnosis']) {
            $parts[] = 'Provisional diagnosis: ' . rtrim($sheet['provisional_diagnosis'], '.') . '.';
        }
        $inv = array();
        foreach ($charts as $c) {
            foreach (preg_split('/[\n,]+/', (string) $c['investigations']) as $i) {
                $i = trim($i, " -\t");
                if ($i !== '') {
                    $inv[strtolower($i)] = $i;
                }
            }
        }
        if ($inv) {
            $parts[] = 'Investigations done: ' . implode(', ', $inv) . '.';
        }
        if ($meds) {
            $parts[] = 'Treated with ' . implode(', ', array_map(function ($m) {
                return trim($m['drug'] . ' ' . $m['dose']);
            }, $meds)) . ' and supportive care.';
        }
        $last = $charts ? end($charts) : null;
        if ($last && $last['vitals']) {
            $lv = end($last['vitals']);
            $bits = array();
            foreach (array('temp' => 'Temp', 'hr' => 'Pulse', 'bp' => 'BP', 'spo2' => 'SpO2') as $k => $l) {
                if (!empty($lv[$k])) {
                    $bits[] = $l . ' ' . $lv[$k];
                }
            }
            if ($bits) {
                $parts[] = 'At discharge patient is stable: ' . implode(', ', $bits) . '.';
            }
        }
        $this->json(array('text' => implode(' ', $parts)));
    }

    // ------------------------------------------------------------------ prints
    public function printout($kind = 'summary', $ipd_id = 0, $date = '')
    {
        $ctx = $this->context_or_404($ipd_id);
        $sheet = $this->casesheet_model->sheet($ipd_id);
        // Print-header image per document (Setup > Print Header Footer); null = text letterhead from the hospital's name and address,
        // since the stock IPD header image is titled "IPD Bill".
        $views = array('summary' => 'discharge_card', 'prescription' => 'ipdpres', 'consent' => null, 'nursing' => null, 'medchart' => null);
        if (!array_key_exists($kind, $views)) {
            show_404();
        }
        $data = array(
            'ctx'    => $ctx,
            'sheet'  => $sheet,
            'kind'   => $kind,
            'lh'     => $this->casesheet_model->letterhead($views[$kind]),
            'author' => $sheet['summary_by'] ? $this->db->query("SELECT name, surname, qualification FROM staff WHERE id = ?", array((int) $sheet['summary_by']))->row_array() : null,
        );
        if ($kind === 'summary' || $kind === 'medchart') {
            $data['meds'] = $this->casesheet_model->meds($ipd_id);
            $data['med_dates'] = $this->casesheet_model->chart_range($ctx, $sheet);
        }
        if ($kind === 'summary' || $kind === 'nursing') {
            $data['charts'] = $this->casesheet_model->all_charts($ipd_id);
            if ($kind === 'nursing' && $this->valid_date($date)) {
                $data['charts'] = array($date => $this->casesheet_model->chart($ipd_id, $date));
            }
        }
        $this->load->view('admin/casesheet/print', $data);
    }
}
