# Skylark Hospital, Akola: go-live runbook

Fresh production install. Takes about 20 minutes. Do the steps in order.

## 1. Database

1. In hosting control panel, create a **new, empty** MySQL/MariaDB database and a user with all privileges on it.
2. Get `SKYLARK_PRODUCTION_DB.sql` onto the server. It is **not in git** because it holds the admin password hash.
   Copy it from the developer machine, or build it with `python3 tools/build_production_db.py --admin-password '<password>'`.
   Then import it (phpMyAdmin → select the empty DB → Import, or):
   ```
   mysql -u <user> -p <database> < SKYLARK_PRODUCTION_DB.sql
   ```
   The last result should read `Skylark Hospital | skylark | 1 | 0` (hospital, theme, staff, patients).
   If the DB is not empty the import stops at the first statement with
   `Subquery returns more than 1 row`, and nothing is changed.

The file works on MySQL 5.7 / 8.x and MariaDB 10.x+. It holds no patients, bills or demo data.
Do **not** import `DEMO_SEED_ALL_MODULES.sql` or `CASESHEET_DEMO_SEED.sql` on this server.

To use your own admin password instead of the one handed over:
`python3 tools/build_production_db.py --admin-password '<new password>'` (needs `php` on PATH), then import.

## 2. Code and files

1. Deploy the code (git pull / upload). `uploads/` is not in git.
2. Copy the logos into place:
   ```
   cp backend/images/brand/skylark/skylark_logo.png \
      backend/images/brand/skylark/skylark_mini_logo.png \
      backend/images/brand/skylark/skylark_app_logo.png  uploads/hospital_content/logo/
   ```
3. Make sure these are writable by the web server: `uploads/` (all), `application/logs/`, `application/cache/`, `backup/`.
4. Create `.env` from `.env.example`:
   ```
   CI_ENV=production
   DB_HOST=localhost
   DB_USERNAME=...
   DB_PASSWORD=...
   DB_DATABASE=...
   BASE_URL=https://<your domain>/
   ABDM_MRN_PREFIX=SKY        # plus the other ABDM_* values, or ABDM_ENABLED=false until the bridge is ready
   ```

## 3. First login

1. Open `https://<domain>/site/login`. You should see the teal Skylark showcase.
2. Sign in with **skylarkhospitalakola@gmail.com** and the temporary password handed over separately.
3. **Change the password straight away** (profile menu, top right → Change Password).
4. If the dashboard shows an `ONLY_FULL_GROUP_BY` warning, ask the host to remove it from `sql_mode`.

## 4. Branding check (5 min)

| Where | What to do |
|---|---|
| Setup → Settings → General Setting | Check name, address, phone, email, ₹ INR, Asia/Kolkata, dd-mm-yyyy. Add the hospital registration number in *Hospital Code*. |
| Setup → Settings → **Brand Kit** | Tagline, Marathi name, emergency numbers, 24×7 badge, highlights and welcome line. Switches for the login showcase and dashboard banner. |
| Setup → Settings → Theme Studio | **Skylark** preset is active. Staff can still pick personal themes under Appearance. |
| Setup → Print Header Footer | Click **Generate headers from hospital settings** once. This builds all 20 print headers (OPD prescription, bills, discharge summary, etc.) with the logo, name, tagline and contact details. |
| Setup → Patient / Staff ID Card | Name, address and colour are set. Upload the logo and the authorised signature. |

## 5. Before the first patient

- Email (SMTP) and SMS settings, so password resets and notifications are sent.
- Departments, doctors/staff accounts and roles, beds, hospital charges, TPA/insurance list, pharmacy medicines.
- Prefixes (OPDN, IPDN, …) if the hospital wants its own numbering. Change these before the first record.
- Cron job: in Setup → Settings → Backup / Restore (or Queue Process), copy the cron URL with its key.
  Schedule it every 5–15 minutes, for example
  `*/10 * * * * curl -s https://<domain>/cron/index/<key> > /dev/null`.
  It runs the auto backup, the medicine expiry/stock alerts and the message queue.
- Turn on the backup schedule and take a first backup.

## 6. Setting up at the hospital

The portal is a website. Every counter only needs a browser and internet; nothing is installed on hospital PCs.

**Address.** Keep the hospital's existing site at www.skylarkhospital.com. Point a sub-domain such as
`hms.skylarkhospital.com` at the server, turn on SSL (https), and set `BASE_URL` to it.
Add a "Patient Login" link on the existing website pointing to `https://hms.skylarkhospital.com/site/userlogin`.

**Each counter / PC**
- Chrome or Edge, kept up to date. Bookmark `…/site/login` and set it as the home page.
- Printer set as the default, A4, margins "Default", **Background graphics ON** so the print headers print.
  Print one OPD prescription and one bill to check them.
- USB barcode scanner (optional): plug-and-play, works in the patient search box. The type is set in General Setting → *Scan code type*.
- A wired LAN or reliable Wi-Fi, plus a mobile hotspot as a fallback if the internet goes down.

**Accounts: one login per person, never shared**

| Desk | Role |
|---|---|
| Reception / front desk | Receptionist |
| Consultants and duty doctors | Doctor |
| Ward / ICU nurses | Nurse |
| Pharmacy | Pharmacist |
| Lab / X-ray | Pathologist / Radiologist |
| Billing, cash, TPA | Accountant |
| Hospital manager | Admin |

Create staff under Human Resource → Add Staff (department, designation, role).
Check what each role can see in Setup → Roles Permissions before handing out logins.
Keep the Super Admin login with the owner or IT person only.

**Biometric attendance (optional).** Turn it on in General Setting and enter the device serial number(s).
The device must push punches to `https://<domain>/biometric` (POST, JSON). Staff IDs on the device must match the Employee ID in the portal.

**Training (half a day, by desk)**
1. Reception: register patient → OPD visit / appointment → bill → print.
2. Doctor: OPD prescription (Smart Rx), IPD admission, case sheet, discharge summary.
3. Nurse: IPD nursing chart, medication chart, vitals.
4. Pharmacy / lab: bills from prescriptions, report entry and print.
5. Accounts: payments, TPA cases, day-end reports (Reports → Transaction).

**Dry run before go-live day.** Put one test patient through the whole journey: register → OPD →
admit to a bed → lab test → pharmacy → discharge → final bill.
Check every printout, then delete the test patient (Patient → open the patient → Delete).
Start real entries on the morning of go-live.

**Day one.** Keep paper forms at hand for the first shift. Have someone at reception for questions.
Check Reports → Transaction at closing time, and take a manual backup (Setup → Backup / Restore).

## 7. Inauguration ceremony

A full-screen ceremony page: the chief guest lights the lamp (दीप प्रज्वलन), then cuts the ribbon.
After a 3-2-1 countdown the curtains open, marigold petals fall, and the screen shows **Now Live**.

**Before the event**
1. Setup → Settings → **Brand Kit** → *Inauguration ceremony*: enter the chief guest's name and save.
2. Click **Rehearse (not saved)** and run through it on the actual screen/projector. Check the sound on the speakers.
3. On the day: connect the laptop to the projector, log in, and click **Inaugurate portal** on the dashboard
   (or Brand Kit → **Open ceremony screen**). Press **F** for full screen.

**During**
- The chief guest clicks the lamp, then **Cut the Ribbon**. A presenter remote works too (→ / PageDown / Space).
  **M** mutes the sound.
- The first real cut is saved as the portal's official go-live time and shown in Brand Kit.
  If someone cuts by mistake before the event, use **Reset** in Brand Kit.

**After.** The page becomes a keepsake screen showing the date and chief guest, with a *Replay* button
that does not change the saved time. Switch it off in Brand Kit when it is no longer needed.

## Open items

- **Logo:** the current logo is a cleaned-up crop from a photo of the file cover. Ask the hospital for the original logo file (PNG/SVG). Replace the four files in `backend/images/brand/skylark/` (and in `uploads/hospital_content/logo/`), then generate the print headers again.
- **Address:** it is translated from the Marathi on the file cover. Ask the hospital for the PIN code and add it in General Setting.
