<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Staff travel expense claims.
 *
 * draft → submitted → approved → paid (through payroll)
 *                   ↘ rejected → (staff edits) → submitted
 *
 * A claim holds expense lines (date, category, from/to, mode, km, amount, bill attachment).
 * Approvers (Travel Expense Approval permission) approve the full or a reduced amount, or reject
 * with a reason. Approved claims show up when the staff member's payslip is generated and are
 * added as an earning; saving the payslip marks them paid, deleting it puts them back to approved.
 */
class Travelexpense_model extends MY_Model
{
    const FILE_DIR = 'uploads/hr_expenses/';

    public static $statuses = array(
        'draft'     => 'Draft',
        'submitted' => 'Waiting for approval',
        'approved'  => 'Approved',
        'rejected'  => 'Rejected',
        'paid'      => 'Paid in payroll',
    );

    public static $modes = array('Bus', 'Train', 'Flight', 'Taxi / cab', 'Auto', 'Own car', 'Own two-wheeler', 'Hospital vehicle', 'Other');

    public static $default_categories = array('Travel fare', 'Fuel / mileage', 'Local conveyance', 'Lodging', 'Food', 'Conference / CME fee', 'Other');

    public function ensure_schema()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $this->load->model('hrms_model');
        $this->hrms_model->ensure_schema(); // permissions + settings table

        if (!$this->db->table_exists('hr_expense_claims')) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `hr_expense_claims` (
                `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `claim_no` varchar(30) DEFAULT NULL,
                `staff_id` int NOT NULL,
                `title` varchar(200) NOT NULL,
                `purpose` text,
                `from_date` date DEFAULT NULL,
                `to_date` date DEFAULT NULL,
                `advance_taken` decimal(12,2) NOT NULL DEFAULT 0,
                `total_amount` decimal(12,2) NOT NULL DEFAULT 0,
                `approved_amount` decimal(12,2) DEFAULT NULL,
                `status` varchar(12) NOT NULL DEFAULT 'draft',
                `submitted_at` datetime DEFAULT NULL,
                `decided_by` int DEFAULT NULL,
                `decided_at` datetime DEFAULT NULL,
                `decision_note` varchar(500) DEFAULT NULL,
                `payslip_id` int DEFAULT NULL,
                `paid_at` datetime DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                KEY `staff_status` (`staff_id`, `status`),
                KEY `payslip_id` (`payslip_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        }
        if (!$this->db->table_exists('hr_expense_items')) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `hr_expense_items` (
                `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `claim_id` int NOT NULL,
                `expense_date` date DEFAULT NULL,
                `category` varchar(60) NOT NULL,
                `description` varchar(300) DEFAULT NULL,
                `from_place` varchar(100) DEFAULT NULL,
                `to_place` varchar(100) DEFAULT NULL,
                `travel_mode` varchar(40) DEFAULT NULL,
                `distance_km` decimal(8,1) DEFAULT NULL,
                `amount` decimal(12,2) NOT NULL DEFAULT 0,
                `attachment` varchar(300) DEFAULT NULL,
                `attachment_name` varchar(255) DEFAULT NULL,
                KEY `claim_id` (`claim_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        }
        $path = FCPATH . self::FILE_DIR;
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        if (is_dir($path) && !file_exists($path . '.htaccess')) {
            @file_put_contents($path . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            @file_put_contents($path . 'index.html', '');
        }
    }

    public function categories()
    {
        $this->load->model('hms_settings_model');
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->hms_settings_model->get('expense_categories', '')))));
        return $lines ?: self::$default_categories;
    }

    private function base_query()
    {
        return $this->db->select('c.*, s.name, s.surname, s.employee_id, d.name as decided_name, d.surname as decided_surname, sd.designation', false)
            ->from('hr_expense_claims c')
            ->join('staff s', 's.id = c.staff_id')
            ->join('staff_designation sd', 'sd.id = s.staff_designation_id', 'left')
            ->join('staff d', 'd.id = c.decided_by', 'left');
    }

    public function claim($id)
    {
        $c = $this->base_query()->where('c.id', (int) $id)->get()->row_array();
        if ($c) {
            $c['items'] = $this->db->where('claim_id', (int) $id)->order_by('expense_date, id')->get('hr_expense_items')->result_array();
        }
        return $c;
    }

    public function claims_of($staff_id)
    {
        return $this->base_query()->where('c.staff_id', (int) $staff_id)->order_by('c.id', 'desc')->get()->result_array();
    }

    /** Approver list. $f: status, staff_id, month (Y-m) */
    public function search(array $f)
    {
        $this->base_query()->where('c.status !=', 'draft');
        if (!empty($f['status'])) {
            $this->db->where('c.status', $f['status']);
        }
        if (!empty($f['staff_id'])) {
            $this->db->where('c.staff_id', (int) $f['staff_id']);
        }
        if (!empty($f['month']) && preg_match('/^\d{4}-\d{2}$/', $f['month'])) {
            $this->db->where("DATE_FORMAT(COALESCE(c.submitted_at, c.created_at), '%Y-%m') =", $f['month']);
        }
        return $this->db->order_by("FIELD(c.status, 'submitted', 'approved', 'rejected', 'paid')", '', false)->order_by('c.id', 'desc')->limit(1000)->get()->result_array();
    }

    public function counts()
    {
        $out = array_fill_keys(array_keys(self::$statuses), array('n' => 0, 'amount' => 0));
        foreach ($this->db->query("SELECT status, COUNT(*) n, SUM(COALESCE(approved_amount, total_amount)) amount FROM hr_expense_claims GROUP BY status")->result_array() as $r) {
            $out[$r['status']] = array('n' => (int) $r['n'], 'amount' => (float) $r['amount']);
        }
        return $out;
    }

    /** Approved claims not yet paid, for the payslip of this staff member. */
    public function payable($staff_id)
    {
        return $this->db->where('staff_id', (int) $staff_id)->where('status', 'approved')->where('payslip_id IS NULL', null, false)
            ->order_by('id')->get('hr_expense_claims')->result_array();
    }

    public static function payable_amount(array $c)
    {
        return max(0, round((float) $c['approved_amount'] - (float) $c['advance_taken'], 2));
    }

    /**
     * Create or update a claim with its lines (only while draft or rejected).
     * $items: list of rows; each may carry 'id' (existing line) and 'keep_file' => true.
     */
    public function save_claim($staff_id, array $head, array $items, $id = 0)
    {
        $this->db->trans_start();
        $total = 0;
        foreach ($items as $it) {
            $total += (float) $it['amount'];
        }
        $head['total_amount'] = round($total, 2);
        if ($id) {
            $this->db->where('id', (int) $id)->where('staff_id', (int) $staff_id)->update('hr_expense_claims', $head);
        } else {
            $head['staff_id'] = (int) $staff_id;
            $head['status']   = 'draft';
            $this->db->insert('hr_expense_claims', $head);
            $id = (int) $this->db->insert_id();
            $this->db->where('id', $id)->update('hr_expense_claims', array('claim_no' => 'TE-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT)));
        }
        $keep = array();
        foreach ($items as $it) {
            $line_id = (int) ($it['id'] ?? 0);
            unset($it['id']);
            $it['claim_id'] = (int) $id;
            if ($line_id) {
                $this->db->where('id', $line_id)->where('claim_id', (int) $id)->update('hr_expense_items', $it);
                $keep[] = $line_id;
            } else {
                $this->db->insert('hr_expense_items', $it);
                $keep[] = (int) $this->db->insert_id();
            }
        }
        // Lines removed on the form: delete them and their bills.
        $this->db->where('claim_id', (int) $id);
        if ($keep) {
            $this->db->where_not_in('id', $keep);
        }
        foreach ($this->db->get('hr_expense_items')->result_array() as $gone) {
            $this->remove_file($gone['attachment']);
            $this->db->where('id', (int) $gone['id'])->delete('hr_expense_items');
        }
        $this->db->trans_complete();
        $this->log('Travel expense claim saved', $id, 'Update');
        return (int) $id;
    }

    public function remove_file($stored)
    {
        if ($stored && is_file(FCPATH . self::FILE_DIR . basename($stored))) {
            @unlink(FCPATH . self::FILE_DIR . basename($stored));
        }
    }

    public function set_status($id, array $row)
    {
        $this->db->where('id', (int) $id)->update('hr_expense_claims', $row);
        $this->log('Travel expense claim ' . ($row['status'] ?? 'updated'), $id, 'Update');
    }

    public function delete_claim($id)
    {
        foreach ($this->db->where('claim_id', (int) $id)->get('hr_expense_items')->result_array() as $it) {
            $this->remove_file($it['attachment']);
        }
        $this->db->where('claim_id', (int) $id)->delete('hr_expense_items');
        $this->db->where('id', (int) $id)->delete('hr_expense_claims');
        $this->log('Travel expense claim deleted', $id, 'Delete');
    }

    /** Payslip saved: mark the listed approved claims of this staff member as paid. */
    public function mark_paid($staff_id, array $claim_ids, $payslip_id)
    {
        $claim_ids = array_filter(array_map('intval', $claim_ids));
        if (!$claim_ids || !$payslip_id) {
            return 0;
        }
        $this->db->where('staff_id', (int) $staff_id)->where('status', 'approved')->where('payslip_id IS NULL', null, false)
            ->where_in('id', $claim_ids)
            ->update('hr_expense_claims', array('status' => 'paid', 'payslip_id' => (int) $payslip_id, 'paid_at' => date('Y-m-d H:i:s')));
        return $this->db->affected_rows();
    }

    /** Payslip deleted: its claims go back to approved so the next payslip picks them up. */
    public function unmark_paid($payslip_id)
    {
        if (!$this->db->table_exists('hr_expense_claims')) {
            return;
        }
        $this->db->where('payslip_id', (int) $payslip_id)->update('hr_expense_claims', array('status' => 'approved', 'payslip_id' => null, 'paid_at' => null));
    }
}
