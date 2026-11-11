<?php
/** Manage hospital policies & documents. Vars: $policies, $categories, $roles */
$this->load->view('admin/hrms/_nav', array('active' => 'policies'));
$can_add = $this->rbac->hasPrivilege('hospital_policy', 'can_add');
$can_edit = $this->rbac->hasPrivilege('hospital_policy', 'can_edit');
$can_delete = $this->rbac->hasPrivilege('hospital_policy', 'can_delete');
$role_names = array(); foreach ($roles as $r) { $role_names[(int) $r['id']] = $r['name']; }
$fmt = function ($d) { return empty($d) ? '' : $this->customlib->YYYYMMDDTodateFormat($d); };
?>
<div class="card">
  <div class="card-header d-flex align-items-center">
    <h3 class="card-title mb-0 me-auto">Policies &amp; hospital documents</h3>
    <?php if ($can_add) { ?><button type="button" class="btn btn-primary btn-sm" id="shPolAdd"><i class="fa fa-plus"></i> Add document</button><?php } ?>
  </div>
  <div class="card-body">
    <p class="text-muted small">HR policies, SOPs, NABH documents, licences and circulars. Staff open them from <a href="<?php echo base_url('admin/hrms'); ?>">My HR</a>. Limit a document to some roles, or leave roles empty for everyone.</p>
    <?php if (empty($policies)) { ?>
      <div class="sh-hr-empty"><i class="fa fa-book"></i>Nothing published yet.</div>
    <?php } else { ?>
    <div class="table-responsive">
    <table class="table table-hover sh-hr-table example">
      <thead><tr><th>Title</th><th>Category</th><th>Version</th><th>Effective</th><th>Who can see</th><th>Status</th><th class="text-end noExport">Action</th></tr></thead>
      <tbody>
      <?php foreach ($policies as $p) {
          $rids = array_filter(array_map('intval', explode(',', (string) $p['role_ids'])));
          $who = $rids ? implode(', ', array_map(function ($id) use ($role_names) { return $role_names[$id] ?? '#' . $id; }, $rids)) : 'Everyone'; ?>
        <tr>
          <td><a target="_blank" rel="noopener" href="<?php echo base_url('admin/hrms/policy_file/' . $p['id']); ?>" class="fw-semibold"><?php echo html_escape($p['title']); ?></a>
            <?php if ($p['description']) { ?><br><small class="text-muted"><?php echo html_escape($p['description']); ?></small><?php } ?></td>
          <td><?php echo html_escape($categories[$p['category']] ?? $p['category']); ?></td>
          <td><?php echo html_escape($p['version']); ?></td>
          <td><?php echo $fmt($p['effective_date']); ?></td>
          <td><?php echo html_escape($who); ?></td>
          <td><span class="sh-hr-pill <?php echo $p['is_active'] ? 'active' : 'muted'; ?>"><?php echo $p['is_active'] ? 'Published' : 'Hidden'; ?></span></td>
          <td class="text-end text-nowrap">
            <?php if ($can_edit) { ?><button type="button" class="btn btn-light btn-sm sh-pol-edit" title="Edit" data-p="<?php echo html_escape(json_encode(array(
                'id' => (int) $p['id'], 'title' => $p['title'], 'category' => $p['category'], 'version' => $p['version'], 'description' => $p['description'],
                'effective_date' => $fmt($p['effective_date']), 'role_ids' => array_values($rids), 'is_active' => (int) $p['is_active'], 'file_name' => $p['file_name']))); ?>"><i class="fa fa-pencil"></i></button><?php } ?>
            <?php if ($can_delete) { ?><button type="button" class="btn btn-light btn-sm sh-pol-del" data-id="<?php echo (int) $p['id']; ?>" title="Delete"><i class="fa fa-trash"></i></button><?php } ?>
          </td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
    </div>
    <?php } ?>
  </div>
</div>

<div class="modal fade sh-modal" id="shPolModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <form class="modal-content" id="shPolForm" enctype="multipart/form-data" autocomplete="off">
      <div class="modal-header"><h5 class="modal-title" id="shPolTitle">Add document</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?php echo $this->customlib->getCSRF(); ?>
        <div class="row g-2">
          <div class="col-md-8"><label class="form-label">Title <span class="req">*</span></label><input class="form-control" name="title" maxlength="200"></div>
          <div class="col-md-4"><label class="form-label">Category</label><select class="form-select" name="category"><?php foreach ($categories as $k => $v) { ?><option value="<?php echo $k; ?>"><?php echo html_escape($v); ?></option><?php } ?></select></div>
          <div class="col-md-4"><label class="form-label">Version</label><input class="form-control" name="version" maxlength="30" placeholder="e.g. 2.1"></div>
          <div class="col-md-4"><label class="form-label">Effective from</label><input class="form-control date" name="effective_date"></div>
          <div class="col-md-4 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="shPolAct" checked><label class="form-check-label" for="shPolAct">Published</label></div></div>
          <div class="col-12"><label class="form-label">Short description</label><textarea class="form-control" name="description" rows="2" maxlength="2000"></textarea></div>
          <div class="col-12"><label class="form-label">Who can see it <small class="text-muted">(none ticked = all staff)</small></label>
            <div class="d-flex flex-wrap gap-3"><?php foreach ($roles as $r) { ?><div class="form-check"><input class="form-check-input" type="checkbox" name="role_ids[]" value="<?php echo (int) $r['id']; ?>" id="shPolR<?php echo (int) $r['id']; ?>"><label class="form-check-label" for="shPolR<?php echo (int) $r['id']; ?>"><?php echo html_escape($r['name']); ?></label></div><?php } ?></div></div>
          <div class="col-12"><label class="form-label">File <span class="req" id="shPolReq">*</span> <small class="text-muted">PDF, image, Word or Excel, up to 25 MB</small> <small class="text-muted" id="shPolCur"></small></label><input class="form-control" type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.txt"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
    </form>
  </div>
</div>
<script>
$(function () {
  var id = 0, $f = $('#shPolForm'), modal = function () { return bootstrap.Modal.getOrCreateInstance(document.getElementById('shPolModal')); };
  function open(p) {
    $f[0].reset(); shHr.clearErrors($f); id = p ? p.id : 0;
    $('#shPolTitle').text(p ? 'Edit document' : 'Add document');
    $('#shPolReq').toggle(!p); $('#shPolCur').text(p && p.file_name ? '— current: ' + p.file_name + ' (choose a file only to replace it)' : '');
    if (p) {
      $.each(['title', 'category', 'version', 'description', 'effective_date'], function (_, k) { $f.find('[name="' + k + '"]').val(p[k] || ''); });
      $f.find('[name="is_active"]').prop('checked', !!p.is_active);
      $.each(p.role_ids || [], function (_, r) { $('#shPolR' + r).prop('checked', true); });
    }
    modal().show();
  }
  $('#shPolAdd').on('click', function () { open(null); });
  $('.sh-pol-edit').on('click', function () { open($(this).data('p')); });
  $f.on('submit', function (e) { e.preventDefault(); shHr.postForm(this, { url: base_url + 'admin/hrms/policy_save/' + id, done: function () { location.reload(); } }); });
  $('.sh-pol-del').on('click', function () {
    shHr.post(base_url + 'admin/hrms/policy_delete/' + $(this).data('id'), {}, { confirm: 'Delete this document and its file?', done: function () { location.reload(); } });
  });
});
</script>
