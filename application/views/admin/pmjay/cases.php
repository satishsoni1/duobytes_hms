<?php $this->load->view('admin/pmjay/_nav'); ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <form class="d-flex flex-wrap gap-2" method="get">
            <select name="status" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                <option value="">All stages</option>
                <?php foreach (Pmjay_model::STATUSES as $k => $v) { ?><option value="<?php echo $k; ?>" <?php echo $status === $k ? 'selected' : ''; ?>><?php echo html_escape($v[0]); ?></option><?php } ?>
            </select>
            <input name="q" class="form-control form-control-sm" style="width:220px" placeholder="Patient, PM-JAY ID or TMS case no" value="<?php echo html_escape($q); ?>">
            <button class="btn btn-outline-secondary btn-sm"><i class="fa fa-search"></i></button>
        </form>
        <?php if ($this->rbac->hasPrivilege('pmjay_cases', 'can_add')) { ?><a class="btn btn-primary btn-sm" href="<?php echo site_url('admin/pmjay/new_case'); ?>"><i class="fa fa-plus"></i> New case</a><?php } ?>
    </div>
    <div class="card-body table-responsive">
        <table class="table table-sm table-striped table-hover mb-0">
            <thead><tr><th>Case</th><th>TMS case no</th><th>Patient</th><th>PM-JAY ID</th><th>Package</th><th>Admitted</th><th>Status</th><th class="text-end">Package</th><th class="text-end">Approved</th><th class="text-end">Paid</th></tr></thead>
            <tbody>
            <?php if (!$rows) { ?><tr><td colspan="10" class="text-muted">No cases.</td></tr><?php } ?>
            <?php foreach ($rows as $r) { ?>
                <tr>
                    <td><a href="<?php echo site_url('admin/pmjay/view/' . $r['id']); ?>"><?php echo $this->pmjay_model->case_no($r['id']); ?></a></td>
                    <td class="font-monospace small"><?php echo html_escape($r['tms_case_no']); ?></td>
                    <td><?php echo html_escape($r['patient_name']); ?> <span class="text-muted small">#<?php echo (int) $r['patient_id']; ?></span></td>
                    <td class="font-monospace small"><?php echo html_escape($r['pmjay_id']); ?></td>
                    <td class="small"><span class="font-monospace"><?php echo html_escape($r['pkg_code']); ?></span> <?php echo html_escape($r['pkg_name']); ?></td>
                    <td class="small"><?php echo html_escape($r['admission_date']); ?></td>
                    <td><?php echo Pmjay_model::badge($r['status']); ?></td>
                    <td class="text-end"><?php echo pmjay_money($r['package_amount']); ?></td>
                    <td class="text-end"><?php echo pmjay_money($r['approved_amount']); ?></td>
                    <td class="text-end"><?php echo pmjay_money($r['paid_amount']); ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>
