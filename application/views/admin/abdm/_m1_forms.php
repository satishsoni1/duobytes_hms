<?php
/* M1 forms: verify an existing ABHA (QR / ABHA number / ABHA address / mobile / Aadhaar) or create one via Aadhaar OTP.
   Optional: $m1_mobile (prefill), $m1_verify_label, $m1_address_label. Behaviour: m1Flow() in backend/abdm/abdm.js.
   Every OTP box gets its resend control from abdm.js (60 s wait, 2 resends; CRT_ABHA_106, VRFY_ABHA_405). */
$m1_verify_label = isset($m1_verify_label) ? $m1_verify_label : 'Verify &amp; attach';
?>
<style>
    .m1-rules li { list-style: none; }
    .m1-rules li::before { content: '\2716'; margin-right: .35rem; }
    .m1-rules li.ok { color: #198754; } .m1-rules li.ok::before { content: '\2714'; }
    .m1-rules li.bad { color: #dc3545; }
    .m1-acct { cursor: pointer; }
    .m1-acct.active { border-color: #0d6efd !important; background: #f0f6ff; }
</style>
<ul class="nav nav-pills mb-2" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#abha-verify" type="button" id="m1-tab-verify">Verify existing ABHA</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#abha-create" type="button" id="m1-tab-create">Create new ABHA</button></li>
</ul>
<div class="tab-content">
    <!-- ======================= Verify ======================= -->
    <div class="tab-pane fade show active" id="abha-verify">
        <label class="form-label small mb-1" for="v-qr">Scan ABHA card QR <span class="text-muted">(QR scanner, or paste the QR text)</span></label>
        <input id="v-qr" class="form-control form-control-sm mb-1" placeholder="Click here and scan the patient's ABHA card QR" autocomplete="off">
        <div id="v-qr-info" class="small mb-2"></div>

        <label class="form-label small mb-1">The patient has their</label>
        <div class="btn-group btn-group-sm w-100 mb-2" role="group" id="v-ident">
            <input type="radio" class="btn-check" name="v-ident" id="v-ident-number" value="number" checked><label class="btn btn-outline-primary" for="v-ident-number">ABHA number</label>
            <input type="radio" class="btn-check" name="v-ident" id="v-ident-address" value="address"><label class="btn btn-outline-primary" for="v-ident-address">ABHA address</label>
            <input type="radio" class="btn-check" name="v-ident" id="v-ident-mobile" value="mobile"><label class="btn btn-outline-primary" for="v-ident-mobile">Mobile only</label>
            <input type="radio" class="btn-check" name="v-ident" id="v-ident-aadhaar" value="aadhaar"><label class="btn btn-outline-primary" for="v-ident-aadhaar">Aadhaar</label>
        </div>

        <div class="v-pane" data-ident="number">
            <input id="v-num" class="form-control form-control-sm mb-2" placeholder="14-digit ABHA number, e.g. 91-1234-5678-9012" inputmode="numeric" maxlength="17" autocomplete="off">
            <label class="form-label small mb-1" for="v-num-method">Verify with</label>
            <select id="v-num-method" class="form-select form-select-sm mb-2">
                <option value="abha-number:aadhaar">OTP to the Aadhaar-linked mobile</option>
                <option value="abha-number:mobile">OTP to the ABHA-linked mobile</option>
            </select>
            <button class="btn btn-primary btn-sm" id="v-num-send">Send OTP</button>
        </div>

        <div class="v-pane d-none" data-ident="address">
            <div class="input-group input-group-sm mb-2">
                <input id="v-addr" class="form-control" placeholder="ABHA address, e.g. name.surname" autocomplete="off">
                <span class="input-group-text v-suffix">@sbx</span>
            </div>
            <button class="btn btn-primary btn-sm" id="v-addr-search">Continue</button>
            <div id="v-addr-methods" class="mt-2"></div>
        </div>

        <div class="v-pane d-none" data-ident="mobile">
            <p class="small text-muted mb-1">Find a forgotten ABHA: list the ABHAs on the patient's mobile, pick theirs, then verify it with an OTP.</p>
            <input id="v-mob" class="form-control form-control-sm mb-1" placeholder="10-digit mobile number" inputmode="numeric" maxlength="10" autocomplete="off">
            <div id="v-mob-err" class="small text-danger mb-1"></div>
            <button class="btn btn-primary btn-sm" id="v-mob-search">Search ABHA</button>
            <div id="v-find" class="mt-2"></div>
        </div>

        <div class="v-pane d-none" data-ident="aadhaar">
            <div class="input-group input-group-sm mb-1">
                <input id="v-aad" type="password" class="form-control" placeholder="12-digit Aadhaar number" inputmode="numeric" maxlength="12" autocomplete="new-password">
                <button class="btn btn-outline-secondary m1-eye" type="button" data-for="v-aad" title="Show / hide"><i class="fa fa-eye"></i></button>
            </div>
            <div id="v-aad-err" class="small text-danger mb-1"></div>
            <button class="btn btn-primary btn-sm" id="v-aad-send">Send OTP</button>
            <div id="v-aad-none" class="alert alert-warning mt-2 mb-0 d-none">
                <strong>NO ABHA user registered with this Aadhaar Number.</strong>
                <div class="mt-1">Create an ABHA for the patient now. The Aadhaar number is carried over; you will only need their consent and a new OTP.</div>
                <button class="btn btn-success btn-sm mt-2" id="v-aad-create">Create ABHA with this Aadhaar</button>
            </div>
        </div>

        <div id="v-otp" class="d-none mt-2 border rounded p-2">
            <p class="small text-muted mb-1" id="v-otp-msg"></p>
            <input id="v-otp-in" class="form-control form-control-sm mb-1 m1-otp" placeholder="6-digit OTP" inputmode="numeric" maxlength="6" autocomplete="one-time-code">
            <div class="m1-resend mb-2" id="v-otp-resend"></div>
            <button class="btn btn-success btn-sm" id="v-otp-verify" disabled><?php echo $m1_verify_label; ?></button>
        </div>
        <div id="v-accounts" class="mt-2"></div>
    </div>

    <!-- ======================= Create ======================= -->
    <div class="tab-pane fade" id="abha-create">
        <div id="c-aadhaar-box">
            <div class="input-group input-group-sm mb-1">
                <input id="c-aadhaar" type="password" class="form-control" placeholder="Aadhaar number (12 digits)" inputmode="numeric" maxlength="12" autocomplete="new-password">
                <button class="btn btn-outline-secondary m1-eye" type="button" data-for="c-aadhaar" title="Show / hide"><i class="fa fa-eye"></i></button>
            </div>
        </div>
        <div id="c-aadhaar-carried" class="alert alert-info py-1 px-2 small mb-1 d-none"></div>
        <div id="c-aadhaar-err" class="small text-danger mb-1"></div>
        <div class="form-check small bg-light p-2 ps-4 rounded mb-2" style="max-height:140px;overflow:auto">
            <input class="form-check-input" type="checkbox" id="c-consent">
            <label class="form-check-label" for="c-consent"><?php echo html_escape(Abdm_model::CONSENT_EN); ?></label>
        </div>
        <button class="btn btn-primary btn-sm" id="c-send">I agree, send Aadhaar OTP</button>

        <div id="c-step2" class="d-none mt-2 border rounded p-2">
            <p class="small text-muted mb-1" id="c-msg"></p>
            <input id="c-otp" class="form-control form-control-sm mb-1 m1-otp" placeholder="6-digit Aadhaar OTP" inputmode="numeric" maxlength="6" autocomplete="one-time-code">
            <div class="m1-resend mb-2" id="c-otp-resend"></div>
            <label class="form-label small mb-1" for="c-mobile">Mobile number for the ABHA (communication mobile)</label>
            <input id="c-mobile" class="form-control form-control-sm mb-2" placeholder="10-digit mobile" inputmode="numeric" maxlength="10" value="<?php echo html_escape(isset($m1_mobile) ? $m1_mobile : ''); ?>">
            <button class="btn btn-success btn-sm" id="c-verify" disabled>Verify &amp; create ABHA</button>
        </div>

        <div id="c-step3" class="d-none mt-2 border rounded p-2">
            <p class="small mb-1">This mobile is not the one linked to the Aadhaar. Verify it with an OTP sent to <strong id="c-motp-to"></strong>.</p>
            <p class="small text-muted mb-1" id="c-motp-msg"></p>
            <input id="c-motp" class="form-control form-control-sm mb-1 m1-otp" placeholder="6-digit mobile OTP" inputmode="numeric" maxlength="6" autocomplete="one-time-code">
            <div class="m1-resend mb-2" id="c-motp-resend"></div>
            <button class="btn btn-success btn-sm" id="c-motp-verify" disabled>Verify mobile</button>
        </div>

        <div id="c-step4" class="d-none mt-2 border rounded p-2">
            <div id="c-existing" class="d-none mb-2"></div>
            <div id="c-new-addr">
                <p class="small mb-1">Choose an ABHA address:</p>
                <div id="c-sugg" class="mb-2"></div>
                <div class="input-group input-group-sm mb-1">
                    <input id="c-addr" class="form-control" placeholder="or type one, e.g. firstname.lastname" autocomplete="off">
                    <span class="input-group-text v-suffix">@sbx</span>
                </div>
                <ul class="m1-rules small ps-0 mb-2" id="c-rules">
                    <li data-rule="length">8 to 18 characters, including <span class="v-suffix">@sbx</span></li>
                    <li data-rule="characters">Only letters and numbers, with at most one dot (.) and one underscore (_)</li>
                    <li data-rule="edges">No dot or underscore at the beginning or end</li>
                </ul>
                <div id="c-exists" class="alert alert-warning py-2 small d-none"></div>
                <button class="btn btn-success btn-sm" id="c-addr-btn" disabled><?php echo isset($m1_address_label) ? $m1_address_label : 'Create ABHA address &amp; attach'; ?></button>
            </div>
        </div>
    </div>
</div>
