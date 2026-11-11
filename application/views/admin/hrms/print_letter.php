<?php
/**
 * Printable generated letter / MOU (standalone page, A4). Vars: $doc, $staff, $header (image URL or ''), $setting
 * $doc['body'] was rendered from an admin template (cleaned) with escaped staff values.
 */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo html_escape($doc['title'] . ' - ' . trim($staff['name'] . ' ' . $staff['surname'])); ?></title>
<style>
  @page { size: A4; margin: 12mm 14mm; }
  html, body { background: #e5e7eb; margin: 0; }
  body { font-family: Georgia, 'Times New Roman', serif; color: #111; font-size: 14px; line-height: 1.6; }
  .sheet { background: #fff; max-width: 210mm; margin: 16px auto; padding: 14mm 16mm; box-shadow: 0 2px 12px rgba(0,0,0,.15); box-sizing: border-box; }
  .hdr img { width: 100%; display: block; margin-bottom: 10px; }
  .hdr-text { text-align: center; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 16px; }
  .hdr-text h1 { margin: 0; font-size: 22px; }
  .bar { max-width: 210mm; margin: 12px auto 0; display: flex; gap: 8px; justify-content: flex-end; font-family: system-ui, sans-serif; padding: 0 8px; }
  .bar button { padding: 8px 16px; border-radius: 6px; border: 1px solid #0f766e; background: #0f766e; color: #fff; cursor: pointer; font-size: 14px; }
  .bar button.alt { background: #fff; color: #0f766e; }
  table { border-collapse: collapse; }
  @media print { html, body { background: #fff; } .bar { display: none; } .sheet { box-shadow: none; margin: 0; padding: 0; max-width: none; } }
  @media (max-width: 600px) { .sheet { padding: 16px; margin: 8px; } }
</style>
</head>
<body>
<div class="bar"><button class="alt" onclick="window.close()">Close</button><button onclick="window.print()">Print / save as PDF</button></div>
<div class="sheet">
  <?php if ($header) { ?>
    <div class="hdr"><img src="<?php echo html_escape($header); ?>" alt=""></div>
  <?php } else { ?>
    <div class="hdr-text"><h1><?php echo html_escape($setting->name); ?></h1><div><?php echo html_escape($setting->address); ?></div><div><?php echo html_escape($setting->phone); ?> <?php echo html_escape($setting->email); ?></div></div>
  <?php } ?>
  <div class="body"><?php echo $doc['body']; ?></div>
</div>
</body>
</html>
