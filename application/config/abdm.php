<?php defined('BASEPATH') or exit('No direct script access allowed');
/*
| ABDM (Ayushman Bharat Digital Mission) integration.
| Smart Hospital talks to the ABDM bridge service, which handles the ABDM gateway protocol and encryption.
| Values can also come from environment variables of the same name (ABDM_BRIDGE_URL, ABDM_API_KEY, ABDM_CRON_KEY).
*/

// Master switch.
$config['abdm_enabled'] = true;

// Base URL of the ABDM bridge, e.g. https://abdm.yourhospital.in
$config['abdm_bridge_url'] = getenv('ABDM_BRIDGE_URL') ?: '';

// API key issued by the bridge: npm run create-api-key -- --facility <HIP id> --name "Smart Hospital"
$config['abdm_api_key'] = getenv('ABDM_API_KEY') ?: '';

// Secret for the cron URL (/abdmcron/sync?key=...). Long random string.
$config['abdm_cron_key'] = getenv('ABDM_CRON_KEY') ?: '';

// Prefix for the patient reference number shared with ABDM (MRN). Never change after go-live.
$config['abdm_mrn_prefix'] = 'SH';

// Only records on/after this date are shared (avoids pushing years of history on day one).
$config['abdm_sync_from'] = '2024-01-01';

// Re-check records changed in the last N days on every sync run (edits to reports, prescriptions...).
$config['abdm_resync_days'] = 7;

// Max records sent per sync run (keep cron runs short on shared hosting).
$config['abdm_batch_size'] = 200;
