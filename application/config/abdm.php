<?php defined('BASEPATH') or exit('No direct script access allowed');
/*
| ABDM (Ayushman Bharat Digital Mission) integration.
| Smart Hospital talks to the ABDM bridge service, which handles the ABDM gateway protocol and encryption.
| All values come from .env in the project root (see .env.example) or real environment variables.
*/

// Master switch.
$config['abdm_enabled'] = filter_var(env('ABDM_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);

// Base URL of the ABDM bridge, e.g. https://abdm.yourhospital.in
$config['abdm_bridge_url'] = env('ABDM_BRIDGE_URL', '');

// API key issued by the bridge: npm run create-api-key -- --facility <HIP id> --name "Smart Hospital"
$config['abdm_api_key'] = env('ABDM_API_KEY', '');

// Secret for the cron URL (/abdmcron/sync?key=...). Long random string.
$config['abdm_cron_key'] = env('ABDM_CRON_KEY', '');

// Prefix for the patient reference number shared with ABDM (MRN). Never change after go-live.
$config['abdm_mrn_prefix'] = env('ABDM_MRN_PREFIX', 'SH');

// Only records on/after this date are shared (avoids pushing years of history on day one).
$config['abdm_sync_from'] = env('ABDM_SYNC_FROM', '2024-01-01');

// Re-check records changed in the last N days on every sync run (edits to reports, prescriptions...).
$config['abdm_resync_days'] = (int) (env('ABDM_RESYNC_DAYS', 7));

// Max records sent per sync run (keep cron runs short on shared hosting).
$config['abdm_batch_size'] = (int) (env('ABDM_BATCH_SIZE', 200));
