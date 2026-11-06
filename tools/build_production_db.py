#!/usr/bin/env python3
"""
Builds SKYLARK_PRODUCTION_DB.sql — a clean, ready-to-import production database
for Skylark Hospital, Akola — from the v7.0 base schema backup.

    python3 tools/build_production_db.py --admin-password 'Choose-A-Strong-One'
    python3 tools/build_production_db.py            # generates a random admin password and prints it

What it does to the base dump:
  * adds a header (SET NAMES, FOREIGN_KEY_CHECKS=0, sql_mode, time zone) so a plain
    `mysql db < file.sql` or phpMyAdmin import works — the raw dump has 300+ FKs and fails without it
  * rewrites the MariaDB-11-only collation utf8mb3_uca1400_ai_ci to utf8 / utf8_general_ci
    (same as the app connection in config/database.php) so it imports on MySQL 5.7/8.x and MariaDB 10.x+
  * drops the developer's login-history row
  * refuses to run unless the target database is EMPTY (the dump DROPs every table)
  * appends the Skylark configuration: hospital details, India locale, theme, Brand Kit,
    ID cards, front-site contact, super admin, and the module tables that otherwise self-create

Python 3.8+, and `php` on PATH (used for the bcrypt password hash, same as the app).
"""
import argparse
import os
import re
import secrets
import string
import subprocess
import sys
from datetime import date

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BASE_DUMP = os.path.join(ROOT, 'backup', 'database_backup', 'db_ver_7.0_2026-10-10_17-09-28.sql')
OUT = os.path.join(ROOT, 'SKYLARK_PRODUCTION_DB.sql')

# ---------------------------------------------------------------- client details
# Source: hospital file cover (brochure) + public listing, supplied by the client.
HOSPITAL = {
    'name': 'Skylark Hospital',
    'email': 'skylarkhospitalakola@gmail.com',
    'phone': '0724-2992001',
    'address': 'Beside LIC Office, Tower-Umri Road, Shrawagi Plot, Ratanlal Chowk, Akola, Maharashtra',
    'currency': 'INR',
    'currency_symbol': '₹',
    'timezone': 'Asia/Kolkata',
    'date_format': 'd-m-Y',
    'time_format': '12-hour',
    'start_month': '4',           # Indian financial year starts in April
    'logo': 'skylark_logo.png',
    'mini_logo': 'skylark_mini_logo.png',
    'app_logo': 'skylark_app_logo.png',
}
BRAND = {
    'tagline': 'Multispeciality Under One Roof',
    'local_name': 'स्कायलार्क हॉस्पीटल',
    'city': 'Ratanlal Chowk, Akola',
    'emergency_phone': '7350001592 / 93 / 94 / 95',
    'website': 'www.skylarkhospital.com',
    'hours_text': 'Open 24×7',
    'highlights': '\n'.join([
        'Multispeciality care under one roof',
        'Cashless treatment (insurance / TPA)',
        'Laparoscopic surgery',
        'Colorectal surgery',
        'Open 24 hours, all 7 days',
    ]),
    'inauguration_title': 'Digital Hospital Management System',
    'inauguration_guest': '',      # set the chief guest in Brand Kit before the event
    'welcome_message': 'High-quality healthcare for patients of all ages, open 24 hours a day, every day of the week.',
}
THEME = {
    'theme_preset': 'skylark', 'primary_color': '#1A7A7E', 'font_color': '#0E2A2C',
    'font_family': 'Nunito', 'corner_radius': 'rounded', 'text_size': 'normal', 'density': 'comfortable',
}
ADMIN_EMAIL = HOSPITAL['email']


def q(value):
    """SQL string literal (MySQL/MariaDB escaping)."""
    if value is None:
        return 'NULL'
    s = str(value).replace('\\', '\\\\').replace("'", "\\'").replace('\n', '\\n').replace('\r', '\\r')
    return "'" + s + "'"


def sets(d):
    return ', '.join('`%s` = %s' % (k, q(v)) for k, v in d.items())


def bcrypt(password):
    php = "echo password_hash($argv[1], PASSWORD_DEFAULT);"
    out = subprocess.run(['php', '-r', php, password], check=True, capture_output=True, text=True).stdout.strip()
    if not out.startswith('$2y$'):
        sys.exit('php password_hash failed')
    return out


def random_password():
    alphabet = string.ascii_letters + string.digits
    core = ''.join(secrets.choice(alphabet) for _ in range(10))
    return 'Sky@' + core


def transform_dump(sql):
    for bad in ('CREATE VIEW', 'CREATE TRIGGER', 'CREATE PROCEDURE', 'CREATE FUNCTION', 'DEFINER='):
        if bad in sql:
            sys.exit('base dump contains %s — review before generating' % bad)
    sql = sql.replace('COLLATE=utf8mb3_uca1400_ai_ci', 'COLLATE=utf8_general_ci')
    sql = sql.replace('COLLATE utf8mb3_general_ci', 'COLLATE utf8_general_ci')
    sql = sql.replace('CHARSET=utf8mb3', 'CHARSET=utf8')
    sql = sql.replace('CHARACTER SET utf8mb3', 'CHARACTER SET utf8')
    if 'uca1400' in sql or 'utf8mb3' in sql:
        sys.exit('unexpected utf8mb3/uca1400 left in dump')
    # developer login history
    sql, n = re.subn(r"^INSERT INTO `userlog` .*\n", '', sql, flags=re.M)

    # developer's email + password hash on the base super-admin row (the real values are set further down)
    def scrub(m):
        line = re.sub(r"'\$2y\$[^']+'", "''", m.group(0))
        return re.sub(r"'[^'@\s]+@[^'\s]+'", q(ADMIN_EMAIL), line)
    sql, n = re.subn(r"^INSERT INTO `staff` .*$", scrub, sql, flags=re.M)
    if n != 1 or '$2y$' in sql:
        sys.exit('expected exactly one base staff row without a password hash')
    return sql


HEADER = """-- =====================================================================
-- SKYLARK HOSPITAL, AKOLA — production database (fresh install)
-- Generated {today} by tools/build_production_db.py from db_ver_7.0 base schema.
--
-- Import into an EMPTY database only:
--     mysql -u <user> -p <database> < SKYLARK_PRODUCTION_DB.sql
--   or phpMyAdmin > select the empty database > Import.
-- The script stops on its first statement if the database already has tables.
--
-- Contains no patients, staff (except the super admin), bills or demo data.
-- =====================================================================

SET NAMES utf8mb4;
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS = 0;
SET @OLD_SQL_MODE = @@SQL_MODE, SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+05:30';

-- Safety: abort unless the target database is empty (a second row in the subquery raises an error).
SELECT IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()) = 0,
          'ok: empty database',
          (SELECT 'ABORT: database is not empty - create a new empty database for this import' UNION SELECT 'x')) AS safety_check;

"""

MODULE_TABLES = """
-- ---------------------------------------------------------------------
-- Module tables (Case Sheet, Smart Rx, ABDM). The app creates these on first
-- use; creating them here keeps a fresh install complete from day one.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ipd_casesheet` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY, `ipd_id` int NOT NULL,
  `admitted_by_name` varchar(150) DEFAULT NULL, `admitted_by_relation` varchar(60) DEFAULT NULL, `admitted_by_phone` varchar(30) DEFAULT NULL,
  `consent_language` varchar(10) NOT NULL DEFAULT 'mr', `consent_signed_by` varchar(150) DEFAULT NULL,
  `consent_witness1` varchar(150) DEFAULT NULL, `consent_witness2` varchar(150) DEFAULT NULL, `consent_at` datetime DEFAULT NULL,
  `chief_complaints` text, `history` text, `examination` text, `admission_vitals` text, `provisional_diagnosis` text,
  `lab_findings` text, `initial_advice` text, `final_diagnosis` text, `procedures` text, `course_in_hospital` text,
  `condition_at_discharge` text, `discharge_advice` text, `diet_advice` varchar(255) DEFAULT NULL,
  `follow_up_date` date DEFAULT NULL, `follow_up_note` varchar(255) DEFAULT NULL, `emergency_note` text,
  `discharge_at` datetime DEFAULT NULL, `summary_by` int DEFAULT NULL, `discharge_rx` mediumtext,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `ipd_casesheet_ipd` (`ipd_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ipd_nursing_chart` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY, `ipd_id` int NOT NULL, `chart_date` date NOT NULL,
  `special_instructions` text, `vitals` mediumtext, `investigations` text, `stat_drugs` mediumtext,
  `iv_fluids` mediumtext, `io` mediumtext, `insulin` mediumtext, `updated_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `ipd_nursing_chart_day` (`ipd_id`, `chart_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ipd_med_chart` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY, `ipd_id` int NOT NULL, `drug` varchar(150) NOT NULL,
  `dose` varchar(60) DEFAULT NULL, `route` varchar(30) DEFAULT NULL, `frequency` varchar(20) DEFAULT NULL,
  `slots` varchar(120) DEFAULT NULL, `start_date` date NOT NULL, `stop_date` date DEFAULT NULL,
  `instruction` varchar(255) DEFAULT NULL, `ordered_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `ipd_med_chart_ipd` (`ipd_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ipd_med_admin` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY, `med_id` int NOT NULL, `admin_date` date NOT NULL,
  `slot` varchar(5) NOT NULL, `given_by` int DEFAULT NULL, `given_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `ipd_med_admin_dose` (`med_id`, `admin_date`, `slot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rx_templates` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY, `staff_id` int NOT NULL, `name` varchar(100) NOT NULL,
  `is_shared` tinyint(1) NOT NULL DEFAULT 0, `payload` mediumtext NOT NULL, `use_count` int NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `rx_templates_staff` (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `abdm_patients` (
  `patient_id` int NOT NULL PRIMARY KEY, `abha_number` varchar(20) DEFAULT NULL, `abha_address` varchar(100) DEFAULT NULL,
  `abha_name` varchar(150) DEFAULT NULL, `verified_by` int DEFAULT NULL, `verified_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `abdm_patients_addr` (`abha_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `abdm_sync` (
  `entity` varchar(12) NOT NULL, `entity_id` int NOT NULL, `patient_id` int NOT NULL, `reference` varchar(64) NOT NULL,
  `hash` char(64) DEFAULT NULL, `linked` tinyint(1) NOT NULL DEFAULT 0, `synced_at` datetime DEFAULT NULL,
  `checked_at` datetime DEFAULT NULL, `error` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`entity`, `entity_id`), KEY `abdm_sync_patient` (`patient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Brand Kit (Setup > Settings > Brand Kit). Same DDL as Hospital_brand_model::ensure_schema().
CREATE TABLE IF NOT EXISTS `hospital_brand_settings` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `tagline` varchar(150) DEFAULT NULL, `local_name` varchar(150) DEFAULT NULL, `city` varchar(80) DEFAULT NULL,
  `emergency_phone` varchar(60) DEFAULT NULL, `website` varchar(150) DEFAULT NULL, `hours_text` varchar(100) DEFAULT NULL,
  `highlights` text, `welcome_message` varchar(255) DEFAULT NULL,
  `show_login_showcase` tinyint(1) NOT NULL DEFAULT 1, `show_dashboard_banner` tinyint(1) NOT NULL DEFAULT 1,
  `inauguration_enabled` tinyint(1) NOT NULL DEFAULT 1, `inauguration_guest` varchar(150) DEFAULT NULL,
  `inauguration_title` varchar(150) DEFAULT NULL, `inaugurated_at` datetime DEFAULT NULL, `inaugurated_by` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
"""


def config_section(pw_hash):
    h = HOSPITAL
    sch = {
        'name': h['name'], 'email': h['email'], 'phone': h['phone'], 'address': h['address'],
        'dise_code': '', 'date_format': h['date_format'], 'time_format': h['time_format'],
        'currency': h['currency'], 'currency_symbol': h['currency_symbol'], 'timezone': h['timezone'],
        'start_month': h['start_month'], 'image': h['logo'], 'mini_logo': h['mini_logo'], 'app_logo': h['app_logo'],
        'app_primary_color_code': THEME['primary_color'], 'app_secondary_color_code': '#DCEFEE',
        'cron_secret_key': secrets.token_hex(16),
    }
    brand = dict(BRAND, id=1, show_login_showcase=1, show_dashboard_banner=1, inauguration_enabled=1)
    cols = ', '.join('`%s`' % k for k in brand)
    vals = ', '.join(q(v) for v in brand.values())
    return """
-- ---------------------------------------------------------------------
-- Skylark Hospital configuration
-- ---------------------------------------------------------------------
UPDATE `sch_settings` SET {sch} WHERE `id` = 1;

UPDATE `hospital_theme_settings` SET {theme} WHERE `id` = 1;
DELETE FROM `user_theme_preferences`;

INSERT INTO `hospital_brand_settings` ({bcols}) VALUES ({bvals});

UPDATE `patient_id_card` SET `title` = 'Patient ID Card', `hospital_name` = {name}, `hospital_address` = {addr}, `header_color` = {color} WHERE `id` = 1;
UPDATE `staff_id_card`   SET `title` = 'Staff ID Card',   `hospital_name` = {name}, `hospital_address` = {addr}, `header_color` = {color} WHERE `id` = 1;

UPDATE `front_cms_settings` SET `contact_us_email` = {email}, `complain_form_email` = {email},
  `footer_text` = {footer} WHERE `id` = 1;

-- Super admin (login = email below; temporary password handed over separately — change it on first login)
UPDATE `staff` SET `name` = 'Administrator', `surname` = '', `email` = {admin_email}, `password` = {pw},
  `verification_code` = '', `created_at` = NOW(), `updated_at` = NOW() WHERE `id` = 1;
""".format(
        sch=sets(sch), theme=sets(THEME), bcols=cols, bvals=vals,
        name=q(h['name']), addr=q(h['address']), color=q(THEME['primary_color']), email=q(h['email']),
        footer=q('© %s · %s' % (h['name'], BRAND['city'])), admin_email=q(ADMIN_EMAIL), pw=q(pw_hash),
    )


FOOTER = """
SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
SET SQL_MODE = @OLD_SQL_MODE;

-- Quick check after import (should show Skylark Hospital / skylark / 1 staff / 0 patients)
SELECT s.name AS hospital, t.theme_preset AS theme, (SELECT COUNT(*) FROM staff) AS staff, (SELECT COUNT(*) FROM patients) AS patients
FROM sch_settings s JOIN hospital_theme_settings t ON t.id = 1 WHERE s.id = 1;
"""


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--admin-password', help='super admin password (default: random, printed once)')
    ap.add_argument('--out', default=OUT)
    args = ap.parse_args()

    password = args.admin_password or random_password()
    if len(password) < 8:
        sys.exit('admin password must be at least 8 characters')

    with open(BASE_DUMP, encoding='utf-8') as f:
        body = transform_dump(f.read())

    sql = HEADER.format(today=date.today().isoformat()) + body + MODULE_TABLES + config_section(bcrypt(password)) + FOOTER
    with open(args.out, 'w', encoding='utf-8') as f:
        f.write(sql)

    print('wrote', os.path.relpath(args.out, ROOT), '(%d KB)' % (len(sql.encode()) // 1024))
    print('super admin login:', ADMIN_EMAIL)
    if not args.admin_password:
        print('temporary password:', password)


if __name__ == '__main__':
    main()
