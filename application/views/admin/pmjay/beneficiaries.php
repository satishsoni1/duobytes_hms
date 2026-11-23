<?php $this->load->view('admin/pmjay/_nav');
$can_save = $this->rbac->hasPrivilege('pmjay_beneficiaries', 'can_add') || $this->rbac->hasPrivilege('pmjay_beneficiaries', 'can_edit');
$b = $edit && $edit['ben'] ? $edit['ben'] : array();
$v = function ($k, $d = '') use ($b) { return html_escape(isset($b[$k]) ? $b[$k] : $d); };
?>
<div class="row g-3">
    <?php if ($can_save) { ?>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?php echo $b ? 'Edit beneficiary' : 'Register beneficiary'; ?></h3></div>
            <div class="card-body">
                <?php if (!$edit) { ?>
                    <p class="small text-muted">Find the patient (register them in the HMS first if they are new), then enter the details from their Ayushman card.</p>
                    <div data-pmjay-picker data-search="<?php echo site_url('admin/pmjay/patients'); ?>" data-go="<?php echo site_url('admin/pmjay/beneficiaries?patient_id='); ?>">
                        <div class="input-group input-group-sm mb-2">
                            <input class="form-control" placeholder="Patient ID, name, mobile or PM-JAY ID" autocomplete="off">
                            <button class="btn btn-outline-secondary"><i class="fa fa-search"></i></button>
                        </div>
                        <div class="list-group" data-results></div>
                    </div>
                <?php } else { $p = $edit['patient']; ?>
                    <div class="alert alert-light border py-2 small mb-3">
                        <strong>#<?php echo (int) $p['id']; ?> <?php echo html_escape($p['patient_name']); ?></strong> · <?php echo html_escape($p['gender']); ?><?php echo $p['age'] ? ' · ' . (int) $p['age'] . 'y' : ''; ?> · <?php echo html_escape($p['mobileno']); ?>
                        <a class="float-end" href="<?php echo site_url('admin/pmjay/beneficiaries'); ?>">change</a>
                    </div>
                    <form method="post" action="<?php echo site_url('admin/pmjay/save_beneficiary'); ?>" autocomplete="off">
                        <input type="hidden" name="pmjay_token" value="<?php echo html_escape($pmjay_token); ?>">
                        <input type="hidden" name="patient_id" value="<?php echo (int) $p['id']; ?>">
                        <input type="hidden" name="next" value="<?php echo html_escape((string) $this->input->get('next', true)); ?>">
                        <div class="mb-2"><label class="form-label small mb-1">PM-JAY ID (as on Ayushman card) *</label>
                            <input name="pmjay_id" class="form-control form-control-sm text-uppercase" required maxlength="40" value="<?php echo $v('pmjay_id'); ?>"></div>
                        <div class="mb-2"><label class="form-label small mb-1">Family ID</label>
                            <input name="family_id" class="form-control form-control-sm" maxlength="40" value="<?php echo $v('family_id'); ?>"></div>
                        <div class="mb-2"><label class="form-label small mb-1">Name on card</label>
                            <input name="card_name" class="form-control form-control-sm" maxlength="150" value="<?php echo $v('card_name', $p['patient_name']); ?>"></div>
                        <div class="row g-2 mb-2">
                            <div class="col-7"><label class="form-label small mb-1">Scheme</label>
                                <input name="scheme" class="form-control form-control-sm" list="pmjay-schemes" value="<?php echo $v('scheme', 'AB PM-JAY'); ?>">
                                <datalist id="pmjay-schemes"><option value="AB PM-JAY"><option value="AB PM-JAY + MJPJAY (Maharashtra)"><option value="AB PM-JAY Vay Vandana (70+)"></datalist></div>
                            <div class="col-5"><label class="form-label small mb-1">State</label>
                                <input name="state" class="form-control form-control-sm" value="<?php echo $v('state', 'Maharashtra'); ?>"></div>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="verified" value="1" id="ben-verified" <?php echo !empty($b['verified']) ? 'checked' : ''; ?>>
                            <label class="form-check-label small" for="ben-verified">Verified in BIS (Aadhaar e-KYC done on the TMS / BIS portal)</label>
                        </div>
                        <div class="mb-3"><label class="form-label small mb-1">Remarks</label>
                            <input name="remarks" class="form-control form-control-sm" maxlength="255" value="<?php echo $v('remarks'); ?>"></div>
                        <button class="btn btn-primary btn-sm">Save beneficiary</button>
                        <?php if ($this->rbac->hasPrivilege('pmjay_cases', 'can_add')) { ?>
                            <button class="btn btn-outline-primary btn-sm" name="next" value="case">Save &amp; create case</button>
                        <?php } ?>
                    </form>
                <?php } ?>
            </div>
        </div>
    </div>
    <?php } ?>
    <div class="<?php echo $can_save ? 'col-lg-8' : 'col-12'; ?>">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h3 class="card-title">Registered beneficiaries</h3>
                <form class="d-flex gap-1" method="get"><input name="q" class="form-control form-control-sm" placeholder="Search" value="<?php echo html_escape($q); ?>"><button class="btn btn-outline-secondary btn-sm"><i class="fa fa-search"></i></button></form>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-sm table-striped table-hover mb-0">
                    <thead><tr><th>Patient</th><th>PM-JAY ID</th><th>Family ID</th><th>Scheme</th><th>BIS</th><th>ABHA</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$rows) { ?><tr><td colspan="7" class="text-muted">No beneficiaries yet.</td></tr><?php } ?>
                    <?php foreach ($rows as $r) { ?>
                        <tr>
                            <td>#<?php echo (int) $r['patient_id']; ?> <?php echo html_escape($r['patient_name']); ?><div class="small text-muted"><?php echo html_escape($r['mobileno']); ?></div></td>
                            <td class="font-monospace"><?php echo html_escape($r['pmjay_id']); ?></td>
                            <td class="small"><?php echo html_escape($r['family_id']); ?></td>
                            <td class="small"><?php echo html_escape($r['scheme']); ?><div class="text-muted"><?php echo html_escape($r['state']); ?></div></td>
                            <td><?php echo $r['verified'] ? '<span class="badge bg-success" title="' . html_escape($r['verified_at']) . '">Verified</span>' : '<span class="badge bg-secondary">Pending</span>'; ?></td>
                            <td class="small"><?php echo $r['abha_address'] ? html_escape($r['abha_address']) : '<span class="text-muted">—</span>'; ?></td>
                            <td class="text-end text-nowrap">
                                <?php if ($can_save) { ?><a class="btn btn-outline-secondary btn-sm" href="<?php echo site_url('admin/pmjay/beneficiaries?patient_id=' . (int) $r['patient_id']); ?>">Edit</a><?php } ?>
                                <?php if ($this->rbac->hasPrivilege('pmjay_cases', 'can_add')) { ?><a class="btn btn-primary btn-sm" href="<?php echo site_url('admin/pmjay/new_case?patient_id=' . (int) $r['patient_id']); ?>">New case</a><?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script defer src="<?php echo base_url('backend/pmjay/pmjay.js'); ?>"></script>
