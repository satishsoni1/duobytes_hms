-- Staff mobile app API tables (api/v1/*).
-- The API also creates these automatically on the first mobile sign-in (Mobileapi_model::ensure_schema),
-- so running this file is optional. It is safe to run more than once.
-- rx_templates is shared with the web Smart Prescription screen and is created by Smartrx_model if missing.

CREATE TABLE IF NOT EXISTS `mobile_api_tokens` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `staff_id` int NOT NULL,                   -- staff.id, or users.id for a patient account
  `subject_type` varchar(10) NOT NULL DEFAULT 'staff', -- 'staff' | 'patient'
  `token_hash` char(64) NOT NULL,            -- sha256 of the bearer token; the token itself is never stored
  `device_id` varchar(100) NOT NULL DEFAULT '',
  `device_name` varchar(150) NOT NULL DEFAULT '',
  `platform` varchar(30) NOT NULL DEFAULT '',
  `app_version` varchar(30) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `last_used_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,            -- sliding 30 days
  `revoked_at` datetime DEFAULT NULL,
  UNIQUE KEY `mobile_api_tokens_hash` (`token_hash`),
  KEY `mobile_api_tokens_staff` (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mobile_login_attempts` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `email` varchar(200) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` datetime NOT NULL,
  KEY `mobile_login_attempts_email` (`email`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per change uploaded from a phone, so a retried upload is never applied twice.
CREATE TABLE IF NOT EXISTS `mobile_sync_ops` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `staff_id` int NOT NULL,
  `client_op_id` varchar(64) NOT NULL,
  `op_type` varchar(50) NOT NULL,
  `status` varchar(20) NOT NULL,
  `result` mediumtext NOT NULL,
  `created_at` datetime NOT NULL,
  UNIQUE KEY `mobile_sync_ops_op` (`staff_id`, `client_op_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Specialty records from the app (follow-ups, vaccinations, growth, pregnancy). JSON data per record_type.
CREATE TABLE IF NOT EXISTS `clinical_records` (
  `uuid` char(36) NOT NULL PRIMARY KEY,
  `patient_id` int NOT NULL,
  `record_type` varchar(40) NOT NULL,
  `event_date` date DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `data` mediumtext NOT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  KEY `clinical_records_patient` (`patient_id`, `record_type`),
  KEY `clinical_records_updated` (`updated_at`),
  KEY `clinical_records_due` (`record_type`, `status`, `event_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Upgrading an install that already has mobile_api_tokens without subject_type (the API also does this itself):
--   ALTER TABLE mobile_api_tokens ADD subject_type varchar(10) NOT NULL DEFAULT 'staff' AFTER staff_id;

-- Per-doctor app settings (print layout and header languages).
CREATE TABLE IF NOT EXISTS `mobile_staff_settings` (
  `staff_id` int NOT NULL,
  `setting_key` varchar(50) NOT NULL,
  `value` mediumtext NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`staff_id`, `setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Revoke every mobile session of one staff member (e.g. lost phone):
--   UPDATE mobile_api_tokens SET revoked_at = NOW() WHERE staff_id = <id> AND revoked_at IS NULL;
