<?php
/** Setup > Settings > Patient address lists. Vars: $defaults, $can_edit */
?>
<script src="<?php echo base_url('backend/js/sh-hrms.js'); ?>"></script>
<style>
.sh-geo-col { border: 1px solid var(--bs-border-color, #e5e7eb); border-radius: 10px; height: 460px; display: flex; flex-direction: column; }
.sh-geo-col h6 { margin: 0; padding: 8px 10px; border-bottom: 1px solid var(--bs-border-color, #e5e7eb); font-weight: 700; }
.sh-geo-list { overflow: auto; flex: 1; }
.sh-geo-item { display: flex; align-items: center; gap: 4px; padding: 5px 10px; cursor: pointer; border-bottom: 1px solid rgba(0,0,0,.04); }
.sh-geo-item.active { background: var(--bs-primary, #0d9488); color: #fff; }
.sh-geo-item .n { flex: 1; } .sh-geo-item button { padding: 0 6px; line-height: 1.4; }
.sh-geo-add { padding: 6px; border-top: 1px solid var(--bs-border-color, #e5e7eb); }
</style>
<div class="row">
  <?php $this->load->view('setting/sidebar'); ?>
  <div class="col-md-10">
    <div class="card">
      <div class="card-header"><h3 class="card-title mb-0">Patient address lists</h3></div>
      <div class="card-body">
        <p class="text-muted">The State, District, Taluka and City / village dropdowns on patient forms. Staff can also type a new name in the form; it is added here when the patient is saved. Renaming or removing an entry does not change addresses already saved on patients.</p>
        <form id="shGeoDef" class="row g-2 align-items-end mb-4" style="max-width:640px" action="<?php echo base_url('admin/geo/save_defaults'); ?>">
          <div class="col-sm-5"><label class="form-label">Default state for new patients</label><input class="form-control" name="state" value="<?php echo html_escape($defaults['state']); ?>" <?php echo $can_edit ? '' : 'disabled'; ?>></div>
          <div class="col-sm-5"><label class="form-label">Default district</label><input class="form-control" name="district" value="<?php echo html_escape($defaults['district']); ?>" <?php echo $can_edit ? '' : 'disabled'; ?>></div>
          <?php if ($can_edit) { ?><div class="col-sm-2"><button class="btn btn-primary w-100" type="submit">Save</button></div><?php } ?>
        </form>
        <div class="row g-2">
          <?php foreach (array('state' => 'States', 'district' => 'Districts', 'taluka' => 'Talukas', 'city' => 'Cities / villages') as $lvl => $label) { ?>
          <div class="col-md-3"><div class="sh-geo-col" data-level="<?php echo $lvl; ?>">
            <h6><?php echo $label; ?> <small class="text-muted fw-normal sh-geo-count"></small></h6>
            <input class="form-control form-control-sm rounded-0 border-0 border-bottom sh-geo-filter" placeholder="Filter">
            <div class="sh-geo-list"><div class="text-muted small p-2"><?php echo $lvl === 'state' ? 'Loading…' : 'Choose on the left'; ?></div></div>
            <?php if ($can_edit) { ?><div class="sh-geo-add d-flex gap-1"><input class="form-control form-control-sm" placeholder="Add <?php echo strtolower(rtrim($label, 's')); ?>"><?php if ($lvl === 'city') { ?><input class="form-control form-control-sm" style="max-width:80px" placeholder="PIN" maxlength="6"><?php } ?><button class="btn btn-sm btn-primary" type="button"><i class="fa fa-plus"></i></button></div><?php } ?>
          </div></div>
          <?php } ?>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
$(function () {
  var order = ['state', 'district', 'taluka', 'city'], parent = { district: 0, taluka: 0, city: 0 }, canEdit = <?php echo $can_edit ? 'true' : 'false'; ?>;
  function col(l) { return $('.sh-geo-col[data-level="' + l + '"]'); }
  function clearFrom(i) { for (var k = i; k < order.length; k++) { col(order[k]).find('.sh-geo-list').html('<div class="text-muted small p-2">Choose on the left</div>'); col(order[k]).find('.sh-geo-count').text(''); if (k > 0) { parent[order[k]] = 0; } } }
  function load(level) {
    var $c = col(level), p = level === 'state' ? 0 : parent[level];
    if (level !== 'state' && !p) { return; }
    $.getJSON(base_url + 'admin/geo/entries', { level: level, parent: p }, function (rows) {
      var $l = $c.find('.sh-geo-list').empty();
      $c.find('.sh-geo-count').text('(' + rows.length + ')');
      if (!rows.length) { $l.html('<div class="text-muted small p-2">Nothing yet.</div>'); }
      $.each(rows, function (_, r) {
        var $i = $('<div class="sh-geo-item"><span class="n"></span></div>').attr('data-id', r.id);
        $i.find('.n').text(r.name + (r.pincode ? ' · ' + r.pincode : ''));
        if (canEdit) { $i.append('<button class="btn btn-light btn-sm sh-ren" title="Rename"><i class="fa fa-pencil"></i></button><button class="btn btn-light btn-sm sh-del" title="Remove"><i class="fa fa-trash"></i></button>'); }
        $i.data('row', r).appendTo($l);
      });
      $c.find('.sh-geo-filter').trigger('input');
    });
  }
  $(document).on('click', '.sh-geo-item', function (e) {
    if ($(e.target).closest('button').length) { return; }
    var level = $(this).closest('.sh-geo-col').data('level'), i = order.indexOf(level);
    $(this).addClass('active').siblings().removeClass('active');
    if (i < order.length - 1) { clearFrom(i + 1); parent[order[i + 1]] = $(this).data('id'); load(order[i + 1]); }
  });
  $(document).on('input', '.sh-geo-filter', function () {
    var q = this.value.toLowerCase();
    $(this).closest('.sh-geo-col').find('.sh-geo-item').each(function () { $(this).toggle($(this).text().toLowerCase().indexOf(q) > -1); });
  });
  function send(data, level) { shHr.post(base_url + 'admin/geo/entry', data, { done: function () { load(level); } }); }
  $(document).on('click', '.sh-geo-add button', function () {
    var $c = $(this).closest('.sh-geo-col'), level = $c.data('level'), $in = $c.find('.sh-geo-add input');
    send({ action: 'add', level: level, parent: level === 'state' ? 0 : parent[level], name: $in.eq(0).val(), pincode: $in.eq(1).val() || '' }, level);
    $in.val('');
  });
  $(document).on('click', '.sh-ren', function () {
    var $i = $(this).closest('.sh-geo-item'), r = $i.data('row'), level = $i.closest('.sh-geo-col').data('level');
    var name = prompt('New name', r.name); if (!name) { return; }
    var pin = level === 'city' ? (prompt('PIN code (optional)', r.pincode || '') || '') : '';
    send({ action: 'rename', level: level, id: r.id, name: name, pincode: pin }, level);
  });
  $(document).on('click', '.sh-del', function () {
    var $i = $(this).closest('.sh-geo-item'), r = $i.data('row'), level = $i.closest('.sh-geo-col').data('level');
    if (!confirm('Remove "' + r.name + '" from the list? Patients already saved keep their address.')) { return; }
    send({ action: 'delete', level: level, id: r.id }, level);
  });
  $('#shGeoDef').on('submit', function (e) { e.preventDefault(); shHr.postForm(this); });
  load('state');
});
</script>
