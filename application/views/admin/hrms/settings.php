<?php
/** Setup > Settings > HR Settings. Vars: $fmt (staff ID format), $next_id, $can_edit, $expense_categories */
$dis = $can_edit ? '' : 'disabled';
?>
<script src="<?php echo base_url('backend/js/sh-hrms.js'); ?>"></script>
<div class="row">
  <?php $this->load->view('setting/sidebar'); ?>
  <div class="col-md-10">
    <div class="card">
      <div class="card-header"><h3 class="card-title mb-0">HR Settings</h3></div>
      <div class="card-body">
        <form id="shHrSet" action="<?php echo base_url('admin/hrms/settings_save'); ?>" autocomplete="off" class="row g-4" style="max-width:820px">
          <?php echo $this->customlib->getCSRF(); ?>
          <div class="col-12">
            <h4 style="font-size:16px;font-weight:700">Staff ID</h4>
            <div class="form-check form-switch mb-3">
              <input class="form-check-input" type="checkbox" name="staff_id_auto" value="1" id="shAuto"<?php echo $fmt['auto'] ? ' checked' : ''; ?> <?php echo $dis; ?>>
              <label class="form-check-label" for="shAuto">Give new staff an ID automatically</label>
            </div>
            <div class="row g-3">
              <div class="col-sm-4"><label class="form-label">Prefix</label><input class="form-control sh-id-in" name="staff_id_prefix" maxlength="20" value="<?php echo html_escape($fmt['prefix']); ?>" placeholder="e.g. SKY" <?php echo $dis; ?>><small class="text-muted">Letters, numbers, / - _</small></div>
              <div class="col-sm-4"><label class="form-label">Number of digits</label><input class="form-control sh-id-in" type="number" min="1" max="10" name="staff_id_digits" value="<?php echo (int) $fmt['digits']; ?>" <?php echo $dis; ?>></div>
              <div class="col-sm-4"><label class="form-label">Start numbering from</label><input class="form-control sh-id-in" type="number" min="1" name="staff_id_start" value="<?php echo (int) $fmt['start']; ?>" <?php echo $dis; ?>></div>
            </div>
            <div class="alert alert-info mt-3 mb-0 py-2">Next staff ID: <strong id="shNextId"><?php echo html_escape($next_id); ?></strong>
              <br><small>It continues after the highest number already used with this prefix. Existing staff IDs are not changed.</small></div>
          </div>
          <div class="col-12">
            <h4 style="font-size:16px;font-weight:700">Travel expense categories</h4>
            <textarea class="form-control" name="expense_categories" rows="4" placeholder="One per line. Leave empty for the defaults: Travel fare, Fuel / mileage, Local conveyance, Lodging, Food, Conference / CME fee, Other" <?php echo $dis; ?>><?php echo html_escape($expense_categories); ?></textarea>
          </div>
          <?php if ($can_edit) { ?><div class="col-12"><button class="btn btn-primary" type="submit"><i class="fa fa-check"></i> Save</button></div><?php } ?>
        </form>
      </div>
    </div>
  </div>
</div>
<script>
$(function () {
  $('#shHrSet').on('submit', function (e) { e.preventDefault(); shHr.postForm(this, { done: function (r) { $('#shNextId').text(r.next_id); } }); });
  var t;
  $('.sh-id-in').on('input', function () {
    clearTimeout(t);
    t = setTimeout(function () {
      $.getJSON(base_url + 'admin/hrms/settings_preview', { prefix: $('[name=staff_id_prefix]').val(), digits: $('[name=staff_id_digits]').val(), start: $('[name=staff_id_start]').val() }, function (r) { $('#shNextId').text(r.next_id); });
    }, 300);
  });
});
</script>
