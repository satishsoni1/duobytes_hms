# HRMS, discount approval, advances and patient address

Features added on top of Smart Hospital 7. Their tables and columns are created automatically
the first time a page that needs them is opened. There is no SQL to run. Each schema check runs
once and is then recorded in `hms_settings` (`schema_hrms`, `schema_discount`, `schema_geo`).

## After deploying

1. Sign in as Super Admin and open **Human Resource** (or **My HR**) once. This creates the HR tables.
2. **Setup → Settings → HR Settings**:
   - Switch on automatic staff IDs.
   - Set the prefix (e.g. `SKY`), number of digits and start number.
   - The page shows the next ID. Existing staff IDs are not changed.
3. **Setup → Settings → Discount Approval**:
   - Tick the doctors or staff who may approve discounts.
   - Optionally set a percentage that applies without approval.
   - Switch it on. It is **off** until you do this.
4. **Setup → Settings → Patient Address Lists**: check the default state and district (Maharashtra / Akola).
5. **Setup → Roles & Permissions** shows the new permissions:
   - Human Resource: HR Letter Templates, Staff HR Documents, Hospital Policies & Documents, Travel Expense Approval, HR Settings.
   - Billing: Discount Approval, Patient Advance.
   - System Settings: Discount Approval Setting.

   The Admin role gets all of them. Grant them to other roles as needed.
6. On the server, upload folders are blocked from direct web access by `.htaccess` (Apache / LiteSpeed):
   - `uploads/hr_documents/`
   - `uploads/hr_policies/`
   - `uploads/hr_expenses/`

   On nginx, add `location ~ ^/uploads/hr_ { deny all; }`.

## What each feature does

| Feature | Where | Notes |
|---|---|---|
| Staff ID auto-generate | Add Staff | The number is taken when the form is saved, so two users adding staff at once never get the same ID. |
| Medical registration no. | Add/Edit Staff (doctor roles), staff profile, OPD/IPD prescription print | Registration number, council, valid till. Expired registrations show in red on the profile. |
| Blood group | Add/Edit Staff, profile | Already existed. |
| HR letters | Human Resource → Letters & documents → Generate | Templates: appointment, joining, offer, experience, relieving, increment, MOU with visiting consultant. `{staff_name}` etc. are filled from the staff record. Any other `{word}` becomes a box to fill in. The wording can be edited per letter. Prints with the payslip print header. |
| MOUs / agreements / scans | Staff profile → Documents tab, or Staff documents page | Generate from a template, or upload a scanned copy with validity dates. Only the staff member and users with *Staff HR Documents* can open them. The documents list can filter to expired or expiring within 60 days. |
| Policies & hospital documents | Human Resource → Policies; staff read them in **My HR** | Optionally limited to some roles. |
| Travel expense claims | **My HR → My travel claims**; approvers: *Expense approvals* | Lines with date, category, from/to, mode, km, amount and a bill photo or PDF. Approve the full amount or less (with a note), or reject with a reason. An approved claim (minus any travel advance taken) is added as an earning on the next payslip. Saving the payslip marks it paid; deleting the payslip puts it back to approved. |
| Discount approval | Every OPD/IPD charge, pathology, radiology, pharmacy, blood issue and ambulance bill, plus mobile-app charges | A discount entered by a non-approver is not applied. The bill is saved at the full amount and a request goes to the chosen approver (a box appears next to the discount field). The approver can approve it, approve a lower %, or reject it. Approvers' and Super Admins' own discounts apply directly and are logged. If the bill changes after the request, the request closes. Menu: **Discount approvals** (shows a count for approvers). |
| Patient advance | Menu **Patient advances** | Collect a deposit (receipt ADV-00001) before or during OPD/IPD. Later use it against a visit's or admission's charges: this adds a payment with mode **Advance adjustment**, so the existing bill and balance screens see it. Unused balance can be refunded, and an adjustment can be reversed. |
| Patient address | All patient add/edit forms | House/street line, then State → District → Taluka → City/village dropdowns and PIN. Type a name that is not listed and it is added when the patient is saved. `patients.address` keeps the full address, so all prints and lists show it. The street line is in `address_line`. |

## Things to know

- **Income reports.**
  - Advance money is counted in the Advance register on the day it is collected.
  - It appears in `transactions` only when it is settled against a case, with mode *Advance adjustment*.
  - When adding up cash received for a day, exclude that mode, so settled advances are not counted twice.
- **Payment taken at a discounted amount.** If OPD registration took payment at a discounted amount and the discount is held for approval, the visit shows the difference as due until the discount is approved.
- **Pharmacy item discounts.** For pharmacy bills, only the bill-level discount is held. Item-level discounts printed on the bill lines stay as entered.
- **Appointment booking discounts** (paid at booking) are not held for approval.
- **Mobile app registrations.** Patients registered in the mobile app get the old single address line. Their state/district can be filled in later from the web.
