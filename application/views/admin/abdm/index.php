<div id="abdm-root" data-page="index" data-base="<?php echo site_url('admin/abdm/'); ?>" data-token="<?php echo html_escape($abdm_token); ?>">
<?php $abdm_tab = 'index'; $this->load->view('admin/abdm/_nav'); ?>
<div class="row g-3">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Connection</h3></div>
            <div class="card-body">
                <?php if (!empty($ping['ok'])) { ?>
                    <p class="mb-1"><span class="badge bg-success">Connected</span> to the ABDM bridge</p>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Facility (HIP/HIU)</dt><dd class="col-sm-8"><?php echo html_escape($ping['facility']['name']); ?> (<?php echo html_escape($ping['facility']['id']); ?>)</dd>
                        <dt class="col-sm-4">Bridge URL</dt><dd class="col-sm-8"><?php echo html_escape($this->config->item('abdm_bridge_url', 'abdm')); ?></dd>
                    </dl>
                <?php } else { ?>
                    <p class="text-danger mb-0"><span class="badge bg-danger">Not connected</span> <?php echo html_escape(isset($ping_error) ? $ping_error : ''); ?></p>
                <?php } ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title">Record sharing</h3>
                <?php if ($this->rbac->hasPrivilege('abdm_settings', 'can_edit')) { ?>
                    <button class="btn btn-primary btn-sm" id="abdm-sync-all"><i class="fa fa-refresh"></i> Sync now</button>
                <?php } ?>
            </div>
            <div class="card-body">
                <dl class="row mb-2">
                    <dt class="col-sm-6">Patients with verified ABHA</dt><dd class="col-sm-6"><?php echo (int) $stats['abha_patients']; ?></dd>
                    <dt class="col-sm-6">Records shared with ABDM</dt><dd class="col-sm-6"><?php echo (int) $stats['synced']; ?></dd>
                    <dt class="col-sm-6">Last sync</dt><dd class="col-sm-6"><?php echo $stats['last_sync'] ? html_escape($stats['last_sync']) : 'never'; ?></dd>
                </dl>
                <p class="small text-muted mb-1" id="abdm-sync-result"></p>
                <p class="small text-muted mb-0">Records sync automatically via cron:
                    <code>php <?php echo html_escape(FCPATH); ?>index.php abdmcron sync</code> every 10 minutes.</p>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Recent sync errors</h3></div>
            <div class="card-body table-responsive">
                <table class="table table-sm table-striped mb-0">
                    <thead><tr><th>Record</th><th>Error</th><th>When</th></tr></thead>
                    <tbody>
                    <?php if (empty($stats['errors'])) { ?><tr><td colspan="3" class="text-muted">No errors</td></tr><?php } ?>
                    <?php foreach ($stats['errors'] as $e) { ?>
                        <tr><td><?php echo html_escape($e['reference']); ?></td><td><?php echo html_escape($e['error']); ?></td><td><?php echo html_escape($e['synced_at']); ?></td></tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>
<script defer src="<?php echo base_url('backend/abdm/abdm.js'); ?>"></script>
