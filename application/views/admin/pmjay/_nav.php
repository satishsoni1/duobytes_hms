<?php
if (!function_exists('pmjay_money')) {
    function pmjay_money($n)
    {
        return $n === null || $n === '' ? '—' : '₹' . number_format((float) $n, 2);
    }
}
$tab = isset($pmjay_tab) ? $pmjay_tab : '';
$tabs = array(
    array('index', 'admin/pmjay', 'Dashboard', 'pmjay_cases'),
    array('cases', 'admin/pmjay/cases', 'Cases', 'pmjay_cases'),
    array('beneficiaries', 'admin/pmjay/beneficiaries', 'Beneficiaries', 'pmjay_beneficiaries'),
    array('packages', 'admin/pmjay/packages', 'HBP packages', 'pmjay_packages'),
    array('exchange', 'admin/pmjay/exchange', 'NHCX exchange', 'pmjay_nhcx'),
);
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
    <h4 class="mb-0"><i class="fas fa-hand-holding-medical text-primary"></i> Ayushman Bharat PM-JAY</h4>
    <?php if ($nhcx_mode === 'simulator') { ?>
        <span class="badge bg-warning text-dark" title="NHCX responses are generated inside the HMS. Nothing is sent to NHA.">NHCX: demo simulator</span>
    <?php } else { ?>
        <span class="badge bg-info">NHCX: sandbox</span>
    <?php } ?>
</div>
<ul class="nav nav-tabs mb-3">
    <?php foreach ($tabs as $t) { if (!$this->rbac->hasPrivilege($t[3], 'can_view')) { continue; } ?>
        <li class="nav-item"><a class="nav-link <?php echo $tab === $t[0] ? 'active' : ''; ?>" href="<?php echo site_url($t[1]); ?>"><?php echo $t[2]; ?></a></li>
    <?php } ?>
</ul>
<?php if (!empty($flash)) { ?>
    <div class="alert alert-<?php echo html_escape($flash['type']); ?> alert-dismissible fade show" role="alert">
        <?php echo html_escape($flash['msg']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php } ?>
