<?php $staff = $this->customlib->getUserData(); ?>
<div id="abdm-root" data-page="consent" data-base="<?php echo site_url('admin/abdm/'); ?>" data-token="<?php echo html_escape($abdm_token); ?>">
<?php $abdm_tab = 'consent'; $this->load->view('admin/abdm/_nav'); ?>
<div class="row g-3">
    <?php if ($this->rbac->hasPrivilege('abdm_consent', 'can_add')) { ?>
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Request patient's records</h3></div>
            <div class="card-body">
                <label class="form-label small mb-0" for="cr-patient">Patient ABHA address</label>
                <input id="cr-patient" class="form-control form-control-sm" placeholder="name@sbx" value="<?php echo html_escape($prefill); ?>" autocomplete="off">
                <div id="cr-who" class="small mb-2 mt-1"></div>
                <label class="form-label small mb-0" for="cr-purpose">Purpose of request</label>
                <select id="cr-purpose" class="form-select form-select-sm mb-2"></select>
                <div class="row g-2 mb-2">
                    <div class="col"><label class="form-label small mb-0" for="cr-from">Health info from</label><input id="cr-from" type="date" class="form-control form-control-sm"></div>
                    <div class="col"><label class="form-label small mb-0" for="cr-to">Health info to</label><input id="cr-to" type="date" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>"></div>
                </div>
                <label class="form-label small mb-0" for="cr-erase">Consent expiry</label>
                <input id="cr-erase" type="date" class="form-control form-control-sm" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                <div class="small text-muted mb-2">Our copy of the records is deleted on this date.</div>
                <div class="row g-2 mb-2">
                    <div class="col"><label class="form-label small mb-0" for="cr-doctor">Requesting doctor</label>
                        <input id="cr-doctor" class="form-control form-control-sm" value="<?php echo html_escape(isset($staff['name']) ? trim('Dr. ' . $staff['name'] . ' ' . $staff['surname']) : ''); ?>"></div>
                    <div class="col"><label class="form-label small mb-0" for="cr-regno">Registration no.</label><input id="cr-regno" class="form-control form-control-sm"></div>
                </div>
                <label class="form-label small mb-0">Health info types</label>
                <div id="cr-types" class="small mb-2"></div>
                <button class="btn btn-primary btn-sm" id="cr-send">Send consent request</button>
                <p class="small text-muted mt-2 mb-0">The patient approves, edits or denies the request in their ABHA app. Records arrive encrypted and are deleted automatically when consent ends.</p>
            </div>
        </div>
    </div>
    <?php } ?>
    <div class="col-xl-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"><h3 class="card-title mb-0">Consent requests</h3>
                <div class="d-flex gap-2 align-items-center">
                    <select id="cr-status" class="form-select form-select-sm" style="width:auto">
                        <option value="">All statuses</option><option>REQUESTED</option><option>GRANTED</option><option>DENIED</option>
                        <option>REVOKED</option><option>EXPIRED</option><option>ERRORED</option>
                    </select>
                    <span class="small text-muted" id="cr-updated"></span>
                    <button class="btn btn-outline-secondary btn-sm" id="cr-refresh"><i class="fa fa-refresh"></i> Refresh</button>
                </div>
            </div>
            <div class="card-body" id="cr-list"><p class="text-muted mb-0">Loading…</p></div>
        </div>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h3 class="card-title mb-0">Received records <span class="small text-muted" id="rec-for"></span></h3>
                <div id="rec-types" class="d-flex gap-1 flex-wrap"></div>
            </div>
            <div class="card-body" id="rec-list"></div>
        </div>
    </div>
</div>
</div>
<script defer src="<?php echo base_url('backend/abdm/abdm.js'); ?>"></script>
