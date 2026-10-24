<?php
$e = function ($v) {
    return html_escape((string) $v);
};
$ipd = (int) $ctx['ipd_id'];
$base = site_url('admin/casesheet/');
$age = Casesheet_model::short_age($ctx);
$dt_local = function ($v) {
    return $v ? date('Y-m-d\TH:i', strtotime($v)) : '';
};
$ro = function ($area) use ($can) {
    return empty($can[$area]) ? 'disabled' : '';
};
$print = function ($kind, $label, $extra = '') use ($base, $ipd) {
    return '<a class="btn btn-sm btn-outline-secondary" target="_blank" href="' . $base . 'printout/' . $kind . '/' . $ipd . $extra . '"><i class="fa fa-print"></i> ' . $label . '</a>';
};
?>
<link rel="stylesheet" href="<?php echo base_url('backend/casesheet/casesheet.css'); ?>">
<div class="content-wrapper">
<section class="content" id="cs-root" data-base="<?php echo $base; ?>" data-ipd="<?php echo $ipd; ?>" data-token="<?php echo $e($cs_token); ?>" data-medurl="<?php echo site_url('admin/smartrx/medicines'); ?>">

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <div>
            <h4 class="mb-1"><?php echo $e($ctx['patient_name']); ?> <small class="text-muted"><?php echo $e($age); ?> / <?php echo $e($ctx['gender']); ?></small></h4>
            <div class="text-muted small">
                <?php echo $e($this->customlib->getSessionPrefixByType('ipd_no') . $ipd); ?> · Case <?php echo $e($ctx['case_reference_id']); ?>
                · Admitted <?php echo $e(date('d-m-Y h:i A', strtotime($ctx['admission_date']))); ?>
                <?php if ($ctx['doctor']) { ?> · <?php echo $e($ctx['doctor']); ?><?php } ?>
                <?php if ($ctx['bed']) { ?> · <?php echo $e($ctx['bed']); ?><?php } ?>
            </div>
            <?php if ($ctx['allergies']) { ?><div class="mt-1"><span class="badge <?php echo $ctx['has_allergy'] ? 'bg-danger' : 'bg-secondary'; ?>">Allergy: <?php echo $e($ctx['allergies']); ?></span></div><?php } ?>
        </div>
        <div class="ms-auto d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-light" href="<?php echo site_url('admin/patient/ipdprofile/' . $ipd); ?>"><i class="fa fa-arrow-left"></i> IPD profile</a>
            <?php echo $print('summary', 'Discharge summary'); ?>
            <?php echo $print('prescription', 'Discharge Rx'); ?>
        </div>
    </div>
</div>

<?php if ($msg) { ?><div class="alert alert-<?php echo $e($msg[0]); ?> py-2"><?php echo $msg[1]; ?></div><?php } ?>

<ul class="nav nav-tabs mb-3">
    <?php foreach ($tabs as $k => $label) { ?>
        <li class="nav-item"><a class="nav-link <?php echo $tab === $k ? 'active' : ''; ?>" href="<?php echo $base . 'index/' . $ipd . '/' . $k; ?>"><?php echo $e($label); ?></a></li>
    <?php } ?>
</ul>

<?php if ($tab === 'admission') { ?>
<!-- ================= Admission & consent ================= -->
<form method="post" action="<?php echo $base . 'save_admission/' . $ipd; ?>" class="card">
    <input type="hidden" name="cs_token" value="<?php echo $e($cs_token); ?>">
    <div class="card-header d-flex align-items-center"><h3 class="card-title mb-0">Admission form &amp; consent (अनुमती पत्र)</h3>
        <div class="ms-auto"><?php echo $print('consent', 'Print admission & consent'); ?></div></div>
    <div class="card-body">
        <fieldset <?php echo $ro('admission'); ?>>
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Admitted by (रुग्णास भरती करणाऱ्याचे नाव)</label>
                <input class="form-control" name="admitted_by_name" value="<?php echo $e($sheet['admitted_by_name'] ?: $ctx['guardian_name']); ?>"></div>
            <div class="col-md-2"><label class="form-label">Relation</label>
                <input class="form-control" name="admitted_by_relation" list="cs-relations" value="<?php echo $e($sheet['admitted_by_relation']); ?>">
                <datalist id="cs-relations"><option>Father</option><option>Mother</option><option>Brother</option><option>Sister</option><option>Spouse</option><option>Son</option><option>Daughter</option><option>Self</option><option>Friend</option></datalist></div>
            <div class="col-md-3"><label class="form-label">Phone (फोन नं.)</label>
                <input class="form-control" name="admitted_by_phone" value="<?php echo $e($sheet['admitted_by_phone']); ?>"></div>
            <div class="col-md-3"><label class="form-label">Consent form language</label>
                <select class="form-select" name="consent_language">
                    <?php foreach (array('mr' => 'मराठी (Marathi)', 'hi' => 'हिंदी (Hindi)', 'en' => 'English') as $k => $l) { ?>
                        <option value="<?php echo $k; ?>" <?php echo $sheet['consent_language'] === $k ? 'selected' : ''; ?>><?php echo $l; ?></option>
                    <?php } ?>
                </select></div>
            <div class="col-md-4"><label class="form-label">Consent signed by (सही — रुग्णाची / भरती करणाऱ्याची)</label>
                <input class="form-control" name="consent_signed_by" value="<?php echo $e($sheet['consent_signed_by']); ?>"></div>
            <div class="col-md-3"><label class="form-label">Witness 1 (साक्षीदार)</label>
                <input class="form-control" name="consent_witness1" value="<?php echo $e($sheet['consent_witness1']); ?>"></div>
            <div class="col-md-3"><label class="form-label">Witness 2</label>
                <input class="form-control" name="consent_witness2" value="<?php echo $e($sheet['consent_witness2']); ?>"></div>
            <div class="col-md-2"><label class="form-label">Consent date &amp; time</label>
                <input class="form-control" type="datetime-local" name="consent_at" value="<?php echo $e($dt_local($sheet['consent_at'] ?: $ctx['admission_date'])); ?>"></div>
        </div>
        </fieldset>
        <p class="text-muted small mt-3 mb-0">Patient name, age, address and admission time come from the IPD admission. The printed form carries the full consent text (general treatment, investigations, specialist opinion, anaesthesia &amp; operation, expense) for signature.</p>
    </div>
    <?php if ($can['admission']) { ?><div class="card-footer text-end"><button class="btn btn-primary">Save</button></div><?php } ?>
</form>

<?php } elseif ($tab === 'notes') { $av = $sheet['admission_vitals']; ?>
<!-- ================= Doctor's notes ================= -->
<form method="post" action="<?php echo $base . 'save_notes/' . $ipd; ?>" class="card">
    <input type="hidden" name="cs_token" value="<?php echo $e($cs_token); ?>">
    <div class="card-header"><h3 class="card-title mb-0">Doctor's admission notes</h3></div>
    <div class="card-body">
        <fieldset <?php echo $ro('notes'); ?>>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Chief complaints (C/O)</label>
                <textarea class="form-control" name="chief_complaints" rows="3" placeholder="Pain &amp; itching all over body"><?php echo $e($sheet['chief_complaints'] ?: $ctx['symptoms']); ?></textarea></div>
            <div class="col-md-6"><label class="form-label">History (H/O)</label>
                <textarea class="form-control" name="history" rows="3" placeholder="Fall from bike 8 days back"><?php echo $e($sheet['history']); ?></textarea></div>
            <div class="col-12"><label class="form-label">Vitals on admission (O/E)</label>
                <div class="row g-2">
                    <?php foreach (array('pulse' => 'Pulse (/min)', 'bp' => 'BP (mmHg)', 'temp' => 'Temp (°F)', 'spo2' => 'SpO2 (%)', 'rr' => 'RR (/min)', 'weight' => 'Weight (kg)', 'rbs' => 'RBS (mg/dl)') as $k => $l) { ?>
                        <div class="col-6 col-md"><div class="form-floating">
                            <input class="form-control" id="av-<?php echo $k; ?>" name="admission_vitals[<?php echo $k; ?>]" placeholder="<?php echo $l; ?>" value="<?php echo $e(isset($av[$k]) ? $av[$k] : ''); ?>">
                            <label for="av-<?php echo $k; ?>"><?php echo $l; ?></label></div></div>
                    <?php } ?>
                </div></div>
            <div class="col-md-6"><label class="form-label">Examination findings</label>
                <textarea class="form-control" name="examination" rows="3" placeholder="Abrasion wound over face &amp; forearm — healing"><?php echo $e($sheet['examination']); ?></textarea></div>
            <div class="col-md-6"><label class="form-label">Lab findings</label>
                <textarea class="form-control" name="lab_findings" rows="3" placeholder="WBC 14,900"><?php echo $e($sheet['lab_findings']); ?></textarea></div>
            <div class="col-md-6"><label class="form-label">Provisional diagnosis</label>
                <textarea class="form-control" name="provisional_diagnosis" rows="2"><?php echo $e($sheet['provisional_diagnosis']); ?></textarea></div>
            <div class="col-md-6"><label class="form-label">Advice / initial orders</label>
                <textarea class="form-control" name="initial_advice" rows="2" placeholder="Soft diet. Admit."><?php echo $e($sheet['initial_advice']); ?></textarea></div>
        </div>
        </fieldset>
        <p class="text-muted small mt-3 mb-0">Drug orders go on the <a href="<?php echo $base . 'index/' . $ipd . '/medication'; ?>">Medication Chart</a> so nurses can chart each dose.</p>
    </div>
    <?php if ($can['notes']) { ?><div class="card-footer text-end"><button class="btn btn-primary">Save</button></div><?php } ?>
</form>

<?php } elseif ($tab === 'nursing') { $v = $chart['vitals']; $io = $chart['io']; ?>
<!-- ================= Nursing chart ================= -->
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <form method="get" action="<?php echo $base . 'index/' . $ipd . '/nursing'; ?>" class="d-flex gap-2 align-items-center">
        <label class="form-label mb-0">Chart date</label>
        <input type="date" class="form-control form-control-sm" name="date" value="<?php echo $e($chart_date); ?>" onchange="this.form.submit()">
    </form>
    <?php foreach ($chart_dates as $d) { ?>
        <a class="btn btn-sm <?php echo $d === $chart_date ? 'btn-primary' : 'btn-outline-primary'; ?>" href="<?php echo $base . 'index/' . $ipd . '/nursing?date=' . $d; ?>"><?php echo date('d M', strtotime($d)); ?></a>
    <?php } ?>
    <div class="ms-auto d-flex gap-2"><?php echo $print('nursing', 'Print this day', '/' . $chart_date); ?><?php echo $print('nursing', 'Print all days'); ?></div>
</div>
<form method="post" action="<?php echo $base . 'save_chart/' . $ipd; ?>" class="card">
    <input type="hidden" name="cs_token" value="<?php echo $e($cs_token); ?>">
    <input type="hidden" name="chart_date" value="<?php echo $e($chart_date); ?>">
    <div class="card-body">
    <fieldset <?php echo $ro('nursing'); ?>>
        <div class="row g-3">
            <div class="col-lg-7">
                <h6 class="cs-h">Vitals — <?php echo date('d-m-Y', strtotime($chart_date)); ?></h6>
                <div class="table-responsive"><table class="table table-sm table-bordered cs-grid mb-0">
                    <thead><tr><th>Parameter</th><?php foreach (Casesheet_model::$slots as $s) { ?><th><?php echo $s; ?></th><?php } ?></tr></thead>
                    <tbody>
                    <?php foreach (Casesheet_model::$vitals as $k => $l) { ?>
                        <tr><th><?php echo $e($l); ?></th>
                        <?php foreach (Casesheet_model::$slots as $s) { ?>
                            <td><input class="form-control form-control-sm" name="vitals[<?php echo $s; ?>][<?php echo $k; ?>]" value="<?php echo $e(isset($v[$s][$k]) ? $v[$s][$k] : ''); ?>"<?php echo $k === 'avpu' ? ' list="cs-avpu"' : ($k === 'motion' ? ' list="cs-yesno"' : ''); ?>></td>
                        <?php } ?></tr>
                    <?php } ?>
                    </tbody>
                </table></div>
                <datalist id="cs-avpu"><option>A</option><option>V</option><option>P</option><option>U</option></datalist>
                <datalist id="cs-yesno"><option>Yes</option><option>No</option></datalist>
            </div>
            <div class="col-lg-5">
                <h6 class="cs-h">Special instructions</h6>
                <textarea class="form-control mb-3" name="special_instructions" rows="2"><?php echo $e($chart['special_instructions']); ?></textarea>
                <h6 class="cs-h">Investigations</h6>
                <textarea class="form-control mb-3" name="investigations" rows="3" placeholder="One per line, e.g. CBC"><?php echo $e($chart['investigations']); ?></textarea>
                <h6 class="cs-h">Doses of insulin</h6>
                <?php $this->load->view('admin/casesheet/_rows', array('name' => 'insulin', 'rows' => $chart['insulin'], 'cols' => array('time' => 'Time', 'rbs' => 'RBS', 'dose' => 'Dose'), 'min' => 1)); ?>
            </div>
            <div class="col-lg-5">
                <h6 class="cs-h">Stat drugs</h6>
                <?php $this->load->view('admin/casesheet/_rows', array('name' => 'stat_drugs', 'rows' => $chart['stat_drugs'], 'cols' => array('drug' => 'Drug', 'dose' => 'Dose', 'time' => 'Time'), 'min' => 2)); ?>
            </div>
            <div class="col-lg-7">
                <h6 class="cs-h">IV fluid + drug</h6>
                <?php $this->load->view('admin/casesheet/_rows', array('name' => 'iv_fluids', 'rows' => $chart['iv_fluids'], 'cols' => array('fluid' => 'IV fluid + drug', 'quantity' => 'Quantity', 'flowrate' => 'Flow rate', 'started' => 'Started', 'finished' => 'Finished'), 'min' => 2)); ?>
            </div>
            <div class="col-12">
                <h6 class="cs-h">I.O. chart</h6>
                <div class="table-responsive"><table class="table table-sm table-bordered cs-grid mb-0">
                    <thead>
                        <tr><th rowspan="2"></th><th colspan="3" class="text-center">Intake</th><th colspan="5" class="text-center">Output</th></tr>
                        <tr><th>IVF</th><th>RT/Oral</th><th>Total</th><th>U.O.</th><th>R.T.A.</th><th>Drain 1</th><th>Drain 2</th><th>Total</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach (Casesheet_model::$io_shifts as $sh => $label) { $row = isset($io[$sh]) ? $io[$sh] : array(); $tot = Casesheet_model::io_totals($row); ?>
                        <tr><th><?php echo $label; ?></th>
                            <?php foreach (array('ivf', 'oral') as $f) { ?><td><input class="form-control form-control-sm" name="io[<?php echo $sh; ?>][<?php echo $f; ?>]" value="<?php echo $e(isset($row[$f]) ? $row[$f] : ''); ?>" placeholder="ml"></td><?php } ?>
                            <td class="text-muted small align-middle"><?php echo $e($tot['in']); ?></td>
                            <?php foreach (array('uo', 'rta', 'drain1', 'drain2') as $f) { ?><td><input class="form-control form-control-sm" name="io[<?php echo $sh; ?>][<?php echo $f; ?>]" value="<?php echo $e(isset($row[$f]) ? $row[$f] : ''); ?>"></td><?php } ?>
                            <td class="text-muted small align-middle"><?php echo $e($tot['out']); ?></td></tr>
                    <?php } ?>
                    </tbody>
                </table></div>
                <div class="form-text">Enter volumes in ml (e.g. 200) — totals add up on save. Urine output can also be a count, e.g. "2 times".</div>
            </div>
        </div>
    </fieldset>
    </div>
    <?php if ($can['nursing']) { ?><div class="card-footer text-end"><button class="btn btn-primary">Save chart for <?php echo date('d-m-Y', strtotime($chart_date)); ?></button></div><?php } ?>
</form>

<?php } elseif ($tab === 'medication') { ?>
<!-- ================= Medication chart ================= -->
<?php if ($can['medication']) { ?>
<form method="post" action="<?php echo $base . 'add_med/' . $ipd; ?>" class="card mb-3">
    <input type="hidden" name="cs_token" value="<?php echo $e($cs_token); ?>">
    <div class="card-header"><h3 class="card-title mb-0">Add drug order</h3></div>
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label">Drug</label>
                <input class="form-control cs-med" name="drug" list="cs-medlist" required placeholder="Inj. Meropenem" autocomplete="off"><datalist id="cs-medlist"></datalist></div>
            <div class="col-md-1"><label class="form-label">Dose</label><input class="form-control" name="dose" placeholder="1 gm"></div>
            <div class="col-md-1"><label class="form-label">Route</label>
                <input class="form-control" name="route" list="cs-routes" placeholder="IV">
                <datalist id="cs-routes"><option>IV</option><option>IM</option><option>SC</option><option>Oral</option><option>Topical</option><option>Neb</option><option>SL</option><option>PR</option></datalist></div>
            <div class="col-md-1"><label class="form-label">Frequency</label>
                <select class="form-select cs-freq" name="frequency">
                    <?php foreach (Casesheet_model::$freq_slots as $f => $s) { ?><option value="<?php echo $f; ?>" data-slots="<?php echo implode(',', $s); ?>" <?php echo $f === 'BD' ? 'selected' : ''; ?>><?php echo $f; ?></option><?php } ?>
                </select></div>
            <div class="col-md-2"><label class="form-label">Times</label><input class="form-control cs-slots" name="slots" value="08:00,20:00" placeholder="08:00,20:00"></div>
            <div class="col-md-1"><label class="form-label">Start</label><input class="form-control" type="date" name="start_date" value="<?php echo date('Y-m-d'); ?>" required></div>
            <div class="col-md-1"><label class="form-label">Stop</label><input class="form-control" type="date" name="stop_date"></div>
            <div class="col-md-2"><label class="form-label">Instruction</label><input class="form-control" name="instruction" placeholder="after food / in 100 ml NS"></div>
        </div>
    </div>
    <div class="card-footer text-end"><button class="btn btn-primary">Add to chart</button></div>
</form>
<?php } ?>

<div class="card">
    <div class="card-header d-flex align-items-center"><h3 class="card-title mb-0">Regular prescription / medicated drugs</h3>
        <div class="ms-auto"><?php echo $print('medchart', 'Print medication chart'); ?></div></div>
    <div class="card-body">
    <?php if (!$meds) { ?>
        <p class="text-muted mb-0">No drug orders yet.</p>
    <?php } else { ?>
        <p class="small text-muted"><?php echo $can['medication'] ? 'Click a time to mark the dose given (click again to undo). ' : ''; ?>✓ = given, — = not due.</p>
        <div class="table-responsive"><table class="table table-sm table-bordered cs-medchart">
            <thead><tr><th>#</th><th>Drug</th><th>Dose</th><th>Route</th><th>Freq</th>
                <?php foreach ($med_dates as $d) { ?><th class="text-center"><?php echo date('d/m', strtotime($d)); ?></th><?php } ?><th></th></tr></thead>
            <tbody>
            <?php foreach ($meds as $i => $m) { ?>
                <tr class="<?php echo $m['stop_date'] && $m['stop_date'] < date('Y-m-d') ? 'cs-stopped' : ''; ?>">
                    <td><?php echo $i + 1; ?></td>
                    <td><strong><?php echo $e($m['drug']); ?></strong><?php if ($m['instruction']) { ?><div class="small text-muted"><?php echo $e($m['instruction']); ?></div><?php } ?>
                        <div class="small text-muted"><?php echo date('d/m', strtotime($m['start_date'])); ?><?php echo $m['stop_date'] ? ' → ' . date('d/m', strtotime($m['stop_date'])) : ''; ?><?php echo $m['staff_name'] ? ' · ' . $e($m['staff_name'] . ' ' . $m['staff_surname']) : ''; ?></div></td>
                    <td><?php echo $e($m['dose']); ?></td><td><?php echo $e($m['route']); ?></td><td><?php echo $e($m['frequency']); ?></td>
                    <?php foreach ($med_dates as $d) { $active = $d >= $m['start_date'] && (!$m['stop_date'] || $d <= $m['stop_date']); ?>
                        <td class="cs-doses">
                        <?php if (!$active) { echo '<span class="text-muted">—</span>'; } else {
                            $slots = $m['slot_list'];
                            foreach (isset($m['given'][$d]) ? array_keys($m['given'][$d]) : array() as $gs) {
                                if (!in_array($gs, $slots, true)) { $slots[] = $gs; }
                            }
                            sort($slots);
                            foreach ($slots as $s) { $g = isset($m['given'][$d][$s]);
                                if (!$g && strtotime($d . ' ' . $s) < strtotime($ctx['admission_date'])) { continue; } ?>
                                <button type="button" class="cs-dose <?php echo $g ? 'given' : ''; ?>" <?php echo $can['medication'] ? '' : 'disabled'; ?> data-med="<?php echo (int) $m['id']; ?>" data-date="<?php echo $d; ?>" data-slot="<?php echo $s; ?>"
                                    title="<?php echo $g ? 'Given ' . $e(date('d-m h:i A', strtotime($m['given'][$d][$s]['given_at']))) : 'Not charted'; ?>"><?php echo date('g A', strtotime($s)); ?></button>
                            <?php }
                            if (!$m['slot_list'] && $can['medication']) { ?>
                                <button type="button" class="cs-dose cs-dose-add" data-med="<?php echo (int) $m['id']; ?>" data-date="<?php echo $d; ?>" title="Record a dose (SOS/STAT)">+</button>
                            <?php }
                        } ?>
                        </td>
                    <?php } ?>
                    <td class="text-nowrap">
                    <?php if ($can['medication']) { ?>
                        <form method="post" action="<?php echo $base . 'med_action/' . $ipd; ?>" class="d-inline">
                            <input type="hidden" name="cs_token" value="<?php echo $e($cs_token); ?>"><input type="hidden" name="med_id" value="<?php echo (int) $m['id']; ?>">
                            <?php if (!$m['stop_date']) { ?><button class="btn btn-sm btn-outline-warning" name="action" value="stop" title="Stop from today">Stop</button><?php } ?>
                            <button class="btn btn-sm btn-outline-danger" name="action" value="delete" onclick="return confirm('Remove this drug and all its charted doses?')" title="Remove"><i class="fa fa-trash"></i></button>
                        </form>
                    <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table></div>
    <?php } ?>
    </div>
</div>

<?php } elseif ($tab === 'discharge') { $rx = $sheet['discharge_rx']; $card = $ctx['discharge_card']; ?>
<!-- ================= Discharge summary ================= -->
<form method="post" action="<?php echo $base . 'save_discharge/' . $ipd; ?>" class="card">
    <input type="hidden" name="cs_token" value="<?php echo $e($cs_token); ?>">
    <div class="card-header d-flex align-items-center"><h3 class="card-title mb-0">Discharge summary</h3>
        <div class="ms-auto d-flex gap-2"><?php echo $print('summary', 'Print summary'); ?><?php echo $print('prescription', 'Print discharge Rx'); ?></div></div>
    <div class="card-body">
    <fieldset <?php echo $ro('discharge'); ?>>
        <div class="row g-3">
            <div class="col-md-3"><label class="form-label">Discharge date &amp; time</label>
                <input class="form-control" type="datetime-local" name="discharge_at" value="<?php echo $e($dt_local($sheet['discharge_at'] ?: ($card ? $card['discharge_date'] : ''))); ?>"></div>
            <div class="col-md-9"><label class="form-label">Final diagnosis</label>
                <input class="form-control" name="final_diagnosis" value="<?php echo $e($sheet['final_diagnosis'] ?: ($card ? $card['diagnosis'] : $sheet['provisional_diagnosis'])); ?>"></div>
            <div class="col-md-6"><label class="form-label">Procedures / operation</label>
                <textarea class="form-control" name="procedures" rows="2"><?php echo $e($sheet['procedures'] ?: ($card ? $card['operation'] : '')); ?></textarea></div>
            <div class="col-md-6"><label class="form-label">Condition at discharge</label>
                <textarea class="form-control" name="condition_at_discharge" rows="2" placeholder="Afebrile, vitals stable, wound healing"><?php echo $e($sheet['condition_at_discharge']); ?></textarea></div>
            <div class="col-12"><label class="form-label d-flex">Course in hospital
                <?php if ($can['discharge']) { ?><button type="button" class="btn btn-link btn-sm p-0 ms-auto" id="cs-draft">Draft from case sheet</button><?php } ?></label>
                <textarea class="form-control" name="course_in_hospital" id="cs-course" rows="4"><?php echo $e($sheet['course_in_hospital']); ?></textarea></div>

            <div class="col-12">
                <h6 class="cs-h d-flex align-items-center">Discharge medication (℞)
                    <?php if ($can['discharge']) { ?><button type="button" class="btn btn-link btn-sm ms-auto p-0" id="cs-rx-from-chart">Copy oral drugs from medication chart</button><?php } ?></h6>
                <div class="table-responsive"><table class="table table-sm cs-rows" data-name="rx" id="cs-rx">
                    <thead><tr><th style="width:28%">Medicine</th><th>Dose</th><th>Frequency</th><th>When</th><th>Duration</th><th>Instruction</th><th></th></tr></thead>
                    <tbody>
                    <?php $rows = $rx ?: array(); while (count($rows) < 3) { $rows[] = array(); }
                    foreach ($rows as $i => $r) { ?>
                        <tr>
                            <td><input class="form-control form-control-sm cs-med" list="cs-medlist" autocomplete="off" name="rx[<?php echo $i; ?>][drug]" value="<?php echo $e(isset($r['drug']) ? $r['drug'] : ''); ?>"></td>
                            <td><input class="form-control form-control-sm" name="rx[<?php echo $i; ?>][dose]" value="<?php echo $e(isset($r['dose']) ? $r['dose'] : ''); ?>" placeholder="1 tab"></td>
                            <td><input class="form-control form-control-sm" list="cs-rxfreq" name="rx[<?php echo $i; ?>][frequency]" value="<?php echo $e(isset($r['frequency']) ? $r['frequency'] : ''); ?>" placeholder="1-0-1"></td>
                            <td><input class="form-control form-control-sm" list="cs-rxtiming" name="rx[<?php echo $i; ?>][timing]" value="<?php echo $e(isset($r['timing']) ? $r['timing'] : ''); ?>"></td>
                            <td><input class="form-control form-control-sm" list="cs-rxdur" name="rx[<?php echo $i; ?>][duration]" value="<?php echo $e(isset($r['duration']) ? $r['duration'] : ''); ?>" placeholder="5 days"></td>
                            <td><input class="form-control form-control-sm" name="rx[<?php echo $i; ?>][instruction]" value="<?php echo $e(isset($r['instruction']) ? $r['instruction'] : ''); ?>"></td>
                            <td><button type="button" class="btn btn-sm btn-link text-danger cs-row-del">&times;</button></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table></div>
                <button type="button" class="btn btn-sm btn-outline-primary cs-row-add" data-target="#cs-rx">+ Add medicine</button>
                <datalist id="cs-medlist"></datalist>
                <datalist id="cs-rxfreq"><option>1-0-1</option><option>1-1-1</option><option>1-0-0</option><option>0-0-1</option><option>0-1-0</option><option>1-1-1-1</option><option>SOS</option></datalist>
                <datalist id="cs-rxtiming"><option>After food</option><option>Before food</option><option>Empty stomach</option><option>At bedtime</option><option>With milk</option></datalist>
                <datalist id="cs-rxdur"><option>3 days</option><option>5 days</option><option>7 days</option><option>10 days</option><option>15 days</option><option>1 month</option><option>Continue</option></datalist>
            </div>

            <div class="col-md-6"><label class="form-label">Advice on discharge</label>
                <textarea class="form-control" name="discharge_advice" rows="3"><?php echo $e($sheet['discharge_advice']); ?></textarea></div>
            <div class="col-md-6"><label class="form-label">When to come back immediately</label>
                <textarea class="form-control" name="emergency_note" rows="3" placeholder="Fever, rash, breathlessness, vomiting"><?php echo $e($sheet['emergency_note']); ?></textarea></div>
            <div class="col-md-4"><label class="form-label">Diet</label>
                <input class="form-control" name="diet_advice" list="cs-diet" value="<?php echo $e($sheet['diet_advice']); ?>">
                <datalist id="cs-diet"><option>Normal diet</option><option>Soft diet</option><option>Liquid diet</option><option>Diabetic diet</option><option>Low salt diet</option></datalist></div>
            <div class="col-md-3"><label class="form-label">Follow-up on</label>
                <input class="form-control" type="date" name="follow_up_date" value="<?php echo $e($sheet['follow_up_date']); ?>"></div>
            <div class="col-md-5"><label class="form-label">Follow-up note</label>
                <input class="form-control" name="follow_up_note" value="<?php echo $e($sheet['follow_up_note']); ?>" placeholder="Review in OPD with CBC report"></div>
        </div>
    </fieldset>
    <?php if ($card === null) { ?>
        <p class="text-muted small mt-3 mb-0">The patient is not yet discharged in Smart Hospital. Use <em>Discharge</em> on the IPD profile to close the admission and bill; this summary prints either way.</p>
    <?php } ?>
    </div>
    <?php if ($can['discharge']) { ?><div class="card-footer text-end"><button class="btn btn-primary">Save discharge summary</button></div><?php } ?>
</form>
<script type="application/json" id="cs-meds-json"><?php
    $oral = array();
    foreach ($this->casesheet_model->meds($ipd) as $m) {
        $oral[] = array('drug' => $m['drug'], 'dose' => $m['dose'], 'route' => $m['route'], 'frequency' => $m['frequency'], 'stopped' => (bool) $m['stop_date']);
    }
    echo json_encode($oral, JSON_HEX_TAG | JSON_HEX_AMP);
?></script>
<?php } ?>

</section>
</div>
<script src="<?php echo base_url('backend/casesheet/casesheet.js'); ?>"></script>
