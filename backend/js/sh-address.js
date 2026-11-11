/*
 * Patient address: adds State → District → Taluka → City / village dropdowns (+ PIN) next to the
 * address box of every patient form (a form that has both "address" and "guardian_name").
 * Each dropdown lists what is known and lets the user type a new name; new names are added to
 * the lists when the patient is saved (Geo_model::remember).
 *
 * Edit forms fill it with: ShAddress.fill('#eaddress', patientJson)
 * Needs jQuery + Select2 4 (both loaded by layout/footer). Defaults: window.SH_GEO_DEFAULTS.
 */
(function (w, $) {
    'use strict';
    if (!$ || !$.fn) { return; }
    var D = w.SH_GEO_DEFAULTS || { state: '', district: '' };
    var api = (w.base_url || '/') + 'admin/geo/options';
    var cache = {};

    function esc(s) { return $('<span>').text(s == null ? '' : String(s)).html(); }

    function load(level, q) {
        var key = level + '|' + JSON.stringify(q);
        if (!cache[key]) { cache[key] = $.getJSON(api, $.extend({ level: level }, q)).then(null, function () { return $.Deferred().resolve([]); }); }
        return cache[key];
    }

    function setOptions($sel, list, value) {
        var html = '<option value=""></option>', seen = false;
        $.each(list, function (_, r) {
            if (r.name === value) { seen = true; }
            html += '<option value="' + esc(r.name) + '"' + (r.pincode ? ' data-pin="' + esc(r.pincode) + '"' : '') + '>' + esc(r.name) + '</option>';
        });
        if (value && !seen) { html += '<option value="' + esc(value) + '">' + esc(value) + '</option>'; }
        $sel.html(html).val(value || '').trigger('change.select2');
    }

    function initSelect($sel, placeholder) {
        if (!$.fn.select2) { return; }
        var $modal = $sel.closest('.modal');
        $sel.select2({
            tags: true, allowClear: true, placeholder: placeholder, width: '100%',
            dropdownParent: $modal.length ? $modal : $(document.body),
            createTag: function (p) {
                var t = $.trim(p.term);
                return t === '' ? null : { id: t, text: t + ' (add new)', newTag: true };
            }
        });
    }

    function parts($wrap) {
        return {
            state: $wrap.find('[name=state]'), district: $wrap.find('[name=district]'),
            taluka: $wrap.find('[name=taluka]'), city: $wrap.find('[name=city_village]'), pin: $wrap.find('[name=pincode]')
        };
    }

    /** Load each level for the values given; returns a promise. */
    function setValues($wrap, v) {
        var p = parts($wrap);
        v = v || {};
        $wrap.data('busy', true);
        return load('state', {}).then(function (states) {
            setOptions(p.state, states, v.state || '');
            if (!v.state) { setOptions(p.district, [], ''); return []; }
            return load('district', { state: v.state });
        }).then(function (ds) {
            if (v.state) { setOptions(p.district, ds, v.district || ''); }
            if (!v.state || !v.district) { setOptions(p.taluka, [], ''); return []; }
            return load('taluka', { state: v.state, district: v.district });
        }).then(function (ts) {
            if (v.district) { setOptions(p.taluka, ts, v.taluka || ''); }
            if (!v.taluka) { setOptions(p.city, [], ''); return []; }
            return load('city', { state: v.state, district: v.district, taluka: v.taluka });
        }).then(function (cs) {
            if (v.taluka) { setOptions(p.city, cs, v.city_village || ''); }
            p.pin.val(v.pincode || '');
        }).always(function () { $wrap.data('busy', false); });
    }

    function current($wrap) {
        var p = parts($wrap);
        return { state: p.state.val() || '', district: p.district.val() || '', taluka: p.taluka.val() || '', city_village: p.city.val() || '', pincode: p.pin.val() || '' };
    }

    function wire($wrap) {
        var p = parts($wrap);
        p.state.on('change', function () { if ($wrap.data('busy')) { return; } var c = current($wrap); setValues($wrap, { state: c.state }); });
        p.district.on('change', function () { if ($wrap.data('busy')) { return; } var c = current($wrap); setValues($wrap, { state: c.state, district: c.district }); });
        p.taluka.on('change', function () { if ($wrap.data('busy')) { return; } var c = current($wrap); setValues($wrap, { state: c.state, district: c.district, taluka: c.taluka }); });
        p.city.on('change', function () {
            var pin = $(this).find(':selected').data('pin');
            if (pin && !p.pin.val()) { p.pin.val(pin); }
        });
    }

    function mount(addr) {
        var $addr = $(addr);
        if ($addr.data('shGeo')) { return $addr.data('shGeo'); }
        var $col = $addr.closest('[class*="col-"]');
        var field = function (name, label, cls) {
            return '<div class="' + cls + '"><label class="form-label">' + label + '</label><select class="form-select" name="' + name + '"></select></div>';
        };
        var $wrap = $('<div class="col-12 sh-geo-wrap"><div class="row g-2">' +
            field('state', 'State', 'col-6 col-md-3') + field('district', 'District', 'col-6 col-md-3') +
            field('taluka', 'Taluka', 'col-6 col-md-2') + field('city_village', 'City / village', 'col-6 col-md-3') +
            '<div class="col-6 col-md-1"><label class="form-label">PIN</label><input class="form-control" name="pincode" maxlength="6" inputmode="numeric" pattern="[0-9]{6}"></div>' +
            '</div></div>');
        ($col.length && $col.parent().hasClass('row') ? $col : $addr.closest('.form-group').length ? $addr.closest('.form-group') : $addr).after($wrap);
        // The address box now holds only the house / street part.
        var $label = ($col.length ? $col : $addr.parent()).find('label').first();
        if ($label.length) { $label.text('House / street / landmark'); }
        var p = parts($wrap);
        initSelect(p.state, 'State'); initSelect(p.district, 'District'); initSelect(p.taluka, 'Taluka'); initSelect(p.city, 'City / village');
        wire($wrap);
        $addr.data('shGeo', $wrap);
        setValues($wrap, { state: D.state, district: D.district });
        return $wrap;
    }

    function scan(root) {
        $(root || document).find('input[name=address], textarea[name=address]').each(function () {
            var $form = $(this).closest('form');
            if ($form.length && $form.find('[name=guardian_name]').length && !$form.find('[name=state]').length) { mount(this); }
        });
    }

    w.ShAddress = {
        mount: mount,
        scan: scan,
        /** Fill the dropdowns from a patient record (keys state, district, taluka, city_village, pincode). */
        fill: function (addrSelector, data) {
            var $addr = $(addrSelector).first();
            if (!$addr.length) { return; }
            var $wrap = $addr.data('shGeo') || mount($addr);
            data = data || {};
            var hasAny = data.state || data.district || data.taluka || data.city_village;
            // patients.address holds the full address; the box shows only the house / street part.
            if (hasAny && data.address_line != null) { $addr.val(data.address_line); }
            setValues($wrap, hasAny ? data : { state: D.state, district: D.district });
        },
        full: function (p) {
            var out = [];
            $.each(['address', 'city_village', 'taluka', 'district', 'state'], function (_, k) { if (p && p[k] && out.indexOf(p[k]) < 0) { out.push(p[k]); } });
            return out.join(', ') + (p && p.pincode ? ' - ' + p.pincode : '');
        }
    };

    $(function () { scan(document); });
    $(document).on('shown.bs.modal', function (e) { scan(e.target); });
})(window, window.jQuery);
