<?php
/** Claim detail (used by view.php and the approvals modal). Vars: $claim, $statuses, $can_decide */
$c   = $claim;
$cur = $this->setting_model->getSetting()->currency_symbol;
$fmt = function ($d) { return empty($d) ? '' : $this->customlib->YYYYMMDDTodateFormat($d); };
$dt  = function ($d) { return empty($d) ? '' : $this->customlib->YYYYMMDDHisTodateFormat($d, $this->customlib->getHospitalTimeFormat()); };
$payable = $c['approved_amount'] !== null ? Travelexpense_model::payable_amount($c) : null;
?>
<div class="d-flex flex-wrap align-items-start gap-3 mb-3">
  <div class="me-auto">
    <div class="fw-bold" style="font-size:16px"><?php echo html_escape($c['title']); ?></div>
    <div class="text-muted small"><?php echo html_escape($c['claim_no']); ?> · <?php echo html_escape(trim($c['name'] . ' ' . $c['surname'])); ?> (<?php echo html_escape($c['employee_id']); ?>)<?php echo $c['designation'] ? ' · ' . html_escape($c['designation']) : ''; ?></div>
    <?php if ($c['purpose']) { ?><div class="small mt-1"><?php echo html_escape($c['purpose']); ?></div><?php } ?>
  </div>
  <span class="sh-hr-pill <?php echo $c['status']; ?>" style="font-size:13px"><?php echo html_escape($statuses[$c['status']]); ?></span>
</div>
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><div class="sh-hr-stat"><div class="v"><?php echo $cur . number_format((float) $c['total_amount'], 2); ?></div><div class="l">Claimed</div></div></div>
  <div class="col-6 col-md-3"><div class="sh-hr-stat"><div class="v"><?php echo $cur . number_format((float) $c['advance_taken'], 2); ?></div><div class="l">Advance taken</div></div></div>
  <div class="col-6 col-md-3"><div class="sh-hr-stat"><div class="v"><?php echo $c['approved_amount'] !== null ? $cur . number_format((float) $c['approved_amount'], 2) : '—'; ?></div><div class="l">Approved</div></div></div>
  <div class="col-6 col-md-3"><div class="sh-hr-stat"><div class="v"><?php echo $payable !== null ? $cur . number_format($payable, 2) : '—'; ?></div><div class="l">Paid with salary</div></div></div>
</div>
<div class="table-responsive">
<table class="table table-sm table-bordered sh-hr-table">
  <thead class="table-light"><tr><th>Date</th><th>Category</th><th>Description</th><th>From → To</th><th>Mode</th><th class="text-end">Km</th><th class="text-end">Amount</th><th>Bill</th></tr></thead>
  <tbody>
  <?php foreach ($c['items'] as $it) { ?>
    <tr>
      <td class="text-nowrap"><?php echo $fmt($it['expense_date']); ?></td>
      <td><?php echo html_escape($it['category']); ?></td>
      <td><?php echo html_escape($it['description']); ?></td>
      <td><?php echo html_escape(trim($it['from_place'] . ($it['to_place'] ? ' → ' . $it['to_place'] : ''))); ?></td>
      <td><?php echo html_escape($it['travel_mode']); ?></td>
      <td class="text-end"><?php echo $it['distance_km'] !== null ? html_escape($it['distance_km']) : ''; ?></td>
      <td class="text-end"><?php echo number_format((float) $it['amount'], 2); ?></td>
      <td><?php if ($it['attachment']) { ?><a target="_blank" rel="noopener" href="<?php echo base_url('admin/travelexpense/file/' . $it['id']); ?>"><i class="fa fa-paperclip"></i> View</a><?php } else { ?><span class="text-muted small">No bill</span><?php } ?></td>
    </tr>
  <?php } ?>
  </tbody>
  <tfoot><tr><th colspan="6" class="text-end">Total</th><th class="text-end"><?php echo number_format((float) $c['total_amount'], 2); ?></th><th></th></tr></tfoot>
</table>
</div>
<div class="small text-muted">
  <?php if ($c['submitted_at']) { ?>Sent <?php echo $dt($c['submitted_at']); ?>. <?php } ?>
  <?php if ($c['decided_at']) { ?><?php echo $c['status'] === 'rejected' ? 'Rejected' : 'Approved'; ?> by <?php echo html_escape(trim($c['decided_name'] . ' ' . $c['decided_surname'])); ?> on <?php echo $dt($c['decided_at']); ?>.<?php } ?>
  <?php if ($c['paid_at']) { ?> Paid with payslip #<?php echo (int) $c['payslip_id']; ?> on <?php echo $dt($c['paid_at']); ?>.<?php } ?>
</div>
<?php if ($c['decision_note']) { ?><div class="alert <?php echo $c['status'] === 'rejected' ? 'alert-danger' : 'alert-info'; ?> py-2 mt-2 mb-0">Approver's note: <?php echo html_escape($c['decision_note']); ?></div><?php } ?>

<?php if ($can_decide) { ?>
<form class="border rounded p-3 mt-3" id="shTeDecide" data-id="<?php echo (int) $c['id']; ?>" onsubmit="return false;">
  <div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label">Approve amount (<?php echo $cur; ?>)</label><input class="form-control" type="number" step="0.01" min="0" max="<?php echo html_escape($c['total_amount']); ?>" name="approved_amount" value="<?php echo html_escape($c['total_amount']); ?>"></div>
    <div class="col-md-5"><label class="form-label">Note <small class="text-muted">(needed to reject or reduce)</small></label><input class="form-control" name="note" maxlength="500"></div>
    <div class="col-md-4 d-flex gap-2">
      <button type="button" class="btn btn-success flex-fill sh-te-act" data-action="approve"><i class="fa fa-check"></i> Approve</button>
      <button type="button" class="btn btn-danger flex-fill sh-te-act" data-action="reject"><i class="fa fa-times"></i> Reject</button>
    </div>
  </div>
</form>
<script>
$(function () {
  $('#shTeDecide .sh-te-act').off('click').on('click', function () {
    var $f = $('#shTeDecide'), action = $(this).data('action');
    shHr.postForm($f[0], { url: base_url + 'admin/travelexpense/decide/' + $f.data('id'), button: this,
      before: function (fd) { fd.append('action', action); }, done: function () { location.reload(); } });
  });
});
</script>
<?php } ?>
