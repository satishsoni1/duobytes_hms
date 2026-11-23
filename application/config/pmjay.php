<?php defined('BASEPATH') or exit('No direct script access allowed');
/*
| PM-JAY (Ayushman Bharat) settings. Values come from .env (see .env.example) or real environment variables.
*/

// Phase 2 NHCX mode:
//   'simulator' – nothing leaves the HMS; responses are generated locally (for demos and training).
//   'sandbox'   – NHA NHCX sandbox (needs participant registration + credentials; not wired yet).
$config['pmjay_nhcx_mode'] = env('PMJAY_NHCX_MODE', 'simulator');

// NHCX participant codes, issued on NHCX registration. Placeholders are fine in simulator mode.
$config['pmjay_participant_code'] = env('PMJAY_PARTICIPANT_CODE', 'DEMO-HOSPITAL@nhcx-sandbox');
$config['pmjay_payer_code']       = env('PMJAY_PAYER_CODE', 'DEMO-SHA@nhcx-sandbox');
$config['pmjay_payer_name']       = env('PMJAY_PAYER_NAME', 'State Health Agency (PM-JAY)');

// Hospital's PM-JAY empanelment / HFR ID shown on bundles.
$config['pmjay_hospital_id'] = env('PMJAY_HOSPITAL_ID', '');
