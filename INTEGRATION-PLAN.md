# ABDM M1 integration plan: Smart Hospital (Skylark)

Skill: `.claude/skills/abdm-m1` (catalogue 2026.09.16, test set revised 21 Oct 2026)
Entity type: **private**
Started: 2026-11-26 · Steps done: **12 of 14 (86%)**: every code step is done; the sandbox run and the tester's run are blocked on credentials and people

## Goal

Pass every M1 case NHA marks mandatory for a private facility. M1 already exists here, so each feature starts at **Test**: audit the code against its cases, fix the gaps, then run the cases with a tester.

## Scope

In scope: the 7 features with mandatory cases for a private entity (1–7 in the skill's build order).
Out of scope:
- Optional features 8–15 (update mobile as a feature on its own, QR read, face, biometric, driving licence), unless you ask for them. Feature 8's call is reused in step 6 for the "update mobile? Yes/No" option.
- Features 16 and 17: government only, recorded NA.
- M2 and M3, which this plan doesn't change.

## Where M1 lives (codebase survey)

| Item | Where |
|---|---|
| Languages | PHP 7/8 on CodeIgniter 3 (this repo); Node/Express ABDM bridge (separate repo, `~/Documents/Project-2026/abdm`) |
| Frontend ↔ backend | `backend/abdm/abdm.js` (vanilla JS) → `application/controllers/admin/Abdm.php` (AJAX, CSRF header `X-ABDM-Token`) |
| Outbound HTTP client | PHP: `application/libraries/Abdm_client.php` → bridge. Bridge: `src/m1/abha.js` `call()` → ABHA v3 |
| Secrets and config | `.env` → `application/config/abdm.php` (bridge URL, API key); ABDM client id and secret live only in the bridge |
| Patient model and ABHA fields | `patients` table; ABHA in `abdm_patients` (`patient_id` PK, `abha_number`, `abha_address`), `application/models/Abdm_model.php` |
| Inbound callbacks | Bridge only (`src/callbacks.js`, e.g. `/api/v3/hip/patient/share`); needs the bridge's public URL |
| Crypto | Bridge `enc()` in `src/m1/abha.js` (RSA-OAEP SHA-1, key from `/v3/profile/public/certificate`) |
| Errors shown and logged | toastr in `abdm.js`; CI logs in `application/logs`; bridge audit log (shown on **Bridge activity**) |
| Test environment | local DB `sh_hrms_test` with `php -S`. **Never the live DB in `.env`** |

## Audit: mandatory cases against the current code

This is a code reading, not a test result. Real results come from the workbook run and are written by `abdm_report.py tracker` into the tracker block below.

| # | Feature | Case | Current code | Gap → fix step |
|---|---|---|---|---|
| 1 | Create by Aadhaar OTP | CRT_ABHA_101 | "Create new ABHA" tab | — |
| | | CRT_ABHA_104 | Bridge rejects with "Aadhaar must be 12 digits"; no check in the browser; not masked | Show "Aadhaar Number is not valid" in the browser before any call; mask the input → **S2** |
| | | CRT_ABHA_102 | Consent text and checkbox present; audit records `abha.enrol.otp` only | Check the text against the current approved wording; record consent (staff, time, version) → **S2** |
| | | CRT_ABHA_105 | 6-digit `maxlength`, API message shown | Accept digits only; message on short OTP → **S3** |
| | | CRT_ABHA_106 | **No resend** | Resend control shared by every OTP screen: enabled after 60 s, max 2, visible counter, message "Maximum OTP resend attempts reached. Please try again after 30 mins" → **S3** |
| | | CRT_ABHA_107 | Verify works; API errors shown | — |
| | | CRT_ABHA_108 | Compares the last 4 digits of `ABHAProfile.mobile` with the entered mobile | Works when the two match |
| | | CRT_ABHA_109 | **Likely broken**: when the mobile differs, ABDM probably returns no profile mobile, so the step that sends the mobile OTP never appears. The address step also doesn't wait for the mobile to be verified | Show the mobile-OTP step when the mobile is empty or differs; add resend; finish it before choosing an address → **S4** |
| | | CRT_ABHA_112 (conditional) | Suggestions and a custom address exist. Bridge accepts 4–32 characters; no rules shown; no "already exists" branch | Rules shown next to the field, ticking green/red as they're met (8–18 characters, one `.` and/or one `_`, not at the start or end); "ABHA address already exists." with *use existing / create new* → **S4** |
| | | CRT_ABHA_113 | Profile card shows the number and address | — |
| | | CRT_ABHA_114 | Card opens in a new tab | Add a real download → **S4** |
| | | CRT_ABHA_116 | Matches on ABHA only; the mobile search runs but staff choose by hand | Match on mobile + name + gender + age (and UHID if given); a full match reuses that UHID → **S7** |
| 2 | Login by ABHA number | VRFY_ABHA_101, 201 | Aadhaar and mobile OTP work | Resend (**S3**). Confirmation form with name, DOB and gender locked and the rest editable, instead of saving unseen (**S6**). "Update mobile on ABHA? Yes/No" when the hospital's mobile differs (**S6**) |
| 3 | Login by ABHA address | VRFY_ABHA_102, 202 | **Missing** (bridge, PHP and UI) | New flow on `/v3/phr/web/login/abha/*`, with auth methods read from the search response → **S8** |
| 4 | Find ABHA by mobile | VRFY_ABHA_301–308 | **Wrong order**: "Mobile · OTP" sends the OTP *before* the profile is chosen (302 and 304 forbid this). The bridge has `/search/mobile` but nothing from PHP calls it | New flow: validate the mobile → search → list masked profiles → pick one → OTP with `loginHint: index` → open only that profile. Exact messages for 301, 303 and 307; comparison screen for 308 → **S9** |
| 5 | Login by Aadhaar OTP | VRFY_ABHA_401–405 | Method exists | 401: message (**S2**). 403: on ABDM-1114, show "NO ABHA user registered with this Aadhaar Number" and offer to create one without asking for the Aadhaar again (**S10**). 405: resend (**S3**) |
| 6 | One ABHA per patient | TAGGING_… | Checked in code before attach or register | No database constraint, so two requests at once could tag the same ABHA twice. Add a unique index on `abha_number` (check the live data for duplicates first) → **S11** |
| 7 | Scan and share | SHARE_PATIENT_PROFILE_701 | Bridge issues tokens per counter; the HMS registers on one click | The tester expects registration with no manual step: auto-register on refresh, or accept a conditional pass → **S12** |
| | | SHARE_PATIENT_PROFILE_702 | Matches on ABHA only. Repeat scans and different counters are already handled in the bridge (`src/m2/hip.js`) | A known patient scanning with a **different ABHA address** creates a duplicate. Match on demographics too and update the address on the existing UHID → **S12** |

Correction to the audit: S5 isn't needed. The bridge's `src/lib/audit.js` already masks `mobile`, `loginId`, `otpValue`, tokens and photos before writing the audit log.

Found while building: the bridge answered a Scan & Share with a token valid for 30 minutes; SHARE_PATIENT_PROFILE_701 asks for 60. Fixed in `src/m2/hip.js`.

## Steps

| # | Step | Repo | Exit condition | Status | Evidence | Date |
|---|---|---|---|---|---|---|
| S1 | Install the skill and survey the code | HMS | Skill complete (30 files, links resolve); survey and audit above | done | `.claude/skills/abdm-m1/`, this file | 2026-11-26 |
| S2 | Aadhaar validation and consent record | HMS + bridge | Invalid Aadhaar shows "Aadhaar Number is not valid" with no network call; consent saved with staff, time and version | done | Verhoeff check in `abdm.js` and bridge `validAadhaar`; masked input with show toggle; `abdm_consents` row per OTP, carried to the ABHA number and patient. Checks CRT_ABHA_104, CRT_ABHA_102 ×3, VRFY_ABHA_401 pass | 2026-11-26 |
| S3 | Shared OTP input and resend control | HMS | Every OTP screen: digits only, resend enabled after 60 s, max 2, counter visible, the third attempt shows the 30-minute message | done | `resender()` and `wireOtp()` in `backend/abdm/abdm.js`, used by all 6 OTP boxes; OTP length enforced by the bridge (check CRT_ABHA_105). Not yet clicked through in a browser: see S14 | 2026-11-26 |
| S4 | Create flow: mobile branch, address rules, card download | HMS + bridge | A mobile that differs always reaches the mobile OTP; address rules shown green/red and enforced in the bridge; "already exists" branch; card downloads | done | Bridge returns `mobileVerificationNeeded`; mobile OTP sent automatically. Rules list ticks green/red; bridge `addressProblems()`; 409 `ABHA_ADDRESS_EXISTS` with *proceed with existing / create new*; View and Download card. Checks CRT_ABHA_108, 109 ×2, 112 ×3, 114 pass | 2026-11-26 |
| S5 | Redact PII from the bridge audit log | bridge | Audit rows for ABHA calls hold no mobile, Aadhaar or OTP (encrypted or plain) | done | No change needed: `src/lib/audit.js` already masks these keys (see the correction above) | 2026-11-26 |
| S6 | Confirmation form and "update mobile?" option | HMS + bridge | Register and attach go through a filled form (name, DOB, gender read-only); Yes/No to update the mobile on the ABHA | done | Form in `abha.php`; server takes name, DOB and gender from ABDM only. `offerMobileUpdate()` → bridge `/m1/profile/mobile/*`. Checks VRFY_ABHA_306 and "update mobile" pass. Not offered after an ABHA-address login: ABDM publishes no PHR-token mobile update | 2026-11-26 |
| S7 | Returning-patient matching | HMS | Same person through create, then verify → one UHID, one `abdm_patients` row (test on `sh_hrms_test`) | done | `match_candidates()`: mobile, name, gender and age, all four for a full match. Lookup lists candidates with ✓/✗; register returns the match instead of a second UHID. Checks CRT_ABHA_116 ×2 pass | 2026-11-26 |
| S8 | Login by ABHA address | bridge + HMS | Search → OTP → verify → profile works end to end; auth methods come from the API | done | Bridge `/m1/address/*` reusing `src/phr/abha-phr.js`; profile, card and QR by token kind `phr`. Checks VRFY_ABHA_102/202 ×3 pass | 2026-11-26 |
| S9 | Find ABHA by mobile (new flow) | bridge + HMS | No OTP before a profile is picked; 301, 303 and 307 messages exact; 308 comparison screen | done | Bridge `/m1/find/*` (`loginHint: index`); the old "Mobile · OTP" option is gone from the screen. Checks VRFY_ABHA_301, 302, 304, 305, 308 pass; the 303 and 307 wording is in `abdm.js` | 2026-11-26 |
| S10 | No-ABHA branch for Aadhaar login | HMS | ABDM-1114 → message and creation offered without asking for the Aadhaar again | done | Bridge and PHP pass `code` through; "NO ABHA user registered with this Aadhaar Number" + *Create ABHA with this Aadhaar*, which carries the number into the Create tab. Check VRFY_ABHA_403 passes | 2026-11-26 |
| S11 | Unique `abha_number` | HMS | Duplicate check on live data run read-only and reviewed by you; index added in `ensure_schema` | done | `ensure_unique_abha_number()` adds the index only when no duplicates exist. `save_abha` no longer uses `REPLACE` and refuses a second patient (409). Patient creation and tagging run in one transaction. Checks TAGGING ×3 pass. **You still need to run the duplicate query in ABDM_INTEGRATION.md on the live database** | 2026-11-26 |
| S12 | Scan & Share: auto-register and match on demographics | HMS + bridge | A known patient sharing a new ABHA address updates their UHID; auto-registration | done | `register_shared()` / `process_share_queue()`, run by the queue page and by `abdmcron sync`; the token is valid 60 minutes. Checks SHARE_PATIENT_PROFILE_701, 702 ×2 pass; the cron registered a shared profile | 2026-11-26 |
| S13 | Sandbox scaffold: every call reaches its exit condition | bridge | Request ids logged for each call in features 1–5 | blocked | Needs: sandbox client id and secret configured in the bridge, and the bridge reachable. Question: is the sandbox bridge on your VPS ready to receive this build? | |
| S14 | Functional test run with a tester (every mandatory case) | — | Workbook filled by `abdm_workbook.py`; tracker below written by `abdm_report.py tracker`; run log in `abdm-ft/` | blocked | Needs: NHA's M1 workbook in `abdm-ft/`, a tester with an Aadhaar and phone for OTPs, and the ABHA app. The screen cases (resend timer, rules colours, masked input, form locks) are checked here in a browser | |

### Test evidence (2026-11-26, local only, no real identities)

- Bridge: `TEST_DATABASE_URL=… npm test` → 26 pass, 1 skipped (the existing fidelius interop test), 0 fail. New file `test/m1.test.js` holds 9 tests named by case id.
- Smart Hospital: local `sh_hrms_test` → local bridge → mock ABHA, 38 of 38 server-side checks pass, plus the cron registering a shared profile. Test data removed afterwards.
- Not covered yet: real ABDM sandbox responses (S13); clicking through the screens in a browser (S14).

### Open points for go-live

- **Consent text:** check the wording in `Abdm_model::CONSENT_EN` against ABDM's current approved text (link in the model), and bump `CONSENT_VERSION` if it changes. CRT_ABHA_103 (consent in other languages) is optional and not built.
- **ABHA address length:** implemented as 8–18 characters *including* the `@sbx`/`@abdm` suffix, reading the rule in CRT_ABHA_112 literally. Confirm in the sandbox.
- **Aadhaar check:** numbers failing the Verhoeff checksum are refused before any call. If a sandbox test Aadhaar is refused, tell me.

## Tracker

Written by `abdm_report.py tracker` once NHA's workbook is in `abdm-ft/`. Never edit it by hand.

<!-- abdm-tracker start: written by abdm_report.py tracker. Never edit it by hand -->
<!-- abdm-tracker end -->

## Run logs

None yet. Each run writes `abdm-ft/m1-<YYYYMMDD-HHMM>.md`.

---

# M2: create and link records (HIP) and M3: fetch data with consent (HIU)

Skills: `.claude/skills/abdm-m2`, `.claude/skills/abdm-m3` (catalogue 2026.09.16). Entity type: **private**: all 13 M2 cases and all 16 M3 cases are mandatory.
Added: 2026-11-26 · M2/M3 steps done: **9 of 12 (75%)**: every code step but the Fidelius interop run is done; the sandbox and tester runs are blocked

Both modules already exist. As with M1, every case starts at **Test**: the audit below is a reading of the code, and the fixes follow it. Same split: screens and HMIS data in Smart Hospital, ABDM calls in the bridge (`~/Documents/Project-2026/abdm`).

Out of scope: sharing invoices as a HIP (Smart Hospital bills are not sent today), SNOMED codes for medicines (no coded drug master in Smart Hospital), consent in other languages.

## Audit: M2 cases (this hospital as HIP)

| Case | Current code | Gap → fix step |
|---|---|---|
| Health_RECORD_CREATION_101 | FHIR bundles built to NRCES profiles (`src/fhir/bundles.js`), SNOMED section codes, ICD-10 diagnoses, LOINC for vitals | **Lab results go out with names only**: pathology parameters have no LOINC code → **M2-S2** |
| HIP_INTI_LINK_504 | Link token cached per patient and reused | Never expires: reuse only while it is under 6 months old → **M2-S3** |
| HIP_INTI_LINK_505 | Linking works, but only when staff click **Link to ABHA** | Must be automatic once records are saved → **M2-S4** |
| HIP_INTI_LINK_506 | Records reach the PHR app once linked | The HMS sync never sets `notifyUpdates`, so an edited record isn't announced to the PHR app (ABDM_INTEGRATION.md says it is) → **M2-S4** |
| USER_INIT_LINK_601, 602 | PHR app side; the facility must be discoverable | Nothing to build; needs the tester and the ABHA app |
| USER_INIT_LINK_603 | Discovery: verified ABHA, then mobile + gender + year of birth + **exact** name | Case asks for a fuzzy name match after a mobile match → **M2-S5** |
| USER_INIT_LINK_606, 604, 605, 607 | Discovery returns care contexts; link OTP by SMS; confirm links (bridge e2e tests 7, 8) | — |
| HIP_INIT_NOTIFY_HIECM | **SMS link** button → ABDM SMS deep link | — |
| HIP_INIT_SHARE_CARECONTEXT | Consent notify, data request, encrypted paged transfer (Fidelius: HKDF-SHA256, AES-256-GCM), notify (bridge e2e test 10) | Interop with fidelius-cli is skipped in the test suite; run once before the tester session → **M2-S6** |

## Audit: M3 cases (this hospital as HIU)

| Case | Current code | Gap → fix step |
|---|---|---|
| HIU_FLOW_102 | Form has ABHA address, HI types, purpose, from, to, expiry (labelled "Delete our copy on") | Label it **Consent expiry**; check the patient is known and show their name and ABHA number before sending → **M3-S3** |
| HIU_FLOW_104 | List shows patient, purpose, status, requested time | Must show name, ABHA address, ABHA number, HI types, from/to, created, expiry, status and **the time of each status**. The bridge keeps only `updated_at` → **M3-S2** (bridge), **M3-S3** (screen) |
| HIU_FLOW_103 | Request reaches the PHR app | If the patient edits it, the granted artefact's HI types, dates and expiry must be what the HIU shows → **M3-S3** |
| HIU_FLOW_105 | DENIED: status set, records deleted | Denial time shown → **M3-S2/S3** |
| HIU_FLOW_106 | GRANTED: artefacts fetched, data requested | Grant time; edited parameters shown; **data of an HI type that wasn't granted is still stored** → **M3-S2** |
| HIU_FLOW_107–114 | One generic "Received records" list | View the health data of each HI type. The set lists 8 cases, which I read as one per ABDM HI type; **Invoice is missing** from the HI types the bridge offers → **M3-S2**, viewer **M3-S4** |
| HIU_FLOW_301 | Artefact marked EXPIRED after `dataEraseAt`, records erased | The request itself never becomes EXPIRED and no expiry time is kept → **M3-S2** |
| HIU_FLOW_201, 202 | REVOKED: status set, records deleted, data requests refused | Revocation time shown; list refreshes by itself → **M3-S3** |

## Steps

| # | Step | Repo | Exit condition | Status | Evidence | Date |
|---|---|---|---|---|---|---|
| M2-S1 | Install the skills and audit M2/M3 | HMS | Both skills complete; audits above | done | `.claude/skills/abdm-m2/` (9 files), `.claude/skills/abdm-m3/` (10 files); scripts identical to the reviewed M1 copies | 2026-11-26 |
| M2-S2 | LOINC codes for lab results | HMS | A pathology parameter can carry a LOINC code (new mapping screen under ABDM); a lab report bundle carries it on the Observation | done | **Lab codes (LOINC)** tab (`loinc.php`), table `abdm_loinc`. 34 suggestions shown but used only once confirmed; check digit validated. Saving re-queues that test's reports. Local run: suggestions right for 9 names; `718-8` refused; confirmed `718-7` reached the FHIR Observation | 2026-11-26 |
| M2-S3 | Link token valid for 6 months | bridge | A token older than 6 months is not reused; a newer one is | done | `hip.link-start` reuses a token only if `created_at > now() - 6 months`. `test/m2m3.test.js`: token reused, then replaced once aged 7 months | 2026-11-26 |
| M2-S4 | Automatic linking and update notices | HMS + bridge | After the cron sync, every unlinked record of a patient with an ABHA is linked with no click; an edited linked record is announced to the PHR app | done | `auto_link()` after each cron sync and **Sync now**; the bridge returns a job already in flight rather than starting another. The sync sends `notifyUpdates`. Local run: one link job started, the next run reused it; an edited linked lab report was re-sent and its context notify reached the gateway. Note: Smart Hospital stores `parameter_update` as a date, so a same-day edit is picked up by the hourly re-check | 2026-11-26 |
| M2-S5 | Fuzzy name match in discovery | bridge | A mobile match with a name differing in order, initials or one typo is found; a different person on the same mobile is not | done | `namesMatch()`: word order, honorifics, initials, spelling variants (ee/i, oo/u, aa/a, w/v, ph/f), one typo per longer part, a missing middle name. Gender and year of birth still checked. Unit and discovery tests pass | 2026-11-26 |
| M2-S6 | Fidelius interop | bridge | The skipped interop test runs against fidelius-cli and passes | blocked | Needs fidelius-cli (Java, from github.com/mgrmtech/fidelius-cli) downloaded and built on this Mac. Question: may I download and build it to run this one test? | |
| M3-S2 | Consent status history, expiry, HI-type filter, Invoice | bridge | Each status keeps its time; an expired consent marks the request EXPIRED; a bundle of a non-granted HI type is refused; Invoice offered | done | Migration `005_consent_history.sql`. `setStatus()` appends `{status, at}` on every change. `eraseExpired()` now marks the request EXPIRED. Data push refuses an HI type the artefact doesn't grant (`bundleHiType()`); `Invoice` added; `/records` returns `hiType`. Tests: REQUESTED→GRANTED→EXPIRED with times; LAB and RX refused when only OPConsultation was granted | 2026-11-26 |
| M3-S3 | Consent request list and form | HMS | List shows every field HIU_FLOW_102/104 names, the granted (possibly edited) values beside the requested ones, the time of each status, and refreshes itself | done | `consent.php` + `abdm.js`. Requests are refused unless the ABHA address is on a patient here. Local run: name and ABHA number shown, unknown patient refused, list fields and history present, Invoice offered. Not yet clicked through in a browser | 2026-11-26 |
| M3-S4 | Health data viewer by HI type | HMS | Received records filtered by HI type, each rendered readably (observations, medications, conditions, documents, immunizations, invoices) | done | HI-type filter buttons; renderer for 17 FHIR resource types plus narrative text. Run in Node against bundles from the bridge's own builder for all 5 types it builds, plus an Immunization and an Invoice bundle: all rendered. The narrative part needs a browser (DOMParser) | 2026-11-26 |
| M2/M3-S7 | Docs | HMS | ABDM_INTEGRATION.md matches what the system does | done | Feature table, linking paragraph, tables and deploy order updated | 2026-11-26 |
| M2/M3-S8 | Sandbox run | bridge | Every M2/M3 call reaches its exit condition with request ids | blocked | Same as S13: sandbox credentials on a reachable bridge | |
| M2/M3-S9 | Functional test run with a tester | — | All 29 cases in NHA's workbooks; run log in `abdm-ft/` | blocked | Needs NHA's M2 and M3 workbooks in `abdm-ft/`, a tester, the ABHA app, and a second HIP (or the sandbox's) for M3 | |

### M2/M3 test evidence (2026-11-26, local only)

- Bridge: full suite 33 pass, 1 skipped (fidelius-cli interop, M2-S6), 0 fail. New file `test/m2m3.test.js` (7 tests named by case id).
- Smart Hospital: local `sh_hrms_test` → local bridge → mock gateway, 17 of 17 checks pass. Test data removed afterwards.

### Found while building, not changed

- **Discharge-summary notes** (operation, investigations, treatment at home) go out only in the Composition's narrative text, not in a coded section. The viewer here now shows narratives, but another hospital's HIU or the PHR app may not. Check the discharge bundle with the NRCES FHIR validator before the tester session.

---

# Deploy

| # | Step | Status | Evidence | Date |
|---|---|---|---|---|
| D1 | Bridge database on Neon (`neon init`, `neon deploy`), wired and tested | done | Production branch: 5 migrations, 21 tables, `/ready` READY. Full bridge suite on a temporary Neon branch: 33 pass (before the worker change); worker tests also pass on Neon | 2026-11-26 |
| D2 | Idle worker so Neon can scale to zero (option b) | done | `test/worker.test.js`: no reads of `jobs` while idle (control: ≥5 in 6 s); a delayed job still fires on time | 2026-11-26 |
| D3 | Commit both repos | done | Bridge `43d017a`, `2340e70`; Smart Hospital on `skylark-go-live` | 2026-11-26 |
| D4 | Bridge GitHub repo and push | done | `satishsoni1/abdm-bridge`, `main` at `2340e70` | 2026-11-26 |
| D5 | Bridge as a Hostinger Node.js web app from GitHub, env set in hPanel | done | https://abdm-bridge.duobytes.in: `/health` UP, `/ready` READY (Neon), callbacks without a gateway token 401, HMS API without a key 401, TLS valid. Entry file `server.cjs` (bridge `3cc54a7`). Open: `NODE_ENV` is not `production` yet (no HSTS, console cookie not Secure) | 2026-11-26 |
| D6 | Smart Hospital `.env`: `ABDM_BRIDGE_URL`, `ABDM_API_KEY`; callback URL registered with ABDM | pending | | |
