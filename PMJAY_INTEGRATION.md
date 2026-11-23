# PM-JAY module for Smart Hospital

Sidebar → **PM-JAY**. The tables, the **PM-JAY** permission group and 8 sample packages are created automatically the first time someone opens the module. Nothing in existing tables is changed.

| Phase | What it does | Status |
|---|---|---|
| **1 · TMS workflow** | Beneficiaries (PM-JAY ID, family ID, BIS verification, ABHA), HBP package master, IPD cases with a pre-auth → discharge → claim → payment pipeline, document checklist, timeline, dashboard and receivables | Working. Use it alongside the NHA TMS 2.0 portal |
| **2 · NHCX** | From a case: eligibility check, pre-auth, claim and status as FHIR bundles. The payer's response updates the case. Every message is logged with its request and response bundles | **Demo simulator.** Responses are generated inside the HMS and nothing is sent to NHA |

## Screens
- **Dashboard:** active cases, pending pre-auths, open queries, pending claims, pipeline value, amounts approved but unpaid, amounts received, and cases by stage.
- **Cases:** filter by stage and search by patient, PM-JAY ID or TMS case number. **New case** works from the beneficiary list or from the **PM-JAY** button on an IPD profile.
- **Case page:**
  - stage stepper and amounts
  - **Update status** for steps done on the TMS portal: TMS case number, approved amounts, discharge, payment UTR
  - document checklist
  - **NHCX panel** for Phase 2, with request/response FHIR viewer
  - timeline
- **Beneficiaries:** register a patient with their Ayushman card details and mark them BIS-verified.
- **HBP packages:** code, name, specialty, rate, pre-auth needed, expected stay, mandatory documents.
- **NHCX exchange:** connection mode, participant codes, and the log of all messages.

## Permissions
Setup → Roles → Assign Permission → **PM-JAY**:
- `PM-JAY Cases`: view / add / edit / delete
- `PM-JAY Beneficiaries`: view / add / edit
- `PM-JAY Packages`: view / add / edit / delete
- `PM-JAY NHCX Exchange`: view / add

## Demo script (about 5 minutes)
1. **HBP packages:** the 8 `DEMO-` packages are shown with a sample badge.
2. **Beneficiaries:** search a patient, enter a PM-JAY ID, tick **Verified in BIS**, then **Save & create case**.
3. Choose `DEMO-SG001 Laparoscopic cholecystectomy` and **Create case**.
4. **NHCX panel:**
   - **Check eligibility:** the beneficiary is eligible.
   - **Send pre-auth:** the case moves to **Pre-auth approved** with an approved amount.
5. **Update status → Discharged**.
6. **Submit claim** with the checklist empty. The payer raises a **query** for missing documents, which shows how queries work.
7. Tick the documents, **Save checklist**, then **Submit claim** again. The claim is approved.
8. **Update status → Paid** with a UTR. The dashboard now shows the amount received.
9. Open **Request bundle (FHIR)** on any message to show what goes to NHCX.
10. **Not-verified beneficiary:** register a patient without BIS verification and send a pre-auth. It returns a query.

## Before go-live
- **Packages:** replace the sample packages (**Remove samples**) with your state's HBP package master and rates.
- **Phase 2:**
  1. Register as an NHCX provider participant.
  2. Complete NHA sandbox testing and certification.
  3. Add the NHCX exchange (encryption, callbacks) to the ABDM bridge.
  4. In `.env`, set `PMJAY_NHCX_MODE=sandbox`, `PMJAY_PARTICIPANT_CODE`, `PMJAY_PAYER_CODE` and `PMJAY_PAYER_NAME`.
  Until then, sandbox mode refuses to send.

## Files
```
application/config/pmjay.php
application/controllers/admin/Pmjay.php
application/libraries/Nhcx_client.php      FHIR bundle builder + simulator
application/models/Pmjay_model.php         tables, permissions, pipeline rules
application/views/admin/pmjay/             (_nav, index, cases, new_case, view, beneficiaries, packages, exchange)
backend/pmjay/pmjay.js
```
Changed: `application/views/layout/sidebar.php` (menu entry), `application/views/admin/patient/ipdprofile.php` (PM-JAY button), `.env.example`.
