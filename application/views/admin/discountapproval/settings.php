<?php
/** Setup > Settings > Discount Approval. Vars: $set (enabled, approvers, free_pct), $staff, $can_edit */
$dis = $can_edit ? '' : 'disabled';
$by_role = array(); foreach ($staff as $s) { $by_role[$s['role_name'] ?: 'Other'][] = $s; }
?>
<script src="<?php echo base_url('backend/js/sh-hrms.js'); ?>"></script>
<div class="row">
  <?php $this->load->view('setting/sidebar'); ?>
  <div class="col-md-10">
    <div class="card">
      <div class="card-header"><h3 class="card-title mb-0">Discount Approval</h3></div>
      <div class="card-body">
        <form id="shDaSet" action="<?php echo base_url('admin/discountapproval/settings_save'); ?>" autocomplete="off" style="max-width:900px">
          <?php echo $this->customlib->getCSRF(); ?>
          <p class="text-muted">When this is on, a discount on an OPD/IPD charge, pathology, radiology, pharmacy, blood bank or ambulance bill entered by someone who is not an approver is held back. The bill is saved at the full amount and the discount is applied only after an approver approves it under <a href="<?php echo base_url('admin/discountapproval'); ?>">Discount approvals</a>.</p>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="enabled" value="1" id="shDaOn"<?php echo $set['enabled'] ? ' checked' : ''; ?> <?php echo $dis; ?>>
            <label class="form-check-label fw-semibold" for="shDaOn">Discounts need approval</label>
          </div>
          <div class="mb-3" style="max-width:320px">
            <label class="form-label">Apply without approval up to (%)</label>
            <input class="form-control" type="number" step="0.01" min="0" max="100" name="free_pct" value="<?php echo html_escape($set['free_pct']); ?>" <?php echo $dis; ?>>
            <small class="text-muted">0 = every discount needs approval.</small>
          </div>
          <label class="form-label fw-semibold">Who can approve <small class="text-muted fw-normal">(doctors or staff; their own discounts apply straight away)</small></label>
          <input type="search" class="form-control form-control-sm mb-2" style="max-width:320px" placeholder="Find staff" oninput="var q=this.value.toLowerCase();document.querySelectorAll('.sh-da-staff').forEach(function(e){e.style.display=e.textContent.toLowerCase().indexOf(q)>-1?'':'none';})">
          <div class="border rounded p-2" style="max-height:420px;overflow:auto">
            <?php foreach ($by_role as $role => $list) { ?>
              <div class="small text-uppercase text-muted fw-bold mt-2"><?php echo html_escape($role); ?></div>
              <div class="row g-1">
              <?php foreach ($list as $s) { ?>
                <div class="col-md-4 sh-da-staff"><div class="form-check"><input class="form-check-input" type="checkbox" name="approvers[]" value="<?php echo (int) $s['id']; ?>" id="shDaS<?php echo (int) $s['id']; ?>"<?php echo in_array((int) $s['id'], $set['approvers'], true) ? ' checked' : ''; ?> <?php echo $dis; ?>>
                  <label class="form-check-label" for="shDaS<?php echo (int) $s['id']; ?>"><?php echo html_escape(trim($s['name'] . ' ' . $s['surname'])); ?> <small class="text-muted"><?php echo html_escape($s['employee_id']); ?></small></label></div></div>
              <?php } ?>
              </div>
            <?php } ?>
          </div>
          <?php if ($can_edit) { ?><button class="btn btn-primary mt-3" type="submit"><i class="fa fa-check"></i> Save</button><?php } ?>
        </form>
      </div>
    </div>
  </div>
</div>
<script>
$(function () { $('#shDaSet').on('submit', function (e) { e.preventDefault(); shHr.postForm(this); }); });
</script>
