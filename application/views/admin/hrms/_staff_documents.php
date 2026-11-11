<?php
/**
 * HR documents block on the staff profile (Documents tab). Loaded by AJAX from
 * admin/hrms/staff_documents/{id}; the controller has already checked the viewer may see it.
 * Vars: $staff_id, $docs, $categories, $can_add, $can_delete
 */
$fmt = function ($d) { return empty($d) ? '' : $this->customlib->YYYYMMDDTodateFormat($d); };
?>
<div class="d-flex align-items-center flex-wrap gap-2 mb-2 mt-3">
  <h5 class="mb-0 me-auto" style="font-size:15px;font-weight:700"><i class="fa fa-lock text-muted"></i> HR letters, MOUs &amp; agreements</h5>
  <?php if ($can_add) { ?>
    <a class="btn btn-primary btn-sm" href="<?php echo base_url('admin/hrms/generate/' . (int) $staff_id); ?>"><i class="fa fa-file-text-o"></i> Generate letter</a>
    <button type="button" class="btn btn-default btn-sm" data-bs-toggle="modal" data-bs-target="#shHrUploadModal"><i class="fa fa-upload"></i> Upload scan</button>
  <?php } ?>
</div>
<p class="text-muted small mb-2">Visible only to this staff member and to HR users with the Staff HR Documents permission.</p>
<?php if (empty($docs)) { ?>
  <div class="sh-hr-empty"><i class="fa fa-folder-open"></i>No HR documents yet.</div>
<?php } else { ?>
<div class="table-responsive">
<table class="table table-sm table-hover sh-hr-table mb-0">
  <thead><tr><th>Document</th><th>Type</th><th>Ref no.</th><th>Issued</th><th>Valid till</th><th class="text-end">Action</th></tr></thead>
  <tbody>
  <?php foreach ($docs as $d) { $expired = !empty($d['valid_till']) && $d['valid_till'] < date('Y-m-d'); ?>
    <tr>
      <td><i class="fa <?php echo $d['source'] === 'generated' ? 'fa-file-text-o' : 'fa-paperclip'; ?> text-muted"></i> <?php echo html_escape($d['title']); ?>
        <?php if ($d['note']) { ?><br><small class="text-muted"><?php echo html_escape($d['note']); ?></small><?php } ?></td>
      <td><?php echo html_escape($categories[$d['category']] ?? $d['category']); ?></td>
      <td><?php echo html_escape($d['ref_no']); ?></td>
      <td><?php echo $fmt($d['issued_on'] ?: substr($d['created_at'], 0, 10)); ?></td>
      <td><?php if ($d['valid_till']) { ?><span class="sh-hr-pill <?php echo $expired ? 'expired' : 'active'; ?>"><?php echo $fmt($d['valid_till']); ?></span><?php } ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-light btn-sm" target="_blank" rel="noopener" href="<?php echo base_url('admin/hrms/document/' . $d['id']); ?>" title="Open"><i class="fa fa-eye"></i></a>
        <?php if ($can_delete) { ?>
        <button type="button" class="btn btn-light btn-sm sh-hr-del" data-id="<?php echo (int) $d['id']; ?>" title="Delete"><i class="fa fa-trash"></i></button>
        <?php } ?>
      </td>
    </tr>
  <?php } ?>
  </tbody>
</table>
</div>
<?php } ?>

<?php if ($can_add) { $this->load->view('admin/hrms/_upload_modal', array('staff_id' => $staff_id, 'categories' => $categories)); } ?>
<?php $this->load->view('admin/hrms/_doc_js'); ?>
