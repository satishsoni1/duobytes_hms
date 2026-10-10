<?php $staff = $this->customlib->getUserData(); ?>
<div id="abdm-root" data-page="consent" data-base="<?php echo site_url('admin/abdm/'); ?>" data-token="<?php echo html_escape($abdm_token); ?>">
<?php $abdm_tab = 'consent'; $this->load->view('admin/abdm/_nav'); ?>
<div class="row g-3">
    <?php if ($this->rbac->hasPrivilege('abdm_consent', 'can_add')) { ?>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Request patient's records</h3></div>
            <div class="card-body">
                <label class="form-label small">Patient ABHA address</label>
                <input id="cr-patient" class="form-control form-control-sm mb-2" placeholder="name@sbx" value="<?php echo html_escape($prefill); ?>">
                <label class="form-label small">Purpose</label>
                <select id="cr-purpose" class="form-select form-select-sm mb-2"></select>
                <div class="row g-2 mb-2">
                    <div class="col"><label class="form-label small">Records from</label><input id="cr-from" type="date" class="form-control form-control-sm"></div>
                    <div class="col"><label class="form-label small">to</label><input id="cr-to" type="date" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>"></div>
                </div>
                <label class="form-label small">Delete our copy on</label>
                <input id="cr-erase" type="date" class="form-control form-control-sm mb-2" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                <div class="row g-2 mb-2">
                    <div class="col"><label class="form-label small">Requesting doctor</label>
                        <input id="cr-doctor" class="form-control form-control-sm" value="<?php echo html_escape(isset($staff['name']) ? trim('Dr. ' . $staff['name'] . ' ' . $staff['surname']) : ''); ?>"></div>
                    <div class="col"><label class="form-label small">Registration no.</label><input id="cr-regno" class="form-control form-control-sm"></div>
                </div>
                <label class="form-label small">Record types</label>
                <div id="cr-types" class="small mb-2"></div>
                <button class="btn btn-primary btn-sm" id="cr-send">Send consent request</button>
                <p class="small text-muted mt-2 mb-0">The patient approves the request in their ABHA app. Records arrive encrypted and are deleted automatically when consent ends.</p>
            </div>
        </div>
    </div>
    <?php } ?>
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title">Consent requests</h3>
                <button class="btn btn-outline-secondary btn-sm" id="cr-refresh"><i class="fa fa-refresh"></i> Refresh</button></div>
            <div class="card-body table-responsive">
                <table class="table table-sm table-striped"><thead><tr><th>Patient</th><th>Purpose</th><th>Status</th><th>Requested</th><th></th></tr></thead>
                    <tbody id="cr-list"><tr><td colspan="5" class="text-muted">Loading…</td></tr></tbody></table>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Granted consents</h3></div>
            <div class="card-body table-responsive">
                <table class="table table-sm table-striped"><thead><tr><th>Patient</th><th>Status</th><th>From hospital</th><th>Period</th><th>Delete on</th><th></th></tr></thead>
                    <tbody id="ca-list"></tbody></table>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title">Received records</h3></div>
            <div class="card-body" id="rec-list"></div>
        </div>
    </div>
</div>
</div>
<script defer src="<?php echo base_url('backend/abdm/abdm.js'); ?>"></script>
