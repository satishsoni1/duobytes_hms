<div id="abdm-root" data-page="abha" data-base="<?php echo site_url('admin/abdm/'); ?>" data-token="<?php echo html_escape($abdm_token); ?>" data-patient-url="<?php echo site_url('admin/abdm/patient/'); ?>" data-can-register="<?php echo $this->rbac->hasPrivilege('patient', 'can_add') ? '1' : ''; ?>">
<?php $abdm_tab = 'abha'; $this->load->view('admin/abdm/_nav'); ?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Create / Verify ABHA</h3></div>
            <div class="card-body">
                <p class="text-muted small">Verify the patient's existing ABHA or create a new one with Aadhaar OTP. Then register them as a new patient or attach the ABHA to an existing patient.</p>
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
                        <dt class="col-sm-4">ABHA number</dt><dd class="col-sm-8" id="m1-number"></dd>
                        <dt class="col-sm-4">ABHA address</dt><dd class="col-sm-8" id="m1-address"></dd>
                        <dt class="col-sm-4">Gender / DOB</dt><dd class="col-sm-8" id="m1-demo"></dd>
                        <dt class="col-sm-4">Mobile</dt><dd class="col-sm-8" id="m1-mobile"></dd>
                        <dt class="col-sm-4">Address</dt><dd class="col-sm-8" id="m1-line"></dd>
                    </dl>
                </div>
                <div class="mt-3">
                    <button class="btn btn-outline-primary btn-sm" id="m1-card"><i class="fa fa-download"></i> ABHA card</button>
                    <button class="btn btn-outline-secondary btn-sm" id="m1-qr"><i class="fa fa-qrcode"></i> QR</button>
                    <div id="m1-qr-img" class="mt-2"></div>
                </div>
                <hr>
                <div id="m1-existing" class="alert alert-success d-none mb-0"></div>
                <div id="m1-actions" class="d-none">
                    <button class="btn btn-primary btn-sm mb-3 d-none" id="m1-register"><i class="fa fa-user-plus"></i> Register as new patient</button>
                    <h6 class="mb-2">Or attach to an existing patient</h6>
                    <div class="input-group input-group-sm mb-2">
                        <input id="m1-q" class="form-control" placeholder="Patient ID, name or mobile" autocomplete="off">
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
