<?php $this->load->view('admin/pmjay/_nav'); ?>
<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-header"><h3 class="card-title">New PM-JAY case</h3></div>
    <div class="card-body">
        <?php if (!$patient) { ?>
            <p class="small text-muted">Find the patient. They must be registered as a PM-JAY beneficiary.</p>
            <div data-pmjay-picker data-search="<?php echo site_url('admin/pmjay/patients'); ?>" data-go="<?php echo site_url('admin/pmjay/new_case?patient_id='); ?>">
                <div class="input-group mb-2">
                    <input class="form-control" placeholder="Patient ID, name, mobile or PM-JAY ID" autocomplete="off">
                    <button class="btn btn-outline-secondary"><i class="fa fa-search"></i> Search</button>
                </div>
                <div class="list-group" data-results></div>
            </div>
        <?php } elseif (!$ben) { ?>
            <div class="alert alert-warning">
                <strong>#<?php echo (int) $patient['id']; ?> <?php echo html_escape($patient['patient_name']); ?></strong> is not registered as a PM-JAY beneficiary.
                <?php if ($this->rbac->hasPrivilege('pmjay_beneficiaries', 'can_add')) { ?>
                    <a class="btn btn-primary btn-sm ms-2" href="<?php echo site_url('admin/pmjay/beneficiaries?next=case&patient_id=' . (int) $patient['id']); ?>">Register beneficiary</a>
                <?php } ?>
            </div>
            <a href="<?php echo site_url('admin/pmjay/new_case'); ?>">Choose another patient</a>
        <?php } else { ?>
            <div class="alert alert-light border small">
                <strong>#<?php echo (int) $patient['id']; ?> <?php echo html_escape($patient['patient_name']); ?></strong> · <?php echo html_escape($patient['gender']); ?><?php echo $patient['age'] ? ' · ' . (int) $patient['age'] . 'y' : ''; ?>
                · PM-JAY <span class="font-monospace"><?php echo html_escape($ben['pmjay_id']); ?></span>
                <?php echo $ben['verified'] ? '<span class="badge bg-success">BIS verified</span>' : '<span class="badge bg-warning text-dark">Not verified in BIS</span>'; ?>
                <a class="float-end" href="<?php echo site_url('admin/pmjay/new_case'); ?>">change</a>
            </div>
            <form method="post" action="<?php echo site_url('admin/pmjay/create_case'); ?>" autocomplete="off">
                <input type="hidden" name="pmjay_token" value="<?php echo html_escape($pmjay_token); ?>">
                <input type="hidden" name="patient_id" value="<?php echo (int) $patient['id']; ?>">
                <div class="mb-3"><label class="form-label">HBP package *</label>
                    <select name="package_id" class="form-select" required>
                        <option value="">Choose…</option>
                        <?php $sp = null; foreach ($packages as $k) { if ($k['specialty'] !== $sp) { if ($sp !== null) { echo '</optgroup>'; } $sp = $k['specialty']; echo '<optgroup label="' . html_escape($sp ?: 'Other') . '">'; } ?>
                            <option value="<?php echo (int) $k['id']; ?>"><?php echo html_escape($k['code'] . ' · ' . $k['name'] . ' · ' . pmjay_money($k['rate'])); ?><?php echo $k['preauth'] ? '' : ' (no pre-auth)'; ?></option>
                        <?php } if ($sp !== null) { echo '</optgroup>'; } ?>
                    </select></div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6"><label class="form-label">IPD admission</label>
                        <select name="ipd_id" class="form-select">
                            <option value="">Not linked</option>
                            <?php foreach ($ipds as $i) { ?><option value="<?php echo (int) $i['id']; ?>" <?php echo (int) $i['id'] === $ipd_id ? 'selected' : ''; ?>>IPD <?php echo (int) $i['id']; ?> · <?php echo html_escape(substr($i['date'], 0, 10)); ?><?php echo $i['discharged'] === 'yes' ? ' (discharged)' : ''; ?></option><?php } ?>
                        </select></div>
                    <div class="col-md-6"><label class="form-label">Admission date</label>
                        <input type="date" name="admission_date" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>
                </div>
                <div class="mb-3"><label class="form-label">Provisional diagnosis</label>
                    <input name="diagnosis" class="form-control" maxlength="255" placeholder="e.g. Cholelithiasis (K80.2)"></div>
                <button class="btn btn-primary">Create case</button>
            </form>
        <?php } ?>
    </div>
</div>
</div>
</div>
<script defer src="<?php echo base_url('backend/pmjay/pmjay.js'); ?>"></script>
