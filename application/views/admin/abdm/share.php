<div id="abdm-root" data-page="share" data-base="<?php echo site_url('admin/abdm/'); ?>" data-token="<?php echo html_escape($abdm_token); ?>" data-patient-url="<?php echo site_url('admin/abdm/patient/'); ?>">
<?php $abdm_tab = 'share'; $this->load->view('admin/abdm/_nav'); ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title">Today's ABHA Scan &amp; Share tokens</h3>
        <button class="btn btn-outline-secondary btn-sm" id="sq-refresh"><i class="fa fa-refresh"></i> Refresh</button>
    </div>
    <div class="card-body table-responsive">
        <p class="text-muted small">Patients who scan the hospital's ABDM QR code in their ABHA app appear here with a token number. Register new patients in one click; their ABHA is attached automatically.</p>
        <table class="table table-striped table-hover">
            <thead><tr><th>Token</th><th>Name</th><th>ABHA address</th><th>Gender / YOB</th><th>Counter</th><th>Time</th><th></th></tr></thead>
            <tbody id="sq-list"><tr><td colspan="7" class="text-muted">Loading…</td></tr></tbody>
        </table>
    </div>
</div>
</div>
<script defer src="<?php echo base_url('backend/abdm/abdm.js'); ?>"></script>
