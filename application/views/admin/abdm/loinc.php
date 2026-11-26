<div id="abdm-root" data-page="loinc" data-base="<?php echo site_url('admin/abdm/'); ?>" data-token="<?php echo html_escape($abdm_token); ?>">
<?php $abdm_tab = 'loinc'; $this->load->view('admin/abdm/_nav'); ?>
<?php
$coded = count(array_filter($rows, function ($r) { return $r['loinc_code']; }));
$suggested = count(array_filter($rows, function ($r) { return !$r['loinc_code'] && $r['suggestion']; }));
?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h3 class="card-title mb-0">Lab codes (LOINC)</h3>
        <div class="small">
            <span class="badge bg-success"><?php echo (int) $coded; ?> coded</span>
            <span class="badge bg-warning text-dark"><?php echo (int) $suggested; ?> with a suggestion to check</span>
            <span class="badge bg-secondary"><?php echo count($rows) - $coded - $suggested; ?> without a code</span>
        </div>
    </div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            ABDM asks for lab results to be shared with LOINC codes. Give each pathology parameter its code here; reports are re-sent with the
            code on the next sync. <strong>Suggestions are not used until you confirm them</strong>: check each against the test your lab
            actually runs (specimen, method, unit), using the LOINC search link, before confirming. A code is checked for its check digit when saved.
        </p>
        <div class="d-flex gap-2 mb-2 flex-wrap">
            <input id="lc-filter" class="form-control form-control-sm" style="max-width:260px" placeholder="Filter by parameter name">
            <select id="lc-show" class="form-select form-select-sm" style="max-width:220px">
                <option value="all">All parameters</option>
                <option value="todo">Not coded yet</option>
                <option value="suggested">With a suggestion</option>
                <option value="coded">Coded</option>
            </select>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead><tr><th>Parameter</th><th>Unit</th><th>Reference range</th><th class="text-end">Used in tests</th><th style="width:150px">LOINC code</th><th>LOINC name</th><th></th></tr></thead>
                <tbody id="lc-rows">
                <?php foreach ($rows as $r) {
                    $state = $r['loinc_code'] ? 'coded' : ($r['suggestion'] ? 'suggested' : 'todo');
                    $code = $r['loinc_code'] ?: ($r['suggestion'] ? $r['suggestion']['code'] : '');
                    $disp = $r['loinc_code'] ? $r['loinc_display'] : ($r['suggestion'] ? $r['suggestion']['display'] : ''); ?>
                    <tr data-id="<?php echo (int) $r['id']; ?>" data-state="<?php echo $state; ?>" data-name="<?php echo html_escape(strtolower($r['parameter_name'])); ?>"
                        data-saved="<?php echo html_escape((string) $r['loinc_code']); ?>">
                        <td><?php echo html_escape($r['parameter_name']); ?></td>
                        <td class="small"><?php echo html_escape($r['unit']); ?></td>
                        <td class="small"><?php echo html_escape($r['reference_range']); ?></td>
                        <td class="text-end small"><?php echo (int) $r['used']; ?></td>
                        <td><input class="form-control form-control-sm lc-code font-monospace" value="<?php echo html_escape($code); ?>" placeholder="e.g. 718-7" <?php echo $can_edit ? '' : 'disabled'; ?>></td>
                        <td><input class="form-control form-control-sm lc-disp" value="<?php echo html_escape((string) $disp); ?>" <?php echo $can_edit ? '' : 'disabled'; ?>></td>
                        <td class="text-nowrap">
                            <?php if ($state === 'coded') { ?><span class="badge bg-success">Confirmed</span>
                            <?php } elseif ($state === 'suggested') { ?><span class="badge bg-warning text-dark">Suggested: check</span>
                            <?php } ?>
                            <a class="small ms-1" target="_blank" rel="noopener noreferrer" href="https://loinc.org/search/?t=1&amp;s=<?php echo rawurlencode($r['parameter_name']); ?>">LOINC search</a>
                            <?php if ($can_edit) { ?><button class="btn btn-<?php echo $state === 'suggested' ? 'warning' : 'outline-primary'; ?> btn-sm ms-1 lc-save"><?php echo $state === 'suggested' ? 'Confirm' : 'Save'; ?></button><?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                <?php if (!$rows) { ?><tr><td colspan="7" class="text-muted">No pathology parameters are set up yet.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>
<script defer src="<?php echo base_url('backend/abdm/abdm.js'); ?>"></script>
