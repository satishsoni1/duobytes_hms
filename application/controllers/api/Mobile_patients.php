<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/Mobile_api_controller.php';

/**
 * Online-only lookups for the doctor app (everything else works offline from the device database).
 *
 * GET api/v1/patients/search?q=      Hospital-wide patient search (respects doctor_restriction).
 * GET api/v1/patients/bundle?id=     Full history of one patient, so the app can keep it offline ("pin").
 * GET api/v1/patients/icd?q=         Full ICD-10 search (the device only caches codes in use).
 */
class Mobile_patients extends Mobile_api_controller
{
    public function __construct()
    {
        parent::__construct();
        $this->authenticate();
        if (empty(array_intersect(array('doctor', 'reception', 'nursing', 'lab'), $this->staff['modules']))) {
            $this->fail(403, 'module_forbidden', 'This app section is not available for your role.');
        }
        $this->load->model('mobiledoctor_model');
    }

    public function search()
    {
        $this->require_method('get');
        $q = trim((string) $this->input->get('q', true));
        if (mb_strlen($q) < 2) {
            $this->respond(array('patients' => array()));
        }
        $rows = $this->mobiledoctor_model->search_patients($q);
        if ($this->doctor_restricted()) {
            $me   = $this->staff['id'];
            $rows = array_values(array_filter($rows, function ($r) use ($me) {
                return $this->mobiledoctor_model->doctor_has_patient($me, $r['id']);
            }));
        }
        $this->respond(array('patients' => $rows));
    }

    public function bundle()
    {
        $this->require_method('get');
        $id = (int) $this->input->get('id', true);
        if ($id <= 0) {
            $this->fail(422, 'validation', 'Patient id is required.');
        }
        if ($this->doctor_restricted() && !$this->mobiledoctor_model->doctor_has_patient($this->staff['id'], $id)) {
            $this->fail(403, 'patient_forbidden', 'This patient is assigned to another doctor.');
        }
        $data = $this->mobiledoctor_model->patient_data(array($id), null);
        if (empty($data['patients'])) {
            $this->fail(404, 'not_found', 'Patient not found.');
        }
        $this->respond(array('data' => $data));
    }

    /** GET api/v1/patients/report_file?kind=pathology|radiology&id= : uploaded report file for a patient this doctor may see. */
    public function report_file()
    {
        $this->require_method('get');
        $kind = (string) $this->input->get('kind', true);
        $file = $this->mobiledoctor_model->lab_report_file($kind, (int) $this->input->get('id', true));
        if (!$file) {
            $this->fail(404, 'not_found', 'No report file was uploaded for this test.');
        }
        if ($this->doctor_restricted() && !$this->mobiledoctor_model->doctor_has_patient($this->staff['id'], $file[0])) {
            $this->fail(403, 'patient_forbidden', 'This patient is assigned to another doctor.');
        }
        $this->send_file($file[1], $file[2]);
    }

    public function icd()
    {
        $this->require_method('get');
        $this->load->model('smartrx_model');
        $rows = $this->smartrx_model->search_icd((string) $this->input->get('q', true), 30);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
        }
        $this->respond(array('codes' => $rows));
    }
}
