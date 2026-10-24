<?php
if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * IPD Case Sheet: admission + consent, doctor's notes, daily nursing chart (vitals / IV fluids / I-O),
 * medication administration chart, and the discharge summary + discharge prescription built from them.
 * Tables are created on first use (ensure_schema), like Smartrx_model.
 */
class Casesheet_model extends CI_Model
{
    /** Nursing-chart vital slots, as on the ward sheet. */
    public static $slots = array('08:00', '12:00', '14:00', '18:00', '22:00', '06:00');
    public static $vitals = array(
        'spo2'   => 'SpO2 (%)',
        'temp'   => 'Temp (°F)',
        'hr'     => 'HR / Pulse',
        'rr'     => 'RR',
        'bp'     => 'BP',
        'cvp'    => 'CVP',
        'motion' => 'Motion Passed',
        'avpu'   => 'A.V.P.U. Scale',
    );
    public static $io_shifts = array('morning' => 'Morning', 'evening' => 'Evening', 'night' => 'Night');
    public static $io_fields = array('ivf' => 'IVF', 'oral' => 'RT/Oral', 'uo' => 'U.O.', 'rta' => 'R.T.A.', 'drain1' => 'Drain 1', 'drain2' => 'Drain 2');
    /** Default administration times per frequency (medication chart rows). */
    public static $freq_slots = array(
        'OD'   => array('08:00'),
        'BD'   => array('08:00', '20:00'),
        'TDS'  => array('08:00', '14:00', '20:00'),
        'QID'  => array('06:00', '12:00', '18:00', '22:00'),
        'Q6H'  => array('06:00', '12:00', '18:00', '00:00'),
        'Q8H'  => array('06:00', '14:00', '22:00'),
        'HS'   => array('22:00'),
        'STAT' => array(),
        'SOS'  => array(),
    );
    /** Discharge Rx: frequency → dose pattern printed as M-A-N. */
    public static $freq_pattern = array('OD' => '1-0-0', 'BD' => '1-0-1', 'TDS' => '1-1-1', 'QID' => '1-1-1-1', 'HS' => '0-0-1', 'SOS' => 'SOS', 'STAT' => 'STAT');

    public function ensure_schema()
    {
        static $done = false;
        if ($done || $this->session->userdata('casesheet_schema_v1')) {
            return;
        }
        $this->db->query("CREATE TABLE IF NOT EXISTS `ipd_casesheet` (
            `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `ipd_id` int NOT NULL,
            `admitted_by_name` varchar(150) DEFAULT NULL,
            `admitted_by_relation` varchar(60) DEFAULT NULL,
            `admitted_by_phone` varchar(30) DEFAULT NULL,
            `consent_language` varchar(10) NOT NULL DEFAULT 'mr',
            `consent_signed_by` varchar(150) DEFAULT NULL,
            `consent_witness1` varchar(150) DEFAULT NULL,
            `consent_witness2` varchar(150) DEFAULT NULL,
            `consent_at` datetime DEFAULT NULL,
            `chief_complaints` text,
            `history` text,
            `examination` text,
            `admission_vitals` text,
            `provisional_diagnosis` text,
            `lab_findings` text,
            `initial_advice` text,
            `final_diagnosis` text,
            `procedures` text,
            `course_in_hospital` text,
            `condition_at_discharge` text,
            `discharge_advice` text,
            `diet_advice` varchar(255) DEFAULT NULL,
            `follow_up_date` date DEFAULT NULL,
            `follow_up_note` varchar(255) DEFAULT NULL,
            `emergency_note` text,
            `discharge_at` datetime DEFAULT NULL,
            `summary_by` int DEFAULT NULL,
            `discharge_rx` mediumtext,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `ipd_casesheet_ipd` (`ipd_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS `ipd_nursing_chart` (
            `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `ipd_id` int NOT NULL,
            `chart_date` date NOT NULL,
            `special_instructions` text,
            `vitals` mediumtext,
            `investigations` text,
            `stat_drugs` mediumtext,
            `iv_fluids` mediumtext,
            `io` mediumtext,
            `insulin` mediumtext,
            `updated_by` int DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `ipd_nursing_chart_day` (`ipd_id`, `chart_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS `ipd_med_chart` (
            `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `ipd_id` int NOT NULL,
            `drug` varchar(150) NOT NULL,
            `dose` varchar(60) DEFAULT NULL,
            `route` varchar(30) DEFAULT NULL,
            `frequency` varchar(20) DEFAULT NULL,
            `slots` varchar(120) DEFAULT NULL,
            `start_date` date NOT NULL,
            `stop_date` date DEFAULT NULL,
            `instruction` varchar(255) DEFAULT NULL,
            `ordered_by` int DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `ipd_med_chart_ipd` (`ipd_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS `ipd_med_admin` (
            `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `med_id` int NOT NULL,
            `admin_date` date NOT NULL,
            `slot` varchar(5) NOT NULL,
            `given_by` int DEFAULT NULL,
            `given_at` datetime DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `ipd_med_admin_dose` (`med_id`, `admin_date`, `slot`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
        $this->session->set_userdata('casesheet_schema_v1', true);
    }

    // ------------------------------------------------------------------ admission context
    /** Patient + admission header used by every screen and print. */
    public function context($ipd_id)
    {
        $row = $this->db->query("SELECT ip.id ipd_id, ip.patient_id, ip.case_reference_id, ip.date admission_date, ip.discharged,
                ip.cons_doctor, ip.symptoms, ip.known_allergies ipd_allergies, ip.height, ip.weight, ip.pulse, ip.temperature, ip.bp,
                p.patient_name, p.gender, p.age, p.month, p.day, p.dob, p.mobileno, p.address, p.guardian_name, p.known_allergies, p.blood_bank_product_id,
                s.name doctor_name, s.surname doctor_surname, s.qualification doctor_qualification
            FROM ipd_details ip
            JOIN patients p ON p.id = ip.patient_id
            LEFT JOIN staff s ON s.id = ip.cons_doctor
            WHERE ip.id = ?", array((int) $ipd_id))->row_array();
        if (!$row) {
            return null;
        }
        $bed = $this->db->query("SELECT b.name bed_name, bg.name bed_group, f.name floor_name
            FROM patient_bed_history h
            JOIN bed b ON b.id = h.bed_id
            LEFT JOIN bed_group bg ON bg.id = h.bed_group_id
            LEFT JOIN floor f ON f.id = bg.floor
            WHERE h.case_reference_id = ? ORDER BY h.is_active = 'yes' DESC, h.id DESC LIMIT 1",
            array((int) $row['case_reference_id']))->row_array();
        $row['bed'] = $bed ? trim($bed['bed_name'] . ($bed['bed_group'] ? ' — ' . $bed['bed_group'] : '') . ($bed['floor_name'] ? ' — ' . $bed['floor_name'] : '')) : '';
        $row['doctor'] = trim('Dr. ' . $row['doctor_name'] . ' ' . $row['doctor_surname']);
        if (!$row['doctor_name']) {
            $row['doctor'] = '';
        }
        $card = $this->db->query("SELECT * FROM discharge_card WHERE ipd_details_id = ? ORDER BY id DESC LIMIT 1", array((int) $ipd_id))->row_array();
        $row['discharge_card'] = $card ?: null;
        $row['blood_group_name'] = '';
        if (!empty($row['blood_bank_product_id'])) {
            $bg = $this->db->query("SELECT name FROM blood_bank_products WHERE id = ?", array((int) $row['blood_bank_product_id']))->row_array();
            $row['blood_group_name'] = $bg ? $bg['name'] : '';
        }
        $allergies = array_filter(array_map('trim', preg_split('/[,;\n]+/', $row['known_allergies'] . ',' . $row['ipd_allergies'])));
        $row['allergies'] = implode(', ', array_unique($allergies));
        // "No known drug allergy", "NKDA", "Nil" … are recorded answers, not allergies: show them without the red alert.
        $row['has_allergy'] = (bool) array_filter($allergies, function ($a) {
            return !preg_match('/^(no\b|nil\b|none\b|nkda\b|n\/a\b|-)/i', $a);
        });
        return $row;
    }

    // ------------------------------------------------------------------ case sheet (admission, notes, discharge)
    public function sheet($ipd_id)
    {
        $row = $this->db->get_where('ipd_casesheet', array('ipd_id' => (int) $ipd_id))->row_array();
        if (!$row) {
            $row = array_fill_keys($this->db->list_fields('ipd_casesheet'), null);
            $row['ipd_id'] = (int) $ipd_id;
            $row['consent_language'] = 'mr';
        }
        $row['discharge_rx'] = $this->decode($row['discharge_rx']);
        $row['admission_vitals'] = $this->decode($row['admission_vitals']);
        return $row;
    }

    public function save_sheet($ipd_id, array $fields)
    {
        $allowed = array_diff($this->db->list_fields('ipd_casesheet'), array('id', 'ipd_id', 'created_at', 'updated_at'));
        $data = array();
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            } elseif (is_string($v)) {
                $v = trim($v);
                if ($v === '') {
                    $v = null;
                }
            }
            $data[$k] = $v;
        }
        if (!$data) {
            return;
        }
        $exists = $this->db->get_where('ipd_casesheet', array('ipd_id' => (int) $ipd_id))->row_array();
        if ($exists) {
            $this->db->where('id', $exists['id'])->update('ipd_casesheet', $data);
        } else {
            $data['ipd_id'] = (int) $ipd_id;
            $this->db->insert('ipd_casesheet', $data);
        }
    }

    // ------------------------------------------------------------------ nursing chart
    public function chart_dates($ipd_id)
    {
        return array_column($this->db->query("SELECT chart_date FROM ipd_nursing_chart WHERE ipd_id = ? ORDER BY chart_date",
            array((int) $ipd_id))->result_array(), 'chart_date');
    }

    public function chart($ipd_id, $date)
    {
        $row = $this->db->get_where('ipd_nursing_chart', array('ipd_id' => (int) $ipd_id, 'chart_date' => $date))->row_array();
        if (!$row) {
            $row = array('ipd_id' => (int) $ipd_id, 'chart_date' => $date, 'special_instructions' => '', 'investigations' => '',
                'vitals' => null, 'stat_drugs' => null, 'iv_fluids' => null, 'io' => null, 'insulin' => null);
        }
        foreach (array('vitals', 'io') as $k) {
            $row[$k] = $this->decode($row[$k]);
        }
        foreach (array('stat_drugs', 'iv_fluids', 'insulin') as $k) {
            $row[$k] = $this->decode($row[$k]);
        }
        return $row;
    }

    public function all_charts($ipd_id)
    {
        $out = array();
        foreach ($this->chart_dates($ipd_id) as $d) {
            $out[$d] = $this->chart($ipd_id, $d);
        }
        return $out;
    }

    public function save_chart($ipd_id, $date, array $in, $staff_id)
    {
        $vitals = array();
        foreach (self::$slots as $slot) {
            foreach (array_keys(self::$vitals) as $v) {
                $val = isset($in['vitals'][$slot][$v]) ? trim((string) $in['vitals'][$slot][$v]) : '';
                if ($val !== '') {
                    $vitals[$slot][$v] = mb_substr($val, 0, 20);
                }
            }
        }
        $io = array();
        foreach (array_keys(self::$io_shifts) as $sh) {
            foreach (array_keys(self::$io_fields) as $f) {
                $val = isset($in['io'][$sh][$f]) ? trim((string) $in['io'][$sh][$f]) : '';
                if ($val !== '') {
                    $io[$sh][$f] = mb_substr($val, 0, 20);
                }
            }
        }
        $data = array(
            'special_instructions' => trim((string) (isset($in['special_instructions']) ? $in['special_instructions'] : '')),
            'investigations'       => trim((string) (isset($in['investigations']) ? $in['investigations'] : '')),
            'vitals'               => json_encode((object) $vitals),
            'io'                   => json_encode((object) $io),
            'stat_drugs'           => json_encode($this->rows(isset($in['stat_drugs']) ? $in['stat_drugs'] : array(), array('drug', 'dose', 'time'))),
            'iv_fluids'            => json_encode($this->rows(isset($in['iv_fluids']) ? $in['iv_fluids'] : array(), array('fluid', 'quantity', 'flowrate', 'started', 'finished'))),
            'insulin'              => json_encode($this->rows(isset($in['insulin']) ? $in['insulin'] : array(), array('time', 'rbs', 'dose'))),
            'updated_by'           => $staff_id ?: null,
        );
        $exists = $this->db->get_where('ipd_nursing_chart', array('ipd_id' => (int) $ipd_id, 'chart_date' => $date))->row_array();
        if ($exists) {
            $this->db->where('id', $exists['id'])->update('ipd_nursing_chart', $data);
        } else {
            $this->db->insert('ipd_nursing_chart', $data + array('ipd_id' => (int) $ipd_id, 'chart_date' => $date));
        }
    }

    /** Totals for one I-O shift: intake = IVF + oral (ml); output = UO + RTA + drains when numeric, else text joined. */
    public static function io_totals(array $shift)
    {
        $num = function ($v) {
            return preg_match('/^\s*(\d+(?:\.\d+)?)/', (string) $v, $m) ? (float) $m[1] : null;
        };
        $sum = function (array $keys) use ($shift, $num) {
            $t = 0;
            $any = false;
            foreach ($keys as $k) {
                if (isset($shift[$k]) && $num($shift[$k]) !== null && !preg_match('/time|x/i', $shift[$k])) {
                    $t += $num($shift[$k]);
                    $any = true;
                }
            }
            return $any ? $t . ' ml' : '';
        };
        $in = $sum(array('ivf', 'oral'));
        $out = $sum(array('uo', 'rta', 'drain1', 'drain2'));
        if ($out === '' && !empty($shift['uo'])) {
            $out = $shift['uo'];
        }
        return array('in' => $in, 'out' => $out);
    }

    // ------------------------------------------------------------------ medication chart
    public function meds($ipd_id)
    {
        $meds = $this->db->query("SELECT m.*, s.name staff_name, s.surname staff_surname FROM ipd_med_chart m
            LEFT JOIN staff s ON s.id = m.ordered_by WHERE m.ipd_id = ? ORDER BY m.start_date, m.id", array((int) $ipd_id))->result_array();
        if (!$meds) {
            return array();
        }
        $ids = array_column($meds, 'id');
        $given = array();
        foreach ($this->db->where_in('med_id', $ids)->get('ipd_med_admin')->result_array() as $g) {
            $given[$g['med_id']][$g['admin_date']][$g['slot']] = $g;
        }
        foreach ($meds as &$m) {
            $m['slot_list'] = $m['slots'] !== null && $m['slots'] !== '' ? explode(',', $m['slots']) : array();
            $m['given'] = isset($given[$m['id']]) ? $given[$m['id']] : array();
        }
        return $meds;
    }

    public function add_med($ipd_id, array $in, $staff_id)
    {
        $freq = strtoupper(trim((string) $in['frequency']));
        $slots = isset($in['slots']) && trim($in['slots']) !== '' ? $this->clean_slots($in['slots'])
            : (isset(self::$freq_slots[$freq]) ? self::$freq_slots[$freq] : array());
        $this->db->insert('ipd_med_chart', array(
            'ipd_id'      => (int) $ipd_id,
            'drug'        => mb_substr(trim($in['drug']), 0, 150),
            'dose'        => mb_substr(trim((string) $in['dose']), 0, 60),
            'route'       => mb_substr(trim((string) $in['route']), 0, 30),
            'frequency'   => mb_substr($freq, 0, 20),
            'slots'       => implode(',', $slots),
            'start_date'  => $in['start_date'],
            'stop_date'   => !empty($in['stop_date']) ? $in['stop_date'] : null,
            'instruction' => mb_substr(trim((string) $in['instruction']), 0, 255),
            'ordered_by'  => $staff_id ?: null,
        ));
        return $this->db->insert_id();
    }

    public function stop_med($ipd_id, $med_id, $date)
    {
        $this->db->where(array('id' => (int) $med_id, 'ipd_id' => (int) $ipd_id))->update('ipd_med_chart', array('stop_date' => $date));
    }

    public function delete_med($ipd_id, $med_id)
    {
        $med = $this->db->get_where('ipd_med_chart', array('id' => (int) $med_id, 'ipd_id' => (int) $ipd_id))->row_array();
        if ($med) {
            $this->db->delete('ipd_med_admin', array('med_id' => $med['id']));
            $this->db->delete('ipd_med_chart', array('id' => $med['id']));
        }
    }

    /** Toggle one dose as given / not given. Returns the new state (row or null). */
    public function toggle_dose($ipd_id, $med_id, $date, $slot, $staff_id)
    {
        $med = $this->db->get_where('ipd_med_chart', array('id' => (int) $med_id, 'ipd_id' => (int) $ipd_id))->row_array();
        if (!$med) {
            return false;
        }
        $key = array('med_id' => $med['id'], 'admin_date' => $date, 'slot' => $slot);
        $row = $this->db->get_where('ipd_med_admin', $key)->row_array();
        if ($row) {
            $this->db->delete('ipd_med_admin', array('id' => $row['id']));
            return null;
        }
        $this->db->insert('ipd_med_admin', $key + array('given_by' => $staff_id ?: null, 'given_at' => date('Y-m-d H:i:s')));
        return $this->db->get_where('ipd_med_admin', $key)->row_array();
    }

    /** Dates covered by the medication chart: admission → discharge (or today), capped to the latest 10 days on screen. */
    public function chart_range($ctx, $sheet)
    {
        $start = substr($ctx['admission_date'], 0, 10);
        $end = $sheet['discharge_at'] ? substr($sheet['discharge_at'], 0, 10)
            : (!empty($ctx['discharge_card']['discharge_date']) ? substr($ctx['discharge_card']['discharge_date'], 0, 10) : date('Y-m-d'));
        if ($end < $start) {
            $end = $start;
        }
        $dates = array();
        for ($d = $start; $d <= $end; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $dates[] = $d;
        }
        return $dates;
    }

    // ------------------------------------------------------------------ helpers
    /** Compact age for case-sheet headers: "20 Y", "3 Y 4 M", "8 M", "12 D" (from DOB when known). */
    public static function short_age(array $ctx)
    {
        if (!empty($ctx['dob']) && $ctx['dob'] !== '0000-00-00' && strtotime($ctx['dob'])) {
            $diff = (new DateTime($ctx['dob']))->diff(new DateTime(substr($ctx['admission_date'], 0, 10)));
            $y = $diff->y;
            $m = $diff->m;
            $d = $diff->d;
        } else {
            $y = (int) $ctx['age'];
            $m = (int) $ctx['month'];
            $d = (int) $ctx['day'];
        }
        if ($y >= 5) {
            return $y . ' Y';
        }
        if ($y > 0) {
            return $y . ' Y' . ($m ? ' ' . $m . ' M' : '');
        }
        return $m ? $m . ' M' : $d . ' D';
    }

    public function clean_slots($s)
    {
        $out = array();
        foreach (preg_split('/[\s,]+/', (string) $s) as $t) {
            if (preg_match('/^(\d{1,2})(?::?(\d{2}))?$/', $t, $m) && (int) $m[1] < 24) {
                $out[] = sprintf('%02d:%s', $m[1], isset($m[2]) ? $m[2] : '00');
            }
        }
        return array_values(array_unique($out));
    }

    private function rows($rows, array $keys)
    {
        $out = array();
        foreach ((array) $rows as $r) {
            $clean = array();
            $any = false;
            foreach ($keys as $k) {
                $clean[$k] = isset($r[$k]) ? mb_substr(trim((string) $r[$k]), 0, 150) : '';
                $any = $any || $clean[$k] !== '';
            }
            if ($any) {
                $out[] = $clean;
            }
        }
        return $out;
    }

    public function clean_rx($rows)
    {
        return $this->rows($rows, array('drug', 'dose', 'frequency', 'timing', 'duration', 'instruction'));
    }

    private function decode($json)
    {
        $v = $json ? json_decode($json, true) : null;
        return is_array($v) ? $v : array();
    }

    /** Hospital letterhead: configured print header image for the given print_setting, or the hospital's name/address. */
    public function letterhead($setting_for)
    {
        $print = $setting_for ? $this->db->get_where('print_setting', array('setting_for' => $setting_for, 'is_active' => 'yes'))->row_array() : null;
        $sch = $this->db->query("SELECT name, address, phone, email, image FROM sch_settings LIMIT 1")->row_array();
        return array(
            'header_image' => $print && $print['print_header'] && is_file(FCPATH . $print['print_header']) ? $print['print_header'] : '',
            'footer'       => $print ? $print['print_footer'] : '',
            'hospital'     => $sch ?: array('name' => '', 'address' => '', 'phone' => '', 'email' => '', 'image' => ''),
        );
    }
}
