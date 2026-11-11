<?php
/**
 * Medical registration (doctors). Included by staffcreate.php and staffedit.php.
 * Vars: $mr = array(medical_reg_no, medical_council, medical_reg_valid_till) — raw values (date as Y-m-d).
 * Shown when the selected role is a doctor role, or when a number is already filled in.
 */
$mr       = isset($mr) ? $mr : array();
$mr_no    = set_value('medical_reg_no', $mr['medical_reg_no'] ?? '');
$mr_c     = set_value('medical_council', $mr['medical_council'] ?? '');
$mr_till  = set_value('medical_reg_valid_till', !empty($mr['medical_reg_valid_till']) ? $this->customlib->YYYYMMDDTodateFormat($mr['medical_reg_valid_till']) : '');
?>
<div class="row g-3 mb-3" id="sh-medreg" data-has-value="<?php echo $mr_no !== '' ? 1 : 0; ?>">
    <div class="col-md-4">
        <label class="sh-label" for="medical_reg_no">Medical registration no. <small class="text-muted">(doctors)</small></label>
        <input id="medical_reg_no" name="medical_reg_no" type="text" maxlength="60" class="form-control" value="<?php echo html_escape($mr_no); ?>" placeholder="e.g. MMC 2012/03/1234" />
    </div>
    <div class="col-md-5">
        <label class="sh-label" for="medical_council">Registered with (council)</label>
        <input id="medical_council" name="medical_council" type="text" maxlength="150" class="form-control" list="sh-medical-councils" value="<?php echo html_escape($mr_c); ?>" />
        <datalist id="sh-medical-councils">
            <option value="Maharashtra Medical Council"></option>
            <option value="National Medical Commission"></option>
            <option value="Maharashtra Council of Indian Medicine"></option>
            <option value="Maharashtra Council of Homoeopathy"></option>
            <option value="Maharashtra State Dental Council"></option>
            <option value="Maharashtra State Council for Occupational Therapy and Physiotherapy"></option>
            <option value="Maharashtra Nursing Council"></option>
            <option value="Maharashtra State Pharmacy Council"></option>
        </datalist>
    </div>
    <div class="col-md-3">
        <label class="sh-label" for="medical_reg_valid_till">Registration valid till</label>
        <input id="medical_reg_valid_till" name="medical_reg_valid_till" type="text" class="form-control date" value="<?php echo html_escape($mr_till); ?>" />
    </div>
</div>
<script>
(function () {
    var box = document.getElementById('sh-medreg'), role = document.getElementById('role');
    if (!box || !role) { return; }
    function sync() {
        var opt = role.options[role.selectedIndex], name = opt ? opt.text.toLowerCase() : '';
        var show = box.getAttribute('data-has-value') === '1' || /doctor|consultant|surgeon|physician/.test(name);
        box.style.display = show ? '' : 'none';
    }
    role.addEventListener('change', sync);
    sync();
})();
</script>
