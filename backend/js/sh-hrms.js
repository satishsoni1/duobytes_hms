/*
 * Shared helpers for the HRMS, travel expense, discount approval and patient advance screens.
 * Needs jQuery and the app's successMsg()/errorMsg() toasts (both loaded by layout/footer).
 */
(function (w, $) {
    'use strict';

    function toast(ok, msg) {
        if (!msg) { return; }
        if (ok && typeof w.successMsg === 'function') { w.successMsg(msg); return; }
        if (!ok && typeof w.errorMsg === 'function') { w.errorMsg(msg); return; }
        w.alert(msg);
    }

    function clearErrors($form) {
        $form.find('.sh-err').remove();
        $form.find('.is-invalid').removeClass('is-invalid');
    }

    function showErrors($form, errors) {
        var shown = false, loose = [];
        $.each(errors || {}, function (field, msg) {
            if (!msg) { return; }
            msg = $('<div>').html(msg).text();
            var $f = $form.find('[name="' + field + '"], [name="' + field + '[]"]').first();
            if ($f.length) {
                $f.addClass('is-invalid');
                $('<div class="sh-err text-danger small mt-1"></div>').text(msg).insertAfter($f.closest('.input-group').length ? $f.closest('.input-group') : $f);
                shown = true;
            } else {
                loose.push(msg);
            }
        });
        if (loose.length) { toast(false, loose.join(' ')); shown = true; }
        return shown;
    }

    /**
     * Post a form (with files) as FormData. opts: {url, before(fd), done(res), button}
     * The server answers {status: 'success'|'fail', message, error: {field: msg}}.
     */
    function postForm(form, opts) {
        var $form = $(form), fd = new FormData($form[0]);
        opts = opts || {};
        if (opts.before) { opts.before(fd); }
        var $btn = $(opts.button || $form.find('[type=submit]').first());
        clearErrors($form);
        $btn.prop('disabled', true).addClass('disabled');
        return $.ajax({
            url: opts.url || $form.attr('action'), type: 'POST', data: fd, dataType: 'json',
            processData: false, contentType: false
        }).done(function (res) {
            if (res && res.status === 'success') {
                toast(true, res.message);
                if (opts.done) { opts.done(res); }
            } else if (!showErrors($form, res && res.error)) {
                toast(false, (res && res.message) || 'Could not save. Check the form and try again.');
            }
        }).fail(function (xhr) {
            toast(false, xhr.status === 413 ? 'The file is too large.' : 'The server did not answer. Try again.');
        }).always(function () {
            $btn.prop('disabled', false).removeClass('disabled');
        });
    }

    /** POST without a form, after an optional confirm. */
    function post(url, data, opts) {
        opts = opts || {};
        if (opts.confirm && !w.confirm(opts.confirm)) { return $.Deferred().reject().promise(); }
        return $.post(url, data || {}, null, 'json').done(function (res) {
            if (res && res.status === 'success') {
                toast(true, res.message);
                if (opts.done) { opts.done(res); }
            } else {
                toast(false, (res && res.message) || 'Could not complete this.');
            }
        }).fail(function () { toast(false, 'The server did not answer. Try again.'); });
    }

    function money(n) {
        n = parseFloat(n);
        return isNaN(n) ? '0.00' : n.toFixed(2);
    }

    w.shHr = { postForm: postForm, post: post, toast: toast, money: money, showErrors: showErrors, clearErrors: clearErrors };
})(window, window.jQuery);
