<?php
/*
 | Patient portal login modal: view username / password and set a new password.
 | Include once per page; any element with data-plc-open="<patient id>" opens it.
 | Viewing needs patient_login_credential:can_view; changing also needs patient:can_edit.
 */
if (!$this->rbac->hasPrivilege('patient_login_credential', 'can_view')) {
    return;
}
$plc_token = $this->session->userdata('patlogin_csrf');
if (!$plc_token) {
    $plc_token = bin2hex(random_bytes(24));
    $this->session->set_userdata('patlogin_csrf', $plc_token);
}
?>
<div class="modal fade" id="plcModal" tabindex="-1" aria-labelledby="plcTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="plcTitle">Patient login</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3" id="plc-patient"></p>
                <div id="plc-nologin" class="alert alert-warning py-2 d-none">This patient has no portal login yet. Set a password below to create one.</div>
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="plc-username" readonly>
                        <button class="btn btn-outline-secondary" type="button" data-plc-copy="plc-username" title="Copy"><i class="fa fa-copy"></i></button>
                    </div>
                </div>
                <div class="mb-3" id="plc-current-wrap">
                    <label class="form-label">Current password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="plc-password" readonly autocomplete="off">
                        <button class="btn btn-outline-secondary" type="button" id="plc-toggle" title="Show / hide"><i class="fa fa-eye"></i></button>
                        <button class="btn btn-outline-secondary" type="button" data-plc-copy="plc-password" title="Copy"><i class="fa fa-copy"></i></button>
                    </div>
                    <div class="form-text" id="plc-inactive"></div>
                </div>
                <form id="plc-form" class="d-none" autocomplete="off">
                    <hr>
                    <h6 class="mb-2">Set new password</h6>
                    <div class="mb-2">
                        <label class="form-label" for="plc-new">New password</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="plc-new" name="new_password" minlength="6" maxlength="50" required autocomplete="new-password">
                            <button class="btn btn-outline-secondary" type="button" id="plc-generate">Generate</button>
                        </div>
                        <div class="form-text">6 to 50 characters: letters, digits and ! @ # $ % ^ * _ - . + = ? ~</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="plc-confirm">Confirm password</label>
                        <input type="text" class="form-control" id="plc-confirm" name="confirm_password" minlength="6" maxlength="50" required autocomplete="new-password">
                    </div>
                    <div class="text-danger small mb-2" id="plc-error"></div>
                    <button type="submit" class="btn btn-primary" id="plc-save">Save password</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function ($) {
    'use strict';
    var BASE = <?php echo json_encode(base_url() . 'admin/patient/'); ?>, TOKEN = <?php echo json_encode($plc_token); ?>, current = null;
    var modalEl = document.getElementById('plcModal');
    function modal() { return bootstrap.Modal.getOrCreateInstance(modalEl); }
    function msg(ok, text) { if (window.toastr) { toastr[ok ? 'success' : 'error'](text); } else { alert(text); } }
    function reset() {
        $('#plc-password').attr('type', 'password').val('');
        $('#plc-toggle i').attr('class', 'fa fa-eye');
        $('#plc-new, #plc-confirm').val('');
        $('#plc-error').text('');
    }
    function open(id) {
        current = id; reset();
        $.ajax({ url: BASE + 'login_credential/' + id, dataType: 'json', cache: false }).done(function (d) {
            $('#plc-patient').text(d.patient);
            $('#plc-username').val(d.username);
            $('#plc-password').val(d.password);
            $('#plc-nologin').toggleClass('d-none', d.has_login);
            $('#plc-current-wrap').toggleClass('d-none', !d.has_login);
            $('#plc-inactive').text(d.has_login && d.is_active !== 'yes' ? 'This login is disabled (patient is inactive).' : '');
            $('#plc-form').toggleClass('d-none', !d.can_change);
            modal().show();
        }).fail(function (x) { msg(false, (x.responseJSON && x.responseJSON.message) || 'Could not load login details'); });
    }
    $(document).on('click', '[data-plc-open]', function (e) { e.preventDefault(); open($(this).data('plc-open')); });
    $('#plc-toggle').on('click', function () {
        var f = $('#plc-password'), show = f.attr('type') === 'password';
        f.attr('type', show ? 'text' : 'password');
        $(this).find('i').attr('class', show ? 'fa fa-eye-slash' : 'fa fa-eye');
    });
    $(document).on('click', '[data-plc-copy]', function () {
        var v = $('#' + $(this).data('plc-copy')).val();
        if (navigator.clipboard) { navigator.clipboard.writeText(v).then(function () { msg(true, 'Copied'); }); }
    });
    $('#plc-generate').on('click', function () {
        var chars = 'abcdefghjkmnpqrstuvwxyz23456789', out = '', a = new Uint32Array(8);
        window.crypto.getRandomValues(a);
        for (var i = 0; i < a.length; i++) { out += chars[a[i] % chars.length]; }
        $('#plc-new, #plc-confirm').val(out);
    });
    $('#plc-form').on('submit', function (e) {
        e.preventDefault();
        var btn = $('#plc-save').prop('disabled', true);
        $('#plc-error').text('');
        $.ajax({ url: BASE + 'save_login_password/' + current, type: 'POST', dataType: 'json', headers: { 'X-Patlogin-Token': TOKEN },
            data: { new_password: $('#plc-new').val(), confirm_password: $('#plc-confirm').val() } })
            .done(function (d) { msg(true, d.message); open(current); if ($.fn.DataTable && $('.ajaxlist').length) { $('.ajaxlist').DataTable().ajax.reload(null, false); } })
            .fail(function (x) { $('#plc-error').text((x.responseJSON && x.responseJSON.message) || 'Could not save the password'); })
            .always(function () { btn.prop('disabled', false); });
    });
    modalEl.addEventListener('hidden.bs.modal', reset);
})(jQuery);
</script>
