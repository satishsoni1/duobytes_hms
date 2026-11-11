<?php defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('medical_reg_suffix')) {
    /**
     * "<br><small>Reg. No. MMC 2012/03/1234 (Maharashtra Medical Council)</small>" for a doctor,
     * looked up by Staff ID; '' when the staff member has no registration number on record.
     */
    function medical_reg_suffix($employee_id)
    {
        static $cache = array();
        $employee_id = (string) $employee_id;
        if ($employee_id === '') {
            return '';
        }
        if (!array_key_exists($employee_id, $cache)) {
            $CI =& get_instance();
            $cache[$employee_id] = '';
            if ($CI->db->field_exists('medical_reg_no', 'staff')) {
                $r = $CI->db->select('medical_reg_no, medical_council')->where('employee_id', $employee_id)->get('staff')->row_array();
                if ($r && trim((string) $r['medical_reg_no']) !== '') {
                    $cache[$employee_id] = '<br><small>Reg. No. ' . html_escape($r['medical_reg_no'])
                        . ($r['medical_council'] ? ' (' . html_escape($r['medical_council']) . ')' : '') . '</small>';
                }
            }
        }
        return $cache[$employee_id];
    }
}
