<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * OPD services/charges and bill summary for the doctor app.
 *
 * Charges are written to patient_charges exactly like the web "add charge" form:
 *   apply_charge = standard charge x qty, less discount;  tax = tax %;  amount = apply_charge + tax.
 * Payments are read from transactions (type payment / refund). The app never records payments.
 */
class Mobilebilling_model extends CI_Model
{
    const MAX_QTY = 100;

    /** Hospital service list (charges master) with tax %, for picking services offline. */
    public function charges_master()
    {
        $rows = $this->db->query("SELECT c.id, c.name, cc.name category, ctm.charge_type type, c.standard_charge, COALESCE(tc.percentage, 0) tax, cu.unit
            FROM charges c
            LEFT JOIN charge_categories cc ON cc.id = c.charge_category_id
            LEFT JOIN charge_type_master ctm ON ctm.id = cc.charge_type_id
            LEFT JOIN tax_category tc ON tc.id = c.tax_category_id
            LEFT JOIN charge_units cu ON cu.id = c.charge_unit_id
            ORDER BY ctm.charge_type, cc.name, c.name")->result_array();
        foreach ($rows as &$r) {
            $r['id']              = (int) $r['id'];
            $r['standard_charge'] = (float) $r['standard_charge'];
            $r['tax']             = (float) $r['tax'];
        }
        return $rows;
    }

    /**
     * Charges and payments per patient. With $since, only patients with an open or recent case
     * (bills change often and charges can be deleted on the web, so the phone replaces them per patient).
     */
    public function for_patients($in, $since = null)
    {
        $ids = array_map('intval', explode(',', $in));
        if ($since !== null) {
            $ids = array();
            foreach ($this->db->query("SELECT DISTINCT patient_id FROM opd_details WHERE patient_id IN ($in) AND (discharged = 'no' OR updated_at > ? OR created_at >= NOW() - INTERVAL 30 DAY)
                UNION SELECT DISTINCT patient_id FROM ipd_details WHERE patient_id IN ($in) AND (discharged = 'no' OR updated_at > ? OR date >= NOW() - INTERVAL 30 DAY)", array($since, $since))->result_array() as $r) {
                $ids[] = (int) $r['patient_id'];
            }
        }
        if (empty($ids)) {
            return array();
        }
        $list = implode(',', $ids);
        $out  = array();
        foreach ($ids as $pid) {
            $out[$pid] = array('patient_id' => $pid, 'charges' => array(), 'payments' => array());
        }
        $charges = $this->db->query("SELECT pc.id, COALESCE(od.patient_id, ip.patient_id) patient_id, pc.opd_id, pc.ipd_id, pc.date, ch.name charge_name,
                cc.name category, pc.qty, pc.standard_charge, pc.apply_charge, pc.tax, pc.amount, pc.note
            FROM patient_charges pc
            LEFT JOIN opd_details od ON od.id = pc.opd_id LEFT JOIN ipd_details ip ON ip.id = pc.ipd_id
            LEFT JOIN charges ch ON ch.id = pc.charge_id LEFT JOIN charge_categories cc ON cc.id = ch.charge_category_id
            WHERE od.patient_id IN ($list) OR ip.patient_id IN ($list) ORDER BY pc.date DESC, pc.id DESC")->result_array();
        foreach ($charges as $c) {
            $pid = (int) $c['patient_id'];
            if (isset($out[$pid])) {
                $out[$pid]['charges'][] = $this->typed_charge($c);
            }
        }
        $payments = $this->db->query("SELECT t.id, t.patient_id, t.opd_id, t.ipd_id, t.type, t.amount, t.payment_mode, t.payment_date
            FROM transactions t WHERE t.patient_id IN ($list) AND t.type IN ('payment', 'refund') AND (t.opd_id IS NOT NULL OR t.ipd_id IS NOT NULL)
            ORDER BY t.payment_date DESC")->result_array();
        foreach ($payments as $p) {
            $pid = (int) $p['patient_id'];
            if (isset($out[$pid])) {
                $p['id']     = (int) $p['id'];
                $p['opd_id'] = $p['opd_id'] ? (int) $p['opd_id'] : null;
                $p['ipd_id'] = $p['ipd_id'] ? (int) $p['ipd_id'] : null;
                $p['amount'] = (float) $p['amount'];
                unset($p['patient_id']);
                $out[$pid]['payments'][] = $p;
            }
        }
        return array_values($out);
    }

    private function typed_charge(array $c)
    {
        foreach (array('id', 'qty') as $k) {
            $c[$k] = (int) $c[$k];
        }
        foreach (array('standard_charge', 'apply_charge', 'tax', 'amount') as $k) {
            $c[$k] = (float) $c[$k];
        }
        $c['opd_id'] = $c['opd_id'] ? (int) $c['opd_id'] : null;
        $c['ipd_id'] = $c['ipd_id'] ? (int) $c['ipd_id'] : null;
        unset($c['patient_id']);
        return $c;
    }

    /**
     * Add a service to an OPD case (opd_details) or IPD admission. Returns the new charge row or an error string.
     */
    public function add_charge($kind, $case_id, $charge_id, $qty, $discount, $note, $date, $staff_id = null)
    {
        $charge = $this->db->query("SELECT c.id, c.standard_charge, COALESCE(tc.percentage, 0) tax FROM charges c
            LEFT JOIN tax_category tc ON tc.id = c.tax_category_id WHERE c.id = ?", array((int) $charge_id))->row_array();
        if (!$charge) {
            return 'This service is no longer in the hospital charge list. Sync and pick it again.';
        }
        $qty      = max(1, min(self::MAX_QTY, (int) $qty));
        $discount = max(0, min(100, (float) $discount));
        $standard = (float) $charge['standard_charge'];
        $apply    = round($standard * $qty * (1 - $discount / 100), 2);
        $tax      = (float) $charge['tax'];
        $amount   = round($apply + $apply * $tax / 100, 2);

        $row = array(
            'date'                => $date,
            'opd_id'              => $kind === 'opd' ? (int) $case_id : null,
            'ipd_id'              => $kind === 'ipd' ? (int) $case_id : null,
            'qty'                 => $qty,
            'charge_id'           => (int) $charge_id,
            'standard_charge'     => $standard,
            'tpa_charge'          => 0,
            'discount_percentage' => $discount,
            'tax'                 => $tax,
            'apply_charge'        => $apply,
            'amount'              => $amount,
            'note'                => mb_substr(strip_tags((string) $note), 0, 500),
        );
        $this->db->insert('patient_charges', $row);
        $id = (int) $this->db->insert_id();
        if (!$id) {
            return null;
        }
        // Discount approval (same rules as the web): a held-back discount is not applied.
        $this->load->model('discountapproval_model');
        $this->discountapproval_model->gate('charge', $id, array('requested_by' => $staff_id, 'calc' => 'mobile', 'source' => 'mobile', 'reason' => 'Added in the mobile app'));
        $c = $this->db->query("SELECT pc.id, pc.opd_id, pc.ipd_id, pc.date, ch.name charge_name, cc.name category, pc.qty, pc.standard_charge, pc.apply_charge,
                pc.tax, pc.amount, pc.note, NULL patient_id
            FROM patient_charges pc LEFT JOIN charges ch ON ch.id = pc.charge_id LEFT JOIN charge_categories cc ON cc.id = ch.charge_category_id
            WHERE pc.id = ?", array($id))->row_array();
        return $this->typed_charge($c);
    }

    // ------------------------------------------------------------------ per-doctor app settings (print layout, ...)
    public function ensure_schema()
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS `mobile_staff_settings` (
            `staff_id` int NOT NULL,
            `setting_key` varchar(50) NOT NULL,
            `value` mediumtext NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`staff_id`, `setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function staff_settings($staff_id)
    {
        $out = array();
        foreach ($this->db->query("SELECT setting_key, value FROM mobile_staff_settings WHERE staff_id = ?", array((int) $staff_id))->result_array() as $r) {
            $out[$r['setting_key']] = json_decode($r['value'], true);
        }
        return $out;
    }

    public function save_staff_setting($staff_id, $key, $value)
    {
        $this->db->query("REPLACE INTO mobile_staff_settings (staff_id, setting_key, value, updated_at) VALUES (?, ?, ?, NOW())",
            array((int) $staff_id, (string) $key, json_encode($value, JSON_UNESCAPED_UNICODE)));
    }

    /** Hospital's OPD prescription letterhead image from the web print settings, if any. */
    public function prescription_header_path()
    {
        $r = $this->db->query("SELECT print_header FROM print_setting WHERE setting_for = 'opdpre' AND is_active = 'yes' LIMIT 1")->row_array();
        $rel = $r ? trim((string) $r['print_header']) : '';
        if ($rel === '') {
            return null;
        }
        $full = realpath(FCPATH . ltrim($rel, './'));
        $root = realpath(FCPATH . 'uploads');
        return ($full && $root && strpos($full, $root . DIRECTORY_SEPARATOR) === 0 && is_file($full)) ? $full : null;
    }
}
