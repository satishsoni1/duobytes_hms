<?php
/**
 * My HR — what every staff member sees about themselves: letters, MOUs and documents HR has
 * given them, and the hospital policies & documents meant for their role.
 * Vars: $docs, $policies, $categories (policy), $doc_categories
 */
$this->load->view('admin/hrms/_nav', array('active' => 'mine'));
$fmt = function ($d) { return empty($d) ? '' : $this->customlib->YYYYMMDDTodateFormat($d); };
$by_cat = array();
foreach ($policies as $p) { $by_cat[$p['category']][] = $p; }
?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><h3 class="card-title mb-0"><i class="fa fa-folder-open"></i> My documents</h3></div>
      <div class="card-body">
        <p class="text-muted small">Letters, agreements and documents the hospital keeps on your file. Only you and HR can open them.</p>
        <?php if (empty($docs)) { ?>
          <div class="sh-hr-empty"><i class="fa fa-folder-open"></i>No documents on your file yet.</div>
        <?php } else { ?>
        <div class="list-group list-group-flush">
          <?php foreach ($docs as $d) {
              $expired = !empty($d['valid_till']) && $d['valid_till'] < date('Y-m-d'); ?>
          <a class="list-group-item list-group-item-action d-flex align-items-center gap-2" target="_blank" rel="noopener" href="<?php echo base_url('admin/hrms/document/' . $d['id']); ?>">
            <i class="fa <?php echo $d['source'] === 'generated' ? 'fa-file-text-o' : 'fa-paperclip'; ?> fa-fw text-muted"></i>
            <span class="me-auto">
              <span class="fw-semibold"><?php echo html_escape($d['title']); ?></span><br>
              <small class="text-muted"><?php echo html_escape($doc_categories[$d['category']] ?? $d['category']); ?>
                <?php echo $d['ref_no'] ? ' · ' . html_escape($d['ref_no']) : ''; ?>
                · <?php echo $fmt($d['issued_on'] ?: substr($d['created_at'], 0, 10)); ?></small>
            </span>
            <?php if (!empty($d['valid_till'])) { ?>
              <span class="sh-hr-pill <?php echo $expired ? 'expired' : 'active'; ?>"><?php echo $expired ? 'Expired ' : 'Valid till '; ?><?php echo $fmt($d['valid_till']); ?></span>
            <?php } ?>
          </a>
          <?php } ?>
        </div>
        <?php } ?>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><h3 class="card-title mb-0"><i class="fa fa-book"></i> Hospital policies &amp; documents</h3></div>
      <div class="card-body">
        <?php if (empty($policies)) { ?>
          <div class="sh-hr-empty"><i class="fa fa-book"></i>No policies have been published yet.</div>
        <?php } else { ?>
          <input type="search" class="form-control form-control-sm mb-2" placeholder="Search policies" oninput="var q=this.value.toLowerCase();document.querySelectorAll('.sh-pol-item').forEach(function(e){e.style.display=e.textContent.toLowerCase().indexOf(q)>-1?'':'none';})">
          <?php foreach ($by_cat as $cat => $items) { ?>
            <h6 class="mt-3 mb-1 text-uppercase small text-muted fw-bold"><?php echo html_escape($categories[$cat] ?? $cat); ?></h6>
            <div class="list-group list-group-flush">
            <?php foreach ($items as $p) { ?>
              <a class="list-group-item list-group-item-action sh-pol-item" target="_blank" rel="noopener" href="<?php echo base_url('admin/hrms/policy_file/' . $p['id']); ?>">
                <div class="d-flex align-items-center gap-2">
                  <i class="fa fa-file-o fa-fw text-muted"></i>
                  <span class="fw-semibold me-auto"><?php echo html_escape($p['title']); ?></span>
                  <?php if ($p['version']) { ?><small class="text-muted">v<?php echo html_escape($p['version']); ?></small><?php } ?>
                  <?php if (!$p['is_active']) { ?><span class="sh-hr-pill muted">Hidden</span><?php } ?>
                </div>
                <?php if ($p['description']) { ?><small class="text-muted d-block ms-4"><?php echo html_escape($p['description']); ?></small><?php } ?>
                <?php if ($p['effective_date']) { ?><small class="text-muted d-block ms-4">Effective <?php echo $fmt($p['effective_date']); ?></small><?php } ?>
              </a>
            <?php } ?>
            </div>
          <?php } ?>
        <?php } ?>
      </div>
    </div>
  </div>
</div>
