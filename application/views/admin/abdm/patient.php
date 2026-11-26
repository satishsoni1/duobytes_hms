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
                <div id="abha-mob" class="d-none mb-2"></div>
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
                    <?php $this->load->view('admin/abdm/_m1_forms', array('m1_mobile' => preg_replace('/\D/', '', substr($patient['mobileno'], -10)))); ?>
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
                        <?php if (!$has_abha && $patient['mobileno']) { ?>
                            <button class="btn btn-outline-primary btn-sm" id="abdm-sms" title="ABDM sends the patient an SMS explaining how to link these records from their ABHA app"><i class="fa fa-commenting"></i> Send ABDM SMS link</button>
                        <?php } ?>
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
