# ABDM integration for Smart Hospital

Smart Hospital (on Hostinger shared hosting) connects to the **ABDM bridge** (a separate service on a small VPS). The bridge handles the ABDM gateway protocol, encryption and callbacks; Smart Hospital provides the screens and the clinical data.

```
Staff ─▶ Smart Hospital (PHP, Hostinger) ──HTTPS + API key──▶ ABDM bridge (Docker, VPS) ◀──▶ ABDM gateway
```

## What staff get

| Where | Feature |
|---|---|
| Patient profile (OPD and IPD) → **ABHA / ABDM** button | Verify an existing ABHA (ABHA number, mobile or Aadhaar OTP) or create a new one via Aadhaar OTP; download the ABHA card and QR; see which records are shared and linked; **Link to ABHA** |
| Sidebar → **ABDM / ABHA** → Create / Verify ABHA | M1 without choosing a patient first. Verify an ABHA by ABHA number (Aadhaar or mobile OTP), ABHA address (methods offered by ABDM), mobile only (find a forgotten ABHA: list, pick, then OTP) or Aadhaar OTP; or create one via Aadhaar OTP. Every OTP has Resend (after 60 s, twice). Then: an ABHA already on a patient shows an ABHA vs hospital comparison; a likely returning patient (mobile, name, gender and age all match) is linked instead of registered again; otherwise a registration form filled from ABHA (name, DOB and gender locked) registers the patient. When the hospital's mobile differs from the ABHA's, staff can update the ABHA mobile by OTP |
| Sidebar → **ABDM / ABHA** → Scan & Share queue | Patients who scan the hospital QR in their ABHA app appear with a token number (valid 60 minutes) and are registered automatically, by the page and by the cron. A known ABHA number with a new ABHA address updates that patient; a full demographic match is reused |
| Sidebar → **ABDM / ABHA** → Records from other hospitals | Send a consent request (the patient must already have a verified ABHA here). Each request shows the patient's name, ABHA address and number, HI types, period, consent expiry, and the time of every status (requested, granted, denied, revoked, expired); what each hospital granted is shown beside it, marked where the patient edited it. The list refreshes every 30 seconds. Received records are filtered by HI type and shown readably (results, medicines, diagnoses, immunizations, invoices, documents); they are deleted automatically when consent ends |
| Sidebar → **ABDM / ABHA** → ABDM status | Bridge connection, sync counts, sync errors, **Sync now** (which also links new records to patients' ABHAs) |
| Sidebar → **ABDM / ABHA** → Lab codes (LOINC) | A LOINC code per pathology parameter, so lab results are shared coded. Suggestions for about 30 common tests are shown but used only once staff confirm them; every code is checked for its LOINC check digit. Saving a code re-sends the lab reports that use the test |
| Sidebar → **ABDM / ABHA** → Our records shared | Consents ABDM sent us for this hospital's records (patient, requester, purpose, valid till) and each data transfer, with errors |
| Sidebar → **ABDM / ABHA** → Bridge activity | Bridge background jobs (failed jobs with **Retry**) and the ABDM audit log for this hospital |

Records shared automatically (from the configured start date):

| Smart Hospital | ABDM record type | Reference |
|---|---|---|
| OPD visit (symptoms, vitals, ICD-10 diagnosis, medicines, note) | OPConsultation | `OPD-<visit id>` |
| OPD/IPD prescription | Prescription | `RX-<prescription id>` |
| Approved pathology report (values, units, reference ranges, result) | DiagnosticReport | `LAB-<report id>` |
| Approved radiology report | DiagnosticReport | `RAD-<report id>` |
| Discharge card (diagnosis, ICD-10, investigations, treatment, notes) | DischargeSummary | `DS-<discharge id>` |

Records are **linked to the patient's ABHA automatically**: each cron run (and **Sync now**) links every shared record of a patient with an ABHA, one link job per patient at a time; the link token is reused for 6 months. When a linked record is edited, the patient's ABHA app is told that new data is available. Lab results carry the LOINC codes set under **Lab codes**.

Patients can also find and link these records from their ABHA app themselves; the bridge matches them by ABHA, then by mobile with a fuzzy name match (word order, initials, spelling variants and one typo are tolerated), gender and year of birth, and sends them an OTP by SMS.

## Files

New:
```
application/config/abdm.php
application/controllers/admin/Abdm.php
application/controllers/Abdmcron.php
application/libraries/Abdm_client.php
application/models/Abdm_model.php
application/views/admin/abdm/        (_nav, _m1_forms, abha, index, patient, consent, share, hip, activity, loinc)
backend/abdm/abdm.js
```
Changed (small additions only):
```
application/views/layout/sidebar.php            ABDM menu entry
application/views/admin/patient/profile.php     ABHA / ABDM button
application/views/admin/patient/ipdprofile.php  ABHA / ABDM button
index.php                                       ENVIRONMENT can be set via CI_ENV (default stays 'production')
```
Database: tables `abdm_patients`, `abdm_sync`, `abdm_consents` and `abdm_loinc` (the LOINC code per pathology parameter) (who took the ABHA-creation consent, when, and which text version; no Aadhaar is stored), plus the **ABDM** permission group, are created automatically the first time an admin opens an ABDM page. A unique index on `abdm_patients.abha_number` (one ABHA per patient) is added at the same time, **only if no ABHA number is already on two patients**; otherwise the duplicates are written to the log and the index is skipped. Check before go-live with:

```sql
SELECT abha_number, GROUP_CONCAT(patient_id) FROM abdm_patients WHERE abha_number IS NOT NULL GROUP BY abha_number HAVING COUNT(*) > 1;
```

Nothing in existing Smart Hospital tables is changed; patients registered from ABHA also fill `email`, `state`, `district`, `city_village`, `pincode` and `address_line` where those columns exist.

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
   - `ABDM Settings`: admins (view: status, bridge activity; edit: **Sync now**, **Retry** failed jobs)

   `ABDM Health Records` (view) also opens **Our records shared**.

## Notes

- **The bridge must be updated with this release.** The new screens call bridge routes added at the same time (`/m1/address/*`, `/m1/find/*`, `/m1/profile/mobile/*`, `/m1/enrol/address/rules`), and the consent screen reads the status history added by bridge migration `005_consent_history.sql` (applied automatically when the bridge starts). Deploy the bridge first.
- **Bridge hosting:** the bridge runs as a Node.js app (Hostinger web app from GitHub) with its database on **Neon Postgres** (`DATABASE_URL` pooled, `DATABASE_URL_UNPOOLED` for migrations; build command: none, start command: `npm start`). Its worker does not poll an idle database, so Neon can scale to zero; housekeeping (erasing expired records, link timeouts) runs when Smart Hospital's cron calls the bridge, with a 30-minute fallback. Each cron run wakes Neon for about five minutes, so a 10-minute cron keeps it awake about half the time; 15 minutes is a good balance on Neon's free plan.
- **M1 test cases:** the plan and the status of every NHA case are in `INTEGRATION-PLAN.md`; the ABDM skill is in `.claude/skills/abdm-m1/`.
- **ABHA is attached only after OTP verification.** Smart Hospital re-reads the profile from ABDM with the patient's token, so staff can't type in someone else's ABHA.
- **Changes after sharing:** an edited report or prescription is re-sent on the next sync, and the patient's ABHA app is told that new data is available.
- **Never change `abdm_mrn_prefix` after go-live.** Patients' linked records are keyed by it.
- **Logs:** CodeIgniter logs are in `application/logs`. The bridge keeps the full ABDM audit trail; this hospital's part of it is under **Bridge activity**.
- **Bridge version:** the **Our records shared** and **Bridge activity** tabs need a bridge that has `/api/hms/admin` (`src/hms/admin.js`). Bridge staff accounts and facility registration stay in the bridge console.
