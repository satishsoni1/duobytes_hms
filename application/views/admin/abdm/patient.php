<?php
$labels = array('opd' => 'OPD visit', 'rx' => 'Prescription', 'lab' => 'Lab report', 'rad' => 'Radiology report', 'ds' => 'Discharge summary');
$has_abha = !empty($patient['abha_address']) || !empty($patient['abha_number']);
?>
<div id="abdm-root" data-page="patient" data-patient="<?php echo (int) $patient['id']; ?>" data-base="<?php echo site_url('admin/abdm/'); ?>" data-token="<?php echo html_escape($abdm_token); ?>">
<?php $abdm_tab = ''; $this->load->view('admin/abdm/_nav'); ?>

<div class="d-flex align-items-center gap-3 mb-3">
    <h4 class="mb-0"><?php echo html_escape($patient['patient_name']); ?></h4>
    <span class="text-muted">Patient #<?php echo (int) $patient['id']; ?> · ABDM ref <?php echo html_escape($mrn); ?></span>
    <span class="text-muted"><?php echo html_escape($patient['gender']); ?><?php echo $patient['mobileno'] ? ' · ' . html_escape($patient['mobileno']) : ''; ?></span>
</div>

<div class="row g-3">
<?php if ($this->rbac->hasPrivilege('abdm_abha', 'can_view')) { ?>
    <!-- ================= ABHA (M1) ================= -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><h3 class="card-title">ABHA (Ayushman Bharat Health Account)</h3></div>
            <div class="card-body">
                <p id="abha-current" class="mb-2">
                    <?php if ($has_abha) { ?>
                        <strong><?php echo html_escape($patient['abha_number']); ?></strong> · <?php echo html_escape($patient['abha_address']); ?>
                        <span class="badge bg-success">Verified <?php echo html_escape(substr($patient['verified_at'], 0, 10)); ?></span>
                    <?php } else { ?>
                        <span class="text-muted">No ABHA attached yet.</span>
                    <?php } ?>
                </p>
                <div id="abha-card-actions" class="d-none mb-2">
                    <button class="btn btn-outline-primary btn-sm" id="abha-card">Download ABHA card</button>
                    <button class="btn btn-outline-secondary btn-sm" id="abha-qr">Show QR</button>
                    <div id="abha-qr-img" class="mt-2"></div>
                </div>
                <?php if ($has_abha && $this->rbac->hasPrivilege('abdm_abha', 'can_add')) { ?>
                    <button class="btn btn-link btn-sm p-0" id="abha-change">Re-verify / change ABHA</button>
                <?php } ?>

                <?php if ($this->rbac->hasPrivilege('abdm_abha', 'can_add')) { ?>
                <div id="abha-forms" class="<?php echo $has_abha ? 'd-none' : ''; ?> mt-2">
                    <ul class="nav nav-pills mb-2" role="tablist">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#abha-verify" type="button">Verify existing ABHA</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#abha-create" type="button">Create new ABHA</button></li>
                    </ul>
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="abha-verify">
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
                                <button class="btn btn-success btn-sm" id="v-verify">Verify &amp; attach</button>
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
                                <input id="c-mobile" class="form-control form-control-sm mb-2" placeholder="Mobile number for ABHA" inputmode="numeric" maxlength="10" value="<?php echo html_escape(preg_replace('/\D/', '', substr($patient['mobileno'], -10))); ?>">
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
                                <button class="btn btn-success btn-sm" id="c-addr-btn">Create ABHA address &amp; attach</button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
        <?php if ($has_abha && $this->rbac->hasPrivilege('abdm_consent', 'can_view')) { ?>
            <a class="btn btn-outline-primary btn-sm mt-3" href="<?php echo site_url('admin/abdm/consent?abha=' . rawurlencode($patient['abha_address'])); ?>">
                <i class="fa fa-download"></i> Request records from other hospitals</a>
        <?php } ?>
    </div>
<?php } ?>

<?php if ($this->rbac->hasPrivilege('abdm_records', 'can_view')) { ?>
    <!-- ================= Records (M2) ================= -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h3 class="card-title">Health records shared via ABDM</h3>
                <?php if ($this->rbac->hasPrivilege('abdm_records', 'can_add')) { ?>
                    <div>
                        <button class="btn btn-outline-secondary btn-sm" id="abdm-sync"><i class="fa fa-refresh"></i> Sync records</button>
                        <button class="btn btn-primary btn-sm" id="abdm-link" <?php echo $has_abha ? '' : 'disabled title="Attach ABHA first"'; ?>><i class="fa fa-link"></i> Link to ABHA</button>
                    </div>
                <?php } ?>
            </div>
            <div class="card-body table-responsive">
                <p class="small" id="link-status"></p>
                <table class="table table-sm table-striped" id="abdm-records">
                    <thead><tr><th>Record</th><th>Date</th><th>ABDM reference</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (empty($records)) { ?><tr><td colspan="4" class="text-muted">No OPD/IPD records yet.</td></tr><?php } ?>
                    <?php foreach ($records as $r) { ?>
                        <tr data-ref="<?php echo html_escape($r['reference']); ?>">
                            <td><?php echo html_escape($labels[$r['entity']]); ?> #<?php echo (int) $r['id']; ?></td>
                            <td><?php echo html_escape(substr($r['rec_date'], 0, 10)); ?></td>
                            <td><?php echo html_escape($r['reference'] ?: '—'); ?></td>
                            <td class="st"><?php
                                if ($r['error']) { echo '<span class="badge bg-danger" title="' . html_escape($r['error']) . '">Sync error</span>'; }
                                elseif ($r['synced_at']) { echo '<span class="badge bg-info">Shared</span>'; }
                                else { echo '<span class="badge bg-secondary">Not shared yet</span>'; } ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
                <p class="small text-muted mb-0">Patients can also find and link these records themselves from their ABHA app (they receive an OTP on their registered mobile).</p>
            </div>
        </div>
    </div>
<?php } ?>
</div>
</div>
<script defer src="<?php echo base_url('backend/abdm/abdm.js'); ?>"></script>
