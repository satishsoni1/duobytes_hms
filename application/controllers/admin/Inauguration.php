<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Inauguration ceremony — full-screen page for the go-live event.
 * Lamp lighting (deep prajwalan) → ribbon cutting → "Now live" reveal.
 *
 *   admin/inauguration            the ceremony (records the go-live moment on the first real cut)
 *   admin/inauguration?mode=rehearsal   practice run, nothing is saved
 *
 * On/off switch, chief guest and title live in Setup > Settings > Brand Kit.
 * Uses the Theme Studio permission (superadmin by default).
 */
class Inauguration extends Admin_Controller
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
        $brand = $this->hospital_brand_model->get();
        if (empty($brand['inauguration_enabled'])) {
            $this->session->set_flashdata('msg', '<div class="alert alert-info">The inauguration page is switched off in Brand Kit.</div>');
            redirect('admin/brandkit');
        }

        $data['brand']     = $brand;
        $data['hospital']  = $this->setting_model->getHospitalDetail();
        $data['rehearsal'] = $this->input->get('mode') === 'rehearsal';
        $data['can_mark']  = $this->rbac->hasPrivilege('theme_studio', 'can_edit');
        $this->load->view('admin/inauguration/index', $data);
    }

    public function mark()
    {
        if ($this->input->method() !== 'post' || !$this->rbac->hasPrivilege('theme_studio', 'can_edit')) {
            echo json_encode(array('status' => 'fail', 'error' => 'access_denied'));
            return;
        }
        $admin = $this->session->userdata('hospitaladmin');
        $guest = trim((string) $this->hospital_brand_model->get()['inauguration_guest']);
        $by    = $guest !== '' ? $guest : (isset($admin['username']) ? $admin['username'] : 'Administrator');
        $row   = $this->hospital_brand_model->mark_inaugurated($by);
        echo json_encode(array(
            'status'         => 'success',
            'inaugurated_at' => $row['inaugurated_at'],
            'display'        => date('d M Y, h:i A', strtotime($row['inaugurated_at'])),
        ));
    }

    public function reset()
    {
        if ($this->input->method() !== 'post' || !$this->rbac->hasPrivilege('theme_studio', 'can_edit')) {
            echo json_encode(array('status' => 'fail', 'error' => 'access_denied'));
            return;
        }
        $this->hospital_brand_model->reset_inauguration();
        echo json_encode(array('status' => 'success'));
    }
}
