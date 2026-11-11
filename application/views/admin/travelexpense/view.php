<?php
/** Travel claim page. Vars: $claim, $statuses, $can_decide */
$mine = (int) $claim['staff_id'] === (int) $this->customlib->getStaffID();
$this->load->view('admin/hrms/_nav', array('active' => $mine ? 'expenses' : 'expense_approvals'));
?>
<div class="card">
  <div class="card-header d-flex align-items-center gap-2 flex-wrap">
    <h3 class="card-title mb-0 me-auto">Travel claim <?php echo html_escape($claim['claim_no']); ?></h3>
    <a class="btn btn-default btn-sm" target="_blank" rel="noopener" href="<?php echo base_url('admin/travelexpense/view/' . $claim['id'] . '?print=1'); ?>"><i class="fa fa-print"></i> Print</a>
    <?php if ($mine && in_array($claim['status'], array('draft', 'rejected'), true)) { ?>
      <a class="btn btn-default btn-sm" href="<?php echo base_url('admin/travelexpense/form/' . $claim['id']); ?>"><i class="fa fa-pencil"></i> Edit</a>
      <button type="button" class="btn btn-primary btn-sm" id="shTeSend"><i class="fa fa-paper-plane"></i> Send for approval</button>
    <?php } ?>
    <a class="btn btn-default btn-sm" href="<?php echo base_url($mine ? 'admin/travelexpense' : 'admin/travelexpense/approvals'); ?>">Back</a>
  </div>
  <div class="card-body"><?php $this->load->view('admin/travelexpense/_detail', array('claim' => $claim, 'statuses' => $statuses, 'can_decide' => $can_decide)); ?></div>
</div>
<script>
$(function () {
  $('#shTeSend').on('click', function () {
    shHr.post(base_url + 'admin/travelexpense/submit/<?php echo (int) $claim['id']; ?>', {}, { confirm: 'Send this claim for approval?', done: function () { location.reload(); } });
  });
});
</script>
