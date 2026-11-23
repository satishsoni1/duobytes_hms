<?php $this->load->view('admin/pmjay/_nav'); ?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Phase 2 · NHCX connection</h3></div>
            <div class="card-body small">
                <dl class="row mb-2">
                    <dt class="col-5">Mode</dt><dd class="col-7"><?php echo $nhcx_mode === 'simulator' ? '<span class="badge bg-warning text-dark">Demo simulator</span>' : '<span class="badge bg-info">NHA sandbox</span>'; ?></dd>
                    <dt class="col-5">Our participant code</dt><dd class="col-7 font-monospace"><?php echo html_escape($participant); ?></dd>
                    <dt class="col-5">Payer (SHA)</dt><dd class="col-7 font-monospace"><?php echo html_escape($payer); ?></dd>
                </dl>
                <p class="mb-2">NHCX is NHA's claims exchange. The hospital sends eligibility checks, pre-auth requests and claims as FHIR bundles, and the State Health Agency replies the same way. PM-JAY on NHCX is being piloted; until it is live for your state, claims continue on the TMS portal (Phase 1).</p>
                <?php if ($nhcx_mode === 'simulator') { ?>
                    <div class="alert alert-warning py-2 mb-2">Simulator mode: bundles are built from real case data, but responses are generated inside the HMS. Nothing is sent to NHA.</div>
                <?php } ?>
                <p class="mb-1 fw-bold">To go live</p>
                <ol class="ps-3 mb-0">
                    <li>Register the hospital as an NHCX provider participant (NHA sandbox).</li>
                    <li>Complete sandbox testing and certification.</li>
                    <li>Add the NHCX module to the ABDM bridge (encryption, callbacks), then set <code>PMJAY_NHCX_MODE=sandbox</code> and the participant codes in <code>.env</code>.</li>
                </ol>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Messages</h3></div>
            <div class="card-body table-responsive">
                <table class="table table-sm table-striped table-hover mb-0">
                    <thead><tr><th>When</th><th>Case</th><th>Patient</th><th>Type</th><th>Status</th><th>Outcome</th></tr></thead>
                    <tbody>
                    <?php if (!$rows) { ?><tr><td colspan="6" class="text-muted">No NHCX messages yet. Open a case and use the NHCX panel.</td></tr><?php } ?>
                    <?php foreach ($rows as $m) { ?>
                        <tr>
                            <td class="small text-nowrap"><?php echo html_escape($m['created_at']); ?></td>
                            <td><a href="<?php echo site_url('admin/pmjay/view/' . (int) $m['case_id']); ?>"><?php echo $this->pmjay_model->case_no($m['case_id']); ?></a></td>
                            <td class="small"><?php echo html_escape($m['patient_name']); ?></td>
                            <td class="small"><?php echo html_escape(Nhcx_client::TYPES[$m['type']]); ?><?php echo $m['mode'] === 'simulator' ? ' <span class="badge bg-light text-dark">sim</span>' : ''; ?></td>
                            <td><span class="badge bg-<?php echo $m['status'] === 'complete' ? 'success' : ($m['status'] === 'query' ? 'danger' : 'secondary'); ?>"><?php echo html_escape($m['status']); ?></span></td>
                            <td class="small"><?php echo html_escape($m['outcome']); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
