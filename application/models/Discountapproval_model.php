<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Discount approval.
 *
 * When switched on (Setup > Settings > Discount Approval), a discount entered by someone who is
 * not an approver is NOT applied: the bill / charge is saved at the full amount and a request is
 * raised. When an approver approves it, the discount is applied to that bill or charge; if
 * rejected, the full amount stays.
 *
 * Every place that saves a discount calls gate($module, $id) right after saving the row. The
 * request remembers the discount asked for; approve() writes it back using the same formula the
 * billing screen uses (see $modules).
 *
 * Approvers are the staff members chosen in settings (doctors or other staff). Their own discounts
 * apply straight away and are logged as approved. Discounts up to the "free" percentage apply
 * without approval.
 */
class Discountapproval_model extends MY_Model
{
    const TABLE = 'discount_requests';
    const EPS   = 0.009;

    /**
     * kind: how amounts are worked out.
     *   pct_tax : amount = gross × (1 − d%) × (1 + tax%)              (OPD/IPD charges, blood issue)
     *   abs_tax : net = (gross − discount) × (1 + tax%)               (ambulance)
     *   abs_net : net = net_without_discount − discount (tax as saved) (pathology, radiology, pharmacy bills)
     */
    public static $modules = array(
        'charge'      => array('label' => 'OPD / IPD charge', 'table' => 'patient_charges', 'kind' => 'pct_tax', 'gross' => 'apply_charge', 'pct' => 'discount_percentage', 'abs' => null, 'tax' => 'tax', 'net' => 'amount'),
        'pathology'   => array('label' => 'Pathology bill', 'table' => 'pathology_billing', 'kind' => 'abs_net', 'gross' => 'total', 'pct' => 'discount_percentage', 'abs' => 'discount', 'tax' => null, 'net' => 'net_amount'),
        'radiology'   => array('label' => 'Radiology bill', 'table' => 'radiology_billing', 'kind' => 'abs_net', 'gross' => 'total', 'pct' => 'discount_percentage', 'abs' => 'discount', 'tax' => null, 'net' => 'net_amount'),
        'pharmacy'    => array('label' => 'Pharmacy bill', 'table' => 'pharmacy_bill_basic', 'kind' => 'abs_net', 'gross' => 'total', 'pct' => 'discount_percentage', 'abs' => 'discount', 'tax' => null, 'net' => 'net_amount'),
        'blood_issue' => array('label' => 'Blood issue', 'table' => 'blood_issue', 'kind' => 'pct_tax', 'gross' => 'amount', 'pct' => 'discount_percentage', 'abs' => null, 'tax' => 'tax_percentage', 'net' => 'net_amount'),
        'ambulance'   => array('label' => 'Ambulance call', 'table' => 'ambulance_call', 'kind' => 'abs_tax', 'gross' => 'amount', 'pct' => 'discount_percentage', 'abs' => 'discount', 'tax' => 'tax_percentage', 'net' => 'net_amount'),
    );

    public static $statuses = array(
        'pending'    => 'Waiting for approval',
        'approved'   => 'Approved',
        'rejected'   => 'Rejected',
        'auto'       => 'Applied (approver / within limit)',
        'superseded' => 'Replaced by a newer request',
        'cancelled'  => 'Bill changed or removed',
    );

    /** Requests raised during this HTTP request (for the response header / toast). */
    public static $raised = 0;

    public function ensure_schema()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $this->load->model('hms_settings_model');
        if ($this->hms_settings_model->schema_is('discount', 1)) {
            return;
        }
        if (!$this->db->table_exists(self::TABLE)) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
                `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `module` varchar(20) NOT NULL,
                `ref_id` int NOT NULL,
                `patient_id` int DEFAULT NULL,
                `case_label` varchar(60) DEFAULT NULL,
                `description` varchar(255) DEFAULT NULL,
                `gross_amount` decimal(12,2) NOT NULL DEFAULT 0,
                `discount_pct` decimal(7,3) NOT NULL DEFAULT 0,
                `discount_amount` decimal(12,2) NOT NULL DEFAULT 0,
                `net_without` decimal(12,2) NOT NULL DEFAULT 0,
                `net_with` decimal(12,2) NOT NULL DEFAULT 0,
                `reason` varchar(500) DEFAULT NULL,
                `requested_by` int DEFAULT NULL,
                `approver_id` int DEFAULT NULL,
                `status` varchar(12) NOT NULL DEFAULT 'pending',
                `decided_by` int DEFAULT NULL,
                `decided_at` datetime DEFAULT NULL,
                `decision_note` varchar(500) DEFAULT NULL,
                `source` varchar(20) DEFAULT 'web',
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                KEY `ref` (`module`, `ref_id`),
                KEY `status` (`status`),
                KEY `approver` (`approver_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        }
        $s = $this->hms_settings_model;
        $s->ensure_permission('bill', 'discount_approval', 'Discount Approval (view all requests)', array(1, 0, 0, 0));
        $s->ensure_permission('system_settings', 'discount_approval_setting', 'Discount Approval Setting', array(1, 0, 1, 0));
        $s->schema_done('discount', 1);
    }

    // ------------------------------------------------------------------ settings

    public function settings()
    {
        $this->load->model('hms_settings_model');
        $s = $this->hms_settings_model;
        return array(
            'enabled'   => $s->get('discount_approval_enabled', '0') === '1',
            'approvers' => array_values(array_filter(array_map('intval', $s->get_json('discount_approvers')))),
            'free_pct'  => max(0, min(100, (float) $s->get('discount_free_pct', '0'))),
        );
    }

    /** Chosen approvers, plus Super Admin accounts (the owner's own discounts are never held). */
    public function is_approver($staff_id)
    {
        static $super = array();
        $staff_id = (int) $staff_id;
        if (in_array($staff_id, $this->settings()['approvers'], true)) {
            return true;
        }
        if (!isset($super[$staff_id])) {
            $super[$staff_id] = $staff_id > 0 && $this->db->from('staff_roles sr')->join('roles r', 'r.id = sr.role_id')
                ->where('sr.staff_id', $staff_id)->where('r.name', 'Super Admin')->count_all_results() > 0;
        }
        return $super[$staff_id];
    }

    /** Approver list for pickers: id, name, employee_id, role. */
    public function approver_list()
    {
        $ids = $this->settings()['approvers'];
        if (!$ids) {
            return array();
        }
        return $this->db->select('s.id, s.name, s.surname, s.employee_id, r.name as role_name', false)
            ->from('staff s')->join('staff_roles sr', 'sr.staff_id = s.id', 'left')->join('roles r', 'r.id = sr.role_id', 'left')
            ->where_in('s.id', $ids)->where('s.is_active', 1)->order_by('s.name')->get()->result_array();
    }

    // ------------------------------------------------------------------ amounts

    private static function r2($n)
    {
        return round((float) $n, 2);
    }

    /** Discount asked for on this row: array(pct, amount). */
    private static function current_discount(array $m, array $row)
    {
        $gross = (float) $row[$m['gross']];
        $pct   = (float) $row[$m['pct']];
        $abs   = $m['abs'] ? (float) $row[$m['abs']] : $gross * $pct / 100;
        if ($m['abs'] && $abs <= 0 && $pct > 0) {
            $abs = $gross * $pct / 100;
        }
        if ($pct <= 0 && $abs > 0 && $gross > 0) {
            $pct = $abs * 100 / $gross;
        }
        return array(round($pct, 3), self::r2($abs));
    }

    /** Column values for this row with discount ($pct, $abs) — 0, 0 for none. */
    private static function values_for(array $m, array $row, $pct, $abs, $net_without = null)
    {
        $gross = (float) $row[$m['gross']];
        $tax   = $m['tax'] ? (float) $row[$m['tax']] : 0;
        $out   = array($m['pct'] => round($pct, 2));
        if ($m['abs']) {
            $out[$m['abs']] = self::r2($abs);
        }
        switch ($m['kind']) {
            case 'pct_tax':
                $out[$m['net']] = self::r2($gross * (1 - $pct / 100) * (1 + $tax / 100));
                break;
            case 'abs_tax':
                $out[$m['net']] = self::r2(($gross - $abs) * (1 + $tax / 100));
                break;
            default: // abs_net: tax stays as the screen saved it
                $out[$m['net']] = self::r2($net_without - $abs);
        }
        return $out;
    }

    private function row($module, $id)
    {
        $m = self::$modules[$module];
        return $this->db->where('id', (int) $id)->get($m['table'])->row_array();
    }

    /** OPD/IPD number prefix from the database (the mobile API has no web session to read it from). */
    private function prefix($type)
    {
        static $cache = array();
        if (!isset($cache[$type])) {
            $r = $this->db->select('prefix')->where('type', $type)->get('prefixes')->row_array();
            $cache[$type] = $r ? (string) $r['prefix'] : '';
        }
        return $cache[$type];
    }

    /** Patient, case label and description for the approver's list. */
    private function describe($module, array $row)
    {
        $patient = isset($row['patient_id']) ? (int) $row['patient_id'] : null;
        $case    = '';
        $desc    = self::$modules[$module]['label'] . ' #' . $row['id'];
        if ($module === 'charge') {
            if (!empty($row['ipd_id'])) {
                $d = $this->db->select('patient_id')->where('id', (int) $row['ipd_id'])->get('ipd_details')->row_array();
                $patient = $d ? (int) $d['patient_id'] : null;
                $case    = $this->prefix('ipd_no') . $row['ipd_id'];
            } elseif (!empty($row['opd_id'])) {
                $d = $this->db->select('patient_id')->where('id', (int) $row['opd_id'])->get('opd_details')->row_array();
                $patient = $d ? (int) $d['patient_id'] : null;
                $case    = $this->prefix('opd_no') . $row['opd_id'];
            }
            $c = $this->db->select('name')->where('id', (int) $row['charge_id'])->get('charges')->row_array();
            if ($c) {
                $desc = $c['name'] . ((int) $row['qty'] > 1 ? ' × ' . (int) $row['qty'] : '');
            }
        } elseif (!empty($row['case_reference_id'])) {
            $case = 'Case ' . (int) $row['case_reference_id'];
        }
        return array($patient ?: null, mb_substr($case, 0, 60), mb_substr($desc, 0, 255));
    }

    // ------------------------------------------------------------------ gate

    /**
     * Call right after a row with a discount is saved.
     * $opts: requested_by (staff id, default: logged-in user), approver_id, reason, source,
     *        calc = 'mobile' for mobile-app charges (their apply_charge is already discounted).
     * Returns 'pending' when the discount was held back for approval, otherwise null.
     */
    public function gate($module, $id, array $opts = array())
    {
        if (!isset(self::$modules[$module]) || !$id) {
            return null;
        }
        $this->ensure_schema();
        $set = $this->settings();
        if (!$set['enabled']) {
            return null;
        }
        $m   = self::$modules[$module];
        $row = $this->row($module, $id);
        if (!$row) {
            return null;
        }
        // Mobile-app charges store apply_charge after discount; restate them like the web does.
        if ($module === 'charge' && ($opts['calc'] ?? '') === 'mobile' && (float) $row['discount_percentage'] > 0) {
            $gross = self::r2((float) $row['standard_charge'] * max(1, (int) $row['qty']));
            $this->db->where('id', (int) $id)->update($m['table'], array('apply_charge' => $gross));
            $row['apply_charge'] = $gross;
        }
        list($pct, $abs) = self::current_discount($m, $row);
        $by = isset($opts['requested_by']) ? (int) $opts['requested_by'] : (int) $this->customlib->getStaffID();

        $open = $this->db->where('module', $module)->where('ref_id', (int) $id)->where('status', 'pending')->get(self::TABLE)->result_array();
        if ($abs < self::EPS) {
            // No discount on the row now: anything pending for it is no longer wanted.
            foreach ($open as $o) {
                $this->db->where('id', $o['id'])->update(self::TABLE, array('status' => 'superseded'));
            }
            return null;
        }
        // Already approved at this level (e.g. the bill was re-saved without changing the discount).
        $ok = $this->db->where('module', $module)->where('ref_id', (int) $id)->where_in('status', array('approved', 'auto'))
            ->where('discount_amount >=', $abs - self::EPS)->order_by('id', 'desc')->limit(1)->get(self::TABLE)->row_array();
        if ($ok) {
            return null;
        }

        $net_with    = (float) $row[$m['net']];
        $net_without = $m['kind'] === 'abs_net' ? self::r2($net_with + $abs) : self::values_for($m, $row, 0, 0)[$m['net']];
        list($patient, $case, $desc) = $this->describe($module, $row);
        $req = array(
            'module' => $module, 'ref_id' => (int) $id, 'patient_id' => $patient, 'case_label' => $case, 'description' => $desc,
            'gross_amount' => self::r2($row[$m['gross']]), 'discount_pct' => $pct, 'discount_amount' => $abs,
            'net_without' => $net_without, 'net_with' => self::r2($net_with),
            'reason' => mb_substr(trim(strip_tags((string) ($opts['reason'] ?? $this->input->post('discount_reason')))), 0, 500) ?: null,
            'requested_by' => $by ?: null, 'source' => $opts['source'] ?? 'web',
        );
        foreach ($open as $o) {
            $this->db->where('id', $o['id'])->update(self::TABLE, array('status' => 'superseded'));
        }

        $approver = $by && $this->is_approver($by);
        if ($approver || ($set['free_pct'] > 0 && $pct <= $set['free_pct'] + 0.0001)) {
            $req['status']     = 'auto';
            $req['decided_by'] = $by ?: null;
            $req['decided_at'] = date('Y-m-d H:i:s');
            $this->db->insert(self::TABLE, $req);
            return null;
        }

        // Hold the discount back: save the row at the full amount.
        $this->db->where('id', (int) $id)->update($m['table'], self::values_for($m, $row, 0, 0, $net_without));
        $chosen = (int) ($opts['approver_id'] ?? $this->input->post('discount_approver_id'));
        $req['approver_id'] = in_array($chosen, $set['approvers'], true) ? $chosen : null;
        $req['status']      = 'pending';
        $this->db->insert(self::TABLE, $req);
        $rid = (int) $this->db->insert_id();
        $this->log('Discount of ' . $abs . ' on ' . $desc . ' sent for approval', $rid, 'Insert');
        self::$raised++;
        if (!headers_sent()) {
            header('X-Discount-Pending: ' . self::$raised);
        }
        return 'pending';
    }

    // ------------------------------------------------------------------ decisions

    public function request($id)
    {
        return $this->db->where('id', (int) $id)->get(self::TABLE)->row_array();
    }

    /** Can this staff member decide this request? Chosen approver, or any approver if none chosen. */
    public function can_decide(array $req, $staff_id)
    {
        if ($req['status'] !== 'pending' || !$this->is_approver($staff_id)) {
            return false;
        }
        if ((int) $req['requested_by'] === (int) $staff_id) {
            return false;
        }
        return true; // any approver may act; the chosen one is shown first in their list
    }

    /** Apply the discount to the bill / charge. Returns true or an error message. */
    public function approve($id, $staff_id, $note = '', $pct_override = null)
    {
        $req = $this->request($id);
        if (!$req || !$this->can_decide($req, $staff_id)) {
            return 'You cannot approve this request.';
        }
        $m   = self::$modules[$req['module']];
        $row = $this->row($req['module'], $req['ref_id']);
        $now = (float) ($row ? $row[$m['net']] : 0);
        if (!$row || abs($now - (float) $req['net_without']) > 0.05 || abs((float) $row[$m['gross']] - (float) $req['gross_amount']) > 0.05) {
            $this->db->where('id', (int) $id)->update(self::TABLE, array('status' => 'cancelled', 'decided_by' => (int) $staff_id, 'decided_at' => date('Y-m-d H:i:s'),
                'decision_note' => 'The bill was changed or deleted after the request; ask for the discount again if still needed.'));
            return 'The bill or charge has changed since this was asked, so the request was closed. The discount can be entered again.';
        }
        $pct = (float) $req['discount_pct'];
        $abs = (float) $req['discount_amount'];
        if ($pct_override !== null && $pct_override !== '' && (float) $pct_override > 0 && (float) $pct_override < $pct) {
            $pct = round((float) $pct_override, 3);
            $abs = self::r2((float) $req['gross_amount'] * $pct / 100);
        }
        $vals = self::values_for($m, $row, $pct, $abs, (float) $req['net_without']);
        $this->db->trans_start();
        $this->db->where('id', (int) $req['ref_id'])->update($m['table'], $vals);
        $this->db->where('id', (int) $id)->update(self::TABLE, array(
            'status' => 'approved', 'decided_by' => (int) $staff_id, 'decided_at' => date('Y-m-d H:i:s'),
            'decision_note' => mb_substr($note, 0, 500) ?: null, 'discount_pct' => $pct, 'discount_amount' => $abs, 'net_with' => $vals[$m['net']],
        ));
        $this->db->trans_complete();
        $this->log('Discount request approved', $id, 'Update');
        return true;
    }

    public function reject($id, $staff_id, $note)
    {
        $req = $this->request($id);
        if (!$req || !$this->can_decide($req, $staff_id)) {
            return 'You cannot reject this request.';
        }
        $this->db->where('id', (int) $id)->update(self::TABLE, array('status' => 'rejected', 'decided_by' => (int) $staff_id, 'decided_at' => date('Y-m-d H:i:s'), 'decision_note' => mb_substr($note, 0, 500)));
        $this->log('Discount request rejected', $id, 'Update');
        return true;
    }

    /** The requester withdraws a pending request (the full amount stays). */
    public function withdraw($id, $staff_id)
    {
        $req = $this->request($id);
        if (!$req || $req['status'] !== 'pending' || (int) $req['requested_by'] !== (int) $staff_id) {
            return 'This request cannot be withdrawn.';
        }
        $this->db->where('id', (int) $id)->update(self::TABLE, array('status' => 'cancelled', 'decided_by' => (int) $staff_id, 'decided_at' => date('Y-m-d H:i:s'), 'decision_note' => 'Withdrawn by the person who asked.'));
        return true;
    }

    // ------------------------------------------------------------------ lists

    /** $f: status, scope (mine|asked|all), staff_id (viewer), module, from, to */
    public function search(array $f)
    {
        $this->db->select('d.*, CONCAT(p.patient_name, "") as patient_name, CONCAT(rq.name, " ", rq.surname) as requested_name, CONCAT(ap.name, " ", ap.surname) as approver_name, CONCAT(dc.name, " ", dc.surname) as decided_name', false)
            ->from(self::TABLE . ' d')
            ->join('patients p', 'p.id = d.patient_id', 'left')
            ->join('staff rq', 'rq.id = d.requested_by', 'left')
            ->join('staff ap', 'ap.id = d.approver_id', 'left')
            ->join('staff dc', 'dc.id = d.decided_by', 'left');
        if (!empty($f['status'])) {
            $this->db->where('d.status', $f['status']);
        }
        if (!empty($f['module']) && isset(self::$modules[$f['module']])) {
            $this->db->where('d.module', $f['module']);
        }
        if (($f['scope'] ?? '') === 'asked') {
            $this->db->where('d.requested_by', (int) $f['staff_id']);
        } elseif (($f['scope'] ?? '') === 'mine') {
            $this->db->group_start()->where('d.approver_id', (int) $f['staff_id'])->or_where('d.approver_id IS NULL', null, false)->group_end()
                ->where('d.requested_by !=', (int) $f['staff_id']);
        }
        if (!empty($f['from'])) {
            $this->db->where('d.created_at >=', $f['from'] . ' 00:00:00');
        }
        if (!empty($f['to'])) {
            $this->db->where('d.created_at <=', $f['to'] . ' 23:59:59');
        }
        return $this->db->order_by('d.id', 'desc')->limit(1000)->get()->result_array();
    }

    public function pending_count_for($staff_id)
    {
        if (!$this->db->table_exists(self::TABLE) || !$this->is_approver($staff_id)) {
            return 0;
        }
        return (int) $this->db->where('status', 'pending')->where('requested_by !=', (int) $staff_id)
            ->group_start()->where('approver_id', (int) $staff_id)->or_where('approver_id IS NULL', null, false)->group_end()
            ->count_all_results(self::TABLE);
    }
}
