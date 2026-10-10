# ABDM integration for Smart Hospital

Smart Hospital (on Hostinger shared hosting) connects to the **ABDM bridge** (a separate service on a small VPS). The bridge handles the ABDM gateway protocol, encryption and callbacks; Smart Hospital provides the screens and the clinical data.

```
Staff ─▶ Smart Hospital (PHP, Hostinger) ──HTTPS + API key──▶ ABDM bridge (Docker, VPS) ◀──▶ ABDM gateway
```

## What staff get

| Where | Feature |
|---|---|
| Patient profile (OPD and IPD) → **ABHA / ABDM** button | Verify an existing ABHA (ABHA number, mobile or Aadhaar OTP) or create a new one via Aadhaar OTP; download the ABHA card and QR; see which records are shared and linked; **Link to ABHA** |
| Sidebar → **ABDM / ABHA** → Scan & Share queue | Patients who scan the hospital QR in their ABHA app appear with a token number; register new patients in one click |
| Sidebar → **ABDM / ABHA** → Records from other hospitals | Send a consent request; once the patient approves in their ABHA app, fetch and read records from other hospitals (deleted automatically when consent ends) |
| Sidebar → **ABDM / ABHA** → ABDM status | Bridge connection, sync counts, sync errors, **Sync now** |

Records shared automatically (from the configured start date):

| Smart Hospital | ABDM record type | Reference |
|---|---|---|
| OPD visit (symptoms, vitals, ICD-10 diagnosis, medicines, note) | OPConsultation | `OPD-<visit id>` |
| OPD/IPD prescription | Prescription | `RX-<prescription id>` |
| Approved pathology report (values, units, reference ranges, result) | DiagnosticReport | `LAB-<report id>` |
| Approved radiology report | DiagnosticReport | `RAD-<report id>` |
| Discharge card (diagnosis, ICD-10, investigations, treatment, notes) | DischargeSummary | `DS-<discharge id>` |

Patients can also find and link these records from their ABHA app themselves; the bridge sends them an OTP by SMS.

## Files

New:
```
application/config/abdm.php
application/controllers/admin/Abdm.php
application/controllers/Abdmcron.php
application/libraries/Abdm_client.php
application/models/Abdm_model.php
application/views/admin/abdm/        (_nav, index, patient, consent, share)
backend/abdm/abdm.js
```
Changed (small additions only):
```
application/views/layout/sidebar.php            ABDM menu entry
application/views/admin/patient/profile.php     ABHA / ABDM button
application/views/admin/patient/ipdprofile.php  ABHA / ABDM button
index.php                                       ENVIRONMENT can be set via CI_ENV (default stays 'production')
```
Database: tables `abdm_patients` and `abdm_sync`, plus the **ABDM** permission group, are created automatically the first time an admin opens an ABDM page. Nothing in existing tables is changed.

## Go-live steps

1. **Deploy the bridge** on a VPS with a domain such as `abdm.yourhospital.in` (see the bridge README: `docker compose --profile https up -d --build`). Point the ABDM bridge URL at it with `node scripts/bridge.js set-url`.
2. **Create the API key** on the bridge server:
   `docker compose exec app node scripts/create-api-key.js --facility SBX_001823 --name "Smart Hospital"`
3. **Upload the files** above to Hostinger (File Manager or FTP). Do **not** upload `application/config/development/`.
4. **Edit `application/config/abdm.php`** on Hostinger:
   - `abdm_bridge_url` → `https://abdm.yourhospital.in`
   - `abdm_api_key` → the key from step 2
   - `abdm_cron_key` → a long random string
   - `abdm_sync_from` → the first date whose records should be shared
5. **Add the cron job** in hPanel → Advanced → Cron Jobs, every 10 minutes:
   ```
   /usr/bin/php /home/u407293162/domains/<your-domain>/public_html/index.php abdmcron sync
   ```
   If CLI cron isn't available: `curl -s "https://<your-hms>/abdmcron/sync?key=<abdm_cron_key>"`
6. **Open ABDM / ABHA** as Super Admin once. This creates the tables and permissions; check that it shows **Connected**.
7. **Grant permissions** in Setup → Roles → Assign Permission → **ABDM** for Doctor, Receptionist, etc.:
   - `ABDM ABHA`: front desk and doctors
   - `ABDM Health Records`: doctors and records staff
   - `ABDM Consent Requests`: doctors
   - `ABDM Settings`: admins

## Notes

- **ABHA is attached only after OTP verification.** Smart Hospital re-reads the profile from ABDM with the patient's token, so staff can't type in someone else's ABHA.
- **Changes after sharing:** an edited report or prescription is re-sent on the next sync, and the patient's ABHA app is told that new data is available.
- **Never change `abdm_mrn_prefix` after go-live.** Patients' linked records are keyed by it.
- **Logs:** CodeIgniter logs are in `application/logs`, and the bridge keeps the full ABDM audit trail.
