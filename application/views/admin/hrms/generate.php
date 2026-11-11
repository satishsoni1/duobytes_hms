<?php
/**
 * Generate a letter / MOU for one staff member from a template.
 * Vars: $staff, $templates, $tpl (selected or null), $extra (placeholders to ask), $ref_no, $values, $types
 */
$this->load->view('admin/hrms/_nav', array('active' => 'documents'));
$sname = trim($staff['name'] . ' ' . $staff['surname']);
$labels = array(
  'probation_months' => 'Probation (months)', 'notice_days' => 'Notice period (days)', 'offered_salary' => 'Offered monthly salary',
  'joining_date' => 'Join on or before', 'resignation_date' => 'Resignation dated', 'new_salary' => 'New monthly salary',
  'effective_date' => 'Effective from', 'visiting_days' => 'Visiting days / timings', 'fee_terms' => 'Fee / revenue-share terms',
  'jurisdiction' => 'Jurisdiction (city)',
);
$defaults = array('probation_months' => '6', 'notice_days' => '30');
$long = array('fee_terms');
?>
<div class="card">
  <div class="card-header d-flex align-items-center flex-wrap gap-2">
    <h3 class="card-title mb-0 me-auto">Generate letter for <a href="<?php echo base_url('admin/staff/profile/' . $staff['id']); ?>"><?php echo html_escape($sname); ?></a> <small class="text-muted"><?php echo html_escape($staff['employee_id']); ?></small></h3>
  </div>
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end mb-3">
      <div class="col-md-6"><label class="form-label">Template</label>
        <select class="form-select" name="template" onchange="this.form.submit()"><option value="">Choose a template</option>
        <?php foreach ($templates as $t) { ?><option value="<?php echo (int) $t['id']; ?>"<?php echo $tpl && (int) $tpl['id'] === (int) $t['id'] ? ' selected' : ''; ?>><?php echo html_escape($t['name']); ?> — <?php echo html_escape($types[$t['letter_type']] ?? $t['letter_type']); ?></option><?php } ?>
        </select></div>
      <?php if ($this->rbac->hasPrivilege('hr_letter_template', 'can_add')) { ?>
      <div class="col-auto"><a class="btn btn-default" href="<?php echo base_url('admin/hrms/templates'); ?>"><i class="fa fa-pencil"></i> Manage templates</a></div>
      <?php } ?>
    </form>

    <?php if (!$tpl) { ?>
      <div class="sh-hr-empty"><i class="fa fa-file-text-o"></i>Choose a template to fill in this letter.</div>
    <?php } else { ?>
    <form id="shGenForm" class="row g-3" autocomplete="off" onsubmit="return false;">
      <?php echo $this->customlib->getCSRF(); ?>
      <input type="hidden" name="template_id" value="<?php echo (int) $tpl['id']; ?>">
      <div class="col-lg-4">
        <div class="mb-2"><label class="form-label">Title on the staff file</label><input class="form-control" name="title" maxlength="200" value="<?php echo html_escape($tpl['name']); ?>"></div>
        <div class="mb-2"><label class="form-label">Reference no.</label><input class="form-control" name="ref_no" maxlength="60" value="<?php echo html_escape($ref_no); ?>"></div>
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label">Valid from</label><input class="form-control date sh-gen-in" name="valid_from"></div>
          <div class="col-6"><label class="form-label">Valid till</label><input class="form-control date sh-gen-in" name="valid_till"></div>
        </div>
        <?php foreach ($extra as $key) { $label = $labels[$key] ?? ucfirst(str_replace('_', ' ', $key)); ?>
          <div class="mb-2"><label class="form-label"><?php echo html_escape($label); ?></label>
          <?php if (in_array($key, $long, true)) { ?>
            <textarea class="form-control sh-gen-in" rows="3" name="extra[<?php echo $key; ?>]"></textarea>
          <?php } else { ?>
            <input class="form-control sh-gen-in" name="extra[<?php echo $key; ?>]" value="<?php echo html_escape($defaults[$key] ?? ''); ?>">
          <?php } ?></div>
        <?php } ?>
        <?php
          $missing = array();
          foreach (array('designation', 'department', 'date_of_joining', 'medical_reg_no') as $k) {
            if (strpos($tpl['body'], '{' . $k . '}') !== false && trim((string) $values[$k]) === '') { $missing[] = $k; }
          }
          if ($missing) { ?>
          <div class="alert alert-warning small py-2">Not filled in on the staff record: <?php echo html_escape(implode(', ', str_replace('_', ' ', $missing))); ?>. <a href="<?php echo base_url('admin/staff/edit/' . $staff['id']); ?>">Edit staff</a> or change the text on the right.</div>
        <?php } ?>
        <div class="d-flex gap-2 mt-3">
          <button type="button" class="btn btn-default" id="shGenPreview"><i class="fa fa-refresh"></i> Refresh preview</button>
          <button type="button" class="btn btn-primary" id="shGenSave"><i class="fa fa-check"></i> Save to staff file</button>
        </div>
        <p class="small text-muted mt-2">You can change the wording on the right for this letter only. Saved letters open in a print view with the payslip print header.</p>
      </div>
      <div class="col-lg-8">
        <label class="form-label">Letter</label>
        <textarea id="shGenBody" name="body" class="form-control" rows="20"><?php echo html_escape($tpl['body']); ?></textarea>
        <label class="form-label mt-3">Preview</label>
        <div class="sh-letter-preview" id="shGenOut"></div>
      </div>
    </form>
    <script>
    $(function () {
      var url = base_url + 'admin/hrms/', sid = <?php echo (int) $staff['id']; ?>, editor = null;
      function syncBody() { if (editor) { $('#shGenBody').val(editor.getData()); } }
      function preview() {
        syncBody();
        $.post(url + 'generate_preview/' + sid, $('#shGenForm').serialize(), null, 'json').done(function (r) {
          if (r.status === 'success') { $('#shGenOut').html(r.html); } else { shHr.toast(false, r.message); }
        });
      }
      $(window).on('load', function () {
        if (window.CKEDITOR) {
          editor = CKEDITOR.replace('shGenBody', { height: 320, allowedContent: true });
          editor.on('change', function () { clearTimeout(window.shGenT); window.shGenT = setTimeout(preview, 600); });
        }
      });
      $('#shGenPreview').on('click', preview);
      $(document).on('change', '.sh-gen-in', preview);
      $('#shGenSave').on('click', function () {
        syncBody();
        var $b = $(this).prop('disabled', true);
        $.post(url + 'generate_save/' + sid, $('#shGenForm').serialize(), null, 'json').done(function (r) {
          if (r.status === 'success') { shHr.toast(true, r.message); window.open(r.url, '_blank'); setTimeout(function () { location.href = base_url + 'admin/staff/profile/' + sid; }, 800); }
          else { shHr.toast(false, r.message); $b.prop('disabled', false); }
        }).fail(function () { $b.prop('disabled', false); shHr.toast(false, 'The server did not answer.'); });
      });
      preview();
    });
    </script>
    <?php } ?>
  </div>
</div>
