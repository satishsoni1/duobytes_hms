<?php
/** Printable travel claim voucher. Vars: $claim, $statuses, $setting */
$c = $claim; $cur = $setting->currency_symbol;
$fmt = function ($d) { return empty($d) ? '' : $this->customlib->YYYYMMDDTodateFormat($d); };
$payable = $c['approved_amount'] !== null ? Travelexpense_model::payable_amount($c) : null;
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo html_escape('Travel claim ' . $c['claim_no']); ?></title>
<style>
@page { size: A4; margin: 12mm; }
body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; color: #111; font-size: 12.5px; margin: 0; background: #fff; }
.wrap { max-width: 190mm; margin: 0 auto; padding: 12px; }
h1 { font-size: 18px; margin: 0; } h2 { font-size: 15px; margin: 14px 0 6px; }
table { width: 100%; border-collapse: collapse; } th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; } th { background: #f3f4f6; }
.r { text-align: right; } .meta td { border: none; padding: 2px 0; } .sign { display: flex; justify-content: space-between; margin-top: 50px; }
.bar { text-align: right; margin-bottom: 8px; } .bar button { padding: 6px 14px; }
@media print { .bar { display: none; } }
</style></head><body><div class="wrap">
<div class="bar"><button onclick="window.print()">Print</button></div>
<div style="text-align:center;border-bottom:2px solid #111;padding-bottom:6px;margin-bottom:10px"><h1><?php echo html_escape($setting->name); ?></h1><div><?php echo html_escape($setting->address); ?></div></div>
<h2 style="text-align:center">TRAVEL EXPENSE CLAIM — <?php echo html_escape($c['claim_no']); ?></h2>
<table class="meta"><tr><td><b>Name:</b> <?php echo html_escape(trim($c['name'] . ' ' . $c['surname'])); ?> (<?php echo html_escape($c['employee_id']); ?>)</td><td class="r"><b>Status:</b> <?php echo html_escape($statuses[$c['status']]); ?></td></tr>
<tr><td><b>Trip / purpose:</b> <?php echo html_escape($c['title']); ?></td><td class="r"><b>Period:</b> <?php echo $fmt($c['from_date']); ?> – <?php echo $fmt($c['to_date']); ?></td></tr></table>
<h2>Expenses</h2>
<table><tr><th>Date</th><th>Category</th><th>Description</th><th>From → To</th><th>Mode</th><th class="r">Km</th><th class="r">Amount (<?php echo $cur; ?>)</th><th>Bill</th></tr>
<?php foreach ($c['items'] as $it) { ?><tr><td><?php echo $fmt($it['expense_date']); ?></td><td><?php echo html_escape($it['category']); ?></td><td><?php echo html_escape($it['description']); ?></td><td><?php echo html_escape(trim($it['from_place'] . ($it['to_place'] ? ' → ' . $it['to_place'] : ''))); ?></td><td><?php echo html_escape($it['travel_mode']); ?></td><td class="r"><?php echo html_escape($it['distance_km']); ?></td><td class="r"><?php echo number_format((float) $it['amount'], 2); ?></td><td><?php echo $it['attachment'] ? 'Attached' : '—'; ?></td></tr><?php } ?>
<tr><th colspan="6" class="r">Total claimed</th><th class="r"><?php echo number_format((float) $c['total_amount'], 2); ?></th><th></th></tr>
<tr><td colspan="6" class="r">Less advance taken</td><td class="r"><?php echo number_format((float) $c['advance_taken'], 2); ?></td><td></td></tr>
<?php if ($c['approved_amount'] !== null) { ?><tr><td colspan="6" class="r">Approved</td><td class="r"><?php echo number_format((float) $c['approved_amount'], 2); ?></td><td></td></tr>
<tr><th colspan="6" class="r">Payable with salary</th><th class="r"><?php echo number_format($payable, 2); ?></th><th></th></tr><?php } ?>
</table>
<?php if ($c['decision_note']) { ?><p><b>Approver's note:</b> <?php echo html_escape($c['decision_note']); ?></p><?php } ?>
<div class="sign"><div>Claimant's signature</div><div>Approved by<?php echo $c['decided_name'] ? ': ' . html_escape(trim($c['decided_name'] . ' ' . $c['decided_surname'])) : ''; ?></div><div>Accounts</div></div>
</div></body></html>
