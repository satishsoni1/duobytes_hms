<?php
/** New / edit travel claim. Vars: $claim (or null), $categories, $modes */
$this->load->view('admin/hrms/_nav', array('active' => 'expenses'));
$c     = $claim ?: array('id' => 0, 'title' => '', 'purpose' => '', 'advance_taken' => '0.00', 'items' => array(), 'status' => 'draft', 'decision_note' => '');
$items = $c['items'] ?: array(array());
$cur   = $this->setting_model->getSetting()->currency_symbol;
$fmt   = function ($d) { return empty($d) ? '' : $this->customlib->YYYYMMDDTodateFormat($d); };
$opt   = function ($list, $sel) { $o = ''; foreach ($list as $v) { $o .= '<option' . ($v === $sel ? ' selected' : '') . '>' . html_escape($v) . '</option>'; } return $o; };
?>
<form id="shTeForm" class="card" action="<?php echo base_url('admin/travelexpense/save/' . (int) $c['id']); ?>" enctype="multipart/form-data" autocomplete="off">
  <?php echo $this->customlib->getCSRF(); ?>
  <div class="card-header d-flex align-items-center">
    <h3 class="card-title mb-0 me-auto"><?php echo html_escape($title); ?><?php echo !empty($c['claim_no']) ? ' · ' . html_escape($c['claim_no']) : ''; ?></h3>
    <a class="btn btn-default btn-sm" href="<?php echo base_url('admin/travelexpense'); ?>">Back</a>
  </div>
  <div class="card-body">
    <?php if ($c['status'] === 'rejected') { ?>
      <div class="alert alert-warning py-2">This claim was rejected<?php echo $c['decision_note'] ? ': <strong>' . html_escape($c['decision_note']) . '</strong>' : ''; ?>. Correct it and send it again.</div>
    <?php } ?>
    <div class="row g-3 mb-3">
      <div class="col-md-6"><label class="form-label">Trip / purpose <span class="req">*</span></label><input class="form-control" name="title" maxlength="200" value="<?php echo html_escape($c['title']); ?>" placeholder="e.g. CME at Nagpur, 12–13 Nov"></div>
      <div class="col-md-4"><label class="form-label">Details</label><input class="form-control" name="purpose" maxlength="2000" value="<?php echo html_escape($c['purpose']); ?>" placeholder="Who approved the trip, patient transfer, etc."></div>
      <div class="col-md-2"><label class="form-label">Advance taken (<?php echo $cur; ?>)</label><input class="form-control" type="number" step="0.01" min="0" name="advance_taken" value="<?php echo html_escape($c['advance_taken']); ?>"></div>
    </div>
    <div class="table-responsive">
      <table class="table table-bordered sh-hr-items mb-2" id="shTeItems">
        <thead class="table-light"><tr><th style="width:120px">Date</th><th style="width:150px">Category</th><th>Description</th><th style="width:110px">From</th><th style="width:110px">To</th><th style="width:130px">Mode</th><th style="width:80px">Km</th><th style="width:110px">Amount <span class="req">*</span></th><th style="width:200px">Bill / ticket</th><th style="width:36px"></th></tr></thead>
        <tbody>
        <?php foreach ($items as $i => $it) { $k = 'r' . $i; ?>
          <tr data-key="<?php echo $k; ?>">
            <td><input type="hidden" name="items[<?php echo $k; ?>][id]" value="<?php echo (int) ($it['id'] ?? 0); ?>"><input class="form-control form-control-sm date" name="items[<?php echo $k; ?>][date]" value="<?php echo $fmt($it['expense_date'] ?? ''); ?>"></td>
            <td><select class="form-select form-select-sm" name="items[<?php echo $k; ?>][category]"><?php echo $opt($categories, $it['category'] ?? ''); ?></select></td>
            <td><input class="form-control form-control-sm" name="items[<?php echo $k; ?>][description]" maxlength="300" value="<?php echo html_escape($it['description'] ?? ''); ?>"></td>
            <td><input class="form-control form-control-sm" name="items[<?php echo $k; ?>][from_place]" maxlength="100" value="<?php echo html_escape($it['from_place'] ?? ''); ?>"></td>
            <td><input class="form-control form-control-sm" name="items[<?php echo $k; ?>][to_place]" maxlength="100" value="<?php echo html_escape($it['to_place'] ?? ''); ?>"></td>
            <td><select class="form-select form-select-sm" name="items[<?php echo $k; ?>][travel_mode]"><option value="">—</option><?php echo $opt($modes, $it['travel_mode'] ?? ''); ?></select></td>
            <td><input class="form-control form-control-sm" type="number" step="0.1" min="0" name="items[<?php echo $k; ?>][distance_km]" value="<?php echo html_escape($it['distance_km'] ?? ''); ?>"></td>
            <td><input class="form-control form-control-sm sh-te-amt" type="number" step="0.01" min="0" name="items[<?php echo $k; ?>][amount]" value="<?php echo html_escape($it['amount'] ?? ''); ?>"></td>
            <td>
              <?php if (!empty($it['attachment'])) { ?>
                <div class="small mb-1"><a target="_blank" rel="noopener" href="<?php echo base_url('admin/travelexpense/file/' . (int) $it['id']); ?>"><i class="fa fa-paperclip"></i> <?php echo html_escape(mb_strimwidth($it['attachment_name'], 0, 22, '…')); ?></a>
                  <label class="ms-1"><input type="checkbox" name="items[<?php echo $k; ?>][remove_file]" value="1"> remove</label></div>
              <?php } ?>
              <input class="form-control form-control-sm" type="file" name="item_file_<?php echo $k; ?>" accept=".pdf,.jpg,.jpeg,.png,.webp" capture="environment">
            </td>
            <td><button type="button" class="btn btn-light btn-sm sh-te-rm" title="Remove line"><i class="fa fa-times"></i></button></td>
          </tr>
        <?php } ?>
        </tbody>
        <tfoot><tr><td colspan="7"><button type="button" class="btn btn-default btn-sm" id="shTeAdd"><i class="fa fa-plus"></i> Add line</button></td><td class="fw-bold text-end" id="shTeTotal">0.00</td><td colspan="2" class="small text-muted" id="shTeNet"></td></tr></tfoot>
      </table>
    </div>
    <div class="d-flex gap-2 flex-wrap mt-3">
      <button type="submit" class="btn btn-default" data-submit="0"><i class="fa fa-save"></i> Save draft</button>
      <button type="submit" class="btn btn-primary" data-submit="1"><i class="fa fa-paper-plane"></i> Send for approval</button>
    </div>
  </div>
</form>
<template id="shTeRow">
  <tr data-key="__K__">
    <td><input type="hidden" name="items[__K__][id]" value="0"><input class="form-control form-control-sm date" name="items[__K__][date]"></td>
    <td><select class="form-select form-select-sm" name="items[__K__][category]"><?php echo $opt($categories, ''); ?></select></td>
    <td><input class="form-control form-control-sm" name="items[__K__][description]" maxlength="300"></td>
    <td><input class="form-control form-control-sm" name="items[__K__][from_place]" maxlength="100"></td>
    <td><input class="form-control form-control-sm" name="items[__K__][to_place]" maxlength="100"></td>
    <td><select class="form-select form-select-sm" name="items[__K__][travel_mode]"><option value="">—</option><?php echo $opt($modes, ''); ?></select></td>
    <td><input class="form-control form-control-sm" type="number" step="0.1" min="0" name="items[__K__][distance_km]"></td>
    <td><input class="form-control form-control-sm sh-te-amt" type="number" step="0.01" min="0" name="items[__K__][amount]"></td>
    <td><input class="form-control form-control-sm" type="file" name="item_file___K__" accept=".pdf,.jpg,.jpeg,.png,.webp" capture="environment"></td>
    <td><button type="button" class="btn btn-light btn-sm sh-te-rm" title="Remove line"><i class="fa fa-times"></i></button></td>
  </tr>
</template>
<script>
$(function () {
  var n = 1000, cur = <?php echo json_encode($cur); ?>, submitNow = 0;
  function total() {
    var t = 0; $('.sh-te-amt').each(function () { t += parseFloat(this.value) || 0; });
    var adv = parseFloat($('[name=advance_taken]').val()) || 0;
    $('#shTeTotal').text(cur + t.toFixed(2));
    $('#shTeNet').text(adv > 0 ? 'Payable after advance: ' + cur + Math.max(0, t - adv).toFixed(2) : '');
  }
  $('#shTeAdd').on('click', function () {
    var k = 'n' + (n++), html = $('#shTeRow').html().replace(/__K__/g, k);
    $(html).appendTo('#shTeItems tbody');
  });
  $(document).on('click', '.sh-te-rm', function () {
    if ($('#shTeItems tbody tr').length > 1) { $(this).closest('tr').remove(); } else { $(this).closest('tr').find('input:not([type=hidden]),select').val(''); }
    total();
  });
  $(document).on('input', '.sh-te-amt, [name=advance_taken]', total);
  $('#shTeForm [type=submit]').on('click', function () { submitNow = $(this).data('submit'); });
  $('#shTeForm').on('submit', function (e) {
    e.preventDefault();
    if (submitNow && !confirm('Send this claim for approval? You cannot change it while it is being reviewed.')) { return; }
    shHr.postForm(this, {
      button: $(this).find('[data-submit="' + submitNow + '"]'),
      before: function (fd) { fd.append('submit_now', submitNow ? '1' : ''); },
      done: function (r) { location.href = base_url + 'admin/travelexpense/view/' + r.id; }
    });
  });
  total();
});
</script>
