<div id="abdm-root" data-page="hip" data-base="<?php echo site_url('admin/abdm/'); ?>" data-token="<?php echo html_escape($abdm_token); ?>">
<?php $abdm_tab = 'hip'; $this->load->view('admin/abdm/_nav'); ?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title">Consents received (our records)</h3>
        <button class="btn btn-outline-secondary btn-sm" id="hip-refresh"><i class="fa fa-refresh"></i> Refresh</button>
    </div>
    <div class="card-body table-responsive">
        <p class="text-muted small">When a patient lets another hospital or their ABHA app see records from this hospital, ABDM sends us the consent. It appears here with its status.</p>
        <table class="table table-sm table-striped mb-0">
            <thead><tr><th>Consent</th><th>Status</th><th>Patient</th><th>Requested by</th><th>Purpose</th><th>Records</th><th>Valid till</th><th>Updated</th></tr></thead>
            <tbody id="hip-consents"><tr><td colspan="8" class="text-muted">Loading…</td></tr></tbody>
        </table>
    </div>
</div>
<div class="card">
    <div class="card-header"><h3 class="card-title">Data sent</h3></div>
    <div class="card-body table-responsive">
        <p class="text-muted small">Each time ABDM asks for the consented records, the bridge encrypts them and sends them. Failed transfers show the error.</p>
        <table class="table table-sm table-striped mb-0">
            <thead><tr><th>Transaction</th><th>Consent</th><th>Status</th><th>Records</th><th>Error</th><th>When</th></tr></thead>
            <tbody id="hip-transfers"><tr><td colspan="6" class="text-muted">Loading…</td></tr></tbody>
        </table>
    </div>
</div>
</div>
<script defer src="<?php echo base_url('backend/abdm/abdm.js'); ?>"></script>
