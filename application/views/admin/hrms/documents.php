<?php
/**
 * Staff HR documents across all staff (HR view). Vars: $rows, $filter, $categories, $staff_list, $templates
 */
$this->load->view('admin/hrms/_nav', array('active' => 'documents'));
$fmt = function ($d) { return empty($d) ? '' : $this->customlib->YYYYMMDDTodateFormat($d); };
$can_add = $this->rbac->hasPrivilege('staff_hr_document', 'can_add');
$can_delete = $this->rbac->hasPrivilege('staff_hr_document', 'can_delete');
?>
<div class="card">
  <div class="card-header d-flex flex-wrap align-items-center gap-2">
    <h3 class="card-title mb-0 me-auto">Staff HR documents</h3>
    <?php if ($can_add) { ?>
      <div class="input-group input-group-sm" style="max-width:420px">
        <select class="form-select" id="shGenStaff"><option value="">Generate a letter for…</option>
          <?php foreach ($staff_list as $s) { ?><option value="<?php echo (int) $s['id']; ?>"><?php echo html_escape(trim($s['name'] . ' ' . $s['surname']) . ' (' . $s['employee_id'] . ')'); ?></option><?php } ?>
        </select>
        <button class="btn btn-primary" type="button" onclick="var v=document.getElementById('shGenStaff').value; if(v){location.href=base_url+'admin/hrms/generate/'+v;}"><i class="fa fa-file-text-o"></i> Generate</button>
      </div>
      <button type="button" class="btn btn-default btn-sm" data-bs-toggle="modal" data-bs-target="#shHrUploadModal"><i class="fa fa-upload"></i> Upload scan</button>
    <?php } ?>
  </div>
  <div class="card-body">
    <form class="row g-2 mb-3" method="get">
      <div class="col-sm-4 col-md-3"><select class="form-select form-select-sm" name="category"><option value="">All types</option>
        <?php foreach ($categories as $k => $v) { ?><option value="<?php echo $k; ?>"<?php echo $filter['category'] === $k ? ' selected' : ''; ?>><?php echo html_escape($v); ?></option><?php } ?></select></div>
      <div class="col-sm-4 col-md-3 d-flex align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" name="expiring" value="1" id="shExp"<?php echo $filter['expiring'] ? ' checked' : ''; ?>><label class="form-check-label" for="shExp">Expired or expiring in 60 days</label></div></div>
      <div class="col-auto"><button class="btn btn-default btn-sm">Filter</button></div>
    </form>
    <?php if (empty($rows)) { ?>
      <div class="sh-hr-empty"><i class="fa fa-folder-open"></i>No documents match.</div>
    <?php } else { ?>
    <div class="table-responsive">
      <table class="table table-striped table-hover sh-hr-table example">
        <thead><tr><th>Staff</th><th>Document</th><th>Type</th><th>Ref no.</th><th>Issued</th><th>Valid till</th><th class="text-end noExport">Action</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $d) { $expired = !empty($d['valid_till']) && $d['valid_till'] < date('Y-m-d'); ?>
          <tr>
            <td><a href="<?php echo base_url('admin/staff/profile/' . $d['staff_id']); ?>"><?php echo html_escape(trim($d['name'] . ' ' . $d['surname'])); ?></a> <small class="text-muted"><?php echo html_escape($d['employee_id']); ?></small></td>
            <td><i class="fa <?php echo $d['source'] === 'generated' ? 'fa-file-text-o' : 'fa-paperclip'; ?> text-muted"></i> <?php echo html_escape($d['title']); ?></td>
            <td><?php echo html_escape($categories[$d['category']] ?? $d['category']); ?></td>
            <td><?php echo html_escape($d['ref_no']); ?></td>
            <td><?php echo $fmt($d['issued_on'] ?: substr($d['created_at'], 0, 10)); ?></td>
            <td><?php if ($d['valid_till']) { ?><span class="sh-hr-pill <?php echo $expired ? 'expired' : 'active'; ?>"><?php echo $fmt($d['valid_till']); ?></span><?php } ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-light btn-sm" target="_blank" rel="noopener" href="<?php echo base_url('admin/hrms/document/' . $d['id']); ?>" title="Open"><i class="fa fa-eye"></i></a>
              <?php if ($can_delete) { ?><button type="button" class="btn btn-light btn-sm sh-hr-del" data-id="<?php echo (int) $d['id']; ?>" title="Delete"><i class="fa fa-trash"></i></button><?php } ?>
            </td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
    </div>
    <?php } ?>
  </div>
</div>
<?php if ($can_add) { $this->load->view('admin/hrms/_upload_modal', array('staff_id' => 0, 'categories' => $categories, 'staff_list' => $staff_list)); } ?>
<?php $this->load->view('admin/hrms/_doc_js'); ?>
