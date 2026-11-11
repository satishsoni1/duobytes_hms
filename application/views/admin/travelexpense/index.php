<?php
/** My travel claims. Vars: $claims, $statuses */
$this->load->view('admin/hrms/_nav', array('active' => 'expenses'));
$fmt = function ($d) { return empty($d) ? '' : $this->customlib->YYYYMMDDTodateFormat($d); };
$cur = $this->setting_model->getSetting()->currency_symbol;
?>
<div class="card">
  <div class="card-header d-flex align-items-center">
    <h3 class="card-title mb-0 me-auto">My travel claims</h3>
    <a class="btn btn-primary btn-sm" href="<?php echo base_url('admin/travelexpense/form'); ?>"><i class="fa fa-plus"></i> New claim</a>
  </div>
  <div class="card-body">
    <p class="text-muted small">Add each expense with its bill or ticket, then send the claim. Once approved, the amount is paid with your salary.</p>
    <?php if (empty($claims)) { ?>
      <div class="sh-hr-empty"><i class="fa fa-plane"></i>No claims yet.</div>
    <?php } else { ?>
    <div class="table-responsive">
    <table class="table table-hover sh-hr-table">
      <thead><tr><th>Claim</th><th>Trip / purpose</th><th>Dates</th><th class="text-end">Claimed</th><th class="text-end">Approved</th><th>Status</th><th class="text-end">Action</th></tr></thead>
      <tbody>
      <?php foreach ($claims as $c) { ?>
        <tr>
          <td><a href="<?php echo base_url('admin/travelexpense/view/' . $c['id']); ?>"><?php echo html_escape($c['claim_no']); ?></a></td>
          <td><?php echo html_escape($c['title']); ?>
            <?php if ($c['status'] === 'rejected' && $c['decision_note']) { ?><br><small class="text-danger">Rejected: <?php echo html_escape($c['decision_note']); ?></small><?php } ?></td>
          <td class="text-nowrap"><?php echo $fmt($c['from_date']); ?><?php echo $c['to_date'] && $c['to_date'] !== $c['from_date'] ? ' – ' . $fmt($c['to_date']) : ''; ?></td>
          <td class="text-end"><?php echo $cur . number_format((float) $c['total_amount'], 2); ?></td>
          <td class="text-end"><?php echo $c['approved_amount'] !== null ? $cur . number_format((float) $c['approved_amount'], 2) : '—'; ?></td>
          <td><span class="sh-hr-pill <?php echo $c['status']; ?>"><?php echo html_escape($statuses[$c['status']]); ?></span></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-light btn-sm" href="<?php echo base_url('admin/travelexpense/view/' . $c['id']); ?>" title="Open"><i class="fa fa-eye"></i></a>
            <?php if (in_array($c['status'], array('draft', 'rejected'), true)) { ?>
              <a class="btn btn-light btn-sm" href="<?php echo base_url('admin/travelexpense/form/' . $c['id']); ?>" title="Edit"><i class="fa fa-pencil"></i></a>
            <?php } ?>
            <?php if ($c['status'] === 'draft') { ?>
              <button type="button" class="btn btn-light btn-sm sh-te-del" data-id="<?php echo (int) $c['id']; ?>" title="Delete"><i class="fa fa-trash"></i></button>
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
<script>
$(function () {
  $('.sh-te-del').on('click', function () {
    shHr.post(base_url + 'admin/travelexpense/delete/' + $(this).data('id'), {}, { confirm: 'Delete this draft claim and its bills?', done: function () { location.reload(); } });
  });
});
</script>
