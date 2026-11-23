<?php
/* M1 forms: verify an existing ABHA (QR / ABHA number / mobile / Aadhaar OTP) or create one via Aadhaar OTP.
   Optional: $m1_mobile (prefill), $m1_verify_label, $m1_address_label. Behaviour: abdmM1() in backend/abdm/abdm.js. */
?>
<ul class="nav nav-pills mb-2" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#abha-verify" type="button">Verify existing ABHA</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#abha-create" type="button">Create new ABHA</button></li>
</ul>
<div class="tab-content">
    <div class="tab-pane fade show active" id="abha-verify">
        <label class="form-label small mb-1" for="v-qr">Scan ABHA card QR <span class="text-muted">(QR scanner, or paste the QR text)</span></label>
        <input id="v-qr" class="form-control form-control-sm mb-1" placeholder="Click here and scan the patient's ABHA card QR" autocomplete="off">
        <div id="v-qr-info" class="small mb-2"></div>
        <label class="form-label">Method</label>
        <select id="v-method" class="form-select form-select-sm mb-2">
            <option value="abha-number:aadhaar">ABHA number · Aadhaar OTP</option>
            <option value="abha-number:mobile">ABHA number · mobile OTP</option>
            <option value="mobile:mobile">Mobile number · OTP</option>
            <option value="aadhaar:aadhaar">Aadhaar · OTP</option>
        </select>
        <input id="v-id" class="form-control form-control-sm mb-2" placeholder="ABHA number / mobile / Aadhaar" autocomplete="off">
        <button class="btn btn-primary btn-sm" id="v-send">Send OTP</button>
        <div id="v-step2" class="d-none mt-2">
            <p class="small text-muted mb-1" id="v-msg"></p>
            <input id="v-otp" class="form-control form-control-sm mb-2" placeholder="OTP" inputmode="numeric" maxlength="6" autocomplete="one-time-code">
            <button class="btn btn-success btn-sm" id="v-verify"><?php echo isset($m1_verify_label) ? $m1_verify_label : 'Verify &amp; attach'; ?></button>
        </div>
        <div id="v-accounts" class="mt-2"></div>
    </div>
    <div class="tab-pane fade" id="abha-create">
        <input id="c-aadhaar" class="form-control form-control-sm mb-2" placeholder="Aadhaar number (12 digits)" inputmode="numeric" maxlength="12" autocomplete="off">
        <div class="form-check small bg-light p-2 ps-4 rounded mb-2" style="max-height:140px;overflow:auto">
            <input class="form-check-input" type="checkbox" id="c-consent">
            <label class="form-check-label" for="c-consent">I, hereby declare that I am voluntarily sharing my Aadhaar number and demographic information issued by UIDAI, with National Health Authority (NHA) for the sole purpose of creation of ABHA number. I understand that my ABHA number can be used and shared for purposes as may be notified by ABDM from time to time including provision of healthcare services. Further, I am aware that my personal identifiable information (Name, Address, Age, Date of Birth, Gender and Photograph) may be made available to the entities working in the National Digital Health Ecosystem (NDHE) which inter alia includes stakeholders and entities such as healthcare professionals (e.g. doctors), facilities (e.g. hospitals, laboratories) and data fiduciaries (e.g. health programmes), which are registered with or linked to the Ayushman Bharat Digital Mission (ABDM), and various processes there under. I reserve the right to revoke the given consent at any point of time.</label>
        </div>
        <button class="btn btn-primary btn-sm" id="c-send">Send Aadhaar OTP</button>
        <div id="c-step2" class="d-none mt-2">
            <p class="small text-muted mb-1" id="c-msg"></p>
            <input id="c-otp" class="form-control form-control-sm mb-2" placeholder="OTP" inputmode="numeric" maxlength="6">
            <input id="c-mobile" class="form-control form-control-sm mb-2" placeholder="Mobile number for ABHA" inputmode="numeric" maxlength="10" value="<?php echo html_escape(isset($m1_mobile) ? $m1_mobile : ''); ?>">
            <button class="btn btn-success btn-sm" id="c-verify">Verify &amp; create ABHA</button>
        </div>
        <div id="c-step3" class="d-none mt-2">
            <p class="small text-muted mb-1">This mobile differs from the Aadhaar-linked one. Verify it:</p>
            <button class="btn btn-outline-primary btn-sm mb-2" id="c-motp-send">Send OTP to mobile</button>
            <input id="c-motp" class="form-control form-control-sm mb-2" placeholder="Mobile OTP" inputmode="numeric" maxlength="6">
            <button class="btn btn-success btn-sm" id="c-motp-verify">Verify mobile</button>
        </div>
        <div id="c-step4" class="d-none mt-2">
            <p class="small mb-1">Choose an ABHA address:</p>
            <div id="c-sugg" class="mb-2"></div>
            <input id="c-addr" class="form-control form-control-sm mb-2" placeholder="firstname.lastname">
            <button class="btn btn-success btn-sm" id="c-addr-btn"><?php echo isset($m1_address_label) ? $m1_address_label : 'Create ABHA address &amp; attach'; ?></button>
        </div>
    </div>
</div>
