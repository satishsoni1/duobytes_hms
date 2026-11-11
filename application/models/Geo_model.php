<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Patient address: State → District → Taluka → City / village (+ PIN code), kept as text on the
 * patient (patients.state, district, taluka, city_village, pincode). The house / street / landmark
 * line typed in the form is kept in patients.address_line, and patients.address holds the full
 * address ("House, City, Taluka, District, State - PIN") so every existing print and list shows it.
 *
 * The lists behind the dropdowns live in geo_* tables. States of India, all districts of
 * Maharashtra and the talukas of Akola and the districts around it are seeded; anything missing is
 * typed into the dropdown and saved to the list when the patient is saved (remember()).
 */
class Geo_model extends CI_Model
{
    public static $patient_fields = array('state' => 60, 'district' => 80, 'taluka' => 80, 'city_village' => 120, 'pincode' => 10);

    public function ensure_schema()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $this->load->model('hms_settings_model');
        if ($this->hms_settings_model->schema_is('geo', 1)) {
            return;
        }
        foreach (self::$patient_fields as $col => $len) {
            if (!$this->db->field_exists($col, 'patients')) {
                $this->db->query("ALTER TABLE `patients` ADD `$col` varchar($len) DEFAULT NULL");
            }
        }
        if (!$this->db->field_exists('address_line', 'patients')) {
            $this->db->query("ALTER TABLE `patients` ADD `address_line` text DEFAULT NULL");
        }
        if (!$this->db->table_exists('geo_states')) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `geo_states` (`id` int NOT NULL AUTO_INCREMENT PRIMARY KEY, `name` varchar(60) NOT NULL, UNIQUE KEY `name` (`name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $this->db->query("CREATE TABLE IF NOT EXISTS `geo_districts` (`id` int NOT NULL AUTO_INCREMENT PRIMARY KEY, `state_id` int NOT NULL, `name` varchar(80) NOT NULL, UNIQUE KEY `state_name` (`state_id`, `name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $this->db->query("CREATE TABLE IF NOT EXISTS `geo_talukas` (`id` int NOT NULL AUTO_INCREMENT PRIMARY KEY, `district_id` int NOT NULL, `name` varchar(80) NOT NULL, UNIQUE KEY `district_name` (`district_id`, `name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $this->db->query("CREATE TABLE IF NOT EXISTS `geo_cities` (`id` int NOT NULL AUTO_INCREMENT PRIMARY KEY, `taluka_id` int NOT NULL, `name` varchar(120) NOT NULL, `pincode` varchar(10) DEFAULT NULL, `created_by` int DEFAULT NULL, UNIQUE KEY `taluka_name` (`taluka_id`, `name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $this->seed();
        }
        $this->hms_settings_model->schema_done('geo', 1);
    }

    // ------------------------------------------------------------------ lists

    public function states()
    {
        return array_column($this->db->select('name')->order_by('name')->get('geo_states')->result_array(), 'name');
    }

    public function districts($state)
    {
        return array_column($this->db->select('d.name')->from('geo_districts d')->join('geo_states s', 's.id = d.state_id')
            ->where('s.name', $state)->order_by('d.name')->get()->result_array(), 'name');
    }

    public function talukas($state, $district)
    {
        return array_column($this->db->select('t.name')->from('geo_talukas t')->join('geo_districts d', 'd.id = t.district_id')->join('geo_states s', 's.id = d.state_id')
            ->where('s.name', $state)->where('d.name', $district)->order_by('t.name')->get()->result_array(), 'name');
    }

    /** Cities / villages of a taluka (name + pincode), optionally filtered by typed text. */
    public function cities($state, $district, $taluka, $q = '')
    {
        $this->db->select('c.name, c.pincode')->from('geo_cities c')->join('geo_talukas t', 't.id = c.taluka_id')
            ->join('geo_districts d', 'd.id = t.district_id')->join('geo_states s', 's.id = d.state_id')
            ->where('s.name', $state)->where('d.name', $district)->where('t.name', $taluka);
        if ($q !== '') {
            $this->db->like('c.name', $q);
        }
        return $this->db->order_by('c.name')->limit(300)->get()->result_array();
    }

    // ------------------------------------------------------------------ save

    private static function clean($v, $len)
    {
        $v = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $v)));
        // Title-case typed names so "AKOT" and "akot" become one entry.
        if ($v !== '' && ($v === mb_strtoupper($v) || $v === mb_strtolower($v))) {
            $v = mb_convert_case($v, MB_CASE_TITLE, 'UTF-8');
        }
        return mb_substr($v, 0, $len);
    }

    /**
     * Address fields posted with a patient form. Only fields that were posted are returned, so a
     * form without the address boxes never clears them.
     */
    public function patient_post()
    {
        $this->ensure_schema();
        $out = array();
        foreach (self::$patient_fields as $f => $len) {
            if ($this->input->post($f) === null) {
                continue;
            }
            $out[$f] = $f === 'pincode' ? substr(preg_replace('/\D/', '', (string) $this->input->post($f)), 0, 6) : self::clean($this->input->post($f), $len);
            if ($out[$f] === '') {
                $out[$f] = null;
            }
        }
        if ($out) {
            $this->remember($out);
            // The form's address box holds the house / street part; store the full address too.
            $street = trim(strip_tags((string) $this->input->post('address')));
            $out['address_line'] = $street !== '' ? $street : null;
            $out['address']      = self::full_address(array('address' => $street) + $out);
        }
        return $out;
    }

    /** Add any state / district / taluka / city typed in for this patient to the dropdown lists. */
    public function remember(array $a)
    {
        $state = $a['state'] ?? null;
        if (!$state) {
            return;
        }
        $sid = $this->id_of('geo_states', array('name' => $state));
        if (empty($a['district'])) {
            return;
        }
        $did = $this->id_of('geo_districts', array('state_id' => $sid, 'name' => $a['district']));
        if (empty($a['taluka'])) {
            return;
        }
        $tid = $this->id_of('geo_talukas', array('district_id' => $did, 'name' => $a['taluka']));
        if (empty($a['city_village'])) {
            return;
        }
        $c = $this->db->where('taluka_id', $tid)->where('name', $a['city_village'])->get('geo_cities')->row_array();
        if (!$c) {
            $this->db->query("INSERT IGNORE INTO geo_cities (taluka_id, name, pincode, created_by) VALUES (?, ?, ?, ?)",
                array($tid, $a['city_village'], $a['pincode'] ?? null, (int) $this->customlib->getStaffID() ?: null));
        } elseif (empty($c['pincode']) && !empty($a['pincode'])) {
            $this->db->where('id', (int) $c['id'])->update('geo_cities', array('pincode' => $a['pincode']));
        }
    }

    private function id_of($table, array $where)
    {
        $row = $this->db->where($where)->get($table)->row_array();
        if ($row) {
            return (int) $row['id'];
        }
        $cols = implode(', ', array_keys($where));
        $marks = implode(', ', array_fill(0, count($where), '?'));
        $this->db->query("INSERT IGNORE INTO $table ($cols) VALUES ($marks)", array_values($where));
        $row = $this->db->where($where)->get($table)->row_array();
        return $row ? (int) $row['id'] : 0;
    }

    /** "House, City, Taluka, District, State - PIN" for printing. */
    public static function full_address($p)
    {
        $p = (array) $p;
        $parts = array();
        foreach (array('address', 'city_village', 'taluka', 'district', 'state') as $k) {
            $v = trim((string) ($p[$k] ?? ''));
            if ($v !== '' && !in_array(mb_strtolower($v), array_map('mb_strtolower', $parts), true)) {
                $parts[] = $v;
            }
        }
        $s = implode(', ', $parts);
        if (!empty($p['pincode'])) {
            $s .= ' - ' . $p['pincode'];
        }
        return $s;
    }

    public function defaults()
    {
        $this->load->model('hms_settings_model');
        return array(
            'state'    => (string) $this->hms_settings_model->get('default_state', 'Maharashtra'),
            'district' => (string) $this->hms_settings_model->get('default_district', 'Akola'),
        );
    }

    // ------------------------------------------------------------------ seed

    private function seed()
    {
        $states = array('Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chandigarh', 'Chhattisgarh',
            'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jammu and Kashmir', 'Jharkhand',
            'Karnataka', 'Kerala', 'Ladakh', 'Lakshadweep', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland',
            'Odisha', 'Puducherry', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal');
        foreach ($states as $s) {
            $this->db->query("INSERT IGNORE INTO geo_states (name) VALUES (?)", array($s));
        }
        $mh = $this->id_of('geo_states', array('name' => 'Maharashtra'));
        $districts = array('Ahilyanagar', 'Akola', 'Amravati', 'Beed', 'Bhandara', 'Buldhana', 'Chandrapur', 'Chhatrapati Sambhajinagar', 'Dharashiv',
            'Dhule', 'Gadchiroli', 'Gondia', 'Hingoli', 'Jalgaon', 'Jalna', 'Kolhapur', 'Latur', 'Mumbai City', 'Mumbai Suburban', 'Nagpur', 'Nanded',
            'Nandurbar', 'Nashik', 'Palghar', 'Parbhani', 'Pune', 'Raigad', 'Ratnagiri', 'Sangli', 'Satara', 'Sindhudurg', 'Solapur', 'Thane',
            'Wardha', 'Washim', 'Yavatmal');
        foreach ($districts as $d) {
            $this->db->query("INSERT IGNORE INTO geo_districts (state_id, name) VALUES (?, ?)", array($mh, $d));
        }
        $talukas = array(
            'Akola'    => array('Akola', 'Akot', 'Balapur', 'Barshitakli', 'Murtizapur', 'Patur', 'Telhara'),
            'Washim'   => array('Washim', 'Malegaon', 'Risod', 'Mangrulpir', 'Karanja', 'Manora'),
            'Buldhana' => array('Buldhana', 'Chikhli', 'Deulgaon Raja', 'Jalgaon Jamod', 'Sangrampur', 'Malkapur', 'Motala', 'Nandura', 'Khamgaon', 'Shegaon', 'Mehkar', 'Sindkhed Raja', 'Lonar'),
            'Amravati' => array('Amravati', 'Bhatkuli', 'Nandgaon Khandeshwar', 'Dharni', 'Chikhaldara', 'Achalpur', 'Chandurbazar', 'Morshi', 'Warud', 'Daryapur', 'Anjangaon Surji', 'Chandur Railway', 'Dhamangaon Railway', 'Teosa'),
            'Yavatmal' => array('Yavatmal', 'Arni', 'Babhulgaon', 'Kalamb', 'Darwha', 'Digras', 'Ner', 'Pusad', 'Umarkhed', 'Mahagaon', 'Kelapur', 'Ralegaon', 'Ghatanji', 'Wani', 'Maregaon', 'Zari Jamani'),
            'Hingoli'  => array('Hingoli', 'Kalamnuri', 'Sengaon', 'Aundha Nagnath', 'Basmat'),
            'Jalna'    => array('Jalna', 'Ambad', 'Bhokardan', 'Badnapur', 'Ghansawangi', 'Partur', 'Mantha', 'Jafrabad'),
        );
        foreach ($talukas as $district => $list) {
            $did = $this->id_of('geo_districts', array('state_id' => $mh, 'name' => $district));
            foreach ($list as $t) {
                $tid = $this->id_of('geo_talukas', array('district_id' => $did, 'name' => $t));
                // The taluka's main town, so the City / village box is not empty on day one.
                $this->db->query("INSERT IGNORE INTO geo_cities (taluka_id, name) VALUES (?, ?)", array($tid, $t));
            }
        }
    }
}
