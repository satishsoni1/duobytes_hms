<?php
/** Collect an advance. Vars: $modes, $patient (optional: fixes the patient) */
$fixed = isset($patient) && $patient;
?>
<div class="modal fade sh-modal" id="shAdvCollect" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="shAdvCollectForm" action="<?php echo base_url('admin/patientadvance/collect'); ?>" autocomplete="off">
    <div class="modal-header"><h5 class="modal-title">Collect advance</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <?php echo $this->customlib->getCSRF(); ?>
      <div class="row g-2">
        <div class="col-12"><label class="form-label">Patient <span class="req">*</span></label>
          <?php if ($fixed) { ?>
            <input type="hidden" name="patient_id" value="<?php echo (int) $patient['id']; ?>"><div class="form-control-plaintext fw-semibold"><?php echo html_escape($patient['patient_name']); ?> (<?php echo (int) $patient['id']; ?>)</div>
          <?php } else { ?>
            <select class="form-select" name="patient_id" id="shAdvPatient" style="width:100%"></select><small class="text-muted">Type name, mobile or patient ID</small>
          <?php } ?></div>
        <div class="col-md-6"><label class="form-label">Amount <span class="req">*</span></label><input class="form-control" type="number" step="0.01" min="1" name="amount"></div>
        <div class="col-md-6"><label class="form-label">For</label><select class="form-select" name="purpose"><?php foreach (Patientadvance_model::$purposes as $k => $v) { ?><option value="<?php echo $k; ?>"><?php echo html_escape($v); ?></option><?php } ?></select></div>
        <div class="col-md-6"><label class="form-label">Payment mode <span class="req">*</span></label><select class="form-select" name="payment_mode"><?php foreach ($modes as $k => $v) { ?><option value="<?php echo html_escape($k); ?>"><?php echo html_escape($v); ?></option><?php } ?></select></div>
        <div class="col-md-6"><label class="form-label">Date</label><input class="form-control date" name="payment_date" value="<?php echo $this->customlib->YYYYMMDDTodateFormat(date('Y-m-d')); ?>"></div>
        <div class="col-12"><label class="form-label">Cheque / UPI / transaction ref.</label><input class="form-control" name="reference_no" maxlength="100"></div>
        <div class="col-12"><label class="form-label">Note</label><input class="form-control" name="note" maxlength="500" placeholder="e.g. Deposit for planned surgery"></div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="fa fa-check"></i> Collect &amp; print receipt</button></div>
  </form></div>
</div>
<script>
$(function () {
  var $m = $('#shAdvCollect');
  if ($('#shAdvPatient').length && $.fn.select2) {
    $('#shAdvPatient').select2({ dropdownParent: $m, minimumInputLength: 2, placeholder: 'Search patient',
      ajax: { url: base_url + 'admin/patientadvance/search', dataType: 'json', delay: 250, data: function (p) { return { q: p.term }; }, processResults: function (d) { return { results: d }; } } });
  }
  $('#shAdvCollectForm').on('submit', function (e) {
    e.preventDefault();
    shHr.postForm(this, { done: function (r) { window.open(r.receipt, '_blank'); location.href = base_url + 'admin/patientadvance/patient/' + r.patient_id; } });
  });
});
</script>
