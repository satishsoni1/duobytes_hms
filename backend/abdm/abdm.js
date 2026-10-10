/* ABDM integration UI for Smart Hospital (vanilla JS; loaded with defer). */
(function () {
  'use strict';
  const root = document.getElementById('abdm-root');
  if (!root) return;
  const BASE = root.dataset.base;           // .../admin/abdm/
  const TOKEN = root.dataset.token;
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (d) => (d ? new Date(d).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' }) : '');
  const fmtDate = (d) => (d ? new Date(d).toLocaleDateString('en-IN', { dateStyle: 'medium' }) : '');
  const ok = (m) => (window.toastr ? toastr.success(m) : alert(m));
  const fail = (m) => (window.toastr ? toastr.error(m) : alert(m));
  const badge = (s) => {
    const cls = { GRANTED: 'success', LINKED: 'success', TRANSFERRED: 'success', OK: 'success', DENIED: 'danger', REVOKED: 'danger',
      EXPIRED: 'secondary', ERRORED: 'danger', FAILED: 'danger', REQUESTED: 'warning', PENDING: 'warning' }[s] || 'secondary';
    return `<span class="badge bg-${cls}">${esc(s)}</span>`;
  };

  async function api(path, body, { method, blob } = {}) {
    const res = await fetch(BASE + path, {
      method: method || (body !== undefined ? 'POST' : 'GET'),
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-ABDM-Token': TOKEN, 'X-Requested-With': 'XMLHttpRequest' },
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
    if (blob && res.ok) return res.blob();
    const data = await res.json().catch(() => ({ error: `Unexpected response (HTTP ${res.status})` }));
    if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
    return data;
  }

  /** Disable button while running; toast errors. */
  function bind(el, fn) {
    if (!el) return;
    el.addEventListener('click', async (e) => {
      e.preventDefault();
      el.disabled = true;
      try { await fn(e); } catch (err) { fail(err.message); } finally { el.disabled = false; }
    });
  }
  const show = (el, on = true) => el && el.classList.toggle('d-none', !on);

  // ================================================================= dashboard
  if (root.dataset.page === 'index') {
    bind($('#abdm-sync-all'), async () => {
      const r = await api('sync_all', {});
      $('#abdm-sync-result').textContent = `Checked ${r.checked}, sent ${r.sent}, unchanged ${r.unchanged}, skipped ${r.skipped}` +
        (r.errors.length ? `, errors: ${r.errors.join('; ')}` : '');
      ok('Sync finished');
    });
  }

  // ================================================================= patient page
  if (root.dataset.page === 'patient') {
    const PID = root.dataset.patient;
    const S = {};

    // ---- ABHA: create via Aadhaar
    bind($('#c-send'), async () => {
      if (!$('#c-consent').checked) throw new Error('Beneficiary consent is required');
      const r = await api('m1/enrol_otp', { aadhaar: $('#c-aadhaar').value.trim(), consent: true });
      S.txnId = r.txnId; $('#c-msg').textContent = r.message || 'OTP sent'; show($('#c-step2'));
      $('#c-aadhaar').value = '';
    });
    bind($('#c-verify'), async () => {
      const mobile = $('#c-mobile').value.trim();
      const r = await api('m1/enrol_verify', { txnId: S.txnId, otp: $('#c-otp').value.trim(), mobile });
      S.txnId = r.txnId || S.txnId; S.token = r.tokens && r.tokens.token; S.profile = r.ABHAProfile || {};
      ok(r.message || (r.isNew ? 'ABHA created' : 'ABHA already exists for this Aadhaar'));
      if (S.profile.mobile && S.profile.mobile.slice(-4) !== mobile.slice(-4)) { S.mobile = mobile; show($('#c-step3')); }
      if (!(S.profile.phrAddress || []).length) {
        const s = await api('m1/address_suggestions', { txnId: S.txnId }).catch(() => ({}));
        S.txnId = s.txnId || S.txnId;
        $('#c-sugg').innerHTML = (s.abhaAddressList || []).map((a) => `<button type="button" class="btn btn-outline-secondary btn-sm me-1 mb-1">${esc(a)}</button>`).join('');
        $$('#c-sugg button').forEach((b) => b.addEventListener('click', () => ($('#c-addr').value = b.textContent)));
        show($('#c-step4'));
      } else {
        await attach(S.token);
      }
    });
    bind($('#c-motp-send'), async () => { const r = await api('m1/mobile_otp', { txnId: S.txnId, mobile: S.mobile }); S.txnId = r.txnId || S.txnId; ok(r.message || 'OTP sent'); });
    bind($('#c-motp-verify'), async () => {
      const r = await api('m1/mobile_verify', { txnId: S.txnId, otp: $('#c-motp').value.trim() });
      S.txnId = r.txnId || S.txnId; ok(r.message || 'Mobile verified'); show($('#c-step3'), false);
    });
    bind($('#c-addr-btn'), async () => {
      const r = await api('m1/address_create', { txnId: S.txnId, abhaAddress: $('#c-addr').value.trim() });
      ok(`ABHA address created: ${r.preferredAbhaAddress || ''}`); show($('#c-step4'), false);
      await attach(S.token);
    });

    // ---- ABHA: verify existing
    bind($('#v-send'), async () => {
      S.method = $('#v-method').value;
      const r = await api('m1/login_otp', { method: S.method, loginId: $('#v-id').value.trim() });
      S.vtxn = r.txnId; $('#v-msg').textContent = r.message || 'OTP sent'; show($('#v-step2'));
    });
    bind($('#v-verify'), async () => {
      const r = await api('m1/login_verify', { method: S.method, txnId: S.vtxn, otp: $('#v-otp').value.trim() });
      if (r.authResult && r.authResult !== 'success') throw new Error(r.message || 'Verification failed');
      const accounts = r.accounts || [];
      if (accounts.length && (accounts.length > 1 || S.method.startsWith('mobile'))) {
        $('#v-accounts').innerHTML = '<p class="mb-1">Select the ABHA to attach:</p>' + accounts.map((a, i) =>
          `<button type="button" class="btn btn-outline-primary btn-sm me-1 mb-1" data-i="${i}">${esc(a.name)} · ${esc(a.ABHANumber)} · ${esc(a.preferredAbhaAddress)}</button>`).join('');
        $$('#v-accounts button').forEach((b) => bind(b, async () => {
          const s = await api('m1/login_select', { txnId: r.txnId || S.vtxn, abhaNumber: accounts[b.dataset.i].ABHANumber, tToken: r.token });
          await attach(s.token);
        }));
        return;
      }
      await attach(r.token);
    });

    /** Save the verified ABHA to this patient (server re-reads the profile with the token). */
    async function attach(token) {
      if (!token) throw new Error('No ABHA session token returned');
      S.token = token;
      const r = await api('save_abha/' + PID, { token });
      ok(`ABHA ${r.abhaNumber || ''} ${r.abhaAddress || ''} attached`);
      $('#abha-current').innerHTML = `<strong>${esc(r.abhaNumber || '')}</strong> · ${esc(r.abhaAddress || '')} <span class="badge bg-success">Verified now</span>`;
      show($('#abha-card-actions'));
      show($('#abha-forms'), false);
    }
    bind($('#abha-card'), async () => window.open(URL.createObjectURL(await api('m1/card', { token: S.token }, { blob: true }))));
    bind($('#abha-qr'), async () => { $('#abha-qr-img').innerHTML = `<img width="200" alt="ABHA QR" src="${URL.createObjectURL(await api('m1/qr', { token: S.token }, { blob: true }))}">`; });
    bind($('#abha-change'), async () => show($('#abha-forms')));

    // ---- Records: sync + link
    async function refreshLinks() {
      const b = await api('bridge_status/' + PID);
      const linked = new Set((b.careContexts || []).filter((c) => c.linked_at).map((c) => c.reference));
      const known = new Set((b.careContexts || []).map((c) => c.reference));
      $$('#abdm-records tr[data-ref]').forEach((tr) => {
        const ref = tr.dataset.ref;
        $('.st', tr).innerHTML = linked.has(ref) ? badge('LINKED') : known.has(ref) ? '<span class="badge bg-info">Shared, not linked</span>' : $('.st', tr).innerHTML;
      });
      const job = (b.linkJobs || [])[0];
      $('#link-status').innerHTML = job ? `Last link request: ${badge(job.status)} ${esc(job.error || '')} <span class="text-muted small">${fmt(job.updated_at)}</span>` : '';
    }
    bind($('#abdm-sync'), async () => {
      const r = await api('sync_patient/' + PID, {});
      ok(`Synced: ${r.sent} sent, ${r.unchanged} unchanged` + (r.errors.length ? `. Errors: ${r.errors.join('; ')}` : ''));
      setTimeout(() => location.reload(), 800);
    });
    bind($('#abdm-link'), async () => {
      const r = await api('link/' + PID, {});
      ok(`Linking ${r.count} record(s) to ABHA…`);
      for (let i = 0; i < 40; i++) {
        const j = await api('link_status/' + r.linkJobId);
        $('#link-status').innerHTML = `Link request: ${badge(j.status)} ${esc(j.error || '')}`;
        if (['LINKED', 'FAILED'].includes(j.status)) break;
        await new Promise((res) => setTimeout(res, 3000));
      }
      await refreshLinks();
    });
    if ($('#abdm-records')) refreshLinks().catch(() => {});
  }

  // ================================================================= HIU / consent
  if (root.dataset.page === 'consent') {
    let meta = { purposes: {}, hiTypes: [] };
    const purposeSel = $('#cr-purpose');
    api('hiu/meta').then((m) => {
      meta = m;
      purposeSel.innerHTML = Object.entries(m.purposes).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('');
      $('#cr-types').innerHTML = m.hiTypes.map((t) => `<label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" value="${t}" checked> ${t}</label>`).join('');
    }).catch((e) => fail(e.message));

    bind($('#cr-send'), async () => {
      const hiTypes = $$('#cr-types input:checked').map((i) => i.value);
      const to = $('#cr-to').value;
      await api('hiu/create', { patient: $('#cr-patient').value.trim(), purpose: purposeSel.value, hiTypes,
        from: $('#cr-from').value || undefined, to: to ? `${to}T23:59:59+05:30` : undefined, eraseAt: $('#cr-erase').value || undefined,
        requester: { name: $('#cr-doctor').value.trim(), regNo: $('#cr-regno').value.trim() } });
      ok('Consent request sent. The patient approves it in their ABHA app.');
      setTimeout(load, 2500);
    });
    bind($('#cr-refresh'), load);

    async function load() {
      const reqs = await api('hiu/requests');
      $('#cr-list').innerHTML = reqs.length ? reqs.map((r) => `<tr><td>${esc(r.patient)}</td><td>${esc(meta.purposes[r.purpose] || r.purpose)}</td>
        <td>${badge(r.status)}${r.error ? `<div class="small text-danger">${esc(r.error)}</div>` : ''}</td><td>${fmt(r.created_at)}</td>
        <td class="text-end">${r.consent_request_id && r.status === 'REQUESTED' ? `<button class="btn btn-link btn-sm p-0" data-st="${esc(r.consent_request_id)}">Check status</button>` : ''}</td></tr>`).join('')
        : '<tr><td colspan="5" class="text-muted">No consent requests yet</td></tr>';
      $$('#cr-list [data-st]').forEach((b) => bind(b, async () => { await api('hiu/status/' + b.dataset.st, {}); ok('Status requested'); setTimeout(load, 2500); }));

      const arts = await api('hiu/artefacts');
      $('#ca-list').innerHTML = arts.length ? arts.map((a) => `<tr><td>${esc(a.detail?.patient?.id || '…')}</td><td>${badge(a.status)}</td><td>${esc(a.hip_id || '')}</td>
        <td>${a.detail ? `${fmtDate(a.detail.permission.dateRange.from)} – ${fmtDate(a.detail.permission.dateRange.to)}` : '<span class="text-muted">fetching…</span>'}</td>
        <td>${fmtDate(a.detail?.permission?.dataEraseAt)}</td>
        <td class="text-end">${a.status === 'GRANTED' && a.detail
          ? `<button class="btn btn-primary btn-sm" data-req="${esc(a.consent_id)}">Get records</button> <button class="btn btn-outline-secondary btn-sm" data-view="${esc(a.consent_id)}">View</button>`
          : `<button class="btn btn-link btn-sm p-0" data-fetch="${esc(a.consent_id)}">Refresh</button>`}</td></tr>`).join('')
        : '<tr><td colspan="6" class="text-muted">No consents granted yet</td></tr>';
      $$('#ca-list [data-fetch]').forEach((b) => bind(b, async () => { await api('hiu/fetch/' + b.dataset.fetch, {}); setTimeout(load, 2500); }));
      $$('#ca-list [data-req]').forEach((b) => bind(b, async () => {
        await api('hiu/request/' + b.dataset.req, {}); ok('Requested. Records arrive in a few seconds.');
        setTimeout(() => records(b.dataset.req), 6000);
      }));
      $$('#ca-list [data-view]').forEach((b) => b.addEventListener('click', () => records(b.dataset.view)));
    }

    async function records(consentId) {
      const rs = await api('hiu/records/' + (consentId || ''));
      $('#rec-list').innerHTML = rs.length ? rs.map((r) => {
        const entries = (r.content && r.content.entry) || [];
        const find = (t) => (entries.find((e) => e.resource.resourceType === t) || {}).resource || {};
        const comp = find('Composition'); const pt = find('Patient'); const org = find('Organization');
        const items = entries.filter((e) => !['Composition', 'Patient', 'Practitioner', 'Organization'].includes(e.resource.resourceType));
        return `<details class="mb-2 border rounded p-2"><summary><strong>${esc(comp.title || 'Record')}</strong> · ${esc(pt.name && pt.name[0] && pt.name[0].text)} · ${esc(org.name || '')} · ${fmtDate(comp.date)}</summary>
          <ul class="mt-2 mb-1">${items.map((e) => `<li><span class="badge bg-light text-dark">${esc(e.resource.resourceType)}</span> ${esc(summarize(e.resource))}</li>`).join('')}</ul>
          <div class="small text-muted">Our copy is erased on ${fmtDate(r.erase_at)}</div></details>`;
      }).join('') : '<p class="text-muted">No records received yet.</p>';
    }
    function summarize(r) {
      const val = r.valueQuantity ? `${r.valueQuantity.value} ${r.valueQuantity.unit || ''}` : r.valueString;
      const range = r.referenceRange && r.referenceRange[0] && r.referenceRange[0].text ? `(ref ${r.referenceRange[0].text})` : '';
      return [r.code && r.code.text, r.medicationCodeableConcept && r.medicationCodeableConcept.text, val, range,
        r.dosageInstruction && r.dosageInstruction[0] && r.dosageInstruction[0].text, r.conclusion].filter(Boolean).join(' — ');
    }
    load().catch((e) => fail(e.message));
    records().catch(() => {});
  }

  // ================================================================= Scan & Share
  if (root.dataset.page === 'share') {
    const patientUrl = root.dataset.patientUrl;
    async function load() {
      const rows = await api('share_queue');
      $('#sq-list').innerHTML = rows.length ? rows.map((r) => `<tr><td class="fs-5"><strong>${esc(r.token_number)}</strong></td><td>${esc(r.name)}</td>
        <td>${esc(r.abha_address)}</td><td>${esc(r.gender)} / ${esc(r.yearOfBirth)}</td><td>${esc(r.context)}</td><td>${fmt(r.created_at)}</td>
        <td class="text-end">${r.hms_patient_id ? `<a class="btn btn-outline-primary btn-sm" href="${patientUrl}${r.hms_patient_id}">Open patient #${r.hms_patient_id}</a>`
          : `<button class="btn btn-primary btn-sm" data-reg="${esc(r.id)}">Register patient</button>`}</td></tr>`).join('')
        : '<tr><td colspan="7" class="text-muted">No tokens today</td></tr>';
      $$('#sq-list [data-reg]').forEach((b) => bind(b, async () => {
        const r = await api('share_register', { id: b.dataset.reg });
        ok(r.existing ? 'Patient already registered' : `Registered as patient #${r.patientId}`);
        load();
      }));
    }
    bind($('#sq-refresh'), load);
    load().catch((e) => fail(e.message));
    setInterval(() => load().catch(() => {}), 30000);
  }
})();
