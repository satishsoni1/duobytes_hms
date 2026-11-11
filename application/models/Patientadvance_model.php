<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Patient advance (deposit) for OPD / IPD.
 *
 * An advance is collected with a receipt (ADV-00001) and kept as a balance for the patient.
 * It is settled later against an OPD visit's or IPD admission's charges: settling writes a
 * normal payment row in `transactions` (payment mode "Advance adjustment") on that case, so the
 * existing bill, balance and discharge screens see it as paid. Unused balance can be refunded.
 * A settlement can be reversed (its payment row is removed and the balance comes back).
 *
 * Cash is counted when the advance is collected (Advance register), and the case shows it as paid
 * when settled. Income reports that list `transactions` show "Advance adjustment" rows on the
 * settlement date; filter that payment mode out when counting cash received.
 */
class Patientadvance_model extends MY_Model
{
    const SETTLE_MODE = 'Advance adjustment';

    /** Payments on the case's own charges, not on its pharmacy / lab / blood bank / ambulance bills. */
    /** A settlement counts only while its payment row exists (it may be deleted from the IPD/OPD payment screen). */
    const LIVE = "(e.entry_type <> 'settle' OR EXISTS (SELECT 1 FROM transactions t WHERE t.id = e.transaction_id))";

    const CASE_ONLY = 't.pharmacy_bill_basic_id IS NULL AND t.pathology_billing_id IS NULL AND t.radiology_billing_id IS NULL AND t.blood_issue_id IS NULL AND t.ambulance_call_id IS NULL';

    public static $purposes = array('ipd' => 'IPD admission / surgery', 'opd' => 'OPD / procedures', 'general' => 'General');

    public function ensure_schema()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $this->load->model('hms_settings_model');
        if (!$this->db->table_exists('patient_advances')) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `patient_advances` (
                `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `receipt_no` varchar(30) DEFAULT NULL,
                `patient_id` int NOT NULL,
                `purpose` varchar(10) NOT NULL DEFAULT 'general',
                `opd_id` int DEFAULT NULL,
                `ipd_id` int DEFAULT NULL,
                `amount` decimal(12,2) NOT NULL,
                `payment_mode` varchar(40) NOT NULL,
                `reference_no` varchar(100) DEFAULT NULL,
                `payment_date` datetime NOT NULL,
                `note` varchar(500) DEFAULT NULL,
                `received_by` int DEFAULT NULL,
                `is_cancelled` tinyint(1) NOT NULL DEFAULT 0,
                `cancel_note` varchar(300) DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                KEY `patient_id` (`patient_id`),
                KEY `payment_date` (`payment_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        }
        if (!$this->db->table_exists('patient_advance_entries')) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `patient_advance_entries` (
                `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `advance_id` int NOT NULL,
                `entry_type` varchar(10) NOT NULL,
                `amount` decimal(12,2) NOT NULL,
                `opd_id` int DEFAULT NULL,
                `ipd_id` int DEFAULT NULL,
                `case_reference_id` int DEFAULT NULL,
                `transaction_id` int DEFAULT NULL,
                `payment_mode` varchar(40) DEFAULT NULL,
                `note` varchar(500) DEFAULT NULL,
                `created_by` int DEFAULT NULL,
                `entry_date` datetime NOT NULL,
                `is_reversed` tinyint(1) NOT NULL DEFAULT 0,
                `reversed_by` int DEFAULT NULL,
                `reversed_at` datetime DEFAULT NULL,
                KEY `advance_id` (`advance_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        }
        $this->hms_settings_model->ensure_permission('bill', 'patient_advance', 'Patient Advance', array(1, 1, 1, 1));
    }

    // ------------------------------------------------------------------ reads

    private function used_sql()
    {
        return "(SELECT COALESCE(SUM(e.amount), 0) FROM patient_advance_entries e WHERE e.advance_id = a.id AND e.is_reversed = 0 AND " . self::LIVE . ")";
    }

    public function advance($id)
    {
        $a = $this->db->select('a.*, ' . $this->used_sql() . ' as used, p.patient_name, p.mobileno, p.guardian_name, CONCAT(s.name, " ", s.surname) as received_name', false)
            ->from('patient_advances a')->join('patients p', 'p.id = a.patient_id')->join('staff s', 's.id = a.received_by', 'left')
            ->where('a.id', (int) $id)->get()->row_array();
        if ($a) {
            $a['balance'] = $a['is_cancelled'] ? 0 : round((float) $a['amount'] - (float) $a['used'], 2);
            $a['entries'] = $this->db->select('e.*, CONCAT(s.name, " ", s.surname) as created_name', false)
                ->from('patient_advance_entries e')->join('staff s', 's.id = e.created_by', 'left')
                ->where('e.advance_id', (int) $id)->order_by('e.id')->get()->result_array();
        }
        return $a;
    }

    public function for_patient($patient_id)
    {
        $rows = $this->db->select('a.*, ' . $this->used_sql() . ' as used', false)->from('patient_advances a')
            ->where('a.patient_id', (int) $patient_id)->order_by('a.id', 'desc')->get()->result_array();
        foreach ($rows as &$r) {
            $r['balance'] = $r['is_cancelled'] ? 0 : round((float) $r['amount'] - (float) $r['used'], 2);
        }
        return $rows;
    }

    public function patient_balance($patient_id)
    {
        $t = 0;
        foreach ($this->for_patient($patient_id) as $r) {
            $t += $r['balance'];
        }
        return round($t, 2);
    }

    /** Register: $f from, to (Y-m-d), mode, open_only */
    public function register(array $f)
    {
        $this->db->select('a.*, ' . $this->used_sql() . ' as used, p.patient_name, p.mobileno, CONCAT(s.name, " ", s.surname) as received_name', false)
            ->from('patient_advances a')->join('patients p', 'p.id = a.patient_id')->join('staff s', 's.id = a.received_by', 'left');
        if (!empty($f['from'])) {
            $this->db->where('a.payment_date >=', $f['from'] . ' 00:00:00');
        }
        if (!empty($f['to'])) {
            $this->db->where('a.payment_date <=', $f['to'] . ' 23:59:59');
        }
        if (!empty($f['mode'])) {
            $this->db->where('a.payment_mode', $f['mode']);
        }
        if (!empty($f['patient_id'])) {
            $this->db->where('a.patient_id', (int) $f['patient_id']);
        }
        $rows = $this->db->order_by('a.id', 'desc')->limit(2000)->get()->result_array();
        foreach ($rows as $k => &$r) {
            $r['balance'] = $r['is_cancelled'] ? 0 : round((float) $r['amount'] - (float) $r['used'], 2);
            if (!empty($f['open_only']) && $r['balance'] <= 0) {
                unset($rows[$k]);
            }
        }
        return array_values($rows);
    }

    /** Open OPD visits / IPD admissions of the patient with what is still due on their charges. */
    public function open_cases($patient_id)
    {
        $out = array();
        $ipd = $this->db->query("SELECT i.id, i.case_reference_id, i.date, i.discharged,
                (SELECT COALESCE(SUM(pc.amount), 0) FROM patient_charges pc WHERE pc.ipd_id = i.id) charges,
                (SELECT COALESCE(SUM(CASE WHEN t.type = 'refund' THEN -t.amount ELSE t.amount END), 0) FROM transactions t WHERE t.ipd_id = i.id AND t.type IN ('payment', 'refund') AND " . self::CASE_ONLY . ") paid
            FROM ipd_details i WHERE i.patient_id = ? ORDER BY i.id DESC LIMIT 20", array((int) $patient_id))->result_array();
        foreach ($ipd as $r) {
            $out[] = array('kind' => 'ipd', 'id' => (int) $r['id'], 'case_reference_id' => (int) $r['case_reference_id'], 'date' => $r['date'],
                'label' => $this->prefix('ipd_no') . $r['id'], 'closed' => $r['discharged'] === 'yes',
                'charges' => round((float) $r['charges'], 2), 'paid' => round((float) $r['paid'], 2), 'due' => round((float) $r['charges'] - (float) $r['paid'], 2));
        }
        $opd = $this->db->query("SELECT o.id, o.case_reference_id, o.discharged, (SELECT MAX(v.appointment_date) FROM visit_details v WHERE v.opd_details_id = o.id) date,
                (SELECT COALESCE(SUM(pc.amount), 0) FROM patient_charges pc WHERE pc.opd_id = o.id) charges,
                (SELECT COALESCE(SUM(CASE WHEN t.type = 'refund' THEN -t.amount ELSE t.amount END), 0) FROM transactions t WHERE t.opd_id = o.id AND t.type IN ('payment', 'refund') AND " . self::CASE_ONLY . ") paid
            FROM opd_details o WHERE o.patient_id = ? ORDER BY o.id DESC LIMIT 20", array((int) $patient_id))->result_array();
        foreach ($opd as $r) {
            $out[] = array('kind' => 'opd', 'id' => (int) $r['id'], 'case_reference_id' => (int) $r['case_reference_id'], 'date' => $r['date'],
                'label' => $this->prefix('opd_no') . $r['id'], 'closed' => $r['discharged'] === 'yes',
                'charges' => round((float) $r['charges'], 2), 'paid' => round((float) $r['paid'], 2), 'due' => round((float) $r['charges'] - (float) $r['paid'], 2));
        }
        return $out;
    }

    private function prefix($type)
    {
        static $cache = array();
        if (!isset($cache[$type])) {
            $r = $this->db->select('prefix')->where('type', $type)->get('prefixes')->row_array();
            $cache[$type] = $r ? (string) $r['prefix'] : '';
        }
        return $cache[$type];
    }

    // ------------------------------------------------------------------ writes

    public function collect(array $row)
    {
        $row['received_by'] = $this->customlib->getStaffID();
        $this->db->insert('patient_advances', $row);
        $id = (int) $this->db->insert_id();
        $this->db->where('id', $id)->update('patient_advances', array('receipt_no' => 'ADV-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT)));
        $this->log('Patient advance collected', $id, 'Insert');
        return $id;
    }

    /**
     * Use $amount of the advance for an OPD visit / IPD admission. Returns entry id or an error string.
     * The row lock on the advance stops two counters spending the same balance at once.
     */
    public function settle($advance_id, $kind, $case_id, $amount, $note = '')
    {
        $amount = round((float) $amount, 2);
        if ($amount <= 0) {
            return 'Enter the amount to use.';
        }
        $this->db->trans_begin();
        $a = $this->db->query("SELECT * FROM patient_advances WHERE id = ? FOR UPDATE", array((int) $advance_id))->row_array();
        if (!$a || $a['is_cancelled']) {
            $this->db->trans_rollback();
            return 'This advance is not available.';
        }
        $used    = (float) $this->db->query("SELECT COALESCE(SUM(e.amount), 0) u FROM patient_advance_entries e WHERE e.advance_id = ? AND e.is_reversed = 0 AND " . self::LIVE, array((int) $advance_id))->row()->u;
        $balance = round((float) $a['amount'] - $used, 2);
        $case    = null;
        foreach ($this->open_cases($a['patient_id']) as $c) {
            if ($c['kind'] === $kind && $c['id'] === (int) $case_id) {
                $case = $c;
            }
        }
        if (!$case) {
            $this->db->trans_rollback();
            return 'That visit / admission does not belong to this patient.';
        }
        if ($amount > $balance + 0.001) {
            $this->db->trans_rollback();
            return 'Only ' . number_format($balance, 2) . ' is left in this advance.';
        }
        if ($amount > $case['due'] + 0.001) {
            $this->db->trans_rollback();
            return 'Only ' . number_format(max(0, $case['due']), 2) . ' is due on ' . $case['label'] . '.';
        }
        $this->config->load('payroll');
        $sections = $this->config->item('payment_section');
        $now = date('Y-m-d H:i:s');
        $this->db->insert('transactions', array(
            'type' => 'payment', 'section' => $sections[$kind], 'patient_id' => (int) $a['patient_id'],
            'case_reference_id' => $case['case_reference_id'], 'opd_id' => $kind === 'opd' ? $case['id'] : null, 'ipd_id' => $kind === 'ipd' ? $case['id'] : null,
            'amount' => $amount, 'payment_mode' => self::SETTLE_MODE, 'payment_date' => $now,
            'note' => mb_substr('Adjusted from advance ' . $a['receipt_no'] . ($note !== '' ? ' — ' . $note : ''), 0, 500),
            'received_by' => $this->customlib->getStaffID(), 'processing_charge_type' => '', 'gateway_processing_charge' => 0,
        ));
        $tid = (int) $this->db->insert_id();
        $this->db->insert('patient_advance_entries', array(
            'advance_id' => (int) $advance_id, 'entry_type' => 'settle', 'amount' => $amount,
            'opd_id' => $kind === 'opd' ? $case['id'] : null, 'ipd_id' => $kind === 'ipd' ? $case['id'] : null,
            'case_reference_id' => $case['case_reference_id'], 'transaction_id' => $tid, 'payment_mode' => self::SETTLE_MODE,
            'note' => mb_substr($note, 0, 500) ?: null, 'created_by' => $this->customlib->getStaffID(), 'entry_date' => $now,
        ));
        $eid = (int) $this->db->insert_id();
        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();
            return 'Could not save. Try again.';
        }
        $this->db->trans_commit();
        $this->log('Advance ' . $a['receipt_no'] . ' settled ' . $amount . ' on ' . $case['label'], $eid, 'Insert');
        return $eid;
    }

    public function refund($advance_id, $amount, $mode, $note)
    {
        $amount = round((float) $amount, 2);
        if ($amount <= 0) {
            return 'Enter the amount to refund.';
        }
        $this->db->trans_begin();
        $a = $this->db->query("SELECT * FROM patient_advances WHERE id = ? FOR UPDATE", array((int) $advance_id))->row_array();
        $used = (float) $this->db->query("SELECT COALESCE(SUM(e.amount), 0) u FROM patient_advance_entries e WHERE e.advance_id = ? AND e.is_reversed = 0 AND " . self::LIVE, array((int) $advance_id))->row()->u;
        if (!$a || $a['is_cancelled'] || $amount > round((float) $a['amount'] - $used, 2) + 0.001) {
            $this->db->trans_rollback();
            return 'The refund is more than the balance left.';
        }
        $this->db->insert('patient_advance_entries', array(
            'advance_id' => (int) $advance_id, 'entry_type' => 'refund', 'amount' => $amount, 'payment_mode' => $mode,
            'note' => mb_substr($note, 0, 500) ?: null, 'created_by' => $this->customlib->getStaffID(), 'entry_date' => date('Y-m-d H:i:s'),
        ));
        $eid = (int) $this->db->insert_id();
        $this->db->trans_commit();
        $this->log('Advance ' . $a['receipt_no'] . ' refunded ' . $amount, $eid, 'Insert');
        return $eid;
    }

    /** Undo a settlement or refund: the payment row on the case is removed, the balance comes back. */
    public function reverse($entry_id)
    {
        $e = $this->db->where('id', (int) $entry_id)->where('is_reversed', 0)->get('patient_advance_entries')->row_array();
        if (!$e) {
            return 'This entry was already reversed.';
        }
        $this->db->trans_start();
        if ($e['transaction_id']) {
            $this->db->where('id', (int) $e['transaction_id'])->where('payment_mode', self::SETTLE_MODE)->delete('transactions');
        }
        $this->db->where('id', (int) $entry_id)->update('patient_advance_entries', array('is_reversed' => 1, 'reversed_by' => $this->customlib->getStaffID(), 'reversed_at' => date('Y-m-d H:i:s')));
        $this->db->trans_complete();
        $this->log('Advance entry reversed', $entry_id, 'Update');
        return true;
    }

    /** Cancel a wrongly entered advance that has not been used. */
    public function cancel($advance_id, $note)
    {
        $a = $this->advance($advance_id);
        if (!$a || $a['is_cancelled']) {
            return 'This advance is already cancelled.';
        }
        if ((float) $a['used'] > 0) {
            return 'Part of this advance has been used or refunded. Reverse those entries first.';
        }
        $this->db->where('id', (int) $advance_id)->update('patient_advances', array('is_cancelled' => 1, 'cancel_note' => mb_substr($note, 0, 300)));
        $this->log('Patient advance cancelled', $advance_id, 'Update');
        return true;
    }
}
