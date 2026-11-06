<?php
/**
 * Brand showcase for the login pages (staff, patient, forgot-password).
 * Rendered inside .lp-brand.sk-showcase when Brand Kit "Show brand showcase on login pages" is on.
 *
 * Vars: $brand (Hospital_brand_model::get()), $portal (label, e.g. "Staff Portal").
 * Name / logo / phone / email / address are read from General Setting.
 */
$sk_h     = $this->setting_model->getHospitalDetail();
$sk_name  = html_escape($sk_h->name ?? '');
$sk_logo  = !empty($sk_h->mini_logo) ? $this->media_storage->getImageURL('uploads/hospital_content/logo/' . $sk_h->mini_logo) : '';
$sk_web   = trim((string) $brand['website']);
$sk_href  = $sk_web === '' ? '' : (preg_match('#^https?://#i', $sk_web) ? $sk_web : 'https://' . $sk_web);
$sk_phone = trim((string) $sk_h->phone);
$sk_tel   = preg_replace('/[^0-9+]/', '', $sk_phone);
$sk_emerg = trim((string) $brand['emergency_phone']);
$sk_etel  = preg_replace('/[^0-9+]/', '', strtok($sk_emerg, '/,'));
?>
<div class="sk-hero-main">
  <div class="sk-show-top sk-rise">
    <?php if ($sk_logo): ?><img class="sk-show-logo" src="<?= $sk_logo ?>" alt="<?= $sk_name ?> logo"><?php endif; ?>
    <div>
      <div class="sk-show-name"><?= $sk_name ?></div>
      <?php if ($brand['local_name'] !== ''): ?><div class="sk-show-local" lang="mr"><?= html_escape($brand['local_name']) ?></div><?php endif; ?>
    </div>
  </div>

  <?php if ($brand['tagline'] !== ''): ?><div class="sk-show-tagline sk-rise d1"><?= html_escape($brand['tagline']) ?></div><?php endif; ?>

  <div class="sk-show-pills sk-rise d1">
    <span class="sk-pill"><?= html_escape($portal) ?></span>
    <?php if ($brand['hours_text'] !== ''): ?><span class="sk-pill"><span class="sk-pulse" aria-hidden="true"></span><?= html_escape($brand['hours_text']) ?></span><?php endif; ?>
    <?php if ($brand['city'] !== ''): ?><span class="sk-pill"><?= html_escape($brand['city']) ?></span><?php endif; ?>
  </div>

  <?php if ($brand['welcome_message'] !== ''): ?><p class="sk-hero-welcome sk-rise d2"><?= html_escape($brand['welcome_message']) ?></p><?php endif; ?>

  <?php if (!empty($brand['highlight_list'])): ?>
  <ul class="sk-show-highlights sk-rise d2">
    <?php foreach ($brand['highlight_list'] as $h): ?><li><?= html_escape($h) ?></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <div class="sk-hero-contact sk-rise d3">
    <?php if ($sk_emerg !== ''): ?>
      <a href="tel:<?= html_escape($sk_etel) ?>"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/></svg><span><strong>Emergency</strong><?= html_escape($sk_emerg) ?></span></a>
    <?php endif; ?>
    <?php if ($sk_phone !== ''): ?>
      <a href="tel:<?= html_escape($sk_tel) ?>"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10M7 12h6"/></svg><span><strong>Reception</strong><?= html_escape($sk_phone) ?></span></a>
    <?php endif; ?>
    <?php if ($sk_href !== ''): ?>
      <a href="<?= html_escape($sk_href) ?>" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20"/></svg><span><strong>Website</strong><?= html_escape($sk_web) ?></span></a>
    <?php endif; ?>
    <?php if (trim((string) $sk_h->address) !== ''): ?>
      <div><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/></svg><span><strong>Address</strong><?= html_escape($sk_h->address) ?></span></div>
    <?php endif; ?>
  </div>
</div>
<svg class="sk-ecg" viewBox="0 0 600 60" preserveAspectRatio="none" aria-hidden="true"><path d="M0 34 H180 l12 -6 10 6 H236 l10 -28 12 50 10 -32 8 10 H360 l12 -7 12 7 H600"/></svg>
