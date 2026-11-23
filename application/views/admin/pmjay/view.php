<?php $this->load->view('admin/pmjay/_nav');
$can_edit = $this->rbac->hasPrivilege('pmjay_cases', 'can_edit');
$can_nhcx = $this->rbac->hasPrivilege('pmjay_nhcx', 'can_add');
$status = $c['status'];
$step = array_search($status, Pmjay_model::PIPELINE, true);
if ($step === false) { // query / rejected / cancelled: show progress up to the last pipeline stage reached
    $step = -1;
    foreach (array_reverse($events) as $e) { $i = array_search($e['status'], Pmjay_model::PIPELINE, true); if ($i !== false) { $step = max($step, $i); } }
}
$fields = array( // next status => [fields, default amount, amount label]
    'preauth_submitted' => array('amount,tms,remarks', $c['preauth_amount'] ?: $c['package_amount'], 'Pre-auth amount requested (₹)'),
    'preauth_approved'  => array('amount,tms,remarks', $c['preauth_amount'] ?: $c['package_amount'], 'Approved pre-auth amount (₹)'),
    'discharged'        => array('date,remarks', '', ''),
    'claim_submitted'   => array('amount,remarks', $c['preauth_amount'] ?: $c['package_amount'], 'Claim amount (₹)'),
    'claim_approved'    => array('amount,remarks', $c['claim_amount'] ?: $c['preauth_amount'], 'Approved claim amount (₹)'),
    'paid'              => array('amount,ref,remarks', $c['approved_amount'], 'Amount received (₹)'),
    'query'             => array('remarks', '', ''),
    'rejected'          => array('remarks', '', ''),
    'cancelled'         => array('remarks', '', ''),
);
$nhcx_actions = array('eligibility' => array('Check eligibility', 'fa-user-check', true));
$nhcx_actions['preauth'] = array('Send pre-auth', 'fa-paper-plane', in_array($status, array('registered', 'preauth_submitted', 'query'), true));
$nhcx_actions['claim'] = array('Submit claim', 'fa-file-invoice', in_array($status, array('discharged', 'claim_submitted', 'query'), true));
$nhcx_actions['status'] = array('Check status', 'fa-sync', true);
$tick = array_flip($ticked);
?>
<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between gap-2">
            <div>
                <h4 class="mb-1"><?php echo $this->pmjay_model->case_no($c['id']); ?> <?php echo Pmjay_model::badge($status); ?></h4>
                <div><strong><?php echo html_escape($c['patient_name']); ?></strong> <span class="text-muted">#<?php echo (int) $c['patient_id']; ?> · <?php echo html_escape($c['gender']); ?><?php echo $c['age'] ? ' · ' . (int) $c['age'] . 'y' : ''; ?></span></div>
                <div class="small text-muted">
                    PM-JAY <span class="font-monospace"><?php echo html_escape($c['pmjay_id']); ?></span>
                    <?php echo $c['verified'] ? '<span class="badge bg-success">BIS verified</span>' : '<span class="badge bg-warning text-dark">BIS not verified</span>'; ?>
                    · <?php echo html_escape($c['scheme']); ?>
                    · ABHA <?php echo $c['abha_address'] ? html_escape($c['abha_address']) : '<a href="' . site_url('admin/abdm/patient/' . (int) $c['patient_id']) . '">not linked</a>'; ?>
                </div>
            </div>
            <div class="text-md-end small">
                <div><span class="font-monospace"><?php echo html_escape($c['pkg_code']); ?></span> <?php echo html_escape($c['pkg_name']); ?><?php echo $c['pkg_sample'] ? ' <span class="badge bg-warning text-dark">sample package</span>' : ''; ?></div>
                <div class="text-muted"><?php echo html_escape($c['specialty']); ?><?php echo $c['los_days'] ? ' · expected stay ' . (int) $c['los_days'] . ' days' : ''; ?></div>
                <div>TMS case no: <span class="font-monospace"><?php echo $c['tms_case_no'] ? html_escape($c['tms_case_no']) : '—'; ?></span>
                    <?php if ($c['ipd_id']) { ?> · <a href="<?php echo site_url('admin/patient/ipdprofile/' . (int) $c['ipd_id']); ?>">IPD <?php echo (int) $c['ipd_id']; ?></a><?php } ?></div>
                <div class="text-muted">Admitted <?php echo html_escape($c['admission_date']); ?><?php echo $c['discharge_date'] ? ' · discharged ' . html_escape($c['discharge_date']) : ''; ?></div>
            </div>
        </div>
        <!-- stepper -->
        <div class="d-flex flex-wrap mt-3 gap-1">
            <?php foreach (Pmjay_model::PIPELINE as $i => $p) { $done = $i < $step || ($i === $step && $status === 'paid'); $cur = $i === $step && $status !== 'paid'; ?>
                <div class="flex-fill text-center small py-1 px-2 rounded <?php echo $done ? 'bg-success text-white' : ($cur ? 'bg-primary text-white' : 'bg-light text-muted'); ?>">
                    <?php echo $done ? '<i class="fa fa-check"></i> ' : ''; ?><?php echo html_escape(Pmjay_model::label($p)); ?>
                </div>
            <?php } ?>
        </div>
        <?php if (in_array($status, array('query', 'rejected', 'cancelled'), true)) { $last = $events ? $events[0] : null; ?>
            <div class="alert alert-<?php echo $status === 'query' ? 'danger' : 'secondary'; ?> mt-3 mb-0 py-2">
                <strong><?php echo html_escape(Pmjay_model::label($status)); ?>:</strong> <?php echo $last ? html_escape($last['remarks']) : ''; ?>
                <?php echo $status === 'query' ? ' Answer the query, then resubmit the pre-auth or claim.' : ''; ?>
            </div>
        <?php } ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Amounts</h3></div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-7">Package rate</dt><dd class="col-5 text-end"><?php echo pmjay_money($c['package_amount']); ?></dd>
                    <dt class="col-7">Pre-auth</dt><dd class="col-5 text-end"><?php echo pmjay_money($c['preauth_amount']); ?></dd>
                    <dt class="col-7">Claimed</dt><dd class="col-5 text-end"><?php echo pmjay_money($c['claim_amount']); ?></dd>
                    <dt class="col-7">Approved</dt><dd class="col-5 text-end"><?php echo pmjay_money($c['approved_amount']); ?></dd>
                    <dt class="col-7">Received</dt><dd class="col-5 text-end fw-bold"><?php echo pmjay_money($c['paid_amount']); ?><?php echo $c['payment_ref'] ? '<div class="text-muted fw-normal">' . html_escape($c['payment_ref']) . '</div>' : ''; ?></dd>
                </dl>
            </div>
        </div>

        <?php if ($can_edit && $next) { ?>
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Update status <span class="small text-muted fw-normal">(as done on the TMS portal)</span></h3></div>
            <div class="card-body">
                <form method="post" action="<?php echo site_url('admin/pmjay/move/' . (int) $c['id']); ?>" id="pmjay-move" autocomplete="off">
                    <input type="hidden" name="pmjay_token" value="<?php echo html_escape($pmjay_token); ?>">
                    <select name="to" class="form-select form-select-sm mb-2">
                        <?php foreach ($next as $n) { $f = isset($fields[$n]) ? $fields[$n] : array('remarks', '', ''); ?>
                            <option value="<?php echo $n; ?>" data-fields="<?php echo $f[0]; ?>" data-amount="<?php echo html_escape((string) $f[1]); ?>" data-amount-label="<?php echo html_escape($f[2]); ?>"><?php echo html_escape(Pmjay_model::label($n)); ?></option>
                        <?php } ?>
                    </select>
                    <div class="mb-2" data-field="amount"><label class="form-label small mb-1" data-amount-label>Amount (₹)</label><input name="amount" type="number" step="0.01" min="0" class="form-control form-control-sm"></div>
                    <div class="mb-2" data-field="tms"><label class="form-label small mb-1">TMS case number</label><input name="tms_case_no" class="form-control form-control-sm text-uppercase" maxlength="40" value="<?php echo html_escape($c['tms_case_no']); ?>"></div>
                    <div class="mb-2" data-field="date"><label class="form-label small mb-1">Discharge date</label><input name="date" type="date" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>"></div>
                    <div class="mb-2" data-field="ref"><label class="form-label small mb-1">Payment reference (UTR)</label><input name="payment_ref" class="form-control form-control-sm" maxlength="60"></div>
                    <div class="mb-2" data-field="remarks"><label class="form-label small mb-1">Remarks</label><input name="remarks" class="form-control form-control-sm" maxlength="500"></div>
                    <button class="btn btn-primary btn-sm">Update</button>
                </form>
            </div>
        </div>
        <?php } ?>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between"><h3 class="card-title">Document checklist</h3><span class="badge bg-light text-dark" id="pmjay-docs-count"></span></div>
            <div class="card-body">
                <form method="post" action="<?php echo site_url('admin/pmjay/docs/' . (int) $c['id']); ?>" id="pmjay-docs">
                    <input type="hidden" name="pmjay_token" value="<?php echo html_escape($pmjay_token); ?>">
                    <?php foreach ($docs as $i => $d) { ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="docs[]" value="<?php echo html_escape($d); ?>" id="doc<?php echo $i; ?>" <?php echo isset($tick[$d]) ? 'checked' : ''; ?> <?php echo $can_edit ? '' : 'disabled'; ?>>
                            <label class="form-check-label small" for="doc<?php echo $i; ?>"><?php echo html_escape($d); ?></label>
                        </div>
                    <?php } ?>
                    <?php if ($can_edit) { ?><button class="btn btn-outline-primary btn-sm mt-2">Save checklist</button><?php } ?>
                    <p class="small text-muted mt-2 mb-0">Tick each document once it is ready (scanned for upload to TMS, or attached to the NHCX claim).</p>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <?php if ($this->rbac->hasPrivilege('pmjay_nhcx', 'can_view')) { ?>
        <div class="card mb-3 border-primary">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title">Phase 2 · NHCX exchange</h3>
                <?php if ($nhcx_mode === 'simulator') { ?><span class="badge bg-warning text-dark">Demo simulator: nothing is sent to NHA</span><?php } ?>
            </div>
            <div class="card-body">
                <p class="small text-muted">Sends this case to the payer as a FHIR bundle over NHCX instead of keying it into the TMS portal. The case status updates from the payer's response.</p>
                <?php if ($can_nhcx) { ?>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?php foreach ($nhcx_actions as $t => $a) { ?>
                        <form method="post" action="<?php echo site_url('admin/pmjay/nhcx/' . (int) $c['id']); ?>">
                            <input type="hidden" name="pmjay_token" value="<?php echo html_escape($pmjay_token); ?>">
                            <input type="hidden" name="type" value="<?php echo $t; ?>">
                            <button class="btn btn-sm <?php echo $a[2] ? 'btn-outline-primary' : 'btn-outline-secondary'; ?>" <?php echo $a[2] ? '' : 'disabled title="Not available at this stage"'; ?>><i class="fas <?php echo $a[1]; ?>"></i> <?php echo $a[0]; ?></button>
                        </form>
                    <?php } ?>
                </div>
                <?php } ?>
                <?php if (!$messages) { ?><p class="small text-muted mb-0">No NHCX messages for this case yet.</p><?php } ?>
                <?php foreach ($messages as $m) { ?>
                    <div class="border rounded p-2 mb-2">
                        <div class="d-flex justify-content-between small">
                            <span><strong><?php echo html_escape(Nhcx_client::TYPES[$m['type']]); ?></strong>
                                <span class="badge bg-<?php echo $m['status'] === 'complete' ? 'success' : ($m['status'] === 'query' ? 'danger' : 'secondary'); ?>"><?php echo html_escape($m['status']); ?></span>
                                <?php echo $m['mode'] === 'simulator' ? '<span class="badge bg-light text-dark">simulated</span>' : ''; ?></span>
                            <span class="text-muted"><?php echo html_escape($m['created_at']); ?></span>
                        </div>
                        <div class="small"><?php echo html_escape($m['outcome']); ?></div>
                        <div class="small text-muted font-monospace">correlation <?php echo html_escape($m['correlation_id']); ?></div>
                        <details class="small"><summary>Request bundle (FHIR)</summary><pre class="bg-light p-2 mb-1" style="max-height:300px;overflow:auto"><?php echo html_escape($m['request']); ?></pre></details>
                        <details class="small"><summary>Response bundle</summary><pre class="bg-light p-2 mb-0" style="max-height:300px;overflow:auto"><?php echo html_escape($m['response']); ?></pre></details>
                    </div>
                <?php } ?>
            </div>
        </div>
        <?php } ?>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Timeline</h3></div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    <?php foreach ($events as $e) { ?>
                        <li class="d-flex gap-2 mb-2">
                            <div style="min-width:120px"><?php echo Pmjay_model::badge($e['status']); ?></div>
                            <div class="small flex-grow-1">
                                <?php echo $e['amount'] !== null ? '<strong>' . pmjay_money($e['amount']) . '</strong> · ' : ''; ?><?php echo html_escape($e['remarks']); ?>
                                <div class="text-muted"><?php echo html_escape($e['created_at']); ?> · <?php echo $e['source'] === 'nhcx' ? 'NHCX' : html_escape($e['staff_name'] ?: 'staff'); ?></div>
                            </div>
                        </li>
                    <?php } ?>
                </ul>
            </div>
        </div>
    </div>
</div>
<script defer src="<?php echo base_url('backend/pmjay/pmjay.js'); ?>"></script>
