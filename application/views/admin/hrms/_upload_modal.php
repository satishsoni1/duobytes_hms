<?php
/** Upload a scanned MOU / agreement / certificate for one staff member. Vars: $staff_id (0 = choose), $categories, $staff_list (when $staff_id = 0) */
?>
<div class="modal fade sh-modal" id="shHrUploadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="shHrUploadForm" action="<?php echo base_url('admin/hrms/upload_document'); ?>" enctype="multipart/form-data" autocomplete="off">
      <div class="modal-header"><h5 class="modal-title">Upload document</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?php echo $this->customlib->getCSRF(); ?>
        <div class="row g-2">
          <?php if ((int) $staff_id > 0) { ?>
            <input type="hidden" name="staff_id" value="<?php echo (int) $staff_id; ?>">
          <?php } else { ?>
          <div class="col-12"><label class="form-label">Staff member <span class="req">*</span></label>
            <select class="form-select" name="staff_id" required><option value="">Select</option>
            <?php foreach ($staff_list as $s) { ?><option value="<?php echo (int) $s['id']; ?>"><?php echo html_escape(trim($s['name'] . ' ' . $s['surname']) . ' (' . $s['employee_id'] . ')'); ?></option><?php } ?>
            </select></div>
          <?php } ?>
          <div class="col-md-7"><label class="form-label">Title <span class="req">*</span></label><input class="form-control" name="title" maxlength="200" placeholder="e.g. MOU 2026-27 signed copy" required></div>
          <div class="col-md-5"><label class="form-label">Type <span class="req">*</span></label>
            <select class="form-select" name="category"><?php foreach ($categories as $k => $v) { ?><option value="<?php echo $k; ?>"<?php echo $k === 'mou' ? ' selected' : ''; ?>><?php echo html_escape($v); ?></option><?php } ?></select></div>
          <div class="col-md-6"><label class="form-label">Reference no.</label><input class="form-control" name="ref_no" maxlength="60"></div>
          <div class="col-md-6"><label class="form-label">Issued / signed on</label><input class="form-control date" name="issued_on"></div>
          <div class="col-md-6"><label class="form-label">Valid from</label><input class="form-control date" name="valid_from"></div>
          <div class="col-md-6"><label class="form-label">Valid till</label><input class="form-control date" name="valid_till"></div>
          <div class="col-12"><label class="form-label">File <span class="req">*</span> <small class="text-muted">PDF, image, Word or Excel, up to 10 MB</small></label><input class="form-control" type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.txt" required></div>
          <div class="col-12"><label class="form-label">Note</label><input class="form-control" name="note" maxlength="500"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="fa fa-upload"></i> Upload</button></div>
    </form>
  </div>
</div>
