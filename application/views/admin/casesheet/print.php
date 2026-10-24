<?php
/* Printable IPD case-sheet documents: summary | prescription | consent | nursing | medchart (A4). */
$e = function ($v) {
    return html_escape((string) $v);
};
$nl = function ($v) use ($e) {
    return nl2br($e(trim((string) $v)));
};
$d = function ($v, $fmt = 'd-m-Y') {
    return $v ? date($fmt, strtotime($v)) : '';
};
$ipd_no = $this->customlib->getSessionPrefixByType('ipd_no') . $ctx['ipd_id'];
$age = Casesheet_model::short_age($ctx);
$card = $ctx['discharge_card'];
$discharge_at = $sheet['discharge_at'] ?: ($card ? $card['discharge_date'] : '');
$titles = array('summary' => 'Discharge Summary', 'prescription' => 'Discharge Prescription', 'consent' => 'Admission Form & Consent',
    'nursing' => 'Nursing Chart', 'medchart' => 'Medication Chart');
$hosp = $lh['hospital'];
$doctor_line = $ctx['doctor'] . ($ctx['doctor_qualification'] ? ', ' . $ctx['doctor_qualification'] : '');
$landscape = $kind === 'medchart';

$letterhead = function () use ($lh, $hosp, $e) {
    if ($lh['header_image']) {
        echo '<div class="lh-img"><img src="' . base_url($lh['header_image']) . '" alt=""></div>';
        return;
    }
    $logo = $hosp['image'] && is_file(FCPATH . 'uploads/hospital_content/logo/' . $hosp['image']) ? base_url('uploads/hospital_content/logo/' . $hosp['image']) : '';
    echo '<div class="lh">' . ($logo ? '<img class="lh-logo" src="' . $logo . '" alt="">' : '')
        . '<div><div class="lh-name">' . $e($hosp['name']) . '</div><div class="lh-addr">' . $e($hosp['address']) . '</div>'
        . '<div class="lh-addr">' . ($hosp['phone'] ? 'Phone: ' . $e($hosp['phone']) : '') . ($hosp['email'] ? ' &nbsp;|&nbsp; Email: ' . $e($hosp['email']) : '') . '</div></div></div>';
};
$patient_block = function ($with_discharge = true) use ($ctx, $sheet, $e, $d, $ipd_no, $age, $discharge_at) {
    $los = '';
    if ($discharge_at) {
        $days = (int) floor((strtotime(substr($discharge_at, 0, 10)) - strtotime(substr($ctx['admission_date'], 0, 10))) / 86400);
        $los = max(1, $days) . ' day' . (max(1, $days) > 1 ? 's' : '');
    }
    ?>
    <table class="pt">
        <tr><th>Patient name</th><td><strong><?php echo $e($ctx['patient_name']); ?></strong></td><th>Age / Sex</th><td><?php echo $e($age . ' / ' . $ctx['gender']); ?></td><th>IPD No.</th><td><?php echo $e($ipd_no); ?></td></tr>
        <tr><th>Address</th><td><?php echo $e($ctx['address']); ?></td><th>Phone</th><td><?php echo $e($ctx['mobileno']); ?></td><th>UHID</th><td><?php echo $e('SH' . $ctx['patient_id']); ?></td></tr>
        <tr><th>Consultant</th><td><?php echo $e($ctx['doctor']); ?></td><th>Admitted by</th><td><?php echo $e(trim($sheet['admitted_by_name'] . ($sheet['admitted_by_relation'] ? ' (' . $sheet['admitted_by_relation'] . ')' : ''))); ?></td><th>Bed</th><td><?php echo $e($ctx['bed']); ?></td></tr>
        <tr><th>Admission</th><td><?php echo $e($d($ctx['admission_date'], 'd-m-Y h:i A')); ?></td>
            <?php if ($with_discharge) { ?><th>Discharge</th><td><?php echo $e($d($discharge_at, 'd-m-Y h:i A')); ?></td><th>Stay</th><td><?php echo $e($los); ?></td>
            <?php } else { ?><th>Case ID</th><td><?php echo $e($ctx['case_reference_id']); ?></td><th>Blood group</th><td><?php echo $e($ctx['blood_group_name']); ?></td><?php } ?></tr>
        <?php if ($ctx['allergies']) { ?><tr><th>Allergies</th><td colspan="5" class="<?php echo $ctx['has_allergy'] ? 'allergy' : ''; ?>"><?php echo $e($ctx['allergies']); ?></td></tr><?php } ?>
    </table>
    <?php
};
$section = function ($title, $body, $raw = false) use ($nl) {
    if (trim(strip_tags((string) $body)) === '') {
        return;
    }
    echo '<div class="sec"><div class="sec-t">' . html_escape($title) . '</div><div class="sec-b">' . ($raw ? $body : $nl($body)) . '</div></div>';
};
$vital_text = function ($v) {
    $map = array('pulse' => 'Pulse %s/min', 'bp' => 'BP %s mmHg', 'temp' => 'Temp %s °F', 'spo2' => 'SpO2 %s%%', 'rr' => 'RR %s/min', 'weight' => 'Wt %s kg', 'rbs' => 'RBS %s mg/dl');
    $out = array();
    foreach ($map as $k => $f) {
        if (!empty($v[$k])) {
            $out[] = sprintf($f, html_escape(rtrim($v[$k], '%')));
        }
    }
    return implode(' &nbsp;·&nbsp; ', $out);
};
$signatures = function ($left, $right) use ($e) {
    echo '<table class="sig"><tr><td>' . $left . '</td><td>' . $right . '</td></tr></table>';
};
$doctor_sign = function () use ($author, $ctx, $e, $doctor_line) {
    $name = $author ? trim('Dr. ' . $author['name'] . ' ' . $author['surname']) . ($author['qualification'] ? ', ' . $author['qualification'] : '') : $doctor_line;
    return '<div class="sig-line"></div>' . $e($name ?: 'Treating doctor') . '<br><small>Signature &amp; stamp</small>';
};
?><!doctype html>
<html lang="<?php echo $kind === 'consent' && $sheet['consent_language'] !== 'en' ? $e($sheet['consent_language']) : 'en'; ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $e($titles[$kind] . ' — ' . $ctx['patient_name']); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;600;700&family=Noto+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
@page { size: A4 <?php echo $landscape ? 'landscape' : 'portrait'; ?>; margin: 10mm 11mm; }
* { box-sizing: border-box; }
body { font-family: 'Noto Sans', 'Noto Sans Devanagari', Arial, sans-serif; font-size: 11.5px; color: #111; margin: 0; background: #e9ecef; line-height: 1.45; }
.page { background: #fff; width: <?php echo $landscape ? '297mm' : '210mm'; ?>; min-height: <?php echo $landscape ? '210mm' : '297mm'; ?>; margin: 12px auto; padding: 10mm 11mm; box-shadow: 0 1px 4px rgba(0,0,0,.15); position: relative; }
.toolbar { text-align: center; padding: 10px; }
.toolbar button { font: inherit; font-size: 13px; padding: 6px 18px; border: 1px solid #0d6efd; background: #0d6efd; color: #fff; border-radius: 4px; cursor: pointer; }
.toolbar a { margin-left: 12px; color: #0d6efd; }
.lh { display: flex; align-items: center; gap: 14px; border-bottom: 3px double #0b5a7a; padding-bottom: 6px; margin-bottom: 8px; }
.lh-logo { height: 56px; max-width: 170px; object-fit: contain; }
.lh-name { font-size: 22px; font-weight: 700; color: #0b5a7a; letter-spacing: .02em; }
.lh-addr { font-size: 11px; color: #333; }
.lh-img img { width: 100%; max-height: 120px; object-fit: contain; display: block; margin-bottom: 8px; }
.doc-title { text-align: center; font-weight: 700; font-size: 15px; letter-spacing: .12em; text-transform: uppercase; margin: 6px 0 8px; }
.doc-title span { border-bottom: 1.5px solid #111; padding: 0 6px 1px; }
table { border-collapse: collapse; width: 100%; }
.pt th, .pt td { border: 1px solid #9aa5b1; padding: 3px 6px; text-align: left; vertical-align: top; }
.pt th { background: #eef3f7; font-weight: 600; white-space: nowrap; width: 1%; }
.pt .allergy { color: #b00020; font-weight: 600; }
.sec { margin-top: 9px; page-break-inside: avoid; }
.sec-t { font-weight: 700; font-size: 11.5px; text-transform: uppercase; color: #0b5a7a; border-bottom: 1px solid #c9d3dc; margin-bottom: 3px; letter-spacing: .04em; }
.sec-b { padding-left: 2px; }
.grid th, .grid td { border: 1px solid #9aa5b1; padding: 3px 5px; text-align: center; }
.grid th { background: #eef3f7; font-weight: 600; }
.grid td.l, .grid th.l { text-align: left; }
.grid .dark th { background: #1f2a33; color: #fff; }
.sig { margin-top: 34px; page-break-inside: avoid; }
.sig td { width: 50%; vertical-align: bottom; padding: 0 10px; }
.sig td:last-child { text-align: right; }
.sig-line { border-top: 1px solid #111; width: 220px; display: inline-block; margin-bottom: 3px; }
.sig-line { display: block; }
.sig td:last-child .sig-line { margin-left: auto; }
.rx-sym { font-family: Georgia, serif; font-size: 30px; font-weight: 700; color: #0b5a7a; line-height: 1; margin: 8px 0 4px; }
.rx td, .rx th { border-bottom: 1px solid #d5dce3; padding: 5px 6px; text-align: left; vertical-align: top; }
.rx th { font-size: 10.5px; text-transform: uppercase; color: #555; border-bottom: 1.5px solid #555; }
.rx .n { width: 18px; color: #555; }
.rx .drug { font-weight: 700; font-size: 12.5px; }
.footer-note { margin-top: 22px; font-size: 9.5px; color: #666; text-align: center; border-top: 1px solid #ccc; padding-top: 3px; }
.consent { font-family: 'Noto Sans Devanagari', 'Noto Sans', sans-serif; font-size: 12px; }
.consent .f { display: flex; gap: 6px; margin: 3px 0; align-items: baseline; }
.consent .f b { white-space: nowrap; font-weight: 600; }
.consent .f span { flex: 1; border-bottom: 1px dotted #333; min-height: 17px; padding: 0 4px; }
.consent ol { padding-left: 22px; margin: 6px 0; }
.consent li, .consent p { margin: 3px 0; text-align: justify; }
.consent .ctr { text-align: center; font-weight: 700; margin: 8px 0 4px; font-size: 13px; }
.nc-day { page-break-after: always; }
.nc-day:last-child { page-break-after: auto; }
.mc .drug { text-align: left; font-weight: 700; }
.mc td.t { font-size: 10px; white-space: nowrap; }
.mc .tick { color: #146c43; font-weight: 700; }
.mc .miss { color: #b00020; }
.mc .off { background: #f3f3f3; color: #aaa; }
.muted { color: #666; }
@media print {
  body { background: #fff; }
  .toolbar { display: none; }
  .page { margin: 0; box-shadow: none; width: auto; min-height: 0; padding: 0; }
  .page + .page { page-break-before: always; }
}
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Print</button><a href="<?php echo site_url('admin/casesheet/index/' . (int) $ctx['ipd_id']); ?>">Back to case sheet</a></div>

<?php if ($kind === 'summary') { $rx = $sheet['discharge_rx']; ?>
<!-- ======================= DISCHARGE SUMMARY ======================= -->
<div class="page">
    <?php $letterhead(); ?>
    <div class="doc-title"><span>Discharge Summary</span></div>
    <?php $patient_block(); ?>

    <?php
    $section('Final diagnosis', $sheet['final_diagnosis'] ?: ($card ? $card['diagnosis'] : $sheet['provisional_diagnosis']));
    $section('Chief complaints', $sheet['chief_complaints'] ?: $ctx['symptoms']);
    $section('History', $sheet['history']);
    $section('On examination (at admission)', implode('<br>', array_filter(array($vital_text($sheet['admission_vitals']), $nl($sheet['examination'])))), true);

    $inv = array();
    foreach ($charts as $c) {
        foreach (preg_split('/[\n,]+/', (string) $c['investigations']) as $i) {
            $i = trim($i, " -\t");
            if ($i !== '') {
                $inv[strtolower($i)] = $i;
            }
        }
    }
    $section('Investigations', implode('<br>', array_filter(array($inv ? html_escape(implode(', ', $inv)) : '', $sheet['lab_findings'] ? '<b>Findings:</b> ' . $nl($sheet['lab_findings']) : ''))), true);
    $section('Procedures / operation', $sheet['procedures'] ?: ($card ? $card['operation'] : ''));
    $section('Course in hospital', $sheet['course_in_hospital']);

    if ($meds) { ?>
    <div class="sec"><div class="sec-t">Treatment given</div>
        <table class="grid"><tr><th>#</th><th class="l">Drug</th><th>Dose</th><th>Route</th><th>Frequency</th><th>From</th><th>To</th><th>Doses given</th></tr>
        <?php foreach ($meds as $i => $m) { $n = 0; foreach ($m['given'] as $day) { $n += count($day); } ?>
            <tr><td><?php echo $i + 1; ?></td><td class="l"><?php echo $e($m['drug']); ?><?php echo $m['instruction'] ? ' <span class="muted">(' . $e($m['instruction']) . ')</span>' : ''; ?></td>
                <td><?php echo $e($m['dose']); ?></td><td><?php echo $e($m['route']); ?></td><td><?php echo $e($m['frequency']); ?></td>
                <td><?php echo $d($m['start_date'], 'd/m'); ?></td><td><?php echo $m['stop_date'] ? $d($m['stop_date'], 'd/m') : ($discharge_at ? $d($discharge_at, 'd/m') : 'ongoing'); ?></td><td><?php echo $n ?: '—'; ?></td></tr>
        <?php } ?>
        </table></div>
    <?php }

    $vrows = array();
    foreach ($charts as $date => $c) {
        foreach (Casesheet_model::$slots as $s) {
            if (!empty($c['vitals'][$s])) {
                $vrows[] = array($date, $s, $c['vitals'][$s]);
            }
        }
    }
    if ($vrows) { ?>
    <div class="sec"><div class="sec-t">Vitals during stay</div>
        <table class="grid"><tr><th>Date</th><th>Time</th><th>SpO2</th><th>Temp</th><th>Pulse</th><th>RR</th><th>BP</th></tr>
        <?php $prev = ''; foreach ($vrows as $r) { list($date, $s, $v) = $r; ?>
            <tr><td><?php echo $date !== $prev ? $d($date, 'd/m/Y') : ''; $prev = $date; ?></td><td><?php echo $e($s); ?></td>
            <?php foreach (array('spo2', 'temp', 'hr', 'rr', 'bp') as $k) { ?><td><?php echo $e(isset($v[$k]) ? $v[$k] : ''); ?></td><?php } ?></tr>
        <?php } ?>
        </table></div>
    <?php }

    $section('Condition at discharge', $sheet['condition_at_discharge']);
    if ($rx) { ?>
    <div class="sec"><div class="sec-t">Medication on discharge (℞)</div>
        <table class="grid"><tr><th>#</th><th class="l">Medicine</th><th>Dose</th><th>Frequency</th><th>When</th><th>Duration</th><th class="l">Instruction</th></tr>
        <?php foreach ($rx as $i => $r) { ?>
            <tr><td><?php echo $i + 1; ?></td><td class="l"><b><?php echo $e($r['drug']); ?></b></td><td><?php echo $e($r['dose']); ?></td><td><?php echo $e($r['frequency']); ?></td><td><?php echo $e($r['timing']); ?></td><td><?php echo $e($r['duration']); ?></td><td class="l"><?php echo $e($r['instruction']); ?></td></tr>
        <?php } ?>
        </table></div>
    <?php }
    $section('Advice on discharge', trim($sheet['discharge_advice'] . ($sheet['diet_advice'] ? "\nDiet: " . $sheet['diet_advice'] : '')));
    $section('Come back immediately if', $sheet['emergency_note']);
    $section('Follow-up', implode(' — ', array_filter(array($d($sheet['follow_up_date'], 'l, d-m-Y'), (string) $sheet['follow_up_note']))));
    $signatures('<div class="sig-line"></div>Patient / relative<br><small>I have understood the advice above</small>', $doctor_sign());
    ?>
    <div class="footer-note">This summary is computer-generated from the IPD case sheet of <?php echo $e($hosp['name']); ?>. Please bring it on every follow-up visit.</div>
</div>

<?php } elseif ($kind === 'prescription') { $rx = $sheet['discharge_rx']; ?>
<!-- ======================= DISCHARGE PRESCRIPTION ======================= -->
<div class="page">
    <?php $letterhead(); ?>
    <table class="pt">
        <tr><th>Name</th><td><strong><?php echo $e($ctx['patient_name']); ?></strong></td><th>Age / Sex</th><td><?php echo $e($age . ' / ' . $ctx['gender']); ?></td><th>Date</th><td><?php echo $e($d($discharge_at ?: date('Y-m-d'))); ?></td></tr>
        <tr><th>IPD No.</th><td><?php echo $e($ipd_no); ?></td><th>Consultant</th><td><?php echo $e($ctx['doctor']); ?></td><th>Phone</th><td><?php echo $e($ctx['mobileno']); ?></td></tr>
        <?php if ($ctx['allergies']) { ?><tr><th>Allergies</th><td colspan="5" class="<?php echo $ctx['has_allergy'] ? 'allergy' : ''; ?>"><?php echo $e($ctx['allergies']); ?></td></tr><?php } ?>
    </table>
    <?php $dx = $sheet['final_diagnosis'] ?: $sheet['provisional_diagnosis']; if ($dx) { ?><div class="sec"><b>Diagnosis:</b> <?php echo $e($dx); ?></div><?php } ?>
    <div class="rx-sym">℞</div>
    <?php if ($rx) { ?>
    <table class="rx">
        <tr><th class="n"></th><th>Medicine</th><th>Dose</th><th>Frequency</th><th>When</th><th>Duration</th></tr>
        <?php foreach ($rx as $i => $r) { ?>
            <tr><td class="n"><?php echo $i + 1; ?>.</td>
                <td><div class="drug"><?php echo $e($r['drug']); ?></div><?php if ($r['instruction']) { ?><div class="muted"><?php echo $e($r['instruction']); ?></div><?php } ?></td>
                <td><?php echo $e($r['dose']); ?></td><td><b><?php echo $e($r['frequency']); ?></b></td><td><?php echo $e($r['timing']); ?></td><td><?php echo $e($r['duration']); ?></td></tr>
        <?php } ?>
    </table>
    <?php } else { ?><p class="muted">No discharge medicines entered.</p><?php }
    $section('Advice', trim($sheet['discharge_advice'] . ($sheet['diet_advice'] ? "\nDiet: " . $sheet['diet_advice'] : '')));
    $section('Come back immediately if', $sheet['emergency_note']);
    if ($sheet['follow_up_date'] || $sheet['follow_up_note']) { ?>
        <div class="sec"><b>Follow-up:</b> <?php echo $e(trim(($sheet['follow_up_date'] ? $d($sheet['follow_up_date'], 'd-m-Y') : '') . ' ' . $sheet['follow_up_note'])); ?></div>
    <?php }
    $signatures('', $doctor_sign()); ?>
    <div class="footer-note">1-0-1 = morning – afternoon – night. Take medicines as advised; do not stop antibiotics early.</div>
</div>

<?php } elseif ($kind === 'consent') {
    $all = include APPPATH . 'views/admin/casesheet/_consent_text.php';
    $t = isset($all[$sheet['consent_language']]) ? $all[$sheet['consent_language']] : $all['mr'];
    $f = $t['fields'];
    $adm = $ctx['admission_date']; ?>
<!-- ======================= ADMISSION FORM & CONSENT ======================= -->
<div class="page consent">
    <?php $letterhead(); ?>
    <div class="doc-title"><span><?php echo $sheet['consent_language'] === 'en' ? 'Admission Form' : ($sheet['consent_language'] === 'hi' ? 'भर्ती फ़ॉर्म' : 'भरती फॉर्म'); ?></span></div>
    <div class="f"><b><?php echo $f['name']; ?></b><span><?php echo $e($ctx['patient_name']); ?></span><b><?php echo $f['age']; ?></b><span style="flex:.35"><?php echo $e($age . ' / ' . $ctx['gender']); ?></span><b><?php echo $f['no']; ?></b><span style="flex:.4"><?php echo $e($ipd_no); ?></span></div>
    <div class="f"><b><?php echo $f['address']; ?></b><span><?php echo $e($ctx['address']); ?></span></div>
    <div class="f"><b><?php echo $f['admitted_by']; ?></b><span><?php echo $e(trim($sheet['admitted_by_name'] . ($sheet['admitted_by_relation'] ? ' (' . $sheet['admitted_by_relation'] . ')' : ''))); ?></span><b><?php echo $f['phone']; ?></b><span style="flex:.6"><?php echo $e($sheet['admitted_by_phone'] ?: $ctx['mobileno']); ?></span></div>
    <div class="f"><b><?php echo $f['doctor']; ?></b><span><?php echo $e($ctx['doctor']); ?></span></div>
    <div class="f"><b><?php echo $f['adm_date']; ?></b><span><?php echo $e($d($adm)); ?></span><b><?php echo $f['time']; ?></b><span><?php echo $e($d($adm, 'h:i A')); ?></span>
        <b><?php echo $f['dis_date']; ?></b><span><?php echo $e($d($discharge_at)); ?></span><b><?php echo $f['time']; ?></b><span><?php echo $e($d($discharge_at, 'h:i A')); ?></span></div>
    <p class="ctr" style="font-weight:600;font-size:12px"><?php echo $t['reserve']; ?></p>
    <div class="ctr">* <?php echo $t['title']; ?> *</div>
    <ol>
        <?php foreach ($t['points'] as $p) { ?><li><?php echo str_replace('{patient}', $e($ctx['patient_name']), $p); ?></li><?php } ?>
    </ol>
    <?php foreach ($t['paras'] as $p) { ?><p>&emsp;<?php echo $p; ?></p><?php } ?>
    <table class="sig" style="margin-top:26px">
        <tr>
            <td style="text-align:left">
                <div class="f"><b><?php echo $t['witness']; ?> 1</b><span><?php echo $e($sheet['consent_witness1']); ?></span></div>
                <div class="f"><b><?php echo $t['witness']; ?> 2</b><span><?php echo $e($sheet['consent_witness2']); ?></span></div>
                <div class="f"><b><?php echo $f['adm_date']; ?></b><span><?php echo $e($d($sheet['consent_at'] ?: $adm, 'd-m-Y h:i A')); ?></span></div>
            </td>
            <td style="text-align:left">
                <div class="f"><b><?php echo $t['sign']; ?></b><span></span></div>
                <div style="text-align:right;font-size:11px"><?php echo $t['signer']; ?></div>
                <div class="f"><b><?php echo $t['signame']; ?></b><span><?php echo $e($sheet['consent_signed_by'] ?: $sheet['admitted_by_name']); ?></span></div>
            </td>
        </tr>
    </table>
</div>

<?php } elseif ($kind === 'nursing') {
    if (!$charts) { ?><div class="page"><?php $letterhead(); ?><p class="muted">No nursing chart entries yet.</p></div><?php }
    foreach ($charts as $date => $c) { $v = $c['vitals']; $io = $c['io']; ?>
<!-- ======================= NURSING CHART (one page per day) ======================= -->
<div class="page">
    <?php $letterhead(); ?>
    <div class="doc-title"><span>Nursing Chart</span></div>
    <table class="pt">
        <tr><th>Name</th><td><strong><?php echo $e($ctx['patient_name']); ?></strong></td><th>Age / Sex</th><td><?php echo $e($age . ' / ' . $ctx['gender']); ?></td><th>Date</th><td><strong><?php echo $d($date); ?></strong></td></tr>
        <tr><th>IPD No.</th><td><?php echo $e($ipd_no); ?></td><th>Bed</th><td><?php echo $e($ctx['bed']); ?></td><th>Consultant</th><td><?php echo $e($ctx['doctor']); ?></td></tr>
    </table>
    <table style="margin-top:6px"><tr>
        <td style="width:62%;vertical-align:top;padding-right:6px">
            <table class="grid"><tr class="dark"><th class="l">Special instructions</th></tr><tr><td class="l" style="height:42px"><?php echo $nl($c['special_instructions']); ?></td></tr></table>
        </td>
        <td style="vertical-align:top">
            <?php $tot_in = 0; $tot_out = ''; foreach ($io as $sh) { $t = Casesheet_model::io_totals($sh); $tot_in += (float) $t['in']; } ?>
            <table class="grid"><tr class="dark"><th colspan="2">Last 24 hrs</th></tr>
                <tr><th class="l">Input</th><td><?php echo $tot_in ? $tot_in . ' ml' : ''; ?></td></tr>
                <tr><th class="l">Output</th><td><?php $outs = array(); foreach ($io as $sh) { $t = Casesheet_model::io_totals($sh); if ($t['out'] !== '') { $outs[] = $t['out']; } } echo $e(implode(' + ', $outs)); ?></td></tr></table>
        </td></tr></table>

    <table style="margin-top:6px"><tr>
        <td style="width:62%;vertical-align:top;padding-right:6px">
            <table class="grid"><tr class="dark"><th colspan="<?php echo count(Casesheet_model::$slots) + 1; ?>">Vitals</th></tr>
                <tr><th class="l">Parameter</th><?php foreach (Casesheet_model::$slots as $s) { ?><th><?php echo substr($s, 0, 5); ?></th><?php } ?></tr>
                <?php foreach (Casesheet_model::$vitals as $k => $l) { ?>
                    <tr><th class="l"><?php echo $e($l); ?></th><?php foreach (Casesheet_model::$slots as $s) { ?><td><?php echo $e(isset($v[$s][$k]) ? $v[$s][$k] : ''); ?></td><?php } ?></tr>
                <?php } ?>
            </table>
        </td>
        <td style="vertical-align:top">
            <table class="grid"><tr class="dark"><th colspan="3">Doses of insulin</th></tr><tr><th>Time</th><th>RBS</th><th>Dose</th></tr>
                <?php $ins = $c['insulin']; for ($i = 0; $i < max(4, count($ins)); $i++) { $r = isset($ins[$i]) ? $ins[$i] : array('time' => '', 'rbs' => '', 'dose' => ''); ?>
                    <tr><td>&nbsp;<?php echo $e($r['time']); ?></td><td><?php echo $e($r['rbs']); ?></td><td><?php echo $e($r['dose']); ?></td></tr>
                <?php } ?></table>
        </td></tr></table>

    <table style="margin-top:6px"><tr>
        <td style="width:50%;vertical-align:top;padding-right:6px">
            <table class="grid"><tr class="dark"><th class="l">Investigations</th></tr><tr><td class="l" style="height:70px;vertical-align:top">
                <?php foreach (array_filter(array_map('trim', preg_split('/\n+/', (string) $c['investigations']))) as $i) { echo '– ' . $e(ltrim($i, '- ')) . '<br>'; } ?></td></tr></table>
        </td>
        <td style="vertical-align:top">
            <table class="grid"><tr class="dark"><th colspan="3">Stat drugs</th></tr><tr><th class="l">Drug</th><th>Dose</th><th>Time</th></tr>
                <?php $sd = $c['stat_drugs']; for ($i = 0; $i < max(3, count($sd)); $i++) { $r = isset($sd[$i]) ? $sd[$i] : array('drug' => '', 'dose' => '', 'time' => ''); ?>
                    <tr><td class="l">&nbsp;<?php echo $e($r['drug']); ?></td><td><?php echo $e($r['dose']); ?></td><td><?php echo $e($r['time']); ?></td></tr>
                <?php } ?></table>
        </td></tr></table>

    <table class="grid" style="margin-top:6px"><tr class="dark"><th class="l">IV fluid + drug</th><th>Quantity</th><th>Flow rate</th><th>Started</th><th>Finished</th></tr>
        <?php $iv = $c['iv_fluids']; for ($i = 0; $i < max(4, count($iv)); $i++) { $r = isset($iv[$i]) ? $iv[$i] : array('fluid' => '', 'quantity' => '', 'flowrate' => '', 'started' => '', 'finished' => ''); ?>
            <tr><td class="l">&nbsp;<?php echo $e($r['fluid']); ?></td><td><?php echo $e($r['quantity']); ?></td><td><?php echo $e($r['flowrate']); ?></td><td><?php echo $e($r['started']); ?></td><td><?php echo $e($r['finished']); ?></td></tr>
        <?php } ?></table>

    <table class="grid" style="margin-top:6px">
        <tr class="dark"><th rowspan="2" class="l">I.O. chart</th><th colspan="3">Intake</th><th colspan="5">Output</th></tr>
        <tr><th>IVF</th><th>RT/Oral</th><th>Total</th><th>U.O.</th><th>R.T.A.</th><th>Drain 1</th><th>Drain 2</th><th>Total</th></tr>
        <?php foreach (Casesheet_model::$io_shifts as $sh => $label) { $r = isset($io[$sh]) ? $io[$sh] : array(); $t = Casesheet_model::io_totals($r);
            $g = function ($k) use ($r, $e) { return $e(isset($r[$k]) ? $r[$k] : ''); }; ?>
            <tr><th class="l"><?php echo $label; ?></th><td><?php echo $g('ivf'); ?></td><td><?php echo $g('oral'); ?></td><td><?php echo $e($t['in']); ?></td>
                <td><?php echo $g('uo'); ?></td><td><?php echo $g('rta'); ?></td><td><?php echo $g('drain1'); ?></td><td><?php echo $g('drain2'); ?></td><td><?php echo $e($t['out']); ?></td></tr>
        <?php } ?>
    </table>
    <?php $signatures('<div class="sig-line"></div>Staff nurse', '<div class="sig-line"></div>Duty doctor'); ?>
</div>
    <?php }

} elseif ($kind === 'medchart') {
    $pages = $med_dates ? array_chunk($med_dates, 7) : array(array());
    foreach ($pages as $pi => $dates) { ?>
<!-- ======================= MEDICATION CHART (7 days per page) ======================= -->
<div class="page">
    <?php $letterhead(); ?>
    <table class="pt">
        <tr><th>Name</th><td><strong><?php echo $e($ctx['patient_name']); ?></strong></td><th>Age / Sex</th><td><?php echo $e($age . ' / ' . $ctx['gender']); ?></td><th>IPD No.</th><td><?php echo $e($ipd_no); ?></td><th>Bed</th><td><?php echo $e($ctx['bed']); ?></td></tr>
        <tr><th>Consultant</th><td><?php echo $e($ctx['doctor']); ?></td><th>Admitted</th><td><?php echo $e($d($ctx['admission_date'], 'd-m-Y h:i A')); ?></td><th>Allergies</th><td colspan="3" class="<?php echo $ctx['has_allergy'] ? 'allergy' : ''; ?>"><?php echo $e($ctx['allergies'] ?: 'None recorded'); ?></td></tr>
    </table>
    <div class="doc-title" style="margin-top:8px"><span>Regular prescription / medicated drugs</span></div>
    <?php if (!$meds) { ?><p class="muted">No drug orders.</p><?php } else { ?>
    <table class="grid mc">
        <tr><th>#</th><th class="l">Drug</th><th>Dose</th><th>Route</th><th>Time</th><th>Dr. sign</th>
            <?php foreach ($dates as $dt) { ?><th><?php echo $d($dt, 'd/m'); ?></th><?php } ?></tr>
        <?php foreach ($meds as $i => $m) { ?>
            <tr><td><?php echo $i + 1; ?></td>
                <td class="drug"><?php echo $e($m['drug']); ?><?php if ($m['instruction']) { ?><div class="muted" style="font-weight:400"><?php echo $e($m['instruction']); ?></div><?php } ?></td>
                <td><?php echo $e($m['dose']); ?></td><td><?php echo $e($m['route']); ?></td><td><?php echo $e($m['frequency']); ?></td>
                <td style="font-size:9.5px"><?php echo $e(trim($m['staff_name'] . ' ' . $m['staff_surname'])); ?></td>
                <?php foreach ($dates as $dt) { $active = $dt >= $m['start_date'] && (!$m['stop_date'] || $dt <= $m['stop_date']);
                    if (!$active) { echo '<td class="off">—</td>'; continue; }
                    $slots = $m['slot_list'];
                    foreach (isset($m['given'][$dt]) ? array_keys($m['given'][$dt]) : array() as $gs) { if (!in_array($gs, $slots, true)) { $slots[] = $gs; } }
                    sort($slots);
                    echo '<td class="t">';
                    foreach ($slots as $s) {
                        $g = isset($m['given'][$dt][$s]);
                        $at = strtotime($dt . ' ' . $s);
                        $out_of_stay = $at < strtotime($ctx['admission_date']) || ($discharge_at && $at > strtotime($discharge_at));
                        echo $g ? '<span class="tick">✓ ' . date('gA', $at) . '</span><br>'
                            : ($out_of_stay ? '' : ($at < time() ? '<span class="miss">' . date('gA', $at) . ' ✗</span><br>' : '<span class="muted">' . date('gA', $at) . '</span><br>'));
                    }
                    echo $slots ? '' : 'SOS';
                    echo '</td>';
                } ?>
            </tr>
        <?php } ?>
    </table>
    <p class="muted" style="margin-top:4px">✓ given &nbsp; ✗ due but not charted &nbsp; — not prescribed that day. Page <?php echo $pi + 1; ?> of <?php echo count($pages); ?>.</p>
    <?php } ?>
    <?php $signatures('<div class="sig-line"></div>Staff nurse', '<div class="sig-line"></div>Treating doctor'); ?>
</div>
    <?php }
} ?>
</body>
</html>
