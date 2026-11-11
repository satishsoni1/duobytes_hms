<?php
/** HR letter templates list. Vars: $templates, $types */
$this->load->view('admin/hrms/_nav', array('active' => 'templates'));
$can_add = $this->rbac->hasPrivilege('hr_letter_template', 'can_add');
$can_edit = $this->rbac->hasPrivilege('hr_letter_template', 'can_edit');
$can_delete = $this->rbac->hasPrivilege('hr_letter_template', 'can_delete');
?>
<div class="card">
  <div class="card-header d-flex align-items-center">
    <h3 class="card-title mb-0 me-auto">Letter templates</h3>
    <?php if ($can_add) { ?><a class="btn btn-primary btn-sm" href="<?php echo base_url('admin/hrms/template'); ?>"><i class="fa fa-plus"></i> New template</a><?php } ?>
  </div>
  <div class="card-body">
    <p class="text-muted small">Templates for joining, appointment, offer, experience and relieving letters, increments, and MOUs or agreements with doctors. Placeholders such as <code>{staff_name}</code> are filled from the staff record; any other <code>{word}</code> is asked for when the letter is generated.</p>
    <?php if (empty($templates)) { ?>
      <div class="sh-hr-empty"><i class="fa fa-file-text-o"></i>No templates yet.</div>
    <?php } else { ?>
    <div class="table-responsive">
    <table class="table table-hover sh-hr-table">
      <thead><tr><th>Name</th><th>Type</th><th>Status</th><th>Updated</th><th class="text-end">Action</th></tr></thead>
      <tbody>
      <?php foreach ($templates as $t) { ?>
        <tr>
          <td class="fw-semibold"><?php echo html_escape($t['name']); ?><?php if ($t['subject']) { ?><br><small class="text-muted"><?php echo html_escape($t['subject']); ?></small><?php } ?></td>
          <td><?php echo html_escape($types[$t['letter_type']] ?? $t['letter_type']); ?></td>
          <td><span class="sh-hr-pill <?php echo $t['is_active'] ? 'active' : 'muted'; ?>"><?php echo $t['is_active'] ? 'In use' : 'Off'; ?></span></td>
          <td><?php echo $this->customlib->YYYYMMDDTodateFormat(substr($t['updated_at'], 0, 10)); ?></td>
          <td class="text-end text-nowrap">
            <?php if ($can_edit) { ?><a class="btn btn-light btn-sm" href="<?php echo base_url('admin/hrms/template/' . $t['id']); ?>" title="Edit"><i class="fa fa-pencil"></i></a><?php } ?>
            <?php if ($can_delete) { ?><button type="button" class="btn btn-light btn-sm sh-tpl-del" data-id="<?php echo (int) $t['id']; ?>" title="Delete"><i class="fa fa-trash"></i></button><?php } ?>
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
  $('.sh-tpl-del').on('click', function () {
    shHr.post(base_url + 'admin/hrms/template_delete/' + $(this).data('id'), {}, { confirm: 'Delete this template? Letters already generated from it are kept.', done: function () { location.reload(); } });
  });
});
</script>
