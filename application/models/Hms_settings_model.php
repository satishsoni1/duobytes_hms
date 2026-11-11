<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Small key/value store for hospital-level switches added after the Smart Hospital baseline
 * (staff ID format, discount approval, advance receipts...). Also registers the permission
 * categories the new modules need, so Roles & Permissions shows them without a migration.
 *
 * Tables create themselves on first use, like Hospital_brand_model.
 */
class Hms_settings_model extends CI_Model
{
    const TABLE = 'hms_settings';

    private static $cache = null;

    public function ensure_schema()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        if (!$this->db->table_exists(self::TABLE)) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
                `name` varchar(100) NOT NULL PRIMARY KEY,
                `value` text,
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        }
    }

    public function get($name, $default = null)
    {
        $all = $this->all();
        return array_key_exists($name, $all) ? $all[$name] : $default;
    }

    public function all()
    {
        if (self::$cache === null) {
            $this->ensure_schema();
            self::$cache = array();
            foreach ($this->db->get(self::TABLE)->result_array() as $r) {
                self::$cache[$r['name']] = $r['value'];
            }
        }
        return self::$cache;
    }

    public function set($name, $value)
    {
        $this->ensure_schema();
        $this->db->query("INSERT INTO `" . self::TABLE . "` (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", array($name, (string) $value));
        if (self::$cache !== null) {
            self::$cache[$name] = (string) $value;
        }
    }

    public function get_json($name, $default = array())
    {
        $v = json_decode((string) $this->get($name, ''), true);
        return is_array($v) ? $v : $default;
    }

    /**
     * Make sure a permission category exists (by short code) and that the Admin role has it.
     * $flags = array(view, add, edit, delete) — which actions the category offers.
     */
    public function ensure_permission($group_code, $short_code, $name, array $flags)
    {
        $row = $this->db->select('id')->where('short_code', $short_code)->get('permission_category')->row_array();
        if ($row) {
            return (int) $row['id'];
        }
        $group = $this->db->select('id')->where('short_code', $group_code)->get('permission_group')->row_array();
        if (!$group) {
            return 0;
        }
        list($v, $a, $e, $d) = $flags;
        $this->db->insert('permission_category', array(
            'perm_group_id' => (int) $group['id'], 'name' => $name, 'short_code' => $short_code,
            'enable_view' => $v, 'enable_add' => $a, 'enable_edit' => $e, 'enable_delete' => $d,
        ));
        $id    = (int) $this->db->insert_id();
        $admin = $this->db->select('id')->where('name', 'Admin')->get('roles')->row_array();
        if ($id && $admin) {
            $this->db->insert('roles_permissions', array(
                'role_id' => (int) $admin['id'], 'perm_cat_id' => $id,
                'can_view' => $v, 'can_add' => $a, 'can_edit' => $e, 'can_delete' => $d,
            ));
        }
        return $id;
    }

    /**
     * Schema checks (tables, columns, permissions) run once per version, not on every request:
     * if (!$this->hms_settings_model->schema_is('geo', 1)) { ...create...; schema_done('geo', 1); }
     */
    public function schema_is($key, $version)
    {
        return (string) $this->get('schema_' . $key, '') === (string) $version;
    }

    public function schema_done($key, $version)
    {
        $this->set('schema_' . $key, (string) $version);
    }

    // ------------------------------------------------------------------ staff ID format

    public function staff_id_format()
    {
        return array(
            'auto'   => $this->get('staff_id_auto', '0') === '1',
            'prefix' => (string) $this->get('staff_id_prefix', 'EMP'),
            'digits' => max(1, min(10, (int) $this->get('staff_id_digits', '4'))),
            'start'  => max(1, (int) $this->get('staff_id_start', '1')),
        );
    }

    /**
     * Next free staff ID for the configured prefix: the highest number already used with this
     * prefix + 1 (never below the configured start), padded to the configured digits.
     */
    public function next_staff_id(?array $f = null)
    {
        $f      = $f ?: $this->staff_id_format();
        $prefix = $f['prefix'];
        $max    = $f['start'] - 1;
        $rows   = $this->db->select('employee_id')->like('employee_id', $prefix, 'after')->get('staff')->result_array();
        foreach ($rows as $r) {
            $rest = substr((string) $r['employee_id'], strlen($prefix));
            if ($rest !== '' && ctype_digit($rest)) {
                $max = max($max, (int) $rest);
            }
        }
        return $prefix . str_pad((string) ($max + 1), $f['digits'], '0', STR_PAD_LEFT);
    }
}
