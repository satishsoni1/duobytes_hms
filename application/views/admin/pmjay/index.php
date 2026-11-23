<?php $this->load->view('admin/pmjay/_nav'); $s = $stats; ?>
<div class="row g-3 mb-3">
    <?php
    $cards = array(
        array('Active cases', $s['active'], 'fa-procedures', 'primary', 'cases'),
        array('Pre-auth pending', $s['preauth_pending'], 'fa-hourglass-half', 'warning', 'cases?status=preauth_submitted'),
        array('Queries to answer', $s['queries'], 'fa-question-circle', 'danger', 'cases?status=query'),
        array('Claims pending', $s['claims_pending'], 'fa-file-invoice', 'info', 'cases?status=claim_submitted'),
    );
    foreach ($cards as $k) { ?>
        <div class="col-6 col-lg-3">
            <a class="card h-100 text-decoration-none" href="<?php echo site_url('admin/pmjay/' . $k[4]); ?>">
                <div class="card-body d-flex align-items-center gap-3">
                    <i class="fas <?php echo $k[2]; ?> fa-2x text-<?php echo $k[3]; ?>"></i>
                    <div><div class="fs-3 fw-bold text-body"><?php echo (int) $k[1]; ?></div><div class="text-muted small"><?php echo $k[0]; ?></div></div>
                </div>
            </a>
        </div>
    <?php } ?>
</div>
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card h-100"><div class="card-body">
        <div class="text-muted small">Value of open cases (package rate)</div><div class="fs-4 fw-bold"><?php echo pmjay_money($s['pipeline_value']); ?></div>
    </div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body">
        <div class="text-muted small">Approved, awaiting payment from SHA</div><div class="fs-4 fw-bold text-warning"><?php echo pmjay_money($s['approved_unpaid']); ?></div>
    </div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body">
        <div class="text-muted small">Received from SHA</div><div class="fs-4 fw-bold text-success"><?php echo pmjay_money($s['paid']); ?></div>
    </div></div></div>
</div>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Cases by stage</h3></div>
            <div class="card-body">
                <?php $max = 1; foreach (Pmjay_model::STATUSES as $k => $v) { $max = max($max, isset($s['by'][$k]) ? (int) $s['by'][$k]['n'] : 0); } ?>
                <?php foreach (Pmjay_model::STATUSES as $k => $v) { $n = isset($s['by'][$k]) ? (int) $s['by'][$k]['n'] : 0; if (!$n && in_array($k, array('cancelled', 'rejected'), true)) { continue; } ?>
                    <a class="d-flex align-items-center gap-2 mb-2 text-decoration-none text-body" href="<?php echo site_url('admin/pmjay/cases?status=' . $k); ?>">
                        <span style="width:150px" class="small"><?php echo html_escape($v[0]); ?></span>
                        <span class="flex-grow-1 bg-light rounded" style="height:14px"><span class="d-block rounded bg-<?php echo $v[1] === 'light' ? 'secondary' : $v[1]; ?>" style="height:14px;width:<?php echo $n ? max(4, round($n * 100 / $max)) : 0; ?>%"></span></span>
                        <span class="small fw-bold" style="width:30px;text-align:right"><?php echo $n; ?></span>
                    </a>
                <?php } ?>
                <p class="small text-muted mb-0 mt-3">Beneficiaries registered: <?php echo (int) $s['beneficiaries']; ?> (<?php echo (int) $s['verified']; ?> verified in BIS)</p>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title">Recently updated cases</h3>
                <?php if ($this->rbac->hasPrivilege('pmjay_cases', 'can_add')) { ?><a class="btn btn-primary btn-sm" href="<?php echo site_url('admin/pmjay/new_case'); ?>"><i class="fa fa-plus"></i> New case</a><?php } ?>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Case</th><th>Patient</th><th>Package</th><th>Status</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                    <?php if (!$recent) { ?><tr><td colspan="5" class="text-muted">No cases yet. Register a beneficiary, then create a case.</td></tr><?php } ?>
                    <?php foreach ($recent as $r) { ?>
                        <tr onclick="location.href='<?php echo site_url('admin/pmjay/view/' . $r['id']); ?>'" style="cursor:pointer">
                            <td><a href="<?php echo site_url('admin/pmjay/view/' . $r['id']); ?>"><?php echo $this->pmjay_model->case_no($r['id']); ?></a></td>
                            <td><?php echo html_escape($r['patient_name']); ?></td>
                            <td class="small"><?php echo html_escape($r['pkg_code']); ?></td>
                            <td><?php echo Pmjay_model::badge($r['status']); ?></td>
                            <td class="text-end"><?php echo pmjay_money($r['approved_amount'] !== null ? $r['approved_amount'] : $r['package_amount']); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
