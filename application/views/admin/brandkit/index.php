<?php
/**
 * Brand Kit — Setup > Settings > Brand Kit.
 * Vars from Brandkit::index(): $brand (array), $setting (sch_settings row object), $can_edit (bool), $title
 */
$b        = $brand;
$hname    = $setting->name ?? '';
$logo_url = !empty($setting->mini_logo) ? $this->media_storage->getImageURL('uploads/hospital_content/logo/' . $setting->mini_logo) : '';
$disabled = $can_edit ? '' : 'disabled';
?>
<div class="row">
  <?php $this->load->view('setting/sidebar'); ?>
  <div class="col-md-10">
    <div class="card">
      <div class="card-header ptbnull d-flex align-items-center">
        <h3 class="card-title titlefix mb-0"><?= html_escape($title) ?></h3>
        <a href="<?= base_url('schsettings') ?>" class="btn btn-default btn-sm ms-auto"><i class="fa fa-hospital-o"></i> Name, logo &amp; contact</a>
      </div>
      <div class="card-body">
        <p class="text-muted mb-4">How <strong><?= html_escape($hname) ?></strong> presents itself on the login screens, the dashboard banner and print headers (tagline). Hospital name, logo, phone and email come from <a href="<?= base_url('schsettings') ?>">General Setting</a>; colours come from <a href="<?= base_url('admin/themestudio') ?>">Theme Studio</a>.</p>

        <div class="row g-4">
          <form id="bkForm" class="col-lg-7" autocomplete="off" onsubmit="return false;">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="bk_tagline">Tagline</label>
                <input class="form-control" id="bk_tagline" name="tagline" maxlength="150" value="<?= html_escape($b['tagline']) ?>" placeholder="Multispeciality Under One Roof" <?= $disabled ?>>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="bk_local_name">Name in local language</label>
                <input class="form-control" id="bk_local_name" name="local_name" maxlength="150" value="<?= html_escape($b['local_name']) ?>" placeholder="स्कायलार्क हॉस्पीटल" <?= $disabled ?>>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="bk_city">City</label>
                <input class="form-control" id="bk_city" name="city" maxlength="80" value="<?= html_escape($b['city']) ?>" <?= $disabled ?>>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="bk_emergency_phone">Emergency / mobile</label>
                <input class="form-control" id="bk_emergency_phone" name="emergency_phone" maxlength="60" value="<?= html_escape($b['emergency_phone']) ?>" <?= $disabled ?>>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="bk_hours_text">Hours</label>
                <input class="form-control" id="bk_hours_text" name="hours_text" maxlength="100" value="<?= html_escape($b['hours_text']) ?>" placeholder="Open 24×7" <?= $disabled ?>>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="bk_website">Website</label>
                <input class="form-control" id="bk_website" name="website" maxlength="150" value="<?= html_escape($b['website']) ?>" placeholder="www.example.com" <?= $disabled ?>>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="bk_welcome_message">Dashboard welcome line</label>
                <input class="form-control" id="bk_welcome_message" name="welcome_message" maxlength="255" value="<?= html_escape($b['welcome_message']) ?>" <?= $disabled ?>>
              </div>
              <div class="col-12">
                <label class="form-label" for="bk_highlights">Highlights <small class="text-muted">— one per line, up to 8 (services, facilities, empanelments)</small></label>
                <textarea class="form-control" id="bk_highlights" name="highlights" rows="5" <?= $disabled ?>><?= html_escape($b['highlights']) ?></textarea>
              </div>
              <div class="col-md-6">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="bk_show_login_showcase" name="show_login_showcase" value="1" <?= !empty($b['show_login_showcase']) ? 'checked' : '' ?> <?= $disabled ?>>
                  <label class="form-check-label" for="bk_show_login_showcase">Show brand showcase on login pages</label>
                </div>
              </div>
              <div class="col-md-6">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="bk_show_dashboard_banner" name="show_dashboard_banner" value="1" <?= !empty($b['show_dashboard_banner']) ? 'checked' : '' ?> <?= $disabled ?>>
                  <label class="form-check-label" for="bk_show_dashboard_banner">Show welcome banner on dashboard</label>
                </div>
              </div>

              <div class="col-12"><hr class="my-2"></div>
              <div class="col-12 d-flex align-items-center flex-wrap gap-2">
                <h4 class="mb-0 me-auto" style="font-size:16px;font-weight:700"><i class="fa fa-star text-warning"></i> Inauguration ceremony</h4>
                <a class="btn btn-default btn-sm" href="<?= base_url('admin/inauguration?mode=rehearsal') ?>" target="_blank" rel="noopener"><i class="fa fa-play-circle"></i> Rehearse (not saved)</a>
                <a class="btn btn-primary btn-sm" href="<?= base_url('admin/inauguration') ?>" target="_blank" rel="noopener"><i class="fa fa-television"></i> Open ceremony screen</a>
              </div>
              <div class="col-12 text-muted small">Full-screen page for the go-live event: the chief guest lights the lamp, then cuts the ribbon (mouse, keyboard or presenter remote). The first real cut is saved as the portal's go-live moment.</div>
              <div class="col-md-6">
                <label class="form-label" for="bk_inauguration_guest">Chief guest</label>
                <input class="form-control" id="bk_inauguration_guest" name="inauguration_guest" maxlength="150" value="<?= html_escape($b['inauguration_guest']) ?>" placeholder="e.g. Dr. A. B. Name, Civil Surgeon, Akola" <?= $disabled ?>>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="bk_inauguration_title">Title line</label>
                <input class="form-control" id="bk_inauguration_title" name="inauguration_title" maxlength="150" value="<?= html_escape($b['inauguration_title']) ?>" placeholder="Digital Hospital Management System" <?= $disabled ?>>
              </div>
              <div class="col-md-6">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="bk_inauguration_enabled" name="inauguration_enabled" value="1" <?= !empty($b['inauguration_enabled']) ? 'checked' : '' ?> <?= $disabled ?>>
                  <label class="form-check-label" for="bk_inauguration_enabled">Ceremony page on (shows a button on the dashboard)</label>
                </div>
              </div>
              <div class="col-md-6 small" id="bkInaugState">
                <?php if (!empty($b['inaugurated_at'])): ?>
                  <i class="fa fa-check-circle text-success"></i> Inaugurated <strong><?= date('d M Y, h:i A', strtotime($b['inaugurated_at'])) ?></strong><?= $b['inaugurated_by'] ? ' by ' . html_escape($b['inaugurated_by']) : '' ?>
                  <?php if ($can_edit): ?> · <a href="#" id="bkInaugReset">Reset</a><?php endif; ?>
                <?php else: ?>
                  <span class="text-muted">Not inaugurated yet.</span>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($can_edit): ?>
            <div class="d-flex align-items-center gap-3 mt-4">
              <button type="button" class="btn btn-primary" id="bkSaveBtn"><i class="fa fa-check"></i> <?= html_escape($this->lang->line('save')) ?></button>
              <span id="bkStatus" class="small text-muted" role="status"></span>
            </div>
            <?php endif; ?>
          </form>

          <div class="col-lg-5">
            <div class="form-label">Live preview</div>
            <div class="sk-showcase sk-preview" aria-hidden="true">
              <div class="sk-show-top">
                <?php if ($logo_url): ?><img class="sk-show-logo" src="<?= $logo_url ?>" alt=""><?php endif; ?>
                <div>
                  <div class="sk-show-name"><?= html_escape($hname) ?></div>
                  <div class="sk-show-local" data-bk="local_name"><?= html_escape($b['local_name']) ?></div>
                </div>
              </div>
              <div class="sk-show-tagline" data-bk="tagline"><?= html_escape($b['tagline']) ?></div>
              <div class="sk-show-pills">
                <span class="sk-pill sk-pill-live"><span class="sk-pulse"></span><span data-bk="hours_text"><?= html_escape($b['hours_text']) ?></span></span>
                <span class="sk-pill" data-bk="city"><?= html_escape($b['city']) ?></span>
              </div>
              <ul class="sk-show-highlights" id="bkPreviewHighlights">
                <?php foreach ($b['highlight_list'] as $h): ?><li><?= html_escape($h) ?></li><?php endforeach; ?>
              </ul>
              <div class="sk-show-contact"><i class="fa fa-phone"></i> <span data-bk="emergency_phone"><?= html_escape($b['emergency_phone']) ?></span></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var form = document.getElementById('bkForm');
  // Live preview: mirror text fields into [data-bk] spans and rebuild the highlight list.
  form.addEventListener('input', function () {
    document.querySelectorAll('.sk-preview [data-bk]').forEach(function (el) {
      var src = form.elements[el.getAttribute('data-bk')];
      if (src) el.textContent = src.value;
    });
    var ul = document.getElementById('bkPreviewHighlights');
    ul.innerHTML = '';
    form.elements.highlights.value.split(/\r?\n/).map(function (s) { return s.trim(); })
      .filter(Boolean).slice(0, 8).forEach(function (s) {
        var li = document.createElement('li'); li.textContent = s; ul.appendChild(li);
      });
  });

  var reset = document.getElementById('bkInaugReset');
  if (reset) reset.addEventListener('click', function (e) {
    e.preventDefault();
    if (!confirm('Clear the recorded inauguration time? Use this only after a test cut before the real event.')) return;
    $.post('<?= base_url('admin/inauguration/reset') ?>', { reset: 1 }, function (res) {
      if (res && res.status === 'success') document.getElementById('bkInaugState').innerHTML = '<span class="text-muted">Not inaugurated yet.</span>';
    }, 'json');
  });

  var btn = document.getElementById('bkSaveBtn');
  if (!btn) return;
  var status = document.getElementById('bkStatus');
  btn.addEventListener('click', function () {
    var data = $(form).serializeArray();
    if (typeof SH_CSRF_NAME !== 'undefined') data.push({ name: SH_CSRF_NAME, value: SH_CSRF_TOKEN });
    btn.disabled = true; status.textContent = 'Saving…';
    $.post('<?= base_url('admin/brandkit/save') ?>', data, function (res) {
      btn.disabled = false;
      if (res && res.status === 'success') {
        status.textContent = 'Saved. Login pages and dashboard now use these details.';
      } else {
        var err = res && res.error;
        status.textContent = 'Not saved: ' + (typeof err === 'object' ? Object.values(err).join(' ') : (err || 'unknown error'));
      }
    }, 'json').fail(function () { btn.disabled = false; status.textContent = 'Not saved: server did not respond.'; });
  });
})();
</script>
