<?php
/** Travel expense approvals. Vars: $claims, $filter, $counts, $statuses, $staff_list, $can_decide */
$this->load->view('admin/hrms/_nav', array('active' => 'expense_approvals'));
$cur = $this->setting_model->getSetting()->currency_symbol;
$fmt = function ($d) { return empty($d) ? '' : $this->customlib->YYYYMMDDTodateFormat($d); };
$sum = 0; foreach ($claims as $c) { $sum += (float) ($c['approved_amount'] ?? $c['total_amount']); }
?>
<div class="row g-2 mb-3">
  <?php foreach (array('submitted' => 'Waiting for approval', 'approved' => 'Approved, to pay in payroll', 'rejected' => 'Rejected', 'paid' => 'Paid in payroll') as $k => $label) { ?>
  <div class="col-6 col-md-3"><a class="text-decoration-none text-reset" href="?status=<?php echo $k; ?>"><div class="sh-hr-stat<?php echo $filter['status'] === $k ? ' border-primary' : ''; ?>">
    <div class="v"><?php echo (int) $counts[$k]['n']; ?> <small class="text-muted" style="font-size:13px"><?php echo $cur . number_format($counts[$k]['amount'], 0); ?></small></div><div class="l"><?php echo $label; ?></div></div></a></div>
  <?php } ?>
</div>
<div class="card">
  <div class="card-header"><h3 class="card-title mb-0">Travel expense claims</h3></div>
  <div class="card-body">
    <form class="row g-2 mb-3" method="get">
      <div class="col-sm-3"><select class="form-select form-select-sm" name="status"><option value="">All (not drafts)</option>
        <?php foreach ($statuses as $k => $v) { if ($k === 'draft') { continue; } ?><option value="<?php echo $k; ?>"<?php echo $filter['status'] === $k ? ' selected' : ''; ?>><?php echo html_escape($v); ?></option><?php } ?></select></div>
      <div class="col-sm-4"><select class="form-select form-select-sm" name="staff_id"><option value="">All staff</option>
        <?php foreach ($staff_list as $s) { ?><option value="<?php echo (int) $s['id']; ?>"<?php echo (int) $filter['staff_id'] === (int) $s['id'] ? ' selected' : ''; ?>><?php echo html_escape(trim($s['name'] . ' ' . $s['surname']) . ' (' . $s['employee_id'] . ')'); ?></option><?php } ?></select></div>
      <div class="col-sm-3"><input class="form-control form-control-sm" type="month" name="month" value="<?php echo html_escape($filter['month']); ?>" title="Month sent"></div>
      <div class="col-auto"><button class="btn btn-default btn-sm">Filter</button></div>
    </form>
    <?php if (empty($claims)) { ?>
      <div class="sh-hr-empty"><i class="fa fa-check-square-o"></i>No claims here.</div>
    <?php } else { ?>
    <div class="table-responsive">
    <table class="table table-hover sh-hr-table example">
      <thead><tr><th>Claim</th><th>Staff</th><th>Trip / purpose</th><th>Dates</th><th class="text-end">Claimed</th><th class="text-end">Approved</th><th>Status</th><th class="text-end noExport">Action</th></tr></thead>
      <tbody>
      <?php foreach ($claims as $c) { ?>
        <tr>
          <td><?php echo html_escape($c['claim_no']); ?></td>
          <td><?php echo html_escape(trim($c['name'] . ' ' . $c['surname'])); ?> <small class="text-muted"><?php echo html_escape($c['employee_id']); ?></small></td>
          <td><?php echo html_escape($c['title']); ?></td>
          <td class="text-nowrap"><?php echo $fmt($c['from_date']); ?><?php echo $c['to_date'] && $c['to_date'] !== $c['from_date'] ? ' – ' . $fmt($c['to_date']) : ''; ?></td>
          <td class="text-end"><?php echo number_format((float) $c['total_amount'], 2); ?></td>
          <td class="text-end"><?php echo $c['approved_amount'] !== null ? number_format((float) $c['approved_amount'], 2) : '—'; ?></td>
          <td><span class="sh-hr-pill <?php echo $c['status']; ?>"><?php echo html_escape($statuses[$c['status']]); ?></span></td>
          <td class="text-end text-nowrap"><button type="button" class="btn btn-<?php echo $c['status'] === 'submitted' && $can_decide ? 'primary' : 'light'; ?> btn-sm sh-te-open" data-id="<?php echo (int) $c['id']; ?>"><?php echo $c['status'] === 'submitted' && $can_decide ? 'Review' : '<i class="fa fa-eye"></i>'; ?></button></td>
        </tr>
      <?php } ?>
      </tbody>
      <tfoot><tr><th colspan="5" class="text-end">Total shown</th><th class="text-end"><?php echo $cur . number_format($sum, 2); ?></th><th colspan="2"></th></tr></tfoot>
    </table>
    </div>
    <?php } ?>
  </div>
</div>
<div class="modal fade sh-modal" id="shTeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Travel claim</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body" id="shTeBody"></div>
  </div></div>
</div>
<script>
$(function () {
  $('.sh-te-open').on('click', function () {
    $('#shTeBody').html('<div class="text-muted">Loading…</div>');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('shTeModal')).show();
    $.get(base_url + 'admin/travelexpense/view/' + $(this).data('id'), function (html) { $('#shTeBody').html(html); });
  });
});
</script>
