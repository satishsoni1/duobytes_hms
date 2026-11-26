<div id="abdm-root" data-page="abha" data-base="<?php echo site_url('admin/abdm/'); ?>" data-token="<?php echo html_escape($abdm_token); ?>" data-patient-url="<?php echo site_url('admin/abdm/patient/'); ?>" data-can-register="<?php echo $this->rbac->hasPrivilege('patient', 'can_add') ? '1' : ''; ?>">
<?php $abdm_tab = 'abha'; $this->load->view('admin/abdm/_nav'); ?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Create / Verify ABHA</h3></div>
            <div class="card-body">
                <p class="text-muted small">Verify the patient's existing ABHA, or create a new one with Aadhaar OTP. The verified profile then fills the registration form, or is linked to the patient's existing record.</p>
                <?php $this->load->view('admin/abdm/_m1_forms', array('m1_verify_label' => 'Verify', 'm1_address_label' => 'Create ABHA address')); ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card d-none" id="m1-result">
            <div class="card-header"><h3 class="card-title">ABHA profile</h3></div>
            <div class="card-body">
                <div class="d-flex gap-3 align-items-start">
                    <img id="m1-photo" class="rounded border d-none" width="96" alt="Photo">
                    <dl class="row mb-0 flex-grow-1">
                        <dt class="col-sm-4">Name</dt><dd class="col-sm-8" id="m1-name"></dd>
                        <dt class="col-sm-4">ABHA number</dt><dd class="col-sm-8 fw-bold" id="m1-number"></dd>
                        <dt class="col-sm-4">ABHA address</dt><dd class="col-sm-8" id="m1-address"></dd>
                        <dt class="col-sm-4">Gender / DOB</dt><dd class="col-sm-8" id="m1-demo"></dd>
                        <dt class="col-sm-4">Mobile</dt><dd class="col-sm-8" id="m1-mobile"></dd>
                        <dt class="col-sm-4">Address</dt><dd class="col-sm-8" id="m1-line"></dd>
                    </dl>
                </div>
                <div class="mt-3">
                    <button class="btn btn-outline-primary btn-sm" id="m1-card-view"><i class="fa fa-id-card"></i> View ABHA card</button>
                    <button class="btn btn-outline-primary btn-sm" id="m1-card"><i class="fa fa-download"></i> Download ABHA card</button>
                    <button class="btn btn-outline-secondary btn-sm" id="m1-qr"><i class="fa fa-qrcode"></i> QR</button>
                    <div class="small text-muted mt-1">The ABHA card can be downloaded only for a KYC-verified ABHA.</div>
                    <div id="m1-card-img" class="mt-2"></div>
                    <div id="m1-qr-img" class="mt-2"></div>
                </div>
                <hr>

                <!-- Already linked (VRFY_ABHA_308) -->
                <div id="m1-linked" class="d-none">
                    <div class="alert alert-success mb-2"><strong>This ABHA profile is already linked to HMIS Beneficiary ID <span id="m1-linked-id"></span>.</strong> No new patient is created.</div>
                    <table class="table table-sm table-bordered mb-2">
                        <thead class="table-light"><tr><th style="width:24%"></th><th>From ABHA</th><th>In the hospital (HMIS)</th></tr></thead>
                        <tbody id="m1-compare"></tbody>
                    </table>
                    <a class="btn btn-primary btn-sm" id="m1-linked-open" href="#">Open patient</a>
                </div>

                <!-- Linked just now -->
                <div id="m1-done" class="alert alert-success d-none mb-2"></div>

                <!-- Update the mobile on the ABHA (VRFY_ABHA_101/201 step 5) -->
                <div id="m1-mob" class="d-none"></div>

                <div id="m1-actions" class="d-none">
                    <!-- Returning patient (CRT_ABHA_116) -->
                    <div id="m1-match" class="d-none mb-3">
                        <h6 class="mb-1">This may be a returning patient</h6>
                        <p class="small text-muted mb-1">Hospital records that share details with this ABHA. A patient who matches on every detail is linked rather than registered again.</p>
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Patient</th><th class="text-center">Mobile</th><th class="text-center">Name</th><th class="text-center">Gender</th><th class="text-center">Age</th><th></th></tr></thead>
                            <tbody id="m1-match-rows"></tbody>
                        </table>
                    </div>

                    <!-- Registration form filled from the profile (VRFY_ABHA_306) -->
                    <div id="m1-form" class="d-none mb-3">
                        <h6 class="mb-1">Register as a new patient</h6>
                        <p class="small text-muted mb-2">Filled from the ABHA profile. Name, date of birth and gender come from ABHA and cannot be changed here.</p>
                        <div class="row g-2">
                            <div class="col-md-6"><label class="form-label small mb-0">Name</label><input class="form-control form-control-sm" id="f-name" readonly></div>
                            <div class="col-md-3"><label class="form-label small mb-0">Date of birth</label><input class="form-control form-control-sm" id="f-dob" readonly></div>
                            <div class="col-md-3"><label class="form-label small mb-0">Gender</label><input class="form-control form-control-sm" id="f-gender" readonly></div>
                            <div class="col-md-6"><label class="form-label small mb-0" for="f-mobile">Mobile</label><input class="form-control form-control-sm" id="f-mobile" inputmode="numeric" maxlength="10"></div>
                            <div class="col-md-6"><label class="form-label small mb-0" for="f-email">Email</label><input class="form-control form-control-sm" id="f-email" type="email"></div>
                            <div class="col-12"><label class="form-label small mb-0" for="f-street">Address</label><input class="form-control form-control-sm" id="f-street"></div>
                            <div class="col-md-4"><label class="form-label small mb-0" for="f-city">City / village</label><input class="form-control form-control-sm" id="f-city"></div>
                            <div class="col-md-4"><label class="form-label small mb-0" for="f-district">District</label><input class="form-control form-control-sm" id="f-district"></div>
                            <div class="col-md-4"><label class="form-label small mb-0" for="f-state">State</label><input class="form-control form-control-sm" id="f-state"></div>
                            <div class="col-md-4"><label class="form-label small mb-0" for="f-pincode">PIN code</label><input class="form-control form-control-sm" id="f-pincode" inputmode="numeric" maxlength="6"></div>
                        </div>
                        <div id="m1-form-match" class="alert alert-warning small mt-2 mb-0 d-none"></div>
                        <button class="btn btn-primary btn-sm mt-2" id="m1-register"><i class="fa fa-user-plus"></i> Register patient</button>
                    </div>

                    <h6 class="mb-2">Or link to an existing patient</h6>
                    <div class="input-group input-group-sm mb-2">
                        <input id="m1-q" class="form-control" placeholder="Patient ID / UHID, name or mobile" autocomplete="off">
                        <button class="btn btn-outline-secondary" id="m1-search"><i class="fa fa-search"></i> Search</button>
                    </div>
                    <table class="table table-sm table-hover mb-0">
                        <tbody id="m1-patients"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<script defer src="<?php echo base_url('backend/abdm/abdm.js'); ?>"></script>
