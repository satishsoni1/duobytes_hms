<?php $abdm_tab = isset($abdm_tab) ? $abdm_tab : ''; ?>
<ul class="nav nav-tabs mb-3">
    <?php if ($this->rbac->hasPrivilege('abdm_settings', 'can_view')) { ?>
        <li class="nav-item"><a class="nav-link <?php echo $abdm_tab === 'index' ? 'active' : ''; ?>" href="<?php echo site_url('admin/abdm'); ?>">ABDM status</a></li>
    <?php } ?>
    <?php if ($this->rbac->hasPrivilege('abdm_abha', 'can_view')) { ?>
        <li class="nav-item"><a class="nav-link <?php echo $abdm_tab === 'share' ? 'active' : ''; ?>" href="<?php echo site_url('admin/abdm/share'); ?>">Scan &amp; Share queue</a></li>
    <?php } ?>
    <?php if ($this->rbac->hasPrivilege('abdm_consent', 'can_view')) { ?>
        <li class="nav-item"><a class="nav-link <?php echo $abdm_tab === 'consent' ? 'active' : ''; ?>" href="<?php echo site_url('admin/abdm/consent'); ?>">Records from other hospitals</a></li>
    <?php } ?>
</ul>
<?php if (empty($configured)) { ?>
    <div class="alert alert-warning">ABDM bridge is not configured. Set <code>abdm_bridge_url</code> and <code>abdm_api_key</code> in <code>application/config/abdm.php</code>.</div>
<?php } ?>
