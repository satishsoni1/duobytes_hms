<?php
/** Advance register. Vars: $rows, $filter, $modes */
$cur = $this->setting_model->getSetting()->currency_symbol;
$dt  = function ($d) { return empty($d) ? '' : $this->customlib->YYYYMMDDHisTodateFormat($d, $this->customlib->getHospitalTimeFormat()); };
$tot = array('amount' => 0, 'balance' => 0); $by_mode = array();
foreach ($rows as $r) { if ($r['is_cancelled']) { continue; } $tot['amount'] += $r['amount']; $tot['balance'] += $r['balance']; $by_mode[$r['payment_mode']] = ($by_mode[$r['payment_mode']] ?? 0) + $r['amount']; }
$can_add = $this->rbac->hasPrivilege('patient_advance', 'can_add');
?>
<link rel="stylesheet" href="<?php echo base_url('backend/css/sh-hrms.css'); ?>">
<script src="<?php echo base_url('backend/js/sh-hrms.js'); ?>"></script>
<div class="card">
  <div class="card-header d-flex align-items-center flex-wrap gap-2">
    <h3 class="card-title mb-0 me-auto">Patient advances</h3>
    <?php if ($can_add) { ?><button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#shAdvCollect"><i class="fa fa-plus"></i> Collect advance</button><?php } ?>
  </div>
  <div class="card-body">
    <p class="text-muted small">Deposits taken before or during an OPD visit or IPD stay. Open a patient to use the advance against the visit's or admission's charges, or to refund what is left.</p>
    <form class="row g-2 mb-3 align-items-center" method="get">
      <div class="col-sm-2"><input class="form-control form-control-sm" type="date" name="from" value="<?php echo html_escape($filter['from']); ?>" title="From"></div>
      <div class="col-sm-2"><input class="form-control form-control-sm" type="date" name="to" value="<?php echo html_escape($filter['to']); ?>" title="To"></div>
      <div class="col-sm-2"><select class="form-select form-select-sm" name="mode"><option value="">All modes</option><?php foreach ($modes as $k => $v) { ?><option value="<?php echo html_escape($k); ?>"<?php echo $filter['mode'] === $k ? ' selected' : ''; ?>><?php echo html_escape($v); ?></option><?php } ?></select></div>
      <div class="col-auto"><div class="form-check"><input class="form-check-input" type="checkbox" name="open" value="1" id="shAdvOpen"<?php echo $filter['open_only'] ? ' checked' : ''; ?>><label class="form-check-label" for="shAdvOpen">Only with balance left (any date)</label></div></div>
      <div class="col-auto"><button class="btn btn-default btn-sm">Show</button></div>
    </form>
    <div class="row g-2 mb-3">
      <div class="col-6 col-md-3"><div class="sh-hr-stat"><div class="v"><?php echo $cur . number_format($tot['amount'], 2); ?></div><div class="l">Collected (<?php echo count($rows); ?> receipts)</div></div></div>
      <div class="col-6 col-md-3"><div class="sh-hr-stat"><div class="v"><?php echo $cur . number_format($tot['balance'], 2); ?></div><div class="l">Still unused</div></div></div>
      <div class="col-12 col-md-6"><div class="sh-hr-stat"><div class="l mb-1">By payment mode</div><?php foreach ($by_mode as $k => $v) { ?><span class="me-3"><b><?php echo html_escape($modes[$k] ?? $k); ?></b> <?php echo $cur . number_format($v, 2); ?></span><?php } ?></div></div>
    </div>
    <?php if (empty($rows)) { ?>
      <div class="sh-hr-empty"><i class="fa fa-money"></i>No advances in this period.</div>
    <?php } else { ?>
    <div class="table-responsive">
    <table class="table table-hover sh-hr-table example">
      <thead><tr><th>Receipt</th><th>Date</th><th>Patient</th><th>For</th><th>Mode</th><th class="text-end">Amount</th><th class="text-end">Used / refunded</th><th class="text-end">Balance</th><th>Received by</th><th class="text-end noExport"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r) { ?>
        <tr<?php echo $r['is_cancelled'] ? ' class="text-muted text-decoration-line-through"' : ''; ?>>
          <td><?php echo html_escape($r['receipt_no']); ?></td>
          <td class="text-nowrap small"><?php echo $dt($r['payment_date']); ?></td>
          <td><a href="<?php echo base_url('admin/patientadvance/patient/' . $r['patient_id']); ?>"><?php echo html_escape($r['patient_name']); ?></a> <small class="text-muted">(<?php echo (int) $r['patient_id']; ?>)</small></td>
          <td><?php echo html_escape(Patientadvance_model::$purposes[$r['purpose']] ?? $r['purpose']); ?></td>
          <td><?php echo html_escape($modes[$r['payment_mode']] ?? $r['payment_mode']); ?></td>
          <td class="text-end"><?php echo number_format((float) $r['amount'], 2); ?></td>
          <td class="text-end"><?php echo number_format((float) $r['used'], 2); ?></td>
          <td class="text-end fw-semibold"><?php echo $r['is_cancelled'] ? 'Cancelled' : number_format($r['balance'], 2); ?></td>
          <td class="small"><?php echo html_escape($r['received_name']); ?></td>
          <td class="text-end text-nowrap"><a class="btn btn-light btn-sm" target="_blank" rel="noopener" href="<?php echo base_url('admin/patientadvance/receipt/' . $r['id']); ?>" title="Receipt"><i class="fa fa-print"></i></a></td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
    </div>
    <?php } ?>
  </div>
</div>
<?php if ($can_add) { $this->load->view('admin/patientadvance/_collect_modal', array('modes' => $modes)); } ?>
