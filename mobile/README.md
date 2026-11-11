# Skylark Staff mobile app

Flutter app for hospital staff, backed by a small REST API added to this CodeIgniter app.
**Phase 1 is for doctors:** patient history and prescriptions, with full offline support.
Nursing and other roles plug in as more modules later (see [Adding a role](#adding-a-role-eg-nursing)).

```
mobile/
  staff_app/        Flutter app (Android + iOS)
  backend/          SQL for the API tables, curl smoke test
application/
  core/Mobile_api_controller.php        token auth -> same session/RBAC as the web app
  controllers/api/Mobile_auth.php       api/v1/auth/*
  controllers/api/Mobile_sync.php       api/v1/sync/*  (masters, pull, push)
  controllers/api/Mobile_patients.php   api/v1/patients/* (online-only lookups)
  models/Mobileapi_model.php            tokens, login throttling, idempotent op log
  models/Mobiledoctor_model.php         doctor scope, history queries, prescription writes
  config/routes.php                     api/v1/* routes
```

## What a doctor can do

| | Offline | Notes |
|---|---|---|
| Today: OPD visits booked with them, their admitted (IPD) patients, appointments | yes | Shows which visits still need a prescription |
| Search patients on the phone (name, phone, ID) | yes | |
| Search the whole hospital and keep a patient on the phone | needs internet | Respects the hospital's *doctor restriction* setting |
| Patient history: visits/admissions, symptoms, vitals, ICD-10 diagnoses, all prescriptions, lab and radiology results with parameters, timeline | yes | |
| Write / edit a prescription: medicines (dose, frequency, duration, instruction), pathology and radiology tests, findings, notes, advice, new diagnoses | yes | Saved on the phone, uploaded on the next sync |
| Repeat last prescription, apply / save templates | yes | Templates are shared with the web *Smart Prescription* screen |
| Allergy warning when a medicine matches a recorded allergy | yes | Name and drug-class match only; not a full interaction database |
| Full ICD-10 search | needs internet | Offline uses the codes this hospital has used before |

### OPD queue (Today tab)

Today's OPD visits in token order (token = the web `appointment_queue` position), filtered All / Waiting / With
doctor / Seen. **Call next** moves the next waiting token to "with doctor" and opens the patient. Each row: write Rx,
mark seen / skip / back to waiting, history & reports & old prescriptions, add a service, print the visit's
prescription. Queue state is a synced `opd_status` record per visit (same id on every device); a visit with a
prescription counts as seen.

### Services & billing (patient → Billing tab)

Charges, payments and balance for the patient's OPD/IPD cases. **Add service** picks from the hospital charge list
(offline), with quantity and discount; price = charge × qty − discount + tax, exactly like the web charge form. It is
queued as `charge.add`, shown as "waiting to upload", and written to `patient_charges` on sync (permission
`opd_charges` / `charges` → can add; refused for discharged cases). Payments are read-only: they are collected at the
billing counter.

### Printing prescriptions

From a prescription, **Save & print** in the editor, or the queue menu. The preview is exactly what prints:
- Paper **A4 or A5**; print on the **full page, top half or bottom half** (two prescriptions per A4, or the unused
  half of a sheet).
- Header: **hospital header** (text, in the chosen language), the **letterhead image** from the web print settings
  (`print_setting` "opdpre"), **pre-printed paper** (blank space, adjustable mm), or **none** (back side of a page).
- Language: **English, हिंदी, मराठी** (labels, dosing in plain words such as "दिन में 3 बार (TDS)", durations and the
  standard instructions; medicine names stay as written), optional English labels alongside. Header text can be
  entered per language under Account → Print settings, with a default language and layout. Settings sync per doctor.
- **Print** opens the phone's print dialog (USB / Wi-Fi / Bluetooth printers via Android print services or AirPrint);
  **Share PDF** sends it on WhatsApp, email, etc.

Translations in `lib/features/doctor/print/print_strings.dart` should be checked by a native speaker before go-live.

### Insights (Reports tab → Insights)

Last 7 / 30 / 90 days, from data on the phone: OPD visits, patients (new), prescriptions and medicines per Rx, services
billed, follow-ups done/overdue, abnormal reports, visits per day, top diagnoses / medicines / tests, gender and age mix.

### Patient app: booking and live queue

Patients book with a doctor by **date and time slot**: doctors who have OPD shifts, with specialities, fee and working
days; slots are the doctor's shift for that weekday split by the consultation duration (same as the web), booked ones
disabled. The booking is saved as status **pending**, source **"Mobile App"** (the hospital confirms; fee paid at the
hospital). It is deliberately not tagged "Online", because the web cron deletes unpaid "Online" bookings after 10
minutes; the web queue screen treats "Mobile App" bookings like online, slot-based ones. Double booking is prevented
with a database lock. Patients can cancel upcoming appointments.
On the day, a **live queue card** shows the token (once the hospital queues it), patients ahead, who the doctor is
seeing now (from the doctor app's queue) and an estimated wait; it refreshes every minute.

### Medical reports (Reports tab)

All lab and imaging results for the doctor's patients in one inbox, offline: grouped by date, **New** (reported in
the last 14 days and not yet opened on this phone, with a badge on the tab), **Abnormal**, **Awaiting**, Lab /
Imaging, and search by patient or test. Values outside the reference range are flagged high/low wherever reports
appear (doctor and patient apps). Ranges like `12-15`, `<200`, `>40`, `up to 10` are understood; text results and
ranges with several bands (e.g. male/female) are deliberately left unflagged. Reports the lab uploaded as a file
(PDF/image) open in the phone's viewer: downloaded once with the user's token through `report_file`, kept in the
app's private cache for offline viewing, deleted at sign-out.

### Specialty tools (Care tab)

Each doctor gets toolkits matched from their specialty on the hospital system (`staff.specialist` →
specialist names, matched by keyword); with no specialty set, all toolkits are offered. Doctors can switch
toolkits on/off under **Account → Specialty tools**. A toolkit only shows for patients it applies to.

| Toolkit | Matches specialties containing | Shows for | Tools |
|---|---|---|---|
| Follow-ups | (every doctor) | all patients | Schedule/reschedule/complete reviews, also from the prescription editor; **Follow-ups due** on Today |
| Paediatrics | paed, pediat, child, neonat, nicu, infant | under 18 | Vaccination chart (IAP-based schedule, due/overdue from DOB, batch no., given elsewhere, other vaccines), growth (weight, length/height, head circ., MUAC, charts, BMI), weight-based dose calculator |
| Obstetrics & Gynaecology | gyn, obst, matern, antenatal, fertility, ivf, women | women 12–55 | Pregnancy record: LMP/USG EDD, gestational age, trimester, G/P/A/L, risk factors, WHO 8-contact antenatal plan, outcome; obstetric history from the web antenatal module (read-only) |
| Chronic care | medicine, physician, cardio, diabet, endocrin, nephro, geriat, pulmo, chest, neuro, internal | 12 and over | BP (systolic/diastolic), pulse, weight, sugar and other vitals trend charts from existing vitals |

Records are stored in one generic table, `clinical_records` (type + JSON data, keyed by a phone-generated UUID,
soft-deleted), pushed with the `record.save` op and pulled with the patient's history. Patients see their
vaccinations, next review and pregnancy dates in the patient app.

**Clinical content to check before go-live:** the vaccination schedule (`specialty/vaccine_schedule.dart`) follows
IAP recommendations and should be confirmed against the hospital's protocol and the national UIP. Growth charts plot
the child's own measurements without WHO/IAP percentile curves. The dose calculator does arithmetic only; it holds
no drug doses.

**Adding a specialty** (e.g. orthopaedics, ophthalmology): write its cards/screens under
`lib/features/doctor/specialty/`, register a `SpecialtyToolkit` in `specialty_registry.dart` (keywords, which
patients it applies to, cards), and if it saves data add its record type and allowed keys to
`Mobileclinical_model::TYPES` on the server. No new tables or sync code are needed.

Prescriptions are written to the same tables as the web screens (`ipd_prescription_basic` / `_details` / `_test`),
so they appear unchanged in the web app and its print layouts.

## Clinic-in-a-tablet features (offline-first)

### Roles
`Mobileapi_model::$role_modules`: Doctor → `doctor`; Receptionist (and Admin / Super Admin) → `reception`; Nurse → `nursing`;
Pathologist → `lab`; Pharmacist → `pharmacy`. All roles share one offline store and `sync/*`; the server picks what each role
pulls (`Mobilestaff_model::scope_patient_ids`) and every upload checks the matching web permission. The web
*doctor restriction* setting applies to doctors only.

| Section | Tabs | Writes (offline, uploaded on sync) |
|---|---|---|
| Reception | OPD queue of every doctor, Patients, Beds, Account | Register patient + OPD visit, revisit, queue status |
| Nursing | Ward (admitted), OPD triage, Beds, Account | Vitals (`patients_vitals`), nursing notes (`nurse_note`) |
| Lab | Pathology worklist (pending / reported), Account | Parameter values and result, draft or "reported" |
| Pharmacy | Medicine stock: low, out, expiring in 90 days, expired | — (read-only) |

### Offline registration
`patient.register` and `visit.create` reuse the web models (`Patient_model::add_patient`, `User_model::add`,
`Patient_model::add_opd`), so the rows match the reception screens: patient + `pat<id>` login, OPD case, consultation
charge, payment, visit. A patient whose mobile and name match an existing one is linked instead of duplicated. The
consultation charge defaults to the doctor's shift charge; if none is set, reception picks one.
On the device, new patients and visits get negative ids from a per-device block (`RegistrationRepository.nextLocalId`).
Prescriptions, services, vitals and specialty records can be written against them at once; ops that still refer to a
local id wait in the outbox (`SyncModule.isReady`), and registration ops are sent alone (`isBarrier`) so their server ids
are mapped (`remapPatient` / `remapVisit`) before anything that depends on them is built. Walk-in tokens: the provisional
token is replaced by the server's (position among the doctor's visits that day, stored in the visit's `opd_status` record).

### Sharing between devices without internet (hotspot / local Wi-Fi)
Account → Clinic devices. One device is the hub (HTTP on port 47820, UDP beacon on 47821); others connect with its
6-digit code. Requests and answers are HMAC-signed with a key from the code and the hospital server URL. Devices exchange
local registrations, today's queue state and triage vitals every 8 s (`LanStore`). Only the creating device uploads a
record; when it gets server ids it shares the mapping and the others re-link their work (e.g. the doctor's prescription
for a patient reception registered) and upload it. Data is not encrypted beyond the hotspot's own Wi-Fi encryption.

### Specialty packs (configurable)
Dental (FDI tooth chart, plan per tooth, balance), Ophthalmology (VA, refraction, IOP, spectacle Rx PDF), Orthopaedics
(cast removal alert), Dermatology, Physiotherapy (session packages, pain/ROM trends), Ayurveda & Homeopathy, Diabetes
(annual eye/foot/kidney checks, HbA1c and lab trends from reports). Forms are declared as field specs
(`specialty/forms/specialty_forms.dart`); record types prefixed `sp_` are accepted by the server with any safe keys
(`Mobileclinical_model::OPEN_PREFIX`), so a new specialty form needs no server change. These packs are opt-in (by the
doctor's specialty or Account → Specialty tools) and also show whenever the patient already has such records.

### App lock and WhatsApp
App lock: 4–6 digit PIN (salted, iterated SHA-256 in the keystore), optional fingerprint/face, re-lock after 0/1/5/15 min in
the background, escalating wait after 5 wrong PINs; "Forgot PIN" signs out (which deletes local data).
WhatsApp: follow-up reminders (Today and the follow-up card; `reminded_on` is synced) and the prescription as text, in
English, हिंदी or मराठी, opened in the doctor's own WhatsApp via `wa.me` (no message is sent without the user pressing send).

### New sync ops
`patient.register`, `visit.create`, `vitals.save` `{patient_id, measured_at, values{vital_id: value}}`,
`nurse_note.save` `{ipd_id, date, note, comment}`, `lab.result` `{report_id, values{parameterdetail_id: value}, result, complete}`.
`sync/pull` also returns `extras`: `vital_types`, `beds`, `lab_worklist` (lab), `pharmacy_stock` (pharmacy, doctors), and masters
return `opd_doctors`.

### Not done yet
* Photos on dermatology records (needs an upload endpoint and storage quota handling).
* Collecting payments other than at registration; dispensing from pharmacy stock; bed allotment/transfer from the app.
* The write paths of the new ops have been unit-tested against a fake server and their SQL checked read-only against the
  real schema, but not yet run against a live database: test them on a staging copy before go-live.

## Running

**Backend.** Deploy the code as usual; nothing else is required. The three API tables are created on the first mobile
sign-in (or run `mobile/backend/mobile_api_schema.sql` beforehand). The account must have the **Doctor** role, and that
role needs the *Prescription* / *IPD Prescription* add/edit permissions it has on the web.

Smoke test against a server (read-only unless `WRITE_VISIT_ID` is set):

```bash
mobile/backend/smoke_test.sh https://skylark.duobytes.in doctor@example.com
```

**App.**

```bash
cd mobile/staff_app
flutter pub get
flutter test                 # 62 tests: sync, conflicts, patient app, specialty tools, reports, queue, billing, printing, booking
flutter run                  # device or emulator
```

The server address defaults to `AppConfig.defaultServerUrl` (`lib/core/config.dart`) and can be changed on the sign-in screen
("Server: …"). To test against MAMP on your Mac, enter e.g. `http://192.168.1.8:8888` on a phone on the same Wi-Fi, or
`http://10.0.2.2:8888` from the Android emulator. Plain `http` works only in debug builds on Android
(`android/app/src/debug/AndroidManifest.xml`) and only for local-network addresses on iOS (`NSAllowsLocalNetworking`);
release builds need https.

## API (`/api/v1`)

All bodies are JSON. Auth: `X-Auth-Token: <token>` (or `Authorization: Bearer <token>`). Errors are
`{"error": {"code": "...", "message": "..."}}` with a meaningful HTTP status; the app shows `message` as-is.

| Method & path | Purpose |
|---|---|
| `POST auth/login` `{email, password, device_id, device_name, platform, app_version}` | Returns `token`, `staff` (with `role`, `modules`), `hospital` branding. 5 failed attempts per email lock sign-in for 15 min. A new sign-in on the same device revokes that device's previous token. |
| `GET auth/me` | Profile + branding refresh |
| `POST auth/logout` | Revokes the token |
| `GET sync/masters` | Medicines, dose/interval/duration options, pathology & radiology tests, ICD-10 codes in use, templates, the doctor's frequent medicines. Send `If-None-Match` → `304` when unchanged. |
| `POST sync/pull` `{since, known_patient_ids, pinned_patient_ids}` | Full history for patients new to the phone, changes since `since` for the rest. Returns `cursor` (next `since`), `scope_patient_ids` (phone removes others), `prescription_ids` (phone removes prescriptions deleted on the web), `appointments`, `data`. |
| `POST sync/push` `{ops: [{op_id, type, payload}]}` | Up to 50 changes, applied in order. Types: `prescription.save`, `template.save`, `template.delete`. Per-op result `ok` / `rejected` / `conflict` / `error`. Replaying an `op_id` returns the stored result, so retries never duplicate. |
| `POST patient/login` `{username, password, device_id, …}` | **Patient** sign-in with the web patient-portal login (`users`, role `patient`). Refused when the hospital has the patient panel disabled. Returns `token`, `patient`, `modules` (the admin's patient module switches), `hospital`. |
| `GET patient/me`, `POST patient/logout` | Patient profile refresh / sign out |
| `GET patient/data` | The patient's own records, read-only: visits/admissions, diagnoses, prescriptions, lab & imaging reports, vitals, appointments, timeline. Same visibility as the web patient panel: disabled modules are left out, only timeline entries marked visible, no internal staff notes. Supports `If-None-Match` → `304`. |
| `POST sync/push` op `record.save` `{uuid, patient_id, type, event_date, status, data, deleted}` | Specialty record upsert (types: `followup`, `vaccination`, `growth`, `pregnancy`). Last write wins per record. |
| `POST sync/push` op `charge.add` `{kind, case_ref_id, charge_id, qty, discount, note, date}` | Add a service to the visit/admission bill. |
| `POST sync/push` op `settings.save` `{key: "print", value}` | Doctor's print settings (synced across devices; returned in masters `staff_settings`). |
| `GET sync/print_header` | The hospital's OPD prescription letterhead image (authenticated). |
| `GET patient/doctors` · `GET patient/slots?doctor_id=&date=` · `POST patient/book` · `POST patient/cancel` · `GET patient/queue` | Patient booking by slot and live queue position (needs the "My Appointments" patient module). |
| `GET patients/search?q=` | Hospital-wide search |
| `GET patients/bundle?id=` | One patient's full history (to keep on the phone) |
| `GET patients/report_file?kind=pathology\|radiology&id=` | Uploaded report file for a patient the doctor may see (respects doctor restriction). Files are resolved only inside `uploads/`. |
| `GET patient/report_file?kind=…&id=` | The patient's own report file (module must be enabled for patients). |
| `GET patients/icd?q=` | Full ICD-10 search |

**Which patients are on a doctor's phone:** OPD visits with them in the last 365 days (or still open), IPD admissions where they
are consultant or an assigned doctor (open, or in the last 365 days), appointments with them from 30 days ago onward, plus any
patient they chose to keep. Each patient's *complete* history is included, not just this doctor's part.

**`prescription.save` payload:** `kind` (`opd`|`ipd`), `case_ref_id` (visit_details.id or ipd_details.id), `server_id`
(null = new), `base_version`, `force`, `date`, `header_note`, `footer_note`, `finding_description`, `print_findings`,
`items[{id, medicine_id, dosage_id, interval_id, duration_id, instruction}]`, `pathology_ids`, `radiology_ids`, `icd_ids`.
Server rules match the web: dose and interval required per medicine; at least one medicine or test; permission
`prescription`/`ipd_prescription` `can_add`/`can_edit`; with doctor restriction on, only the patient's own doctor; no new
prescription on a discharged visit. OPD prescriptions are recorded as prescribed by the visit's consultant (as on the web);
IPD ones by the signed-in doctor when they are on the admission.

## How offline works

* **Local store:** SQLite encrypted with SQLCipher; the key is random and kept in the Android Keystore / iOS Keychain.
  Signing out, or a different user signing in on the same phone, deletes all patient data. Android cloud backup is off.
* **Writes** go to the local database and an outbox. The upload payload is built from the latest local copy at send time,
  so several edits made offline go up as one change.
* **Sync** runs on app start, on return to foreground, when the network comes back, every 5 minutes while open, and ~2 s
  after a save. Order: push outbox → masters (if changed) → pull.
* **Conflicts:** each prescription carries a server `version`. If someone changed it on the web after the doctor started
  editing, the upload comes back as `conflict`; the app shows both versions and the doctor picks *Keep my version*
  (re-sent with `force`) or *Use server version*.
* **Edge cases handled (and tested):** edit made while its upload is in flight; upload reply lost so the next pull brings a
  duplicate down; transient server errors (kept queued); prescription deleted on the web; patient no longer assigned
  (kept until their unsent work is uploaded); expired token (data kept, only sync pauses until the password is re-entered).

## Adding a role (e.g. nursing)

1. **Server:** add `'Nurse' => array('nursing')` to `Mobileapi_model::$role_modules`; add `controllers/api/Mobile_nursing*.php`
   extending `Mobile_api_controller` with `require_module('nursing')`, plus routes. Reuse `authenticate()`, `can()`, the
   `mobile_sync_ops` idempotency and the patient queries in `Mobiledoctor_model` where they fit (e.g. move the scope query
   into a per-role method).
2. **App:** create `lib/features/nursing/` with a `SyncModule` implementation (pull + op handling) and a home widget, and register
   it in `lib/modules.dart`. Sign-in, encryption, outbox, retries and the sync triggers are shared. Add tables in
   `AppDatabase.onUpgrade` with a version bump.

## Not in phase 1

* Prescription PDF/print from the phone (use the web print; the data is identical).
* Patient notifications on a new prescription. The web sends one only when its form's "visible" option is ticked; the API
  does not send notifications yet.
* Background sync while the app is closed (changes wait safely in the outbox until the app is next opened online).
* App lock (PIN / biometrics) on top of the phone's own lock screen.
* Attachments on prescriptions, and editing visits, vitals or admissions from the phone.
* Rich-text notes: notes written on the web in the rich editor show as plain text on the phone, and if the phone edits that
  prescription the notes are saved back as plain text.

## Release checklist

* Set the app icon (`flutter_launcher_icons` from `assets/brand/app_logo.png`) and signing (Android keystore, iOS team).
* Confirm `AppConfig.defaultServerUrl` is the production address and uses https.
* Run `mobile/backend/smoke_test.sh` against production with a Doctor account.
* `flutter build appbundle` / `flutter build ipa`.
