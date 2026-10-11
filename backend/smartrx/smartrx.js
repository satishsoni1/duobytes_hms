/* Smart Prescription — one-screen, keyboard-first prescribing for Smart Hospital (OPD + IPD).
 * Saves through the existing add_opd_prescription / add_ipdprescription actions (same field names as the classic form).
 * Usage: SmartRx.open('opd', visitDetailsId)  |  SmartRx.open('ipd', ipdId)
 */
(function () {
  'use strict';
  const BASE = (window.base_url || '/').replace(/\/?$/, '/');
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const ok = (m) => (window.successMsg ? successMsg(m) : window.toastr ? toastr.success(m) : alert(m));
  const fail = (m) => (window.errorMsg ? errorMsg(m) : window.toastr ? toastr.error(m) : alert(m));
  const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
  const TIMING = ['After food', 'Before food', 'Empty stomach', 'At bedtime', 'With food'];
  const ADVICE = ['Plenty of oral fluids', 'Adequate rest', 'Avoid oily & spicy food', 'Steam inhalation', 'Review if symptoms persist', 'Complete the full course'];
  const FOLLOWUP = [['3 days', 3], ['5 days', 5], ['1 week', 7], ['2 weeks', 14], ['1 month', 30]];

  let S = null; // current session state
  let modal = null;

  async function getJSON(path) {
    const r = await fetch(BASE + path, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const d = await r.json().catch(() => ({ error: 'Unexpected response (HTTP ' + r.status + ')' }));
    if (!r.ok) throw new Error(d.error || 'HTTP ' + r.status);
    return d;
  }
  async function postForm(path, fd, token) {
    const r = await fetch(path.startsWith('http') ? path : BASE + path, { method: 'POST', credentials: 'same-origin', body: fd,
      headers: Object.assign({ 'X-Requested-With': 'XMLHttpRequest' }, token ? { 'X-SmartRx-Token': token } : {}) });
    const d = await r.json().catch(() => ({ error: 'Unexpected response (HTTP ' + r.status + ')' }));
    if (!r.ok) throw new Error(d.error || 'HTTP ' + r.status);
    return d;
  }
  const fd = (obj) => { const f = new FormData(); Object.entries(obj).forEach(([k, v]) => (Array.isArray(v) ? v.forEach((x) => f.append(k, x)) : f.append(k, v))); return f; };

  // =================================================================== shell
  function shell() {
    let el = document.getElementById('smartrx-modal');
    if (el) return el;
    el = document.createElement('div');
    el.className = 'modal fade';
    el.id = 'smartrx-modal';
    el.tabIndex = -1;
    el.setAttribute('aria-labelledby', 'smartrx-title');
    el.innerHTML = `<div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
      <div class="modal-header srx-head">
        <div class="srx-patient" id="smartrx-title"></div>
        <div class="srx-actions">
          <div class="dropdown d-inline-block">
            <button class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" type="button"><i class="fa fa-star"></i> Templates</button>
            <ul class="dropdown-menu dropdown-menu-end srx-templates"></ul>
          </div>
          <button class="btn btn-sm btn-outline-secondary srx-repeat" type="button"><i class="fa fa-history"></i> Repeat last</button>
          <button class="btn btn-sm btn-link srx-classic" type="button">Classic form</button>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
      </div>
      <div class="modal-body srx-body"></div>
      <div class="modal-footer srx-foot justify-content-start">
        <!-- Actions sit on the left: Smart Hospital's toast stack is fixed bottom-right above modals. -->
        <button class="btn btn-primary srx-save-print" type="button"><i class="fa fa-print"></i> Save &amp; Print</button>
        <button class="btn btn-secondary srx-save" type="button">Save</button>
        <button class="btn btn-sm btn-link srx-save-template" type="button"><i class="fa fa-star-o"></i> Save as template</button>
        <span class="srx-hint text-muted small">Enter adds a medicine · Ctrl+Enter saves</span>
      </div></div></div>`;
    document.body.appendChild(el);
    return el;
  }

  // =================================================================== open
  async function open(type, id, classicFn) {
    const el = shell();
    modal = modal || new bootstrap.Modal(el);
    el.querySelector('.srx-body').innerHTML = '<div class="text-center p-5"><i class="fa fa-spinner fa-spin"></i> Loading…</div>';
    el.querySelector('.srx-patient').textContent = 'Prescription';
    modal.show();
    try {
      const ctx = await getJSON(`admin/smartrx/context?type=${type}&id=${encodeURIComponent(id)}`);
      S = { type, id, ctx, classicFn, items: [], diagnosis: ctx.diagnosis.map((d) => ({ ...d, saved: true })),
        tests: { pathology: [], radiology: [] }, advice: [], followup: null, templateId: null };
      render();
    } catch (e) {
      el.querySelector('.srx-body').innerHTML = `<div class="alert alert-danger m-3">${esc(e.message)}</div>`;
    }
  }

  // =================================================================== render
  function render() {
    const el = document.getElementById('smartrx-modal');
    const c = S.ctx;
    const allergies = c.patient.allergies.length
      ? `<span class="badge bg-danger ms-2" title="Known allergies"><i class="fa fa-exclamation-triangle"></i> Allergies: ${esc(c.patient.allergies.join(', '))}</span>`
      : '<span class="badge bg-light text-muted ms-2">No known allergies</span>';
    el.querySelector('.srx-patient').innerHTML = `<strong>${esc(c.patient.name)}</strong>
      <span class="text-muted">${esc([c.patient.age, c.patient.gender].filter(Boolean).join(' · '))}</span>
      <span class="badge bg-info ms-1">${S.type.toUpperCase()}</span>${allergies}
      ${c.doctor ? `<div class="small text-muted">${esc(c.doctor)}</div>` : ''}`;

    const repeat = el.querySelector('.srx-repeat');
    repeat.disabled = !c.lastRx;
    repeat.title = c.lastRx ? 'Copy medicines from ' + c.lastRx.date : 'No previous prescription';
    repeat.onclick = () => { addItems(c.lastRx.items); ok('Copied ' + c.lastRx.items.length + ' medicine(s) from ' + c.lastRx.date); };
    el.querySelector('.srx-classic').onclick = () => { modal.hide(); S.classicFn && S.classicFn(); };
    renderTemplates();

    el.querySelector('.srx-body').innerHTML = `
      <div class="row g-3">
        ${S.type === 'ipd' ? `<div class="col-lg-4">
          <label class="form-label srx-label" for="srx-prescriber">Prescribed by <span class="text-danger">*</span></label>
          <select id="srx-prescriber" class="form-select">${c.prescribers.length ? '' : '<option value="">No doctor assigned to this admission</option>'}
            ${c.prescribers.map((d) => `<option value="${d.id}" ${+d.id === +c.doctorId ? 'selected' : ''}>${esc(d.name)}</option>`).join('')}</select>
        </div>` : ''}
        <div class="col-lg-6">
          <label class="form-label srx-label" for="srx-complaints">Complaints &amp; findings</label>
          <textarea id="srx-complaints" class="form-control" rows="2" placeholder="e.g. Fever 3 days, body ache. Throat congested.">${esc(c.symptoms)}</textarea>
        </div>
        <div class="col-lg-6">
          <label class="form-label srx-label" for="srx-icd">Diagnosis (ICD-10)</label>
          <div class="srx-search"><input id="srx-icd" class="form-control" placeholder="Search diagnosis or ICD code…" autocomplete="off">
            <div class="srx-dropdown d-none" id="srx-icd-dd"></div></div>
          <div class="srx-chips mt-1" id="srx-diag"></div>
        </div>

        <div class="col-12">
          <label class="form-label srx-label" for="srx-med">Medicines <span class="text-danger">*</span></label>
          <div class="srx-search"><input id="srx-med" class="form-control form-control-lg" placeholder="Type medicine, generic or company name…" autocomplete="off">
            <div class="srx-dropdown d-none" id="srx-med-dd"></div></div>
          <div class="srx-frequent mt-1" id="srx-frequent"></div>
          <div class="table-responsive mt-2">
            <table class="table table-sm align-middle srx-table mb-0">
              <thead><tr><th style="width:26%">Medicine</th><th style="width:12%">Dose</th><th style="width:20%">Frequency</th><th style="width:16%">Duration</th><th>When / instructions</th><th></th></tr></thead>
              <tbody id="srx-items"></tbody>
            </table>
          </div>
        </div>

        <div class="col-lg-6">
          <label class="form-label srx-label" for="srx-test">Investigations</label>
          <div class="srx-search"><input id="srx-test" class="form-control" placeholder="Search lab / radiology tests…" autocomplete="off">
            <div class="srx-dropdown d-none" id="srx-test-dd"></div></div>
          <div class="srx-chips mt-1" id="srx-tests"></div>
        </div>
        <div class="col-lg-6">
          <label class="form-label srx-label">Advice</label>
          <div class="srx-chips mb-1" id="srx-advice-chips">${ADVICE.map((a) => `<button type="button" class="srx-chip" data-advice="${esc(a)}">${esc(a)}</button>`).join('')}</div>
          <textarea id="srx-advice" class="form-control" rows="2" placeholder="Other advice…"></textarea>
          <label class="form-label srx-label mt-2">Follow-up</label>
          <div class="srx-chips" id="srx-follow">${FOLLOWUP.map(([l, d]) => `<button type="button" class="srx-chip" data-days="${d}">${l}</button>`).join('')}
            <input type="date" id="srx-follow-date" class="form-control form-control-sm d-inline-block w-auto ms-1"></div>
        </div>
      </div>`;

    // Frequent medicines (this doctor, last 6 months)
    el.querySelector('#srx-frequent').innerHTML = c.frequent.length
      ? '<span class="small text-muted me-1">Your frequent:</span>' + c.frequent.map((m, i) => `<button type="button" class="srx-chip srx-chip-sm" data-freq="${i}">${esc(m.name)}</button>`).join('')
      : '';
    el.querySelectorAll('[data-freq]').forEach((b) => b.addEventListener('click', () => { addItems([c.frequent[+b.dataset.freq]]); el.querySelector('#srx-med').focus(); }));

    wireMedicineSearch();
    wireIcdSearch();
    wireTestSearch();
    el.querySelectorAll('[data-advice]').forEach((b) => b.addEventListener('click', () => {
      const a = b.dataset.advice;
      const i = S.advice.indexOf(a);
      if (i >= 0) S.advice.splice(i, 1); else S.advice.push(a);
      b.classList.toggle('active', i < 0);
    }));
    el.querySelectorAll('[data-days]').forEach((b) => b.addEventListener('click', () => {
      const d = new Date(); d.setDate(d.getDate() + +b.dataset.days);
      el.querySelector('#srx-follow-date').value = d.toISOString().slice(0, 10);
      el.querySelectorAll('[data-days]').forEach((x) => x.classList.toggle('active', x === b));
    }));
    renderItems(); renderDiagnosis(); renderTests();
    el.querySelector('.srx-save').onclick = () => save(false);
    el.querySelector('.srx-save-print').onclick = () => save(true);
    el.querySelector('.srx-save-template').onclick = saveTemplate;
    el.onkeydown = (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); save(false); } };
    setTimeout(() => el.querySelector('#srx-med').focus(), 300);
  }

  // =================================================================== medicines
  function defaultsFor(m) {
    const dos = S.ctx.masters.dosages[m.categoryId] || [];
    return { dosageId: m.dosageId || (dos.length ? dos[0].id : null), intervalId: m.intervalId || null, durationId: m.durationId || null };
  }

  function addItems(list) {
    list.forEach((m) => {
      if (S.items.some((x) => x.medicineId === +(m.medicineId || m.id))) return; // already in the list
      S.items.push(Object.assign({ medicineId: +(m.medicineId || m.id), name: m.name, composition: m.composition || '', categoryId: +m.categoryId,
        stock: m.stock, instruction: m.instruction || '' }, defaultsFor(m)));
    });
    renderItems();
  }

  function warningsFor(it) {
    const w = [];
    const hay = (it.name + ' ' + (it.composition || '')).toLowerCase();
    S.ctx.patient.allergies.forEach((a) => {
      const k = a.toLowerCase().trim();
      if (k.length >= 3 && (hay.includes(k) || k.includes(it.name.toLowerCase()))) w.push({ level: 'danger', text: 'Patient allergy: ' + a });
    });
    if (it.composition) {
      const dup = S.items.filter((x) => x !== it && x.composition && x.composition.toLowerCase() === it.composition.toLowerCase());
      if (dup.length) w.push({ level: 'warning', text: 'Same composition as ' + dup.map((d) => d.name).join(', ') });
    }
    if (it.stock !== undefined && it.stock !== null && +it.stock <= 0) w.push({ level: 'secondary', text: 'Not in pharmacy stock' });
    return w;
  }

  function chipSelect(name, idx, options, value, quickCount) {
    const quick = options.slice(0, quickCount);
    return `<div class="srx-cell-chips">${quick.map((o) => `<button type="button" class="srx-chip srx-chip-sm ${+o.id === +value ? 'active' : ''}" data-set="${name}" data-idx="${idx}" data-val="${o.id}">${esc(o.name || o.label)}</button>`).join('')}
      ${options.length > quickCount ? `<select class="form-select form-select-sm srx-more" data-set="${name}" data-idx="${idx}"><option value="">More…</option>${options.slice(quickCount).map((o) => `<option value="${o.id}" ${+o.id === +value ? 'selected' : ''}>${esc(o.name || o.label)}</option>`).join('')}</select>` : ''}</div>`;
  }

  function renderItems() {
    const tbody = document.getElementById('srx-items');
    if (!tbody) return;
    const m = S.ctx.masters;
    if (!S.items.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-muted small">No medicines yet. Search above, pick a frequent one, a template, or Repeat last.</td></tr>';
      return;
    }
    tbody.innerHTML = S.items.map((it, i) => {
      const dos = m.dosages[it.categoryId] || [];
      const warn = warningsFor(it);
      const missing = !it.dosageId || !it.intervalId;
      return `<tr class="${missing ? 'srx-missing' : ''}">
        <td><strong>${esc(it.name)}</strong>${it.composition ? `<div class="small text-muted">${esc(it.composition)}</div>` : ''}
          ${warn.map((w) => `<div><span class="badge bg-${w.level}">${esc(w.text)}</span></div>`).join('')}</td>
        <td><select class="form-select form-select-sm ${!it.dosageId ? 'is-invalid' : ''}" data-set="dosageId" data-idx="${i}">
          <option value="">Dose…</option>${dos.map((d) => `<option value="${d.id}" ${+d.id === +it.dosageId ? 'selected' : ''}>${esc(d.label)}</option>`).join('')}</select>
          ${!dos.length ? '<div class="small text-danger">No doses set up for this category</div>' : ''}</td>
        <td class="${!it.intervalId ? 'srx-need' : ''}">${chipSelect('intervalId', i, m.intervals, it.intervalId, 4)}</td>
        <td>${chipSelect('durationId', i, m.durations, it.durationId, 3)}</td>
        <td><div class="srx-cell-chips">${TIMING.slice(0, 3).map((t) => `<button type="button" class="srx-chip srx-chip-sm ${it.instruction.includes(t) ? 'active' : ''}" data-timing="${esc(t)}" data-idx="${i}">${esc(t)}</button>`).join('')}</div>
          <input class="form-control form-control-sm mt-1" data-set="instruction" data-idx="${i}" value="${esc(it.instruction)}" placeholder="Instructions…"></td>
        <td><button type="button" class="btn btn-sm btn-link text-danger" data-del="${i}" aria-label="Remove ${esc(it.name)}"><i class="fa fa-trash"></i></button></td>
      </tr>`;
    }).join('');

    tbody.querySelectorAll('button[data-set]').forEach((b) => b.addEventListener('click', () => { S.items[+b.dataset.idx][b.dataset.set] = +b.dataset.val; renderItems(); }));
    tbody.querySelectorAll('select[data-set]').forEach((s) => s.addEventListener('change', () => { if (s.value) { S.items[+s.dataset.idx][s.dataset.set] = +s.value; renderItems(); } }));
    tbody.querySelectorAll('input[data-set]').forEach((inp) => inp.addEventListener('input', () => { S.items[+inp.dataset.idx].instruction = inp.value; }));
    tbody.querySelectorAll('[data-timing]').forEach((b) => b.addEventListener('click', () => {
      const it = S.items[+b.dataset.idx];
      const t = b.dataset.timing;
      let parts = it.instruction.split(/\s*;\s*/).filter(Boolean).filter((p) => !TIMING.includes(p));
      if (!it.instruction.includes(t)) parts = [t].concat(parts);
      it.instruction = parts.join('; ');
      renderItems();
    }));
    tbody.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', () => { S.items.splice(+b.dataset.del, 1); renderItems(); }));
  }

  function typeahead(input, dd, search, renderRow, pick) {
    let results = [];
    let active = 0;
    const draw = () => {
      if (!results.length) { dd.classList.add('d-none'); return; }
      dd.innerHTML = results.map((r, i) => `<div class="srx-opt ${i === active ? 'active' : ''}" data-i="${i}">${renderRow(r)}</div>`).join('');
      dd.classList.remove('d-none');
      dd.querySelectorAll('.srx-opt').forEach((o) => o.addEventListener('mousedown', (e) => { e.preventDefault(); choose(+o.dataset.i); }));
    };
    const choose = (i) => { if (results[i]) { pick(results[i]); input.value = ''; results = []; draw(); input.focus(); } };
    const run = debounce(async () => {
      const q = input.value.trim();
      if (q.length < 2) { results = []; draw(); return; }
      try { results = await search(q); active = 0; draw(); } catch (e) { fail(e.message); }
    }, 180);
    input.addEventListener('input', run);
    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, results.length - 1); draw(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); draw(); }
      else if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey) { e.preventDefault(); choose(active); }
      else if (e.key === 'Escape') { results = []; draw(); }
    });
    input.addEventListener('blur', () => setTimeout(() => { results = []; draw(); }, 150));
  }

  function wireMedicineSearch() {
    typeahead(document.getElementById('srx-med'), document.getElementById('srx-med-dd'),
      (q) => getJSON('admin/smartrx/medicines?q=' + encodeURIComponent(q)),
      (m) => `<strong>${esc(m.name)}</strong> <span class="text-muted small">${esc(m.composition || '')}${m.company ? ' · ' + esc(m.company) : ''}</span>
        <span class="float-end small ${+m.stock > 0 ? 'text-success' : 'text-danger'}">${+m.stock > 0 ? 'Stock ' + (+m.stock) : 'No stock'}</span>`,
      (m) => addItems([m]));
  }

  // =================================================================== diagnosis
  function renderDiagnosis() {
    document.getElementById('srx-diag').innerHTML = S.diagnosis.map((d, i) =>
      `<span class="srx-tag">${esc(d.code)} ${esc(d.description)}${d.saved ? '' : ` <button type="button" class="btn-close btn-close-sm" data-ddel="${i}" aria-label="Remove"></button>`}</span>`).join('');
    document.querySelectorAll('[data-ddel]').forEach((b) => b.addEventListener('click', () => { S.diagnosis.splice(+b.dataset.ddel, 1); renderDiagnosis(); }));
  }
  function wireIcdSearch() {
    typeahead(document.getElementById('srx-icd'), document.getElementById('srx-icd-dd'),
      (q) => getJSON('admin/smartrx/icd?q=' + encodeURIComponent(q)),
      (d) => `<strong>${esc(d.code)}</strong> ${esc(d.description)}`,
      (d) => { if (!S.diagnosis.some((x) => +x.id === +d.id)) S.diagnosis.push(d); renderDiagnosis(); });
  }

  // =================================================================== investigations
  function renderTests() {
    const m = S.ctx.masters;
    const name = (kind, id) => (m[kind].find((t) => +t.id === +id) || {}).name;
    document.getElementById('srx-tests').innerHTML = ['pathology', 'radiology'].flatMap((k) => S.tests[k].map((id) =>
      `<span class="srx-tag"><span class="badge bg-light text-dark">${k === 'pathology' ? 'Lab' : 'Radiology'}</span> ${esc(name(k, id))}
        <button type="button" class="btn-close btn-close-sm" data-tdel="${k}:${id}" aria-label="Remove"></button></span>`)).join('');
    document.querySelectorAll('[data-tdel]').forEach((b) => b.addEventListener('click', () => {
      const [k, id] = b.dataset.tdel.split(':'); S.tests[k] = S.tests[k].filter((x) => +x !== +id); renderTests();
    }));
  }
  function wireTestSearch() {
    const m = S.ctx.masters;
    typeahead(document.getElementById('srx-test'), document.getElementById('srx-test-dd'),
      async (q) => {
        const ql = q.toLowerCase();
        const hit = (t) => (t.name || '').toLowerCase().includes(ql) || (t.short_name || '').toLowerCase().includes(ql);
        return m.pathology.filter(hit).slice(0, 10).map((t) => ({ ...t, kind: 'pathology' }))
          .concat(m.radiology.filter(hit).slice(0, 10).map((t) => ({ ...t, kind: 'radiology' })));
      },
      (t) => `<span class="badge bg-light text-dark">${t.kind === 'pathology' ? 'Lab' : 'Radiology'}</span> ${esc(t.name)} <span class="text-muted small">${esc(t.short_name || '')}</span>`,
      (t) => { if (!S.tests[t.kind].includes(+t.id)) S.tests[t.kind].push(+t.id); renderTests(); });
  }

  // =================================================================== templates
  function renderTemplates() {
    const ul = document.querySelector('#smartrx-modal .srx-templates');
    const t = S.ctx.templates;
    ul.innerHTML = t.length ? t.map((x, i) => `<li class="d-flex align-items-center"><a class="dropdown-item" href="#" data-tpl="${i}">${esc(x.name)}
        <span class="text-muted small">(${x.payload.items.length})${x.is_shared == 1 ? ' · shared' : ''}</span></a>
        ${x.mine ? `<button type="button" class="btn btn-sm btn-link text-danger" data-tpldel="${x.id}" title="Delete template"><i class="fa fa-trash"></i></button>` : ''}</li>`).join('')
      : '<li><span class="dropdown-item-text text-muted small">No templates yet. Build a prescription and click “Save as template”.</span></li>';
    ul.querySelectorAll('[data-tpl]').forEach((a) => a.addEventListener('click', (e) => {
      e.preventDefault();
      const tpl = t[+a.dataset.tpl];
      applyTemplate(tpl);
      postForm('admin/smartrx/used_template/' + tpl.id, new FormData(), S.ctx.token).catch(() => {});
    }));
    ul.querySelectorAll('[data-tpldel]').forEach((b) => b.addEventListener('click', async (e) => {
      e.stopPropagation();
      if (!confirm('Delete this template?')) return;
      try { await postForm('admin/smartrx/delete_template/' + b.dataset.tpldel, new FormData(), S.ctx.token); S.ctx.templates = S.ctx.templates.filter((x) => +x.id !== +b.dataset.tpldel); renderTemplates(); }
      catch (err) { fail(err.message); }
    }));
  }

  function applyTemplate(tpl) {
    const p = tpl.payload;
    addItems(p.items || []);
    ['pathology', 'radiology'].forEach((k) => (p.tests && p.tests[k] || []).forEach((id) => { if (!S.tests[k].includes(+id)) S.tests[k].push(+id); }));
    renderTests();
    if (p.advice) { const a = document.getElementById('srx-advice'); a.value = a.value ? a.value + '\n' + p.advice : p.advice; }
    ok('Template “' + tpl.name + '” applied');
  }

  async function saveTemplate() {
    if (!S.items.length) return fail('Add at least one medicine first.');
    const name = prompt('Template name (e.g. "Viral fever – adult"):');
    if (!name) return;
    const shared = confirm('Share this template with other doctors?\nOK = shared, Cancel = only me');
    const payload = { items: S.items, advice: adviceText(false),
      tests: S.tests, diagnosis: S.diagnosis.map((d) => +d.id) };
    try {
      const r = await postForm('admin/smartrx/save_template', fd({ name, shared: shared ? '1' : '0', payload: JSON.stringify(payload) }), S.ctx.token);
      S.ctx.templates.unshift({ id: r.id, name, is_shared: shared ? 1 : 0, payload, mine: true });
      renderTemplates();
      ok('Template saved');
    } catch (e) { fail(e.message); }
  }

  // =================================================================== save
  function adviceText(withFollowup) {
    const lines = S.advice.slice();
    const other = (document.getElementById('srx-advice').value || '').trim();
    if (other) lines.push(other);
    let out = lines.length ? 'Advice: ' + lines.join('; ') : '';
    const f = document.getElementById('srx-follow-date').value;
    if (withFollowup && f) out += (out ? '\n' : '') + 'Follow-up on: ' + new Date(f).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
    return out;
  }
  const toHtml = (t) => t ? t.split('\n').map((l) => `<p>${esc(l)}</p>`).join('') : '';

  async function save(print) {
    const el = document.getElementById('smartrx-modal');
    const tests = S.tests.pathology.length + S.tests.radiology.length;
    if (!S.items.length && !tests) return fail('Add at least one medicine or investigation.');
    const incomplete = S.items.filter((it) => !it.dosageId || !it.intervalId);
    if (incomplete.length) { renderItems(); return fail('Set dose and frequency for: ' + incomplete.map((i) => i.name).join(', ')); }
    const allergic = S.items.filter((it) => warningsFor(it).some((w) => w.level === 'danger'));
    if (allergic.length && !confirm('ALLERGY WARNING\n\n' + allergic.map((i) => i.name).join(', ') + ' may conflict with the patient\'s known allergies.\n\nPrescribe anyway?')) return;

    const f = new FormData();
    if (S.type === 'opd') {
      f.append('visit_details_id', S.id);
    } else {
      const doc = (el.querySelector('#srx-prescriber') || {}).value;
      if (!doc) return fail('Choose who is prescribing (Prescribed by).');
      f.append('ipd_id', S.id);
      f.append('prescribe_by', doc);
    }
    f.append('action', 'add');
    f.append('ipd_prescription_basic_id', '0');
    const complaints = (document.getElementById('srx-complaints').value || '').trim();
    f.append('header_note', toHtml(complaints ? 'Complaints: ' + complaints : ''));
    f.append('finding_description', S.diagnosis.length ? 'Diagnosis: ' + S.diagnosis.map((d) => `${d.code} ${d.description}`).join('; ') : '');
    f.append('finding_print', 'yes');
    f.append('footer_note', toHtml(adviceText(true)));
    S.items.forEach((it, i) => {
      const n = i + 1;
      f.append('rows[]', n);
      f.append('medicine_cat_' + n, '1');
      f.append('medicine_' + n, it.medicineId);
      f.append('dosage_' + n, it.dosageId);
      f.append('interval_dosage_' + n, it.intervalId);
      f.append('duration_dosage_' + n, it.durationId || '');
      f.append('instruction_' + n, it.instruction || '');
    });
    S.tests.pathology.forEach((id) => f.append('pathology[]', id));
    S.tests.radiology.forEach((id) => f.append('radiology[]', id));

    const btns = el.querySelectorAll('.srx-save, .srx-save-print');
    btns.forEach((b) => (b.disabled = true));
    try {
      const r = await fetch(S.ctx.saveUrl, { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const data = await r.json();
      if (String(data.status) === '0') {
        const msg = Object.values(data.error || {}).filter(Boolean).join(' ').replace(/<[^>]+>/g, ' ').trim();
        throw new Error(msg || data.message || 'Could not save the prescription');
      }
      const newCodes = S.diagnosis.filter((d) => !d.saved).map((d) => d.id);
      if (newCodes.length) {
        await postForm('admin/smartrx/diagnosis', fd({ type: S.type, id: S.id, 'codes[]': newCodes }), S.ctx.token).catch((e) => fail('Prescription saved, but diagnosis codes were not: ' + e.message));
      }
      ok(data.message || 'Prescription saved');
      modal.hide();
      if (print && typeof window.printprescription === 'function') {
        window.printprescription(S.type === 'opd' ? data.visitid : data.ipd_prescription_basic_id, true);
      } else if (S.type === 'ipd') {
        window.location.reload();
      }
      if (window.jQuery && jQuery.fn.DataTable && jQuery('.ajaxlistvisit').length) jQuery('.ajaxlistvisit').DataTable().ajax.reload();
    } catch (e) {
      fail(e.message);
    } finally {
      btns.forEach((b) => (b.disabled = false));
    }
  }

  window.SmartRx = { open, enabled: true };
})();
