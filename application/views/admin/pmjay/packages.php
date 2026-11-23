<?php $this->load->view('admin/pmjay/_nav');
$can_add = $this->rbac->hasPrivilege('pmjay_packages', 'can_add');
$can_edit = $this->rbac->hasPrivilege('pmjay_packages', 'can_edit');
$e = $edit ?: array();
$v = function ($k, $d = '') use ($e) { return html_escape(isset($e[$k]) ? $e[$k] : $d); };
$samples = count(array_filter($rows, function ($r) { return $r['is_sample'] && $r['is_active']; }));
?>
<?php if ($samples) { ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><strong><?php echo $samples; ?> sample packages</strong> (codes starting DEMO-) are loaded for the demo. Their rates are illustrative, not official. Replace them with your state's HBP package master before go-live.</span>
        <?php if ($this->rbac->hasPrivilege('pmjay_packages', 'can_delete')) { ?>
            <form method="post" action="<?php echo site_url('admin/pmjay/delete_samples'); ?>" onsubmit="return confirm('Remove all sample packages?');">
                <input type="hidden" name="pmjay_token" value="<?php echo html_escape($pmjay_token); ?>">
                <button class="btn btn-outline-dark btn-sm">Remove samples</button>
            </form>
        <?php } ?>
    </div>
<?php } ?>
<div class="row g-3">
    <?php if ($can_add || ($edit && $can_edit)) { ?>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?php echo $edit ? 'Edit package' : 'Add package'; ?></h3></div>
            <div class="card-body">
                <form method="post" action="<?php echo site_url('admin/pmjay/save_package'); ?>" autocomplete="off">
                    <input type="hidden" name="pmjay_token" value="<?php echo html_escape($pmjay_token); ?>">
                    <input type="hidden" name="id" value="<?php echo (int) ($edit ? $edit['id'] : 0); ?>">
                    <div class="row g-2 mb-2">
                        <div class="col-5"><label class="form-label small mb-1">Package code *</label><input name="code" class="form-control form-control-sm text-uppercase" required maxlength="30" value="<?php echo $v('code'); ?>"></div>
                        <div class="col-7"><label class="form-label small mb-1">Specialty</label><input name="specialty" class="form-control form-control-sm" maxlength="80" value="<?php echo $v('specialty'); ?>"></div>
                    </div>
                    <div class="mb-2"><label class="form-label small mb-1">Procedure / package name *</label><input name="name" class="form-control form-control-sm" required maxlength="200" value="<?php echo $v('name'); ?>"></div>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label small mb-1">Rate (₹) *</label><input name="rate" type="number" min="1" step="0.01" class="form-control form-control-sm" required value="<?php echo $v('rate'); ?>"></div>
                        <div class="col-6"><label class="form-label small mb-1">Expected stay (days)</label><input name="los_days" type="number" min="0" class="form-control form-control-sm" value="<?php echo $v('los_days'); ?>"></div>
                    </div>
                    <div class="mb-2"><label class="form-label small mb-1">Mandatory documents (one per line)</label>
                        <textarea name="documents" rows="3" class="form-control form-control-sm"><?php echo $v('documents'); ?></textarea>
                        <div class="form-text">Added to the standard list: <?php echo html_escape(implode(', ', Pmjay_model::STANDARD_DOCS)); ?>.</div></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="preauth" value="1" id="pk-pre" <?php echo !$edit || $e['preauth'] ? 'checked' : ''; ?>><label class="form-check-label small" for="pk-pre">Pre-authorisation required</label></div>
                    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="pk-act" <?php echo !$edit || $e['is_active'] ? 'checked' : ''; ?>><label class="form-check-label small" for="pk-act">Active</label></div>
                    <button class="btn btn-primary btn-sm">Save package</button>
                    <?php if ($edit) { ?><a class="btn btn-link btn-sm" href="<?php echo site_url('admin/pmjay/packages'); ?>">Cancel</a><?php } ?>
                </form>
            </div>
        </div>
    </div>
    <?php } ?>
    <div class="<?php echo $can_add || $edit ? 'col-lg-8' : 'col-12'; ?>">
        <div class="card">
            <div class="card-header"><h3 class="card-title">HBP package master</h3></div>
            <div class="card-body table-responsive">
                <table class="table table-sm table-striped table-hover mb-0">
                    <thead><tr><th>Code</th><th>Package</th><th>Specialty</th><th class="text-end">Rate</th><th>Pre-auth</th><th>Stay</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$rows) { ?><tr><td colspan="7" class="text-muted">No packages yet.</td></tr><?php } ?>
                    <?php foreach ($rows as $r) { ?>
                        <tr class="<?php echo $r['is_active'] ? '' : 'text-muted'; ?>">
                            <td class="font-monospace small"><?php echo html_escape($r['code']); ?><?php echo $r['is_sample'] ? ' <span class="badge bg-warning text-dark">sample</span>' : ''; ?></td>
                            <td><?php echo html_escape($r['name']); ?><?php echo $r['is_active'] ? '' : ' <span class="badge bg-secondary">inactive</span>'; ?></td>
                            <td class="small"><?php echo html_escape($r['specialty']); ?></td>
                            <td class="text-end"><?php echo pmjay_money($r['rate']); ?></td>
                            <td><?php echo $r['preauth'] ? 'Yes' : 'No'; ?></td>
                            <td class="small"><?php echo $r['los_days'] !== null ? (int) $r['los_days'] . ' d' : '—'; ?></td>
                            <td class="text-end"><?php if ($can_edit) { ?><a class="btn btn-outline-secondary btn-sm" href="<?php echo site_url('admin/pmjay/packages?edit=' . (int) $r['id']); ?>">Edit</a><?php } ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
