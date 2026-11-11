<?php
/**
 * HRMS tab bar shared by the HR letter, document, policy and travel expense screens.
 * Var: $active — one of mine, documents, templates, policies, expenses, expense_approvals.
 */
$active = isset($active) ? $active : '';
$tabs   = array(array('mine', 'admin/hrms', 'fa-user', 'My HR', true));
$tabs[] = array('expenses', 'admin/travelexpense', 'fa-plane', 'My travel claims', true);
$tabs[] = array('expense_approvals', 'admin/travelexpense/approvals', 'fa-check-square-o', 'Expense approvals', $this->rbac->hasPrivilege('travel_expense_approval', 'can_view'));
$tabs[] = array('documents', 'admin/hrms/documents', 'fa-folder-open', 'Staff documents', $this->rbac->hasPrivilege('staff_hr_document', 'can_view'));
$tabs[] = array('templates', 'admin/hrms/templates', 'fa-file-text-o', 'Letter templates', $this->rbac->hasPrivilege('hr_letter_template', 'can_view'));
$tabs[] = array('policies', 'admin/hrms/policies', 'fa-book', 'Policies', $this->rbac->hasPrivilege('hospital_policy', 'can_view'));
?>
<link rel="stylesheet" href="<?php echo base_url('backend/css/sh-hrms.css'); ?>">
<script src="<?php echo base_url('backend/js/sh-hrms.js'); ?>"></script>
<ul class="nav nav-pills sh-hr-nav mb-3 flex-wrap gap-1">
    <?php foreach ($tabs as $t) { if (!$t[4]) { continue; } ?>
    <li class="nav-item"><a class="nav-link<?php echo $active === $t[0] ? ' active' : ''; ?>" href="<?php echo base_url($t[1]); ?>"><i class="fa <?php echo $t[2]; ?>"></i> <?php echo $t[3]; ?></a></li>
    <?php } ?>
    <?php if ($this->rbac->hasPrivilege('staff', 'can_view')) { ?>
    <li class="nav-item ms-auto"><a class="nav-link" href="<?php echo base_url('admin/staff'); ?>"><i class="fa fa-users"></i> Staff directory</a></li>
    <?php } ?>
</ul>
