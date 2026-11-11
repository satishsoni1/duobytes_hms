<?php
/**
 * Discount approval helper on every staff page (included by layout/footer.php).
 * - For staff who are not approvers: when a discount field gets a value, an "Approver / reason"
 *   box appears in the same form so the request goes to the chosen approver.
 * - After any save that held a discount back (response header X-Discount-Pending), a toast says so.
 * The server holds the discount back regardless of this box (Discountapproval_model::gate).
 */
$CI =& get_instance();
if (!$CI->session->userdata('hospitaladmin')) { return; }
$CI->load->model('discountapproval_model');
if (!$CI->db->table_exists('hms_settings')) { return; }
$da_set = $CI->discountapproval_model->settings();
if (!$da_set['enabled']) { return; }
$da_me  = (int) $CI->customlib->getStaffID();
$da_is_approver = $CI->discountapproval_model->is_approver($da_me);
$da_list = $da_is_approver ? array() : array_map(function ($a) {
    return array('id' => (int) $a['id'], 'name' => trim($a['name'] . ' ' . $a['surname']) . ($a['role_name'] ? ' (' . $a['role_name'] . ')' : ''));
}, $CI->discountapproval_model->approver_list());
?>
<script>
(function ($) {
  if (!$) { return; }
  var approvers = <?php echo json_encode($da_list); ?>, isApprover = <?php echo $da_is_approver ? 'true' : 'false'; ?>, freePct = <?php echo json_encode($da_set['free_pct']); ?>;
  var url = '<?php echo base_url('admin/discountapproval'); ?>';
  var skip = /^(discount_approver_id|discount_reason)$/;
  function isDiscountField(el) { return el && el.name && /discount/i.test(el.name) && !skip.test(el.name) && el.type !== 'hidden'; }
  function boxFor($scope) {
    var $b = $scope.find('.sh-da-box');
    if ($b.length) { return $b; }
    var opts = '<option value="">Any approver</option>' + approvers.map(function (a) { return '<option value="' + a.id + '">' + $('<span>').text(a.name).html() + '</option>'; }).join('');
    $b = $('<div class="sh-da-box alert alert-warning py-2 px-2 my-2 small" style="grid-column:1/-1">' +
      '<div class="mb-1"><i class="fa fa-percent"></i> This discount needs approval. The bill is saved at the full amount until it is approved.</div>' +
      '<div class="d-flex flex-wrap gap-2"><select class="form-select form-select-sm" name="discount_approver_id" style="max-width:240px">' + opts + '</select>' +
      '<input class="form-control form-control-sm" name="discount_reason" maxlength="500" placeholder="Reason (e.g. staff family, BPL card)" style="max-width:320px"></div></div>');
    return $b;
  }
  if (!isApprover) {
    $(document).on('input change', 'input, select', function () {
      if (!isDiscountField(this)) { return; }
      var $form = $(this).closest('form'); if (!$form.length) { $form = $(this).closest('.modal-content, .card'); }
      if (!$form.length) { return; }
      var any = false;
      $form.find('input, select').each(function () {
        if (!isDiscountField(this)) { return; }
        var v = parseFloat(this.value); if (!isNaN(v) && v > 0 && !(/percent/i.test(this.name) && freePct > 0 && v <= freePct)) { any = true; }
      });
      var $box = $form.find('.sh-da-box');
      if (any && !$box.length) {
        $box = boxFor($form);
        var $anchor = $(this).closest('[class*="col-"], .form-group, td').first();
        if ($anchor.is('td')) { $anchor = $(this).closest('table'); }
        ($anchor.length ? $anchor : $(this)).after($box);
      }
      $box.toggle(any);
      $box.find('select, input').prop('disabled', !any);
    });
  }
  $(document).ajaxComplete(function (e, xhr) {
    var n = xhr && xhr.getResponseHeader ? parseInt(xhr.getResponseHeader('X-Discount-Pending'), 10) : 0;
    if (n > 0) {
      var msg = (n > 1 ? n + ' discounts were' : 'The discount was') + ' sent for approval. The bill shows the full amount until it is approved.';
      if (window.toastr) { toastr.warning(msg + ' <a href="' + url + '?scope=asked" style="text-decoration:underline">View</a>', '', { timeOut: 9000, escapeHtml: false }); } else if (window.successMsg) { successMsg(msg); }
    }
  });
})(window.jQuery);
</script>
