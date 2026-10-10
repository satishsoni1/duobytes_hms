<?php
if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * Background sync of OPD/IPD records to ABDM. Run every 5–15 minutes:
 *   CLI (preferred):  php /path/to/index.php abdmcron sync
 *   URL (hPanel cron): curl -s "https://your-hms/abdmcron/sync?key=<abdm_cron_key>"
 */
class Abdmcron extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->config->load('abdm', true);
    }

    public function sync()
    {
        $key = (string) $this->config->item('abdm_cron_key', 'abdm');
        $given = (string) $this->input->get('key', true);
        if (!is_cli() && ($key === '' || !hash_equals($key, $given))) {
            show_error('Forbidden', 403);
        }
        if (!$this->config->item('abdm_enabled', 'abdm')) {
            echo "ABDM disabled\n";
            return;
        }
        if (!is_cli()) {
            $this->load->library('session');
        }
        $this->load->model('abdm_model');
        $this->load->library('abdm_client');
        $this->abdm_model->ensure_schema();

        // Single-flight: skip if a previous run is still going.
        $lock = $this->db->query("SELECT GET_LOCK('abdm_sync', 0) l")->row()->l;
        if (!$lock) {
            echo json_encode(array('skipped' => 'another sync is running')) . "\n";
            return;
        }
        try {
            $out = $this->abdm_model->sync($this->abdm_client);
            log_message('info', 'ABDM sync: ' . json_encode($out));
            echo json_encode($out) . "\n";
        } catch (Exception $e) {
            log_message('error', 'ABDM sync failed: ' . $e->getMessage());
            echo json_encode(array('error' => $e->getMessage())) . "\n";
        }
        $this->db->query("SELECT RELEASE_LOCK('abdm_sync')");
    }
}
