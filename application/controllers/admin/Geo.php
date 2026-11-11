<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Patient address lists (State → District → Taluka → City / village).
 *
 *  admin/geo/options?level=district&state=..   JSON for the address dropdowns (any staff)
 *  admin/geo                                   Setup page: defaults, rename / remove entries
 */
class Geo extends Admin_Controller
{
    private static $levels = array(
        'state'    => array('table' => 'geo_states', 'parent' => null),
        'district' => array('table' => 'geo_districts', 'parent' => 'state_id'),
        'taluka'   => array('table' => 'geo_talukas', 'parent' => 'district_id'),
        'city'     => array('table' => 'geo_cities', 'parent' => 'taluka_id'),
    );

    public function __construct()
    {
        parent::__construct();
        $this->load->model(array('hms_settings_model', 'geo_model'));
        $this->geo_model->ensure_schema();
    }

    private function json($arr)
    {
        $this->output->set_content_type('application/json')->set_output(json_encode($arr));
    }

    public function options()
    {
        $g = $this->geo_model;
        $s = (string) $this->input->get('state');
        $d = (string) $this->input->get('district');
        $t = (string) $this->input->get('taluka');
        switch ($this->input->get('level')) {
            case 'state':
                return $this->json(array_map(function ($n) { return array('name' => $n); }, $g->states()));
            case 'district':
                return $this->json(array_map(function ($n) { return array('name' => $n); }, $g->districts($s)));
            case 'taluka':
                return $this->json(array_map(function ($n) { return array('name' => $n); }, $g->talukas($s, $d)));
            case 'city':
                return $this->json($g->cities($s, $d, $t, trim((string) $this->input->get('q'))));
        }
        $this->json(array());
    }

    private function can_manage()
    {
        return $this->rbac->hasPrivilege('general_setting', 'can_edit');
    }

    public function index()
    {
        if (!$this->rbac->hasPrivilege('general_setting', 'can_view')) {
            access_denied();
        }
        $this->session->set_userdata('top_menu', 'setup');
        $this->session->set_userdata('sub_menu', 'schsettings/index');
        $this->session->set_userdata('inner_menu', 'admin/geo');
        $data['title']    = 'Patient address lists';
        $data['module']   = 'setup';
        $data['defaults'] = $this->geo_model->defaults();
        $data['can_edit'] = $this->can_manage();
        $this->load->view('layout/header', $data);
        $this->load->view('admin/geo/index', $data);
        $this->load->view('layout/footer', $data);
    }

    public function save_defaults()
    {
        if (!$this->can_manage()) {
            return $this->json(array('status' => 'fail', 'message' => $this->lang->line('access_denied')));
        }
        $state    = mb_substr(trim(strip_tags((string) $this->input->post('state'))), 0, 60);
        $district = mb_substr(trim(strip_tags((string) $this->input->post('district'))), 0, 80);
        $this->hms_settings_model->set('default_state', $state);
        $this->hms_settings_model->set('default_district', $district);
        $this->json(array('status' => 'success', 'message' => $this->lang->line('success_message')));
    }

    /** Entries of one level under a parent, with ids, for the management page. */
    public function entries()
    {
        $level = (string) $this->input->get('level');
        if (!isset(self::$levels[$level])) {
            return $this->json(array());
        }
        $L = self::$levels[$level];
        $this->db->select('id, name' . ($level === 'city' ? ', pincode' : ''))->from($L['table']);
        if ($L['parent']) {
            $this->db->where($L['parent'], (int) $this->input->get('parent'));
        }
        $this->json($this->db->order_by('name')->limit(2000)->get()->result_array());
    }

    /** Add, rename or remove one entry. Removing never touches patients (their address is text). */
    public function entry()
    {
        if (!$this->can_manage()) {
            return $this->json(array('status' => 'fail', 'message' => $this->lang->line('access_denied')));
        }
        $level = (string) $this->input->post('level');
        if (!isset(self::$levels[$level])) {
            return $this->json(array('status' => 'fail', 'message' => 'Unknown list.'));
        }
        $L      = self::$levels[$level];
        $id     = (int) $this->input->post('id');
        $action = (string) $this->input->post('action');
        $name   = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $this->input->post('name')))), 0, $level === 'city' ? 120 : 80);
        if ($action === 'delete') {
            $children = array('state' => 'district', 'district' => 'taluka', 'taluka' => 'city');
            if (isset($children[$level])) {
                $C = self::$levels[$children[$level]];
                if ($this->db->where($C['parent'], $id)->count_all_results($C['table']) > 0) {
                    return $this->json(array('status' => 'fail', 'message' => 'Remove the entries under it first.'));
                }
            }
            $this->db->where('id', $id)->delete($L['table']);
            return $this->json(array('status' => 'success', 'message' => 'Removed from the list.'));
        }
        if ($name === '') {
            return $this->json(array('status' => 'fail', 'message' => 'Type a name.'));
        }
        $row = array('name' => $name);
        if ($level === 'city') {
            $row['pincode'] = substr(preg_replace('/\D/', '', (string) $this->input->post('pincode')), 0, 6) ?: null;
        }
        $parent = 0;
        if ($action === 'rename' && $id) {
            $cur = $this->db->where('id', $id)->get($L['table'])->row_array();
            if (!$cur) {
                return $this->json(array('status' => 'fail', 'message' => 'This entry no longer exists.'));
            }
            $parent = $L['parent'] ? (int) $cur[$L['parent']] : 0;
        } elseif ($L['parent']) {
            $parent = (int) $this->input->post('parent');
            if (!$parent) {
                return $this->json(array('status' => 'fail', 'message' => 'Choose where it belongs first.'));
            }
        }
        $this->db->where('name', $name)->where('id !=', $id);
        if ($L['parent']) {
            $this->db->where($L['parent'], $parent);
        }
        if ($this->db->count_all_results($L['table']) > 0) {
            return $this->json(array('status' => 'fail', 'message' => 'That name is already in the list.'));
        }
        if ($action === 'rename' && $id) {
            $this->db->where('id', $id)->update($L['table'], $row);
        } else {
            if ($L['parent']) {
                $row[$L['parent']] = $parent;
            }
            $this->db->insert($L['table'], $row);
        }
        $this->json(array('status' => 'success', 'message' => $this->lang->line('success_message')));
    }
}
