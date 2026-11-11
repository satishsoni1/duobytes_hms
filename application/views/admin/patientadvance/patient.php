<?php
/** One patient's advances. Vars: $patient, $advances, $balance, $cases, $entries, $modes */
$cur = $this->setting_model->getSetting()->currency_symbol;
$dt  = function ($d) { return empty($d) ? '' : $this->customlib->YYYYMMDDHisTodateFormat($d, $this->customlib->getHospitalTimeFormat()); };
$can_add = $this->rbac->hasPrivilege('patient_advance', 'can_add');
$can_edit = $this->rbac->hasPrivilege('patient_advance', 'can_edit');
$can_delete = $this->rbac->hasPrivilege('patient_advance', 'can_delete');
$open_adv = array_values(array_filter($advances, function ($a) { return $a['balance'] > 0; }));
$due_cases = array_values(array_filter($cases, function ($c) { return $c['due'] > 0; }));
?>
<link rel="stylesheet" href="<?php echo base_url('backend/css/sh-hrms.css'); ?>">
<script src="<?php echo base_url('backend/js/sh-hrms.js'); ?>"></script>
<div class="card">
  <div class="card-header d-flex align-items-center flex-wrap gap-2">
    <h3 class="card-title mb-0 me-auto"><?php echo html_escape($patient['patient_name']); ?> <small class="text-muted">(<?php echo (int) $patient['id']; ?>)<?php echo $patient['mobileno'] ? ' · ' . html_escape($patient['mobileno']) : ''; ?></small></h3>
    <?php if ($can_add) { ?><button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#shAdvCollect"><i class="fa fa-plus"></i> Collect advance</button><?php } ?>
    <a class="btn btn-default btn-sm" href="<?php echo base_url('admin/patientadvance'); ?>">Advance register</a>
  </div>
  <div class="card-body">
    <div class="row g-2 mb-3">
      <div class="col-6 col-md-3"><div class="sh-hr-stat"><div class="v text-success"><?php echo $cur . number_format($balance, 2); ?></div><div class="l">Advance available</div></div></div>
      <div class="col-6 col-md-3"><div class="sh-hr-stat"><div class="v"><?php echo $cur . number_format(array_sum(array_column($due_cases, 'due')), 2); ?></div><div class="l">Due on open visits / admissions</div></div></div>
      <div class="col-12 col-md-6 d-flex align-items-center gap-2 flex-wrap">
        <?php if ($can_add && $open_adv && $due_cases) { ?><button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#shAdvSettle"><i class="fa fa-exchange"></i> Use advance against bill</button><?php } ?>
        <?php if ($can_edit && $open_adv) { ?><button type="button" class="btn btn-default" data-bs-toggle="modal" data-bs-target="#shAdvRefund"><i class="fa fa-undo"></i> Refund balance</button><?php } ?>
      </div>
    </div>

    <h5 class="mt-3" style="font-size:15px;font-weight:700">Visits &amp; admissions</h5>
    <?php if (empty($cases)) { ?><div class="text-muted small mb-3">No OPD visits or IPD admissions yet. The advance waits until one is opened.</div><?php } else { ?>
    <div class="table-responsive"><table class="table table-sm sh-hr-table">
      <thead><tr><th>Case</th><th>Date</th><th class="text-end">Charges</th><th class="text-end">Paid</th><th class="text-end">Due</th><th></th></tr></thead><tbody>
      <?php foreach ($cases as $c) { ?>
        <tr><td><?php echo html_escape($c['label']); ?> <span class="text-muted small"><?php echo strtoupper($c['kind']); ?></span></td><td class="small"><?php echo $dt($c['date']); ?></td>
          <td class="text-end"><?php echo number_format($c['charges'], 2); ?></td><td class="text-end"><?php echo number_format($c['paid'], 2); ?></td>
          <td class="text-end fw-semibold<?php echo $c['due'] > 0 ? ' text-danger' : ''; ?>"><?php echo number_format($c['due'], 2); ?></td>
          <td><?php echo $c['closed'] ? '<span class="sh-hr-pill muted">Discharged</span>' : ''; ?></td></tr>
      <?php } ?></tbody></table></div>
    <?php } ?>

    <h5 class="mt-3" style="font-size:15px;font-weight:700">Advances</h5>
    <?php if (empty($advances)) { ?><div class="sh-hr-empty"><i class="fa fa-money"></i>No advance collected from this patient.</div><?php } else { ?>
    <div class="table-responsive"><table class="table table-sm table-hover sh-hr-table">
      <thead><tr><th>Receipt</th><th>Date</th><th>For</th><th>Mode</th><th class="text-end">Amount</th><th class="text-end">Used</th><th class="text-end">Balance</th><th class="text-end"></th></tr></thead><tbody>
      <?php foreach ($advances as $a) { ?>
        <tr<?php echo $a['is_cancelled'] ? ' class="text-muted"' : ''; ?>>
          <td><?php echo html_escape($a['receipt_no']); ?><?php echo $a['is_cancelled'] ? ' <span class="sh-hr-pill cancelled">Cancelled</span>' : ''; ?></td>
          <td class="small"><?php echo $dt($a['payment_date']); ?></td>
          <td><?php echo html_escape(Patientadvance_model::$purposes[$a['purpose']] ?? $a['purpose']); ?></td>
          <td><?php echo html_escape($modes[$a['payment_mode']] ?? $a['payment_mode']); ?><?php echo $a['reference_no'] ? '<br><small class="text-muted">' . html_escape($a['reference_no']) . '</small>' : ''; ?></td>
          <td class="text-end"><?php echo number_format((float) $a['amount'], 2); ?></td>
          <td class="text-end"><?php echo number_format((float) $a['used'], 2); ?></td>
          <td class="text-end fw-semibold"><?php echo number_format($a['balance'], 2); ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-light btn-sm" target="_blank" rel="noopener" href="<?php echo base_url('admin/patientadvance/receipt/' . $a['id']); ?>" title="Receipt"><i class="fa fa-print"></i></a>
            <?php if ($can_delete && !$a['is_cancelled'] && (float) $a['used'] == 0) { ?><button type="button" class="btn btn-light btn-sm sh-adv-cancel" data-id="<?php echo (int) $a['id']; ?>" title="Cancel (entered by mistake)"><i class="fa fa-ban"></i></button><?php } ?>
          </td>
        </tr>
      <?php } ?></tbody></table></div>
    <?php } ?>

    <?php if ($entries) { ?>
    <h5 class="mt-3" style="font-size:15px;font-weight:700">Adjustments &amp; refunds</h5>
    <div class="table-responsive"><table class="table table-sm sh-hr-table">
      <thead><tr><th>Date</th><th>Advance</th><th>Type</th><th>Against / mode</th><th class="text-end">Amount</th><th>By</th><th>Note</th><th></th></tr></thead><tbody>
      <?php foreach ($entries as $e) { $case = $e['ipd_id'] ? 'IPD ' . $e['ipd_id'] : ($e['opd_id'] ? 'OPD ' . $e['opd_id'] : ''); ?>
        <tr<?php echo $e['is_reversed'] ? ' class="text-muted text-decoration-line-through"' : ''; ?>>
          <td class="small"><?php echo $dt($e['entry_date']); ?></td><td><?php echo html_escape($e['receipt_no']); ?></td>
          <td><?php echo $e['entry_type'] === 'settle' ? 'Used against bill' : 'Refunded'; ?></td>
          <td><?php echo html_escape($e['entry_type'] === 'settle' ? $case : ($modes[$e['payment_mode']] ?? $e['payment_mode'])); ?></td>
          <td class="text-end"><?php echo number_format((float) $e['amount'], 2); ?></td>
          <td class="small"><?php echo html_escape($e['created_name']); ?></td><td class="small"><?php echo html_escape($e['note']); ?></td>
          <td class="text-end"><?php if ($can_edit && !$e['is_reversed']) { ?><button type="button" class="btn btn-light btn-sm sh-adv-rev" data-id="<?php echo (int) $e['id']; ?>" title="Reverse"><i class="fa fa-undo"></i></button><?php } ?></td>
        </tr>
      <?php } ?></tbody></table></div>
    <?php } ?>
  </div>
</div>

<?php if ($can_add) { $this->load->view('admin/patientadvance/_collect_modal', array('modes' => $modes, 'patient' => $patient)); } ?>

<?php if ($can_add && $open_adv && $due_cases) { ?>
<div class="modal fade sh-modal" id="shAdvSettle" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="shAdvSettleForm" action="<?php echo base_url('admin/patientadvance/settle'); ?>" autocomplete="off">
    <div class="modal-header"><h5 class="modal-title">Use advance against bill</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><div class="row g-2">
      <div class="col-12"><label class="form-label">From advance</label><select class="form-select" name="advance_id" id="shAdvFrom"><?php foreach ($open_adv as $a) { ?><option value="<?php echo (int) $a['id']; ?>" data-bal="<?php echo $a['balance']; ?>"><?php echo html_escape($a['receipt_no'] . ' — balance ' . $cur . number_format($a['balance'], 2)); ?></option><?php } ?></select></div>
      <div class="col-12"><label class="form-label">Against</label><select class="form-select" name="case" id="shAdvCase"><?php foreach ($due_cases as $c) { ?><option value="<?php echo $c['kind'] . ':' . $c['id']; ?>" data-due="<?php echo $c['due']; ?>"><?php echo html_escape($c['label'] . ' — due ' . $cur . number_format($c['due'], 2)); ?></option><?php } ?></select></div>
      <div class="col-md-6"><label class="form-label">Amount</label><input class="form-control" type="number" step="0.01" min="0.01" name="amount" id="shAdvAmt"></div>
      <div class="col-md-6"><label class="form-label">Note</label><input class="form-control" name="note" maxlength="500"></div>
      <div class="col-12 small text-muted">This adds a payment of this amount (mode "Advance adjustment") to the visit or admission, so its bill shows it as paid.</div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success">Adjust</button></div>
  </form></div>
</div>
<?php } ?>
<?php if ($can_edit && $open_adv) { ?>
<div class="modal fade sh-modal" id="shAdvRefund" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="shAdvRefundForm" action="<?php echo base_url('admin/patientadvance/refund'); ?>" autocomplete="off">
    <div class="modal-header"><h5 class="modal-title">Refund advance</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><div class="row g-2">
      <div class="col-12"><label class="form-label">Advance</label><select class="form-select" name="advance_id" id="shAdvRefFrom"><?php foreach ($open_adv as $a) { ?><option value="<?php echo (int) $a['id']; ?>" data-bal="<?php echo $a['balance']; ?>"><?php echo html_escape($a['receipt_no'] . ' — balance ' . $cur . number_format($a['balance'], 2)); ?></option><?php } ?></select></div>
      <div class="col-md-6"><label class="form-label">Amount</label><input class="form-control" type="number" step="0.01" min="0.01" name="amount" id="shAdvRefAmt"></div>
      <div class="col-md-6"><label class="form-label">Paid back by</label><select class="form-select" name="payment_mode"><?php foreach ($modes as $k => $v) { ?><option value="<?php echo html_escape($k); ?>"><?php echo html_escape($v); ?></option><?php } ?></select></div>
      <div class="col-12"><label class="form-label">Note</label><input class="form-control" name="note" maxlength="500" placeholder="e.g. Unused deposit returned at discharge"></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Record refund</button></div>
  </form></div>
</div>
<?php } ?>
<script>
$(function () {
  function fill() {
    var bal = parseFloat($('#shAdvFrom option:selected').data('bal')) || 0, due = parseFloat($('#shAdvCase option:selected').data('due')) || 0;
    $('#shAdvAmt').val(Math.min(bal, due).toFixed(2)).attr('max', Math.min(bal, due).toFixed(2));
  }
  $('#shAdvFrom, #shAdvCase').on('change', fill); fill();
  $('#shAdvRefFrom').on('change', function () { $('#shAdvRefAmt').val((parseFloat($(this).find(':selected').data('bal')) || 0).toFixed(2)); }).trigger('change');
  $('#shAdvSettleForm, #shAdvRefundForm').on('submit', function (e) { e.preventDefault(); shHr.postForm(this, { done: function () { location.reload(); } }); });
  $('.sh-adv-rev').on('click', function () { shHr.post(base_url + 'admin/patientadvance/reverse/' + $(this).data('id'), {}, { confirm: 'Reverse this entry? For an adjustment, the payment is removed from the bill and the amount returns to the advance.', done: function () { location.reload(); } }); });
  $('.sh-adv-cancel').on('click', function () {
    var note = prompt('Why is this advance cancelled? (e.g. entered twice)'); if (!note) { return; }
    shHr.post(base_url + 'admin/patientadvance/cancel/' + $(this).data('id'), { note: note }, { done: function () { location.reload(); } });
  });
});
</script>
