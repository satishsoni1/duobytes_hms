<?php
/** Printable advance receipt. Vars: $a (advance with entries), $header, $setting, $modes */
$cur = $setting->currency_symbol;
$dt  = function ($d) { return empty($d) ? '' : $this->customlib->YYYYMMDDHisTodateFormat($d, $this->customlib->getHospitalTimeFormat()); };
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo html_escape('Advance receipt ' . $a['receipt_no']); ?></title>
<style>
@page { size: A5 landscape; margin: 8mm; }
body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; color: #111; font-size: 13px; margin: 0; }
.wrap { max-width: 200mm; margin: 0 auto; padding: 10px; } .hdr img { width: 100%; }
h2 { text-align: center; font-size: 16px; letter-spacing: 1px; margin: 8px 0; }
table { width: 100%; border-collapse: collapse; } td { padding: 5px 4px; border-bottom: 1px dashed #bbb; } td.k { width: 35%; color: #444; }
.amt { font-size: 20px; font-weight: 700; } .sign { display: flex; justify-content: space-between; margin-top: 40px; }
.void { color: #b91c1c; font-weight: 700; text-align: center; border: 2px solid #b91c1c; padding: 4px; margin: 6px 0; }
.bar { text-align: right; } .bar button { padding: 6px 14px; } @media print { .bar { display: none; } }
</style></head><body><div class="wrap">
<div class="bar"><button onclick="window.print()">Print</button></div>
<?php if ($header) { ?><div class="hdr"><img src="<?php echo html_escape($header); ?>" alt=""></div><?php } else { ?>
<div style="text-align:center;border-bottom:2px solid #111;padding-bottom:6px"><b style="font-size:18px"><?php echo html_escape($setting->name); ?></b><br><?php echo html_escape($setting->address); ?> · <?php echo html_escape($setting->phone); ?></div><?php } ?>
<h2>ADVANCE RECEIPT</h2>
<?php if ($a['is_cancelled']) { ?><div class="void">CANCELLED<?php echo $a['cancel_note'] ? ' — ' . html_escape($a['cancel_note']) : ''; ?></div><?php } ?>
<table>
<tr><td class="k">Receipt no.</td><td><b><?php echo html_escape($a['receipt_no']); ?></b></td></tr>
<tr><td class="k">Date</td><td><?php echo $dt($a['payment_date']); ?></td></tr>
<tr><td class="k">Received from</td><td><?php echo html_escape($a['patient_name']); ?> (Patient ID <?php echo (int) $a['patient_id']; ?>)<?php echo $a['guardian_name'] ? ', ' . html_escape($a['guardian_name']) : ''; ?></td></tr>
<tr><td class="k">Towards</td><td>Advance — <?php echo html_escape(Patientadvance_model::$purposes[$a['purpose']] ?? $a['purpose']); ?><?php echo $a['note'] ? ' (' . html_escape($a['note']) . ')' : ''; ?></td></tr>
<tr><td class="k">Mode</td><td><?php echo html_escape($modes[$a['payment_mode']] ?? $a['payment_mode']); ?><?php echo $a['reference_no'] ? ' · Ref ' . html_escape($a['reference_no']) : ''; ?></td></tr>
<tr><td class="k">Amount</td><td class="amt"><?php echo $cur . number_format((float) $a['amount'], 2); ?></td></tr>
<?php if ((float) $a['used'] > 0) { ?><tr><td class="k">Used / refunded so far</td><td><?php echo $cur . number_format((float) $a['used'], 2); ?> · Balance <?php echo $cur . number_format($a['balance'], 2); ?></td></tr><?php } ?>
</table>
<p style="font-size:11.5px;color:#444">This advance will be adjusted against the final bill. Any unused amount is refundable on producing this receipt.</p>
<div class="sign"><div>Patient / attendant</div><div>Received by: <?php echo html_escape($a['received_name']); ?></div></div>
</div></body></html>
