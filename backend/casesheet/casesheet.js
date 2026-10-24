/* IPD Case Sheet: repeating rows, medicine type-ahead, dose charting, discharge drafting. */
(function ($) {
    var root = $('#cs-root');
    if (!root.length) { return; }
    var base = root.data('base'), ipd = root.data('ipd'), token = root.data('token');

    // ---- repeating rows (inputs named name[i][key]) ----
    function renumber(table) {
        var name = table.data('name');
        table.find('tbody tr').each(function (i) {
            $(this).find('input').each(function () {
                this.name = this.name.replace(new RegExp('^' + name + '\\[\\d+\\]'), name + '[' + i + ']');
            });
        });
    }
    function addRow(table) {
        var row = table.find('tbody tr:last').clone();
        row.find('input').val('');
        table.find('tbody').append(row);
        renumber(table);
        return row;
    }
    root.on('click', '.cs-row-add', function () {
        addRow($($(this).data('target'))).find('input:first').trigger('focus');
    });
    root.on('click', '.cs-row-del', function () {
        var table = $(this).closest('table'), tr = $(this).closest('tr');
        if (table.find('tbody tr').length > 1) { tr.remove(); } else { tr.find('input').val(''); }
        renumber(table);
    });

    // ---- medicine type-ahead from the pharmacy master (Smart Prescription endpoint) ----
    var medTimer, medUrl = root.data('medurl');
    root.on('input', '.cs-med', function () {
        var q = this.value.replace(/^(inj|tab|cap|syp|syr|oint|drops?)\.?\s+/i, '');
        clearTimeout(medTimer);
        if (q.length < 2) { return; }
        medTimer = setTimeout(function () {
            $.getJSON(medUrl, { q: q }).done(function (list) {
                var dl = $('#cs-medlist').empty();
                $.each(list || [], function (_, m) {
                    dl.append($('<option>').val(m.name).text((m.composition || '') + (m.stock !== undefined ? ' · stock ' + m.stock : '')));
                });
            });
        }, 200);
    });

    // ---- medication order: frequency fills default times ----
    root.on('change', '.cs-freq', function () {
        $(this).closest('form').find('.cs-slots').val($(this).find(':selected').data('slots') || '');
    });

    // ---- tick a dose given / undo ----
    function post(url, data) {
        return $.ajax({ url: base + url + '/' + ipd, method: 'POST', data: data, dataType: 'json', headers: { 'X-Casesheet-Token': token } });
    }
    root.on('click', '.cs-dose:not(.cs-dose-add)', function () {
        var b = $(this).prop('disabled', true);
        post('toggle_dose', { med_id: b.data('med'), date: b.data('date'), slot: b.data('slot') })
            .done(function (r) { b.toggleClass('given', r.given).attr('title', r.given ? 'Given ' + r.at : 'Not charted'); })
            .fail(function (x) { alert((x.responseJSON && x.responseJSON.error) || 'Could not save.'); })
            .always(function () { b.prop('disabled', false); });
    });
    root.on('click', '.cs-dose-add', function () {
        var b = $(this), now = new Date();
        var t = prompt('Time the dose was given (HH:MM)', ('0' + now.getHours()).slice(-2) + ':' + ('0' + now.getMinutes()).slice(-2));
        if (!t) { return; }
        post('toggle_dose', { med_id: b.data('med'), date: b.data('date'), slot: t })
            .done(function () { location.reload(); })
            .fail(function (x) { alert((x.responseJSON && x.responseJSON.error) || 'Could not save.'); });
    });

    // ---- discharge: draft course in hospital ----
    $('#cs-draft').on('click', function () {
        var ta = $('#cs-course');
        if ($.trim(ta.val()) && !confirm('Replace the current text with a fresh draft?')) { return; }
        $.getJSON(base + 'draft_course/' + ipd).done(function (r) { ta.val(r.text); });
    });

    // ---- discharge: copy oral drugs from the medication chart ----
    var freqMap = { OD: '1-0-0', BD: '1-0-1', TDS: '1-1-1', QID: '1-1-1-1', HS: '0-0-1', SOS: 'SOS' };
    $('#cs-rx-from-chart').on('click', function () {
        var meds = JSON.parse($('#cs-meds-json').text() || '[]').filter(function (m) {
            return /oral|po/i.test(m.route || '') || /^(tab|cap|syp|syr)\b/i.test(m.drug);
        });
        if (!meds.length) { alert('No oral drugs on the medication chart.'); return; }
        var table = $('#cs-rx');
        var have = table.find('input[name$="[drug]"]').map(function () { return this.value.toLowerCase(); }).get();
        meds.forEach(function (m) {
            if (have.indexOf(m.drug.toLowerCase()) >= 0) { return; }
            var row = table.find('tbody tr').filter(function () { return !$(this).find('input[name$="[drug]"]').val(); }).first();
            if (!row.length) { row = addRow(table); }
            row.find('input[name$="[drug]"]').val(m.drug);
            row.find('input[name$="[dose]"]').val(m.dose || '1 tab');
            row.find('input[name$="[frequency]"]').val(freqMap[m.frequency] || m.frequency || '');
            row.find('input[name$="[duration]"]').val('5 days');
        });
    });
})(jQuery);
