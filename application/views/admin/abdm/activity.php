<div id="abdm-root" data-page="activity" data-base="<?php echo site_url('admin/abdm/'); ?>" data-token="<?php echo html_escape($abdm_token); ?>" data-can-retry="<?php echo $this->rbac->hasPrivilege('abdm_settings', 'can_edit') ? '1' : ''; ?>">
<?php $abdm_tab = 'activity'; $this->load->view('admin/abdm/_nav'); ?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title">Background jobs</h3>
        <button class="btn btn-outline-secondary btn-sm" id="act-jobs-refresh"><i class="fa fa-refresh"></i> Refresh</button>
    </div>
    <div class="card-body table-responsive">
        <p class="text-muted small">The bridge handles ABDM callbacks, linking and data transfers in the background, and retries failures automatically. Jobs that still fail are listed below.</p>
        <p id="act-job-counts" class="mb-2"></p>
        <table class="table table-sm table-striped mb-0">
            <thead><tr><th>#</th><th>Type</th><th>Attempts</th><th>Last error</th><th>When</th><th></th></tr></thead>
            <tbody id="act-jobs"><tr><td colspan="6" class="text-muted">Loading…</td></tr></tbody>
        </table>
    </div>
</div>
<div class="card">
    <div class="card-header"><h3 class="card-title">Audit log</h3></div>
    <div class="card-body table-responsive">
        <p class="text-muted small">Every ABDM exchange and staff action on the bridge. OTPs, Aadhaar numbers and tokens are masked.</p>
        <table class="table table-sm table-striped mb-2">
            <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Status</th><th>Details</th></tr></thead>
            <tbody id="act-audit"><tr><td colspan="5" class="text-muted">Loading…</td></tr></tbody>
        </table>
        <button class="btn btn-outline-secondary btn-sm d-none" id="act-audit-more">Load older</button>
    </div>
</div>
</div>
<script defer src="<?php echo base_url('backend/abdm/abdm.js'); ?>"></script>
