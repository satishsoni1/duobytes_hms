<?php
/** Add / edit an HR letter template. Vars: $tpl (or null), $types, $categories, $placeholders */
$this->load->view('admin/hrms/_nav', array('active' => 'templates'));
$t = $tpl ?: array('id' => 0, 'name' => '', 'letter_type' => 'appointment', 'doc_category' => 'letter', 'subject' => '', 'body' => '', 'is_active' => 1);
?>
<div class="card">
  <div class="card-header d-flex align-items-center">
    <h3 class="card-title mb-0 me-auto"><?php echo html_escape($title); ?></h3>
    <a class="btn btn-default btn-sm" href="<?php echo base_url('admin/hrms/templates'); ?>">Back to templates</a>
  </div>
  <div class="card-body">
    <form id="shTplForm" class="row g-3" action="<?php echo base_url('admin/hrms/template_save/' . (int) $t['id']); ?>" autocomplete="off">
      <?php echo $this->customlib->getCSRF(); ?>
      <div class="col-lg-9">
        <div class="row g-2 mb-2">
          <div class="col-md-5"><label class="form-label">Name <span class="req">*</span></label><input class="form-control" name="name" maxlength="150" value="<?php echo html_escape($t['name']); ?>"></div>
          <div class="col-md-4"><label class="form-label">Type</label><select class="form-select" name="letter_type"><?php foreach ($types as $k => $v) { ?><option value="<?php echo $k; ?>"<?php echo $t['letter_type'] === $k ? ' selected' : ''; ?>><?php echo html_escape($v); ?></option><?php } ?></select></div>
          <div class="col-md-3"><label class="form-label">Saved on staff file as</label><select class="form-select" name="doc_category"><?php foreach ($categories as $k => $v) { ?><option value="<?php echo $k; ?>"<?php echo $t['doc_category'] === $k ? ' selected' : ''; ?>><?php echo html_escape($v); ?></option><?php } ?></select></div>
          <div class="col-md-9"><label class="form-label">Subject</label><input class="form-control" name="subject" maxlength="200" value="<?php echo html_escape($t['subject']); ?>"></div>
          <div class="col-md-3 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="shTplAct"<?php echo $t['is_active'] ? ' checked' : ''; ?>><label class="form-check-label" for="shTplAct">In use</label></div></div>
        </div>
        <label class="form-label">Letter text <span class="req">*</span></label>
        <textarea id="shTplBody" name="body" class="form-control" rows="22"><?php echo html_escape($t['body']); ?></textarea>
        <div class="mt-3"><button type="submit" class="btn btn-primary"><i class="fa fa-check"></i> Save template</button></div>
      </div>
      <div class="col-lg-3">
        <label class="form-label">Placeholders <small class="text-muted">(click to insert)</small></label>
        <div class="border rounded p-2" style="max-height:560px;overflow:auto">
          <?php foreach ($placeholders as $k => $v) { ?>
            <div class="d-flex align-items-center gap-1"><span class="sh-hr-ph" data-ph="{<?php echo $k; ?>}">{<?php echo $k; ?>}</span><small class="text-muted"><?php echo html_escape($v); ?></small></div>
          <?php } ?>
          <hr class="my-2">
          <small class="text-muted">Any other placeholder, e.g. <code>{fee_terms}</code> or <code>{notice_days}</code>, becomes a box to fill in when the letter is generated.</small>
        </div>
      </div>
    </form>
  </div>
</div>
<script>
$(function () {
  var editor = null;
  $(window).on('load', function () { if (window.CKEDITOR) { editor = CKEDITOR.replace('shTplBody', { height: 420, allowedContent: true }); } });
  $('.sh-hr-ph').on('click', function () {
    var ph = $(this).data('ph');
    if (editor) { editor.insertText(ph); editor.focus(); return; }
    var ta = document.getElementById('shTplBody'), p = ta.selectionStart || ta.value.length;
    ta.value = ta.value.slice(0, p) + ph + ta.value.slice(p); ta.focus();
  });
  $('#shTplForm').on('submit', function (e) {
    e.preventDefault();
    if (editor) { $('#shTplBody').val(editor.getData()); }
    shHr.postForm(this, { done: function () { location.href = base_url + 'admin/hrms/templates'; } });
  });
});
</script>
