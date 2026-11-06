<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Brand Kit — hospital identity shown on the login screens, the dashboard
 * welcome banner, print-header tagline and the browser tab (tagline, local-language name, contact
 * strip, highlights). Hospital name / logo / phone / email stay in sch_settings
 * (General Setting); this table holds the presentation extras around them.
 *
 * Single row, id = 1. The table creates itself on first use so the module also
 * works on databases that were not built from SKYLARK_PRODUCTION_DB.sql.
 */
class Hospital_brand_model extends MY_Model
{
    const TABLE = 'hospital_brand_settings';

    /** Editable columns => max length (0 = text). Controller validates against this. */
    public static $fields = array(
        'tagline'               => 150,
        'local_name'            => 150,
        'city'                  => 80,
        'emergency_phone'       => 60,
        'website'               => 150,
        'hours_text'            => 100,
        'highlights'            => 0,
        'welcome_message'       => 255,
        'show_login_showcase'   => 1,
        'show_dashboard_banner' => 1,
        // Inauguration ceremony page (admin/inauguration)
        'inauguration_enabled'  => 1,
        'inauguration_guest'    => 150,
        'inauguration_title'    => 150,
    );

    /** Columns added after the first release; ensure_schema() adds them to older tables. */
    private static $added_columns = array(
        'inauguration_enabled' => "tinyint(1) NOT NULL DEFAULT 1",
        'inauguration_guest'   => "varchar(150) DEFAULT NULL",
        'inauguration_title'   => "varchar(150) DEFAULT NULL",
        'inaugurated_at'       => "datetime DEFAULT NULL",
        'inaugurated_by'       => "varchar(150) DEFAULT NULL",
    );

    public function __construct()
    {
        parent::__construct();
    }

    public function ensure_schema()
    {
        static $done = false;
        if ($done) {
            return;
        }
        if (!$this->db->table_exists(self::TABLE)) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
                `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `tagline` varchar(150) DEFAULT NULL,
                `local_name` varchar(150) DEFAULT NULL,
                `city` varchar(80) DEFAULT NULL,
                `emergency_phone` varchar(60) DEFAULT NULL,
                `website` varchar(150) DEFAULT NULL,
                `hours_text` varchar(100) DEFAULT NULL,
                `highlights` text,
                `welcome_message` varchar(255) DEFAULT NULL,
                `show_login_showcase` tinyint(1) NOT NULL DEFAULT 1,
                `show_dashboard_banner` tinyint(1) NOT NULL DEFAULT 1,
                `inauguration_enabled` tinyint(1) NOT NULL DEFAULT 1,
                `inauguration_guest` varchar(150) DEFAULT NULL,
                `inauguration_title` varchar(150) DEFAULT NULL,
                `inaugurated_at` datetime DEFAULT NULL,
                `inaugurated_by` varchar(150) DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        $existing = $this->db->list_fields(self::TABLE);
        foreach (self::$added_columns as $col => $ddl) {
            if (!in_array($col, $existing, true)) {
                $this->db->query("ALTER TABLE `" . self::TABLE . "` ADD COLUMN `$col` $ddl");
            }
        }
        if ($this->db->where('id', 1)->count_all_results(self::TABLE) == 0) {
            $this->db->insert(self::TABLE, array('id' => 1, 'show_login_showcase' => 1, 'show_dashboard_banner' => 1));
        }
        $done = true;
    }

    /** Brand row with safe defaults; never throws, so login pages cannot break on it. */
    public function get()
    {
        $row = array();
        try {
            $this->ensure_schema();
            $row = $this->db->where('id', 1)->get(self::TABLE)->row_array();
        } catch (Throwable $e) {
            log_message('error', 'Brand Kit unavailable: ' . $e->getMessage());
        }
        $defaults = array_fill_keys(array_keys(self::$fields), '');
        $defaults['show_login_showcase']   = 1;
        $defaults['show_dashboard_banner'] = 1;
        $defaults['inauguration_enabled']  = 1;
        $defaults['inaugurated_at']        = null;
        $defaults['inaugurated_by']        = '';
        $row = array_merge($defaults, is_array($row) ? $row : array());
        $row['highlight_list'] = self::split_highlights($row['highlights']);
        return $row;
    }

    public function update($data)
    {
        $this->ensure_schema();
        $this->db->trans_start();
        $this->db->where('id', 1)->update(self::TABLE, $data);
        $this->log(UPDATE_RECORD_CONSTANT . ' On Brand Kit id 1', 1, UPDATE_RECORD_CONSTANT);
        $this->db->trans_complete();
        return $this->db->trans_status() !== false;
    }

    /** Records the official go-live moment (first real ribbon cut; later cuts do not overwrite it). */
    public function mark_inaugurated($by)
    {
        $this->ensure_schema();
        $this->db->where('id', 1)->where('inaugurated_at IS NULL', null, false)
            ->update(self::TABLE, array('inaugurated_at' => date('Y-m-d H:i:s'), 'inaugurated_by' => $by));
        $this->log('Portal inaugurated by ' . $by, 1, UPDATE_RECORD_CONSTANT);
        return $this->db->where('id', 1)->get(self::TABLE)->row_array();
    }

    /** Clears the recorded moment, e.g. after an accidental cut before the event. */
    public function reset_inauguration()
    {
        $this->ensure_schema();
        return $this->db->where('id', 1)->update(self::TABLE, array('inaugurated_at' => null, 'inaugurated_by' => null));
    }

    /** One highlight per line; blank lines dropped; capped at 8 so the login panel stays tidy. */
    public static function split_highlights($text)
    {
        $items = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $text)), 'strlen');
        return array_slice(array_values($items), 0, 8);
    }
}
