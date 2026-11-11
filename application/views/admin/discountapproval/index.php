<?php
/**
 * Discount approvals. Vars: $filter, $rows, $settings, $approver, $see_all, $me, $modules, $statuses, $approvers
 */
$cur = $this->setting_model->getSetting()->currency_symbol;
$dt  = function ($d) { return empty($d) ? '' : $this->customlib->YYYYMMDDHisTodateFormat($d, $this->customlib->getHospitalTimeFormat()); };
$tabs = array();
if ($approver) { $tabs['mine'] = 'For me to approve'; }
$tabs['asked'] = 'Asked by me';
if ($see_all) { $tabs['all'] = 'All requests'; }
$q = function ($over) use ($filter) { $a = array_merge(array('scope' => $filter['scope'], 'status' => $filter['status'], 'module' => $filter['module'], 'from' => $filter['from'], 'to' => $filter['to']), $over); return '?' . http_build_query(array_filter($a, function ($v) { return $v !== '' && $v !== null; })); };
?>
<link rel="stylesheet" href="<?php echo base_url('backend/css/sh-hrms.css'); ?>">
<script src="<?php echo base_url('backend/js/sh-hrms.js'); ?>"></script>
<?php if (!$settings['enabled']) { ?>
  <div class="alert alert-warning">Discount approval is switched off, so discounts apply straight away. <?php if ($this->rbac->hasPrivilege('discount_approval_setting', 'can_view')) { ?><a href="<?php echo base_url('admin/discountapproval/settings'); ?>">Switch it on</a><?php } ?></div>
<?php } ?>
<ul class="nav nav-pills sh-hr-nav mb-3 flex-wrap gap-1">
  <?php foreach ($tabs as $k => $label) { ?><li class="nav-item"><a class="nav-link<?php echo $filter['scope'] === $k ? ' active' : ''; ?>" href="<?php echo $q(array('scope' => $k, 'status' => $k === 'mine' ? 'pending' : '')); ?>"><?php echo $label; ?></a></li><?php } ?>
</ul>
<div class="card">
  <div class="card-header"><h3 class="card-title mb-0">Discount approvals</h3></div>
  <div class="card-body">
    <p class="text-muted small mb-3">A discount entered by someone who is not an approver is held back: the bill stays at the full amount until an approver approves it here.
      <?php if ($approvers) { ?>Approvers: <?php echo html_escape(implode(', ', array_map(function ($a) { return trim($a['name'] . ' ' . $a['surname']); }, $approvers))); ?>.<?php } ?></p>
    <form class="row g-2 mb-3" method="get">
      <input type="hidden" name="scope" value="<?php echo html_escape($filter['scope']); ?>">
      <div class="col-sm-3"><select class="form-select form-select-sm" name="status"><option value="">All statuses</option>
        <?php foreach ($statuses as $k => $v) { ?><option value="<?php echo $k; ?>"<?php echo $filter['status'] === $k ? ' selected' : ''; ?>><?php echo html_escape($v); ?></option><?php } ?></select></div>
      <div class="col-sm-3"><select class="form-select form-select-sm" name="module"><option value="">All bills</option>
        <?php foreach ($modules as $k => $m) { ?><option value="<?php echo $k; ?>"<?php echo $filter['module'] === $k ? ' selected' : ''; ?>><?php echo html_escape($m['label']); ?></option><?php } ?></select></div>
      <div class="col-sm-2"><input class="form-control form-control-sm" type="date" name="from" value="<?php echo html_escape($filter['from']); ?>" title="From"></div>
      <div class="col-sm-2"><input class="form-control form-control-sm" type="date" name="to" value="<?php echo html_escape($filter['to']); ?>" title="To"></div>
      <div class="col-auto"><button class="btn btn-default btn-sm">Filter</button></div>
    </form>
    <?php if (empty($rows)) { ?>
      <div class="sh-hr-empty"><i class="fa fa-percent"></i><?php echo $filter['scope'] === 'mine' && $filter['status'] === 'pending' ? 'Nothing waiting for you.' : 'No requests.'; ?></div>
    <?php } else { ?>
    <div class="table-responsive">
    <table class="table table-hover sh-hr-table example">
      <thead><tr><th>Asked</th><th>Patient</th><th>Bill / charge</th><th class="text-end">Full amount</th><th class="text-end">Discount</th><th class="text-end">After discount</th><th>Reason</th><th>Asked by → approver</th><th>Status</th><th class="text-end noExport">Action</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r) {
          $can = $approver && $r['status'] === 'pending' && (int) $r['requested_by'] !== (int) $me;
          $mine_req = $r['status'] === 'pending' && (int) $r['requested_by'] === (int) $me; ?>
        <tr>
          <td class="text-nowrap small"><?php echo $dt($r['created_at']); ?><?php echo $r['source'] === 'mobile' ? '<br><span class="sh-hr-pill muted">App</span>' : ''; ?></td>
          <td><?php if ($r['patient_id']) { ?><?php echo html_escape($r['patient_name']); ?> <small class="text-muted">(<?php echo (int) $r['patient_id']; ?>)</small><?php } ?></td>
          <td><?php echo html_escape($modules[$r['module']]['label'] ?? $r['module']); ?><br><small class="text-muted"><?php echo html_escape($r['description']); ?><?php echo $r['case_label'] ? ' · ' . html_escape($r['case_label']) : ''; ?></small></td>
          <td class="text-end"><?php echo number_format((float) $r['net_without'], 2); ?></td>
          <td class="text-end fw-semibold"><?php echo number_format((float) $r['discount_amount'], 2); ?><br><small class="text-muted"><?php echo rtrim(rtrim(number_format((float) $r['discount_pct'], 2), '0'), '.'); ?>%</small></td>
          <td class="text-end"><?php echo number_format((float) $r['net_with'], 2); ?></td>
          <td class="small"><?php echo html_escape($r['reason']); ?><?php if ($r['decision_note']) { ?><br><span class="text-muted">Note: <?php echo html_escape($r['decision_note']); ?></span><?php } ?></td>
          <td class="small"><?php echo html_escape(trim($r['requested_name'])); ?> → <?php echo $r['approver_name'] ? html_escape(trim($r['approver_name'])) : '<span class="text-muted">any approver</span>'; ?>
            <?php if ($r['decided_name'] && $r['status'] !== 'pending') { ?><br><span class="text-muted"><?php echo $r['status'] === 'auto' ? 'Applied by' : 'Decided by'; ?> <?php echo html_escape(trim($r['decided_name'])); ?></span><?php } ?></td>
          <td><span class="sh-hr-pill <?php echo in_array($r['status'], array('auto'), true) ? 'approved' : ($r['status'] === 'superseded' ? 'muted' : $r['status']); ?>"><?php echo html_escape($statuses[$r['status']] ?? $r['status']); ?></span></td>
          <td class="text-end text-nowrap">
            <?php if ($can) { ?>
              <button type="button" class="btn btn-success btn-sm sh-da" data-id="<?php echo (int) $r['id']; ?>" data-pct="<?php echo html_escape($r['discount_pct']); ?>" data-gross="<?php echo html_escape($r['gross_amount']); ?>" data-action="approve" title="Approve"><i class="fa fa-check"></i></button>
              <button type="button" class="btn btn-danger btn-sm sh-da" data-id="<?php echo (int) $r['id']; ?>" data-action="reject" title="Reject"><i class="fa fa-times"></i></button>
            <?php } elseif ($mine_req) { ?>
              <button type="button" class="btn btn-light btn-sm sh-da" data-id="<?php echo (int) $r['id']; ?>" data-action="withdraw" title="Withdraw"><i class="fa fa-undo"></i></button>
            <?php } ?>
          </td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
    </div>
    <?php } ?>
  </div>
</div>
<div class="modal fade sh-modal" id="shDaModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="shDaForm" onsubmit="return false;">
    <div class="modal-header"><h5 class="modal-title" id="shDaTitle">Approve discount</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <div id="shDaPctBox" class="mb-2"><label class="form-label">Discount % to approve <small class="text-muted">(lower it to approve less)</small></label><input class="form-control" type="number" step="0.01" min="0.01" name="discount_pct"></div>
      <label class="form-label" id="shDaNoteLabel">Note</label><input class="form-control" name="note" maxlength="500">
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" id="shDaGo">Approve</button></div>
  </form></div>
</div>
<script>
$(function () {
  var cur = { id: 0, action: '' }, modal = function () { return bootstrap.Modal.getOrCreateInstance(document.getElementById('shDaModal')); };
  $('.sh-da').on('click', function () {
    var $b = $(this); cur = { id: $b.data('id'), action: $b.data('action') };
    if (cur.action === 'withdraw') {
      shHr.post(base_url + 'admin/discountapproval/decide/' + cur.id, { action: 'withdraw' }, { confirm: 'Withdraw this request? The bill stays at the full amount.', done: function () { location.reload(); } });
      return;
    }
    var $f = $('#shDaForm'); $f[0].reset();
    $('#shDaPctBox').toggle(cur.action === 'approve');
    $f.find('[name=discount_pct]').val(cur.action === 'approve' ? parseFloat($b.data('pct')) : '').attr('max', $b.data('pct'));
    $('#shDaTitle').text(cur.action === 'approve' ? 'Approve discount' : 'Reject discount');
    $('#shDaNoteLabel').text(cur.action === 'approve' ? 'Note (optional)' : 'Reason for rejecting');
    $('#shDaGo').text(cur.action === 'approve' ? 'Approve' : 'Reject').toggleClass('btn-danger', cur.action === 'reject').toggleClass('btn-primary', cur.action !== 'reject');
    modal().show();
  });
  $('#shDaForm').on('submit', function () {
    var data = $(this).serializeArray(); data.push({ name: 'action', value: cur.action });
    shHr.post(base_url + 'admin/discountapproval/decide/' + cur.id, $.param(data), { done: function () { modal().hide(); location.reload(); } });
  });
});
</script>
