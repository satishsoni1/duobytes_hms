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
    if (!res.ok) throw Object.assign(new Error(data.error || `HTTP ${res.status}`), { status: res.status, code: data.code });
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

  // ================================================================= M1: create / verify ABHA (shared)
  const digits = (s) => String(s ?? '').replace(/\D/g, '');
  const masked = (s, keep = 4) => { const d = digits(s); return d ? '*'.repeat(Math.max(0, d.length - keep)) + d.slice(-keep) : ''; };
  const validMobile = (v) => /^[6-9]\d{9}$/.test(v);
  // Verhoeff checksum: every Aadhaar number passes it, and none starts with 0 or 1 (CRT_ABHA_104, VRFY_ABHA_401).
  const VD = [[0,1,2,3,4,5,6,7,8,9],[1,2,3,4,0,6,7,8,9,5],[2,3,4,0,1,7,8,9,5,6],[3,4,0,1,2,8,9,5,6,7],[4,0,1,2,3,9,5,6,7,8],
    [5,9,8,7,6,0,4,3,2,1],[6,5,9,8,7,1,0,4,3,2],[7,6,5,9,8,2,1,0,4,3],[8,7,6,5,9,3,2,1,0,4],[9,8,7,6,5,4,3,2,1,0]];
  const VP = [[0,1,2,3,4,5,6,7,8,9],[1,5,7,6,2,8,3,0,9,4],[5,8,0,3,7,9,6,1,4,2],[8,9,1,6,0,4,3,5,2,7],[9,4,5,3,1,2,6,8,7,0],
    [4,6,8,7,9,3,0,2,1,5],[2,7,9,3,8,0,6,4,1,5],[7,0,4,6,9,1,3,2,5,8]];
  const validAadhaar = (v) => {
    if (!/^[2-9]\d{11}$/.test(v)) return false;
    let c = 0;
    [...v].reverse().forEach((d, i) => { c = VD[c][VP[i % 8][Number(d)]]; });
    return c === 0;
  };
  const AADHAAR_INVALID = 'Aadhaar Number is not valid';
  const switchTab = (id) => {
    const t = $(id);
    if (!t) return;
    if (window.bootstrap && bootstrap.Tab) bootstrap.Tab.getOrCreateInstance(t).show(); else t.click();
  };

  /** 6 digits only; the verify button is enabled when the OTP is complete (CRT_ABHA_105, CRT_ABHA_107). */
  function wireOtp(input, button) {
    if (!input) return;
    const sync = () => { input.value = digits(input.value).slice(0, 6); if (button) button.disabled = input.value.length !== 6; };
    input.addEventListener('input', sync);
    sync();
  }
  const otpOf = (input) => {
    const v = digits(input.value);
    if (v.length !== 6) throw new Error('Please enter the 6-digit OTP');
    return v;
  };

  /**
   * Resend OTP control, shared by every OTP box (CRT_ABHA_106, CRT_ABHA_109, VRFY_ABHA_101/201/304/405):
   * enabled 60 s after each send, two resends, the counter visible, and a third attempt refused with ABDM's wording.
   * send() sends the OTP again.
   */
  const RESEND_WAIT = 60, RESEND_MAX = 2, RESEND_LOCK_MS = 30 * 60_000;
  function resender(el, send) {
    if (!el) return { start() {} };
    el.innerHTML = `<button type="button" class="btn btn-link btn-sm p-0 align-baseline">Resend OTP</button>
      <span class="small text-muted ms-1 r-wait"></span><span class="small text-muted ms-2 r-left"></span>
      <div class="small text-danger r-err"></div>`;
    const btn = $('button', el), wait = $('.r-wait', el), left = $('.r-left', el), err = $('.r-err', el);
    let used = 0, timer = null, lockedUntil = 0, busy = false;
    const paint = () => { left.textContent = `· resends left: ${Math.max(0, RESEND_MAX - used)} of ${RESEND_MAX}`; };
    const countdown = () => {
      clearInterval(timer);
      let s = RESEND_WAIT;
      btn.disabled = true;
      wait.textContent = `in ${s}s`;
      timer = setInterval(() => {
        s -= 1;
        if (s > 0) { wait.textContent = `in ${s}s`; return; }
        clearInterval(timer); wait.textContent = ''; btn.disabled = Date.now() < lockedUntil;
      }, 1000);
    };
    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      if (busy) return;
      err.textContent = '';
      if (used >= RESEND_MAX || Date.now() < lockedUntil) {
        if (!lockedUntil) lockedUntil = Date.now() + RESEND_LOCK_MS;
        err.textContent = 'Maximum OTP resend attempts reached. Please try again after 30 mins.';
        btn.disabled = true;
        return;
      }
      busy = true; btn.disabled = true;
      try { await send(); used += 1; paint(); ok('A new OTP has been sent'); countdown(); }
      catch (ex) { fail(ex.message); btn.disabled = false; }
      finally { busy = false; }
    });
    return { start() { used = 0; lockedUntil = 0; err.textContent = ''; paint(); countdown(); } };
  }

  /**
   * Offer to update the mobile on the ABHA when the hospital holds a different one (VRFY_ABHA_101/201 step 5).
   * Only for a token from an ABHA number, mobile, Aadhaar or enrolment login ('abha'); not for an ABHA address login.
   */
  function offerMobileUpdate(box, { token, kind, abhaMobile, hmsMobile }) {
    if (!box) return;
    const hms = digits(hmsMobile).slice(-10), abha = digits(abhaMobile);
    if (kind !== 'abha' || !token || !validMobile(hms) || (abha && abha.slice(-4) === hms.slice(-4) && (abha.length < 10 || abha.slice(-10) === hms))) {
      show(box, false); return;
    }
    box.innerHTML = `<div class="alert alert-info py-2 small mb-2">
      The hospital has mobile <strong>${esc(masked(hms))}</strong> for this patient${abha ? `, but the ABHA has <strong>${esc(masked(abha))}</strong>` : ''}.
      Update the mobile on the ABHA profile?
      <div class="mt-2"><button type="button" class="btn btn-primary btn-sm mu-yes">Yes, send OTP to ${esc(masked(hms))}</button>
      <button type="button" class="btn btn-outline-secondary btn-sm mu-no">No</button></div>
      <div class="mu-step d-none mt-2">
        <input class="form-control form-control-sm mb-1 mu-otp" placeholder="6-digit OTP" inputmode="numeric" maxlength="6" autocomplete="one-time-code">
        <div class="mu-resend mb-1"></div>
        <button type="button" class="btn btn-success btn-sm mu-verify" disabled>Verify &amp; update</button>
      </div></div>`;
    show(box);
    const S = {};
    const send = async () => { const r = await api('m1/mobile_update_otp', { token, mobile: hms }); S.txnId = r.txnId; return r; };
    const res = resender($('.mu-resend', box), send);
    wireOtp($('.mu-otp', box), $('.mu-verify', box));
    $('.mu-no', box).addEventListener('click', () => show(box, false));
    bind($('.mu-yes', box), async () => { await send(); show($('.mu-step', box)); $('.mu-yes', box).classList.add('d-none'); res.start(); });
    bind($('.mu-verify', box), async () => {
      const r = await api('m1/mobile_update_verify', { token, txnId: S.txnId, otp: otpOf($('.mu-otp', box)) });
      box.innerHTML = `<div class="alert alert-success py-2 small mb-2">${esc(r.message || 'Mobile number updated on the ABHA profile')}.</div>`;
    });
  }

  async function blobUrl(path, body) { return URL.createObjectURL(await api(path, body, { blob: true })); }
  /** Download the ABHA card with a file name, rather than only opening it (CRT_ABHA_114). */
  async function downloadCard(token, kind, number) {
    const blob = await api('m1/card', { token, kind }, { blob: true });
    const ext = /pdf/.test(blob.type) ? 'pdf' : /jpe?g/.test(blob.type) ? 'jpg' : 'png';
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `ABHA-card-${digits(number) || 'patient'}.${ext}`;
    document.body.appendChild(a); a.click(); a.remove();
  }

  /** Wires the _m1_forms.php controls. done(token, kind) runs once ABDM returns a user token for the verified/created ABHA. */
  function m1Flow(S, done) {
    // ABHA address suffix of this environment (@sbx in the sandbox, @abdm in production)
    S.suffix = '@sbx';
    api('m1/address_rules', {}).then((r) => { S.suffix = r.suffix || S.suffix; $$('.v-suffix').forEach((e) => (e.textContent = S.suffix)); checkRules(); }).catch(() => {});

    $$('.m1-eye').forEach((b) => b.addEventListener('click', () => { const i = $('#' + b.dataset.for); i.type = i.type === 'password' ? 'text' : 'password'; }));
    ['#v-aad', '#c-aadhaar', '#v-mob', '#c-mobile'].forEach((id) => $(id) && $(id).addEventListener('input', (e) => (e.target.value = digits(e.target.value))));

    // ================= Verify: the identifier first, then how to authenticate =================
    const vOtp = $('#v-otp-in');
    wireOtp(vOtp, $('#v-otp-verify'));
    const vRes = resender($('#v-otp-resend'), () => S.vResend());
    function openOtp(message, resend, verify) {
      S.vResend = resend; S.vVerify = verify;
      $('#v-otp-msg').textContent = message || 'OTP sent';
      vOtp.value = ''; $('#v-otp-verify').disabled = true;
      show($('#v-otp')); vRes.start(); vOtp.focus();
    }
    function resetVerify() {
      show($('#v-otp'), false); $('#v-accounts').innerHTML = '';
      show($('#v-aad-none'), false); $('#v-find').innerHTML = ''; $('#v-addr-methods').innerHTML = '';
      ['#v-aad-err', '#v-mob-err'].forEach((id) => ($(id).textContent = ''));
    }
    const ident = () => ($('input[name="v-ident"]:checked') || {}).value;
    function setIdent(v) {
      const r = $(`#v-ident-${v}`);
      if (r) r.checked = true;
      $$('.v-pane').forEach((p) => show(p, p.dataset.ident === v));
      resetVerify();
    }
    $$('input[name="v-ident"]').forEach((r) => r.addEventListener('change', () => setIdent(ident())));
    bind($('#v-otp-verify'), async () => S.vVerify(otpOf(vOtp)));

    /** ABHA number or Aadhaar login: OTP, then one account or a choice of several. */
    async function loginBy(method, loginId) {
      const send = async () => { const r = await api('m1/login_otp', { method, loginId }); S.vtxn = r.txnId; return r; };
      const r = await send();
      openOtp(r.message, async () => { const x = await send(); $('#v-otp-msg').textContent = x.message || 'OTP sent'; }, async (otp) => {
        let v;
        try {
          v = await api('m1/login_verify', { method, txnId: S.vtxn, otp });
        } catch (e) {
          if (method.startsWith('aadhaar') && (e.code === 'ABDM-1114' || e.status === 404)) {
            // VRFY_ABHA_403: no ABHA on this Aadhaar. Offer creation, carrying the Aadhaar number over.
            S.carryAadhaar = loginId;
            show($('#v-otp'), false); show($('#v-aad-none'));
            return;
          }
          throw e;
        }
        if (v.authResult && v.authResult !== 'success') throw new Error(v.message || 'Verification failed');
        const accounts = v.accounts || [];
        if (accounts.length > 1) {
          $('#v-accounts').innerHTML = '<p class="mb-1 small">This Aadhaar holds several ABHAs. Select the patient\'s:</p>' + accounts.map((a, i) =>
            `<button type="button" class="btn btn-outline-primary btn-sm me-1 mb-1" data-i="${i}">${esc(a.name)} · ${esc(a.ABHANumber)} · ${esc(a.preferredAbhaAddress)}</button>`).join('');
          $$('#v-accounts button').forEach((b) => bind(b, async () => {
            const s = await api('m1/login_select', { txnId: v.txnId || S.vtxn, abhaNumber: accounts[b.dataset.i].ABHANumber, tToken: v.token });
            await done(s.token, 'abha');
          }));
          show($('#v-otp'), false);
          return;
        }
        await done(v.token, 'abha');
      });
    }

    bind($('#v-num-send'), async () => {
      const n = digits($('#v-num').value);
      if (n.length !== 14) throw new Error('ABHA number must be 14 digits');
      resetVerify();
      await loginBy($('#v-num-method').value, n);
    });

    bind($('#v-aad-send'), async () => {
      const a = digits($('#v-aad').value);
      resetVerify();
      if (!validAadhaar(a)) { $('#v-aad-err').textContent = AADHAAR_INVALID; return; }
      await loginBy('aadhaar:aadhaar', a);
      $('#v-aad').value = '';
    });
    bind($('#v-aad-create'), async () => {
      S.aadhaar = S.carryAadhaar;
      show($('#c-aadhaar-box'), false);
      $('#c-aadhaar-carried').innerHTML = `Aadhaar ending <strong>${esc(S.aadhaar.slice(-4))}</strong> carried over from verification. Take the patient's consent below, then send the OTP.`;
      show($('#c-aadhaar-carried'));
      show($('#v-aad-none'), false);
      switchTab('#m1-tab-create');
    });

    // ABHA address: the ways to log in come from ABDM (VRFY_ABHA_102/202)
    const METHOD_LABEL = { 'abha-address:aadhaar': 'OTP to the Aadhaar-linked mobile', 'abha-address:mobile': 'OTP to the ABHA-linked mobile' };
    bind($('#v-addr-search'), async () => {
      const typed = $('#v-addr').value.trim().toLowerCase();
      if (typed.length < 3) throw new Error('Enter the ABHA address');
      resetVerify();
      const r = await api('m1/address_search', { abhaAddress: typed });
      if (!r.methods.length) {
        $('#v-addr-methods').innerHTML = `<div class="alert alert-warning py-1 px-2 small mb-0">${esc(r.abhaAddress)} cannot be verified by OTP here
          (ABDM offers: ${esc(r.authMethods.join(', ') || 'none')}). Use the patient's ABHA number or mobile instead.</div>`;
        return;
      }
      $('#v-addr-methods').innerHTML = `<p class="small mb-1">Verify <strong>${esc(r.abhaAddress)}</strong> with:</p>` + r.methods.map((m) =>
        `<button type="button" class="btn btn-outline-primary btn-sm me-1 mb-1" data-m="${esc(m)}">${esc(METHOD_LABEL[m] || m)}</button>`).join('');
      $$('#v-addr-methods button').forEach((b) => bind(b, async () => {
        const method = b.dataset.m;
        const send = async () => { const x = await api('m1/address_otp', { abhaAddress: r.abhaAddress, method }); S.vtxn = x.txnId; return x; };
        const x = await send();
        openOtp(x.message, async () => { const y = await send(); $('#v-otp-msg').textContent = y.message || 'OTP sent'; }, async (otp) => {
          const v = await api('m1/address_verify', { method, txnId: S.vtxn, otp });
          if (v.authResult && v.authResult !== 'success') throw new Error(v.message || 'Verification failed');
          await done(v.token, 'phr');
        });
      }));
    });

    // Mobile only: find a forgotten ABHA. List first, OTP only after a pick (VRFY_ABHA_301-307)
    bind($('#v-mob-search'), async () => {
      const m = digits($('#v-mob').value);
      resetVerify();
      if (!validMobile(m)) { $('#v-mob-err').textContent = 'Please enter a valid mobile number.'; return; }
      const r = await api('m1/find_search', { mobile: m });
      if (!r.accounts.length) {
        $('#v-find').innerHTML = `<div class="alert alert-warning py-2 small mb-0"><strong>ABHA details not found.</strong> We did not find any ABHA number linked to this mobile number.
          Please use an ABHA-linked mobile number.<div class="mt-2"><button type="button" class="btn btn-success btn-sm" id="v-find-create">Create a new ABHA</button></div></div>`;
        $('#v-find-create').addEventListener('click', () => { $('#c-mobile').value = m; switchTab('#m1-tab-create'); });
        return;
      }
      $('#v-find').innerHTML = `<p class="small mb-1">ABHAs on mobile ${esc(masked(m))}. Select the patient's, then send the OTP:</p>` +
        r.accounts.map((a, i) => `<div class="border rounded p-2 mb-1 m1-acct" data-i="${i}" role="button" tabindex="0">
          <strong>${esc(a.name)}</strong> <span class="text-muted small">${esc(a.gender)}</span> · <span class="font-monospace">${esc(a.abhaNumber)}</span>
          ${a.kycVerified ? '<span class="badge bg-success ms-1">KYC verified</span>' : ''}</div>`).join('') +
        '<button type="button" class="btn btn-primary btn-sm mt-1" id="v-find-send" disabled>Send OTP</button>';
      let pick = null;
      $$('#v-find .m1-acct').forEach((el) => el.addEventListener('click', () => {
        $$('#v-find .m1-acct').forEach((x) => x.classList.toggle('active', x === el));
        pick = r.accounts[el.dataset.i];
        $('#v-find-send').disabled = false;
        show($('#v-otp'), false);
      }));
      bind($('#v-find-send'), async () => {
        if (!pick) throw new Error('Select the patient\'s ABHA first');
        const chosen = pick;
        const send = async () => { const x = await api('m1/find_otp', { txnId: r.txnId, index: chosen.index }); S.vtxn = x.txnId; return x; };
        const x = await send();
        openOtp(`${x.message || 'OTP sent'} · for ${chosen.name} (${chosen.abhaNumber})`, async () => { await send(); }, async (otp) => {
          let v;
          try {
            v = await api('m1/find_verify', { txnId: S.vtxn, otp });
          } catch (e) {
            throw new Error(e.status && e.status < 500 ? 'Invalid OTP. Please enter a valid OTP.' : e.message);
          }
          if (v.authResult && v.authResult !== 'success') throw new Error('Invalid OTP. Please enter a valid OTP.');
          await done(v.token, 'abha');
        });
      });
    });

    // ABHA card QR: the scanner types the JSON payload into the field
    const qr = $('#v-qr');
    if (qr) {
      const parseQr = () => {
        let d;
        try { d = JSON.parse(qr.value.trim()); } catch { return; }
        const num = d.hidn || d.abhaNumber || d.abha_number || d.ABHANumber || '';
        const addr = d.hid || d.phr || d.abhaAddress || d.abha_address || '';
        if (!num && !addr) { $('#v-qr-info').innerHTML = '<span class="text-danger">This QR is not an ABHA card QR.</span>'; return; }
        if (num) { setIdent('number'); $('#v-num').value = num; $('#v-num-method').value = 'abha-number:mobile'; }
        else { setIdent('address'); $('#v-addr').value = addr; }
        $('#v-qr-info').innerHTML = `<div class="alert alert-info py-1 px-2 mb-0">Scanned ABHA: <strong>${esc(d.name || '')}</strong>
          ${esc([d.gender, d.dob || d.yob].filter(Boolean).join(' · '))} · ${esc(num)} ${esc(addr)}<br>
          <span class="text-muted">Compare with the patient, then send the OTP to verify.</span></div>`;
        qr.value = '';
      };
      qr.addEventListener('change', parseQr);
      qr.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); parseQr(); } });
      qr.addEventListener('paste', () => setTimeout(parseQr, 0));
    }

    // ================= Create via Aadhaar OTP =================
    wireOtp($('#c-otp'), $('#c-verify'));
    wireOtp($('#c-motp'), $('#c-motp-verify'));
    const sendAadhaarOtp = async () => { const r = await api('m1/enrol_otp', { aadhaar: S.aadhaar, consent: true }); S.txnId = r.txnId; return r; };
    const cRes = resender($('#c-otp-resend'), async () => { const r = await sendAadhaarOtp(); $('#c-msg').textContent = r.message || 'OTP sent'; });
    const sendMobileOtp = async () => { const r = await api('m1/mobile_otp', { txnId: S.txnId, mobile: S.mobile }); S.txnId = r.txnId || S.txnId; return r; };
    const mRes = resender($('#c-motp-resend'), async () => { const r = await sendMobileOtp(); $('#c-motp-msg').textContent = r.message || 'OTP sent'; });

    bind($('#c-send'), async () => {
      $('#c-aadhaar-err').textContent = '';
      const a = S.carryAadhaar && $('#c-aadhaar-box').classList.contains('d-none') ? S.carryAadhaar : digits($('#c-aadhaar').value);
      if (!validAadhaar(a)) { $('#c-aadhaar-err').textContent = AADHAAR_INVALID; return; }
      if (!$('#c-consent').checked) throw new Error('Tick the consent box: the patient must agree before the Aadhaar OTP is sent');
      S.aadhaar = a; // held in this page only, for a resend; never stored
      const r = await sendAadhaarOtp();
      $('#c-msg').textContent = r.message || 'OTP sent';
      $('#c-aadhaar').value = '';
      show($('#c-step2')); cRes.start(); $('#c-otp').focus();
    });
    bind($('#c-verify'), async () => {
      const mobile = digits($('#c-mobile').value);
      if (!validMobile(mobile)) throw new Error('Please enter a valid mobile number.');
      const r = await api('m1/enrol_verify', { txnId: S.txnId, otp: otpOf($('#c-otp')), mobile });
      S.txnId = r.txnId || S.txnId; S.token = r.tokens && r.tokens.token; S.profile = r.ABHAProfile || {};
      ok(r.isNew === false ? 'An ABHA already exists for this Aadhaar; its details are used' : (r.message || 'ABHA created'));
      show($('#c-step2'), false);
      if (r.mobileVerificationNeeded) {
        // CRT_ABHA_109: a mobile other than the Aadhaar-linked one is verified by its own OTP before going on.
        S.mobile = mobile;
        $('#c-motp-to').textContent = masked(mobile);
        const m = await sendMobileOtp();
        $('#c-motp-msg').textContent = m.message || 'OTP sent';
        show($('#c-step3')); mRes.start(); $('#c-motp').focus();
        return;
      }
      await addressStep();
    });
    bind($('#c-motp-verify'), async () => {
      const r = await api('m1/mobile_verify', { txnId: S.txnId, otp: otpOf($('#c-motp')) });
      if (r.authResult && r.authResult !== 'success') throw new Error(r.message || 'Mobile verification failed');
      S.txnId = r.txnId || S.txnId; ok(r.message || 'Mobile verified');
      show($('#c-step3'), false);
      await addressStep();
    });

    /** CRT_ABHA_112: an ABHA that already has an address may go on with it; otherwise pick a suggestion or type one. */
    async function addressStep() {
      show($('#c-step4'));
      const existing = (S.profile.phrAddress || []).concat(S.profile.preferredAbhaAddress ? [S.profile.preferredAbhaAddress] : [])
        .filter((v, i, a) => v && a.indexOf(v) === i);
      if (existing.length) {
        $('#c-existing').innerHTML = `<p class="small mb-1">This ABHA already has the ABHA address <strong>${esc(existing[0])}</strong>.</p>
          <button type="button" class="btn btn-success btn-sm me-1" id="c-keep">Proceed with ${esc(existing[0])}</button>
          <button type="button" class="btn btn-outline-primary btn-sm" id="c-another">Create a new ABHA address</button>`;
        show($('#c-existing')); show($('#c-new-addr'), false);
        bind($('#c-keep'), async () => { show($('#c-step4'), false); await done(S.token, 'abha'); });
        $('#c-another').addEventListener('click', () => { show($('#c-new-addr')); loadSuggestions(); });
        return;
      }
      show($('#c-existing'), false); show($('#c-new-addr'));
      await loadSuggestions();
    }
    async function loadSuggestions() {
      const s = await api('m1/address_suggestions', { txnId: S.txnId }).catch(() => ({}));
      S.txnId = s.txnId || S.txnId;
      const list = (s.abhaAddressList || []).slice(0, 6);
      $('#c-sugg').innerHTML = list.length ? list.map((a) => `<button type="button" class="btn btn-outline-secondary btn-sm me-1 mb-1">${esc(a)}</button>`).join('')
        : '<span class="small text-muted">No suggestions from ABDM; type an address below.</span>';
      $$('#c-sugg button').forEach((b) => b.addEventListener('click', () => { $('#c-addr').value = b.textContent.replace(/@.*$/, ''); checkRules(); }));
    }
    // Rules beside the field, green when met and red when not (CRT_ABHA_112)
    function checkRules() {
      const input = $('#c-addr');
      if (!input) return false;
      const p = input.value.trim().replace(/@(sbx|abdm)$/i, '');
      const total = p.length + S.suffix.length;
      const res = {
        length: total >= 8 && total <= 18,
        characters: /^[A-Za-z0-9._]*$/.test(p) && (p.match(/\./g) || []).length <= 1 && (p.match(/_/g) || []).length <= 1 && /[A-Za-z0-9]/.test(p),
        edges: p !== '' && !/^[._]|[._]$/.test(p),
      };
      $$('#c-rules li').forEach((li) => { li.classList.toggle('ok', p !== '' && res[li.dataset.rule]); li.classList.toggle('bad', p !== '' && !res[li.dataset.rule]); });
      const all = Object.values(res).every(Boolean);
      $('#c-addr-btn').disabled = !all;
      return all;
    }
    if ($('#c-addr')) $('#c-addr').addEventListener('input', () => { show($('#c-exists'), false); checkRules(); });
    bind($('#c-addr-btn'), async () => {
      if (!checkRules()) throw new Error('The ABHA address does not meet the rules');
      try {
        const r = await api('m1/address_create', { txnId: S.txnId, abhaAddress: $('#c-addr').value.trim().replace(/@(sbx|abdm)$/i, '') });
        ok(`ABHA address created: ${r.preferredAbhaAddress || ''}`);
      } catch (e) {
        if (e.code !== 'ABHA_ADDRESS_EXISTS') throw e;
        const existing = S.profile.preferredAbhaAddress || (S.profile.phrAddress || [])[0];
        $('#c-exists').innerHTML = `<strong>ABHA address already exists.</strong>
          <div class="mt-1">${existing ? `<button type="button" class="btn btn-success btn-sm me-1" id="c-exists-keep">Proceed with the existing ABHA address (${esc(existing)})</button>` : ''}
          <button type="button" class="btn btn-outline-primary btn-sm" id="c-exists-new">Create a new ABHA address</button></div>`;
        show($('#c-exists'));
        if ($('#c-exists-keep')) bind($('#c-exists-keep'), async () => { show($('#c-step4'), false); await done(S.token, 'abha'); });
        $('#c-exists-new').addEventListener('click', () => { $('#c-addr').value = ''; show($('#c-exists'), false); checkRules(); $('#c-addr').focus(); });
        return;
      }
      show($('#c-step4'), false);
      await done(S.token, 'abha');
    });
  }

  // ================================================================= dashboard
  if (root.dataset.page === 'index') {
    bind($('#abdm-sync-all'), async () => {
      const r = await api('sync_all', {});
      const l = r.linking || {};
      $('#abdm-sync-result').textContent = `Checked ${r.checked}, sent ${r.sent}, unchanged ${r.unchanged}, skipped ${r.skipped}` +
        (r.errors.length ? `, errors: ${r.errors.join('; ')}` : '') +
        (l.patients ? `. Linking: ${l.started} started, ${l.running} in progress, ${l.done} complete` + (l.errors.length ? `, errors: ${l.errors.join('; ')}` : '') : '');
      ok('Sync finished');
    });
  }

  // ================================================================= patient page
  if (root.dataset.page === 'patient') {
    const PID = root.dataset.patient;
    const S = {};

    m1Flow(S, attach);

    /** Save the verified ABHA to this patient (server re-reads the profile with the token). */
    async function attach(token, kind = 'abha') {
      if (!token) throw new Error('No ABHA session token returned');
      S.token = token; S.kind = kind;
      let r = await api('save_abha/' + PID, { token, kind });
      if (r.mismatch) {
        const msg = 'The ABHA details do not match this patient:\n\n- ' + r.mismatch.join('\n- ')
          + '\n\nAttach only if you have confirmed this is the same person (e.g. checked an ID). Attach anyway?';
        if (!confirm(msg)) throw new Error('ABHA not attached: details did not match this patient.');
        r = await api('save_abha/' + PID, { token, kind, confirmMismatch: true });
      }
      ok(`ABHA ${r.abhaNumber || ''} ${r.abhaAddress || ''} attached`);
      $('#abha-current').innerHTML = `<strong>${esc(r.abhaNumber || '')}</strong> · ${esc(r.abhaAddress || '')} <span class="badge bg-success">Verified now</span>`;
      show($('#abha-card-actions'));
      show($('#abha-forms'), false);
      offerMobileUpdate($('#abha-mob'), { token, kind, abhaMobile: r.abhaMobile, hmsMobile: r.hmsMobile });
    }
    bind($('#abha-card'), async () => downloadCard(S.token, S.kind, ''));
    bind($('#abha-qr'), async () => { $('#abha-qr-img').innerHTML = `<img width="200" alt="ABHA QR" src="${await blobUrl('m1/qr', { token: S.token, kind: S.kind })}">`; });
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
    bind($('#abdm-sms'), async () => {
      await api('sms_link/' + PID, {});
      ok('ABDM will send the patient an SMS with steps to link these records.');
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
    const HI_LABEL = { OPConsultation: 'OP consultation', Prescription: 'Prescription', DischargeSummary: 'Discharge summary',
      DiagnosticReport: 'Diagnostic report', ImmunizationRecord: 'Immunization', HealthDocumentRecord: 'Health document',
      WellnessRecord: 'Wellness record', Invoice: 'Invoice' };
    const hiLabel = (t) => HI_LABEL[t] || t || 'Other';
    const chips = (list) => (list || []).map((t) => `<span class="badge bg-light text-dark border me-1">${esc(hiLabel(t))}</span>`).join('');
    const day = (d) => (d ? fmtDate(d) : '—');
    const timeline = (h) => (h || []).map((x) => `${badge(x.status)} <span class="text-muted">${esc(fmt(x.at))}</span>`).join(' <span class="text-muted">&rarr;</span> ');
    const sameDay = (a, b) => a && b && new Date(a).toDateString() === new Date(b).toDateString();
    const edited = '<span class="badge bg-info ms-1" title="Changed by the patient when granting">edited by patient</span>';

    api('hiu/meta').then((m) => {
      meta = m;
      if (purposeSel) purposeSel.innerHTML = Object.entries(m.purposes).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('');
      if ($('#cr-types')) $('#cr-types').innerHTML = m.hiTypes.map((t) => `<label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" value="${t}" checked> ${esc(hiLabel(t))}</label>`).join('');
    }).catch((e) => fail(e.message));

    // HIU_FLOW_102: the patient must be known here; show who they are before the request goes out.
    const who = async () => {
      const box = $('#cr-who'), a = ($('#cr-patient') || {}).value?.trim();
      if (!box) return null;
      if (!a) { box.innerHTML = ''; return null; }
      const p = await api('hiu/patient?abha=' + encodeURIComponent(a)).catch(() => null);
      box.innerHTML = p ? `<span class="text-success">&#10004;</span> <strong>${esc(p.name)}</strong> · patient #${esc(p.id)} · ABHA number ${esc(p.abhaNumber || '—')}`
        : '<span class="text-danger">This ABHA address is not on any patient here. Verify the patient\'s ABHA first (Create / Verify ABHA).</span>';
      return p;
    };
    if ($('#cr-patient')) { $('#cr-patient').addEventListener('change', who); who(); }

    bind($('#cr-send'), async () => {
      if (!(await who())) throw new Error('Verify the patient\'s ABHA before requesting records');
      const hiTypes = $$('#cr-types input:checked').map((i) => i.value);
      if (!hiTypes.length) throw new Error('Choose at least one health info type');
      const to = $('#cr-to').value;
      await api('hiu/create', { patient: $('#cr-patient').value.trim(), purpose: purposeSel.value, hiTypes,
        from: $('#cr-from').value || undefined, to: to ? `${to}T23:59:59+05:30` : undefined, eraseAt: $('#cr-erase').value || undefined,
        requester: { name: $('#cr-doctor').value.trim(), regNo: $('#cr-regno').value.trim() } });
      ok('Consent request sent. The patient approves it in their ABHA app.');
      setTimeout(load, 2500);
    });
    bind($('#cr-refresh'), load);
    $('#cr-status').addEventListener('change', load);

    /** HIU_FLOW_102/104/103/106: every request with its patient, values, the time of each status, and what each hospital granted. */
    async function load() {
      const [reqs, arts] = await Promise.all([api('hiu/requests'), api('hiu/artefacts')]);
      const want = $('#cr-status').value;
      const byReq = {};
      arts.forEach((a) => (byReq[a.consent_request_id] ||= []).push(a));
      const list = reqs.filter((r) => !want || r.status === want);
      $('#cr-list').innerHTML = list.length ? list.map((r) => {
        const p = r.hms || {};
        const items = byReq[r.consent_request_id] || [];
        const artRows = items.map((a) => {
          const d = a.detail || {};
          const perm = d.permission || {};
          const range = perm.dateRange || {};
          const typesEdited = d.hiTypes && (d.hiTypes.length !== (r.hi_types || []).length || d.hiTypes.some((t) => !(r.hi_types || []).includes(t)));
          const fromEdited = range.from && !sameDay(range.from, r.date_from), toEdited = range.to && !sameDay(range.to, r.date_to);
          const expEdited = perm.dataEraseAt && !sameDay(perm.dataEraseAt, r.erase_at);
          return `<tr><td>${esc((d.hip && (d.hip.name || d.hip.id)) || a.hip_id || '…')}</td>
            <td>${timeline(a.status_history && a.status_history.length ? a.status_history : [{ status: a.status, at: a.updated_at }])}</td>
            <td>${a.detail ? chips(d.hiTypes) + (typesEdited ? edited : '') : '<span class="text-muted">fetching…</span>'}</td>
            <td class="text-nowrap">${a.detail ? `${day(range.from)} – ${day(range.to)}${fromEdited || toEdited ? edited : ''}` : ''}</td>
            <td class="text-nowrap">${a.detail ? day(perm.dataEraseAt) + (expEdited ? edited : '') : ''}</td>
            <td class="text-end text-nowrap">${a.status === 'GRANTED' && a.detail
              ? `<button class="btn btn-primary btn-sm" data-req="${esc(a.consent_id)}">Get records</button> <button class="btn btn-outline-secondary btn-sm" data-view="${esc(a.consent_id)}">View</button>`
              : a.status === 'GRANTED' ? `<button class="btn btn-link btn-sm p-0" data-fetch="${esc(a.consent_id)}">Refresh</button>` : ''}</td></tr>`;
        }).join('');
        return `<div class="border rounded p-2 mb-2">
          <div class="d-flex justify-content-between flex-wrap gap-2">
            <div><strong>${esc(p.name || 'Unknown patient')}</strong> · ${esc(r.patient)} · ABHA number ${esc(p.abhaNumber || '—')}${p.id ? ` · patient #${esc(p.id)}` : ''}</div>
            <div>${badge(r.status)}</div>
          </div>
          <div class="small mt-1">${esc(meta.purposes[r.purpose] || r.purpose)} · ${chips(r.hi_types)}</div>
          <div class="small text-muted">Health info ${day(r.date_from)} – ${day(r.date_to)} · consent expiry ${day(r.erase_at)} · requested ${esc(fmt(r.created_at))}
            by ${esc((r.requester && r.requester.name) || '')}</div>
          <div class="small mt-1">${timeline(r.status_history && r.status_history.length ? r.status_history : [{ status: r.status, at: r.updated_at }])}</div>
          ${r.error ? `<div class="small text-danger">${esc(r.error)}</div>` : ''}
          ${r.consent_request_id && r.status === 'REQUESTED' ? `<button class="btn btn-link btn-sm p-0" data-st="${esc(r.consent_request_id)}">Check status</button>` : ''}
          ${artRows ? `<div class="table-responsive mt-2"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>From hospital</th><th>Status</th>
            <th>Granted types</th><th>Granted period</th><th>Expiry</th><th></th></tr></thead><tbody>${artRows}</tbody></table></div>` : ''}
        </div>`;
      }).join('') : '<p class="text-muted mb-0">No consent requests' + (want ? ` with status ${esc(want)}` : '') + '</p>';
      $('#cr-updated').textContent = `updated ${new Date().toLocaleTimeString('en-IN', { timeStyle: 'short' })}`;
      $$('#cr-list [data-st]').forEach((b) => bind(b, async () => { await api('hiu/status/' + b.dataset.st, {}); ok('Status requested'); setTimeout(load, 2500); }));
      $$('#cr-list [data-fetch]').forEach((b) => bind(b, async () => { await api('hiu/fetch/' + b.dataset.fetch, {}); setTimeout(load, 2500); }));
      $$('#cr-list [data-req]').forEach((b) => bind(b, async () => {
        await api('hiu/request/' + b.dataset.req, {}); ok('Requested. Records arrive in a few seconds.');
        setTimeout(() => records(b.dataset.req), 6000);
      }));
      $$('#cr-list [data-view]').forEach((b) => b.addEventListener('click', () => records(b.dataset.view)));
    }

    // ---------------- HIU_FLOW_107-114: the health data of each HI type, readable ----------------
    let REC = [], recFilter = '', recConsent = '';
    const text = (c) => (c ? c.text || (c.coding && c.coding[0] && (c.coding[0].display || c.coding[0].code)) || '' : '');
    const human = (n) => (Array.isArray(n) ? n[0] : n) ? ((Array.isArray(n) ? n[0] : n).text || [].concat((Array.isArray(n) ? n[0] : n).given || [], (Array.isArray(n) ? n[0] : n).family || []).join(' ')) : '';
    const qty = (q) => (q ? [q.value, q.unit || q.code].filter((x) => x != null && x !== '').join(' ') : '');
    const money = (m) => (m ? `${m.value != null ? Number(m.value).toLocaleString('en-IN') : ''} ${m.currency || ''}`.trim() : '');
    function attachment(a) {
      if (!a) return '';
      const label = esc(a.title || a.contentType || 'Attachment');
      if (a.data) {
        try {
          const bytes = Uint8Array.from(atob(a.data), (ch) => ch.charCodeAt(0));
          const url = URL.createObjectURL(new Blob([bytes], { type: a.contentType || 'application/octet-stream' }));
          return `<a class="btn btn-outline-primary btn-sm me-1 mb-1" href="${url}" target="_blank" rel="noopener" download="${label}"><i class="fa fa-paperclip"></i> ${label}</a>`;
        } catch { return `<span class="small text-danger">${label}: unreadable attachment</span>`; }
      }
      return a.url && /^https:\/\//.test(a.url) ? `<a href="${esc(a.url)}" target="_blank" rel="noopener noreferrer">${label}</a>` : label;
    }
    function resource(r, byRef) {
      const ref = (x) => (x && x.reference ? byRef[x.reference] || byRef[x.reference.split('/').pop()] : null);
      switch (r.resourceType) {
        case 'Observation': {
          const v = r.valueQuantity ? qty(r.valueQuantity) : r.valueString || text(r.valueCodeableConcept) || (r.valueBoolean != null ? String(r.valueBoolean) : '');
          const range = r.referenceRange && r.referenceRange[0] ? (r.referenceRange[0].text || [qty(r.referenceRange[0].low), qty(r.referenceRange[0].high)].filter(Boolean).join(' – ')) : '';
          const comps = (r.component || []).map((c) => `${esc(text(c.code))}: ${esc(qty(c.valueQuantity) || c.valueString || '')}`).join('; ');
          return { row: [text(r.code), v || comps, range, text((r.interpretation || [])[0])] };
        }
        case 'DiagnosticReport': {
          const obs = (r.result || []).map(ref).filter(Boolean);
          return { html: `<div><strong>${esc(text(r.code) || 'Report')}</strong>${r.conclusion ? ` · ${esc(r.conclusion)}` : ''}</div>`
            + (obs.length ? obsTable(obs.map((o) => resource(o, byRef).row)) : '') + (r.presentedForm || []).map(attachment).join(''), consumed: obs };
        }
        case 'MedicationRequest': case 'MedicationStatement':
          return { html: `<li><strong>${esc(text(r.medicationCodeableConcept) || text((ref(r.medicationReference) || {}).code))}</strong>
            ${esc(((r.dosageInstruction || r.dosage || [])[0] || {}).text || '')}</li>` };
        case 'Condition': return { html: `<li>${esc(text(r.code))} <span class="text-muted small">${esc(text(r.clinicalStatus))}</span></li>` };
        case 'AllergyIntolerance': return { html: `<li>Allergy: ${esc(text(r.code))}</li>` };
        case 'Procedure': return { html: `<li>${esc(text(r.code))} <span class="text-muted small">${esc(fmtDate(r.performedDateTime))}</span></li>` };
        case 'Immunization':
          return { html: `<li><strong>${esc(text(r.vaccineCode))}</strong> · ${esc(fmtDate(r.occurrenceDateTime))}
            ${r.protocolApplied && r.protocolApplied[0] && r.protocolApplied[0].doseNumberPositiveInt ? ` · dose ${esc(r.protocolApplied[0].doseNumberPositiveInt)}` : ''}
            ${r.lotNumber ? ` · lot ${esc(r.lotNumber)}` : ''}</li>` };
        case 'ImmunizationRecommendation':
          return { html: (r.recommendation || []).map((x) => `<li>Recommended: ${esc((x.vaccineCode || []).map(text).join(', '))} ${esc(fmtDate(((x.dateCriterion || [])[0] || {}).value))}</li>`).join('') };
        case 'DocumentReference':
          return { html: `<div>${esc(text(r.type) || r.description || 'Document')} ${(r.content || []).map((c) => attachment(c.attachment)).join('')}</div>` };
        case 'Binary': return { html: attachment({ contentType: r.contentType, data: r.data, title: 'Document' }) };
        case 'Invoice':
          return { html: `<table class="table table-sm mb-1"><thead><tr><th>Item</th><th class="text-end">Amount</th></tr></thead><tbody>
            ${(r.lineItem || []).map((l) => `<tr><td>${esc(text(l.chargeItemCodeableConcept) || text((ref(l.chargeItemReference) || {}).code))}</td>
              <td class="text-end">${esc(money(((l.priceComponent || [])[0] || {}).amount))}</td></tr>`).join('')}</tbody>
            <tfoot><tr><th>Total</th><th class="text-end">${esc(money(r.totalGross || r.totalNet))}</th></tr></tfoot></table>` };
        case 'Appointment': return { html: `<li>Follow-up: ${esc(fmt(r.start))}</li>` };
        case 'ServiceRequest': return { html: `<li>Ordered: ${esc(text(r.code))}</li>` };
        case 'CarePlan': return { html: `<li>Care plan: ${esc(r.description || text((r.category || [])[0]))}</li>` };
        case 'Encounter': return { html: `<li>Visit: ${esc(text((r.type || [])[0]) || (r.class && r.class.display) || '')} ${esc(fmtDate(r.period && r.period.start))}</li>` };
        case 'Specimen': return { html: `<li>Specimen: ${esc(text(r.type))}</li>` };
        default: return { html: `<li><span class="badge bg-light text-dark">${esc(r.resourceType)}</span> ${esc(text(r.code) || r.description || '')}</li>` };
      }
    }
    const obsTable = (rows) => `<table class="table table-sm mb-1"><thead><tr><th>Test</th><th>Result</th><th>Reference range</th><th>Flag</th></tr></thead>
      <tbody>${rows.map((x) => `<tr>${x.map((c) => `<td>${esc(c)}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
    const SKIP = new Set(['Composition', 'Patient', 'Practitioner', 'Organization', 'PractitionerRole']);
    /** FHIR narrative (XHTML) as plain text lines; parsed by the browser, never inserted as HTML. */
    function narrative(t) {
      if (!t || !t.div) return '';
      const doc = new DOMParser().parseFromString(t.div, 'text/html');
      const lines = [...doc.body.querySelectorAll('p, li, td, div:not(:has(*))')].map((n) => n.textContent.trim()).filter(Boolean);
      const uniq = [...new Set(lines.length ? lines : [doc.body.textContent.trim()])].filter((l) => l && !/^[^:]{1,60}: \d+$/.test(l));
      return uniq.length ? `<div class="small" style="white-space:pre-line">${uniq.map(esc).join('\n')}</div>` : '';
    }
    function renderBundle(b) {
      const entries = (b && b.entry) || [];
      const byRef = {};
      entries.forEach((e) => {
        const r = e.resource || {};
        if (e.fullUrl) byRef[e.fullUrl] = r;
        if (r.id) { byRef[`${r.resourceType}/${r.id}`] = r; byRef[r.id] = r; }
      });
      const find = (t) => (entries.find((e) => e.resource && e.resource.resourceType === t) || {}).resource || {};
      const comp = find('Composition');
      const consumed = new Set();
      const block = (list) => {
        const out = [], obs = [];
        list.forEach((r) => {
          if (!r || consumed.has(r)) return;
          const x = resource(r, byRef);
          (x.consumed || []).forEach((c) => consumed.add(c));
          if (x.row) obs.push(x.row); else out.push(x.html);
        });
        const lis = out.filter((h) => h.startsWith('<li')), other = out.filter((h) => !h.startsWith('<li'));
        return (obs.length ? obsTable(obs) : '') + other.join('') + (lis.length ? `<ul class="mb-1">${lis.join('')}</ul>` : '');
      };
      let body = '';
      if (comp.section && comp.section.length) {
        body = comp.section.map((s) => {
          const list = (s.entry || []).map((e) => byRef[e.reference] || byRef[(e.reference || '').split('/').pop()]);
          const inner = block(list) || narrative(s.text);
          return inner ? `<h6 class="mt-2 mb-1">${esc(s.title || text(s.code))}</h6>${inner}` : '';
        }).join('');
      } else {
        body = block(entries.map((e) => e.resource).filter((r) => r && !SKIP.has(r.resourceType)));
      }
      // Free text a hospital puts only in the narrative (e.g. discharge notes) is shown too.
      const story = narrative(comp.text);
      if (story) body += `<h6 class="mt-2 mb-1">Summary</h6>${story}`;
      const author = human((byRef[((comp.author || [])[0] || {}).reference] || {}).name);
      const org = find('Organization').name || '';
      return { title: comp.title || 'Health record', date: comp.date, author, org, body: body || '<p class="small text-muted mb-0">No readable content in this record.</p>' };
    }
    function paint() {
      const types = [...new Set(REC.map((r) => r.hiType || 'Other'))];
      $('#rec-types').innerHTML = REC.length ? [['', `All (${REC.length})`], ...types.map((t) => [t, `${hiLabel(t)} (${REC.filter((r) => (r.hiType || 'Other') === t).length})`])]
        .map(([t, l]) => `<button type="button" class="btn btn-sm ${recFilter === t ? 'btn-primary' : 'btn-outline-primary'}" data-t="${esc(t)}">${esc(l)}</button>`).join('') : '';
      $$('#rec-types [data-t]').forEach((b) => b.addEventListener('click', () => { recFilter = b.dataset.t; paint(); }));
      const shown = REC.filter((r) => !recFilter || (r.hiType || 'Other') === recFilter);
      $('#rec-list').innerHTML = shown.length ? shown.map((r) => {
        const v = renderBundle(r.content);
        return `<details class="mb-2 border rounded p-2"${shown.length === 1 ? ' open' : ''}><summary><span class="badge bg-secondary me-1">${esc(hiLabel(r.hiType))}</span>
          <strong>${esc(v.title)}</strong> · ${esc(v.org)} · ${esc(fmtDate(v.date))}${v.author ? ` · ${esc(v.author)}` : ''}</summary>
          <div class="mt-2">${v.body}</div>
          <div class="small text-muted">Received ${esc(fmt(r.received_at))} · our copy is erased on ${esc(fmtDate(r.erase_at))}</div></details>`;
      }).join('') : '<p class="text-muted mb-0">No records received yet.</p>';
    }
    async function records(consentId) {
      recConsent = consentId || '';
      REC = await api('hiu/records/' + recConsent);
      recFilter = '';
      $('#rec-for').textContent = recConsent ? `· consent ${recConsent.slice(0, 8)}…` : '';
      paint();
    }

    load().catch((e) => fail(e.message));
    records().catch(() => {});
    // HIU_FLOW_202: the list follows grants, denials, revocations and expiry by itself.
    setInterval(() => { if (!document.hidden) load().catch(() => {}); }, 30000);
  }

  // ================================================================= M2: lab codes (LOINC)
  if (root.dataset.page === 'loinc') {
    const filter = () => {
      const q = $('#lc-filter').value.trim().toLowerCase(), mode = $('#lc-show').value;
      $$('#lc-rows tr[data-id]').forEach((tr) => show(tr, (!q || tr.dataset.name.includes(q)) && (mode === 'all' || tr.dataset.state === mode)));
    };
    $('#lc-filter').addEventListener('input', filter);
    $('#lc-show').addEventListener('change', filter);
    $$('#lc-rows .lc-save').forEach((b) => bind(b, async () => {
      const tr = b.closest('tr');
      const code = $('.lc-code', tr).value.trim();
      await api('loinc_save', { items: [{ id: tr.dataset.id, code, display: $('.lc-disp', tr).value.trim() }] });
      tr.dataset.state = code ? 'coded' : 'todo';
      const badgeEl = $('.badge', tr);
      if (badgeEl) badgeEl.remove();
      if (code) b.insertAdjacentHTML('beforebegin', '<span class="badge bg-success">Confirmed</span> ');
      b.className = 'btn btn-outline-primary btn-sm ms-1 lc-save'; b.textContent = 'Save';
      ok(code ? `Saved ${code}; reports with this test are re-sent on the next sync` : 'Code removed');
    }));
  }

  // ================================================================= Scan & Share
  if (root.dataset.page === 'share') {
    const patientUrl = root.dataset.patientUrl;
    const HOW = { new: '<span class="badge bg-success">Registered now</span>', matched: '<span class="badge bg-info">Matched to existing record</span>',
      abha: '<span class="badge bg-secondary">Known patient</span>' };
    async function load() {
      // The server registers each shared profile as it lists the queue (SHARE_PATIENT_PROFILE_701/702).
      const rows = await api('share_queue');
      $('#sq-list').innerHTML = rows.length ? rows.map((r) => `<tr><td class="fs-5"><strong>${esc(r.token_number)}</strong></td><td>${esc(r.name)}</td>
        <td>${esc(r.abha_address)}</td><td>${esc(r.gender)} / ${esc(r.yearOfBirth)}</td><td>${esc(r.context)}</td><td>${fmt(r.created_at)}</td>
        <td class="text-end">${r.hms_patient_id ? `${HOW[r.registered] || ''} <a class="btn btn-outline-primary btn-sm" href="${patientUrl}${r.hms_patient_id}">Patient #${r.hms_patient_id}</a>`
          : `<span class="small text-danger">${esc(r.register_error || 'Not registered')}</span> <button class="btn btn-primary btn-sm" data-reg="${esc(r.id)}">Retry</button>`}</td></tr>`).join('')
        : '<tr><td colspan="7" class="text-muted">No tokens today</td></tr>';
      $$('#sq-list [data-reg]').forEach((b) => bind(b, async () => {
        const r = await api('share_register', { id: b.dataset.reg });
        ok(r.existing ? `Linked to patient #${r.patientId}` : `Registered as patient #${r.patientId}`);
        load();
      }));
    }
    bind($('#sq-refresh'), load);
    load().catch((e) => fail(e.message));
    setInterval(() => load().catch(() => {}), 30000);
  }

  // ================================================================= M2: consents received & data sent
  if (root.dataset.page === 'hip') {
    const short = (s) => `<span class="small font-monospace" title="${esc(s)}">${esc(String(s || '').slice(0, 8))}</span>`;
    async function load() {
      const [consents, transfers] = await Promise.all([api('hip/consents'), api('hip/transfers')]);
      $('#hip-consents').innerHTML = consents.length ? consents.map((c) => {
        const d = c.detail || {};
        const by = (d.requester && d.requester.name) || (d.hiu && (d.hiu.name || d.hiu.id)) || (d.purpose && d.purpose.code === 'PATRQT' ? 'Patient (ABHA app)' : '');
        return `<tr><td>${short(c.consent_id)}</td><td>${badge(c.status)}</td><td>${esc(d.patient && d.patient.id)}</td><td>${esc(by)}</td>
          <td>${esc(d.purpose && d.purpose.text)}</td><td>${esc((d.careContexts || []).length || '')}</td>
          <td>${fmtDate(d.permission && d.permission.dataEraseAt)}</td><td>${fmt(c.updated_at)}</td></tr>`;
      }).join('') : '<tr><td colspan="8" class="text-muted">No consents received yet</td></tr>';
      $('#hip-transfers').innerHTML = transfers.length ? transfers.map((t) => `<tr><td>${short(t.transaction_id)}</td><td>${short(t.consent_id)}</td>
        <td>${badge(t.status)}</td><td>${esc(t.entries ?? '')}</td><td class="small text-danger">${esc(t.error || '')}</td><td>${fmt(t.created_at)}</td></tr>`).join('')
        : '<tr><td colspan="6" class="text-muted">No data sent yet</td></tr>';
    }
    bind($('#hip-refresh'), load);
    load().catch((e) => fail(e.message));
  }

  // ================================================================= Bridge activity: jobs & audit log
  if (root.dataset.page === 'activity') {
    const canRetry = !!root.dataset.canRetry;
    async function jobs() {
      const j = await api('bridge/jobs');
      $('#act-job-counts').innerHTML = j.counts.length ? j.counts.map((c) => `${badge(String(c.status).toUpperCase())} ${esc(c.n)}`).join(' &nbsp; ') : '<span class="text-muted">No jobs</span>';
      $('#act-jobs').innerHTML = j.failed.length ? j.failed.map((x) => `<tr><td>${esc(x.id)}</td><td>${esc(x.type)}</td><td>${esc(x.attempts)}</td>
        <td class="small text-danger">${esc(x.last_error || '')}</td><td>${fmt(x.updated_at)}</td>
        <td class="text-end">${canRetry ? `<button class="btn btn-outline-primary btn-sm" data-retry="${esc(x.id)}">Retry</button>` : ''}</td></tr>`).join('')
        : '<tr><td colspan="6" class="text-muted">No failed jobs</td></tr>';
      $$('#act-jobs [data-retry]').forEach((b) => bind(b, async () => { await api(`bridge/retry/${b.dataset.retry}`, {}); ok('Job queued again'); jobs(); }));
    }
    let oldest = null;
    async function audit(more) {
      const rows = await api('bridge/audit' + (more && oldest ? `?before=${encodeURIComponent(oldest)}` : ''));
      const html = rows.map((a) => `<tr><td class="text-nowrap">${fmt(a.at)}</td><td class="small">${esc(a.actor_type)}${a.actor_id ? ` · ${esc(a.actor_id)}` : ''}</td>
        <td>${esc(a.action)}</td><td>${a.status ? esc(a.status) : ''}</td>
        <td class="small text-muted" style="max-width:420px;word-break:break-all">${esc(a.details ? JSON.stringify(a.details).slice(0, 300) : '')}</td></tr>`).join('');
      if (more) $('#act-audit').insertAdjacentHTML('beforeend', html);
      else $('#act-audit').innerHTML = html || '<tr><td colspan="5" class="text-muted">No activity yet</td></tr>';
      if (rows.length) oldest = rows[rows.length - 1].id;
      show($('#act-audit-more'), rows.length === 100);
    }
    bind($('#act-jobs-refresh'), jobs);
    bind($('#act-audit-more'), () => audit(true));
    jobs().catch((e) => fail(e.message));
    audit(false).catch((e) => fail(e.message));
  }

  // ================================================================= M1: standalone create / verify ABHA
  if (root.dataset.page === 'abha') {
    const S = {};
    const patientUrl = root.dataset.patientUrl;
    const canRegister = !!root.dataset.canRegister;
    const tick = (v) => (v ? '<span class="text-success">&#10004;</span>' : '<span class="text-danger">&#10006;</span>');
    const GENDER = { M: 'Male', F: 'Female', O: 'Other', T: 'Transgender' };

    /** The ABHA is now on a patient: say so and offer the mobile update when the two mobiles differ. */
    function linked(id, text, hmsMobile) {
      $('#m1-done').innerHTML = `${esc(text)} <a class="btn btn-outline-primary btn-sm ms-1" href="${patientUrl}${id}">Open patient #${esc(id)}</a>`;
      show($('#m1-done')); show($('#m1-actions'), false); show($('#m1-linked'), false);
      offerMobileUpdate($('#m1-mob'), { token: S.token, kind: S.kind, abhaMobile: S.p.mobile, hmsMobile });
    }

    async function attachTo(pid) {
      let r = await api('save_abha/' + pid, { token: S.token, kind: S.kind });
      if (r.mismatch) {
        const msg = 'The ABHA details do not match this patient:\n\n- ' + r.mismatch.join('\n- ')
          + '\n\nAttach only if you have confirmed this is the same person (e.g. checked an ID). Attach anyway?';
        if (!confirm(msg)) throw new Error('ABHA not attached: details did not match this patient.');
        r = await api('save_abha/' + pid, { token: S.token, kind: S.kind, confirmMismatch: true });
      }
      ok(`ABHA linked to patient #${pid}`);
      linked(pid, `ABHA linked to the existing patient #${pid}; no new patient was created.`, r.hmsMobile);
    }

    m1Flow(S, async (token, kind = 'abha') => {
      if (!token) throw new Error('No ABHA session token returned');
      S.token = token; S.kind = kind;
      const p = await api('abha_lookup', { token, kind });
      S.p = p;
      $('#m1-name').textContent = p.name || '';
      $('#m1-number').textContent = p.number || '';
      $('#m1-address').textContent = (p.addresses && p.addresses.length ? p.addresses : [p.address]).filter(Boolean).join(', ');
      $('#m1-demo').textContent = [GENDER[p.gender] || p.gender, p.dob].filter(Boolean).join(' · ');
      $('#m1-mobile').textContent = p.mobile || '';
      $('#m1-line').textContent = p.line || '';
      const img = $('#m1-photo');
      if (p.photo) { img.src = `data:image/jpeg;base64,${p.photo}`; show(img); } else show(img, false);
      $('#m1-qr-img').innerHTML = ''; $('#m1-card-img').innerHTML = '';
      show($('#m1-done'), false); show($('#m1-mob'), false); show($('#m1-result'));

      if (p.hms_patient_id) {
        // VRFY_ABHA_308: already linked. Show both sides; create nothing.
        const h = p.hms || {};
        $('#m1-linked-id').textContent = `#${p.hms_patient_id}`;
        const row = (label, a, b) => {
          const same = String(a || '').trim().toLowerCase() === String(b || '').trim().toLowerCase();
          return `<tr${!same && a && b ? ' class="table-warning"' : ''}><th class="small">${label}</th><td>${esc(a)}</td><td>${esc(b)}</td></tr>`;
        };
        $('#m1-compare').innerHTML = row('Name', p.name, h.name) + row('Gender', GENDER[p.gender] || p.gender, h.gender)
          + row('Date of birth', p.dob, h.dob) + row('Mobile', p.mobile, h.mobile) + row('Address', p.line, h.line)
          + row('ABHA number', p.number, h.abhaNumber) + row('ABHA address', p.address, h.abhaAddress);
        $('#m1-linked-open').href = patientUrl + p.hms_patient_id;
        show($('#m1-linked')); show($('#m1-actions'), false);
        offerMobileUpdate($('#m1-mob'), { token, kind, abhaMobile: p.mobile, hmsMobile: h.mobile });
      } else {
        show($('#m1-linked'), false); show($('#m1-actions'));
        // CRT_ABHA_116: patients who may be this person
        const c = p.candidates || [];
        $('#m1-match-rows').innerHTML = c.map((r) => `<tr${r.full ? ' class="table-success"' : ''}><td>#${esc(r.id)} ${esc(r.patient_name)}
            <div class="small text-muted">${esc(r.gender)}${r.age ? ` · ${esc(r.age)}y` : ''} · ${esc(r.mobileno)}</div></td>
          <td class="text-center">${tick(r.checks.mobile)}</td><td class="text-center">${tick(r.checks.name)}</td>
          <td class="text-center">${tick(r.checks.gender)}</td><td class="text-center">${tick(r.checks.age)}</td>
          <td class="text-end">${r.abha_number || r.abha_address ? '<span class="badge bg-secondary">Has another ABHA</span>'
            : `<button class="btn btn-${r.full ? 'success' : 'outline-secondary'} btn-sm" data-attach="${esc(r.id)}">${r.full ? 'Link (all details match)' : 'Link'}</button>`}</td></tr>`).join('');
        show($('#m1-match'), c.length > 0);
        $$('#m1-match-rows [data-attach]').forEach((b) => bind(b, () => attachTo(b.dataset.attach)));

        // VRFY_ABHA_306: the registration form, filled from the profile
        const full = c.find((r) => r.full && !r.abha_number && !r.abha_address);
        show($('#m1-form'), canRegister);
        $('#f-name').value = p.name || ''; $('#f-dob').value = p.dob || ''; $('#f-gender').value = GENDER[p.gender] || p.gender || '';
        $('#f-mobile').value = digits(p.mobile).slice(-10); $('#f-email').value = p.email || '';
        $('#f-street').value = p.street || ''; $('#f-city').value = p.city || ''; $('#f-district').value = p.district || '';
        $('#f-state').value = p.state || ''; $('#f-pincode').value = p.pincode || '';
        $('#m1-register').innerHTML = full ? '<i class="fa fa-user-plus"></i> Register as a different person' : '<i class="fa fa-user-plus"></i> Register patient';
        show($('#m1-form-match'), false);
        $('#m1-q').value = digits(p.mobile).slice(-10);
        $('#m1-patients').innerHTML = '';
      }
      $('#m1-result').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    bind($('#m1-card-view'), async () => { $('#m1-card-img').innerHTML = `<img class="img-fluid border rounded" alt="ABHA card" src="${await blobUrl('m1/card', { token: S.token, kind: S.kind })}">`; });
    bind($('#m1-card'), async () => downloadCard(S.token, S.kind, S.p && S.p.number));
    bind($('#m1-qr'), async () => { $('#m1-qr-img').innerHTML = `<img width="200" alt="ABHA QR" src="${await blobUrl('m1/qr', { token: S.token, kind: S.kind })}">`; });

    const form = () => ({ mobile: digits($('#f-mobile').value), email: $('#f-email').value.trim(), street: $('#f-street').value.trim(),
      city: $('#f-city').value.trim(), district: $('#f-district').value.trim(), state: $('#f-state').value.trim(), pincode: digits($('#f-pincode').value) });
    async function register(forceNew) {
      const f = form();
      if (f.mobile && !validMobile(f.mobile)) throw new Error('Please enter a valid mobile number.');
      const r = await api('abha_register', { token: S.token, kind: S.kind, form: f, forceNew: !!forceNew });
      if (r.match) {
        // A patient matches on every detail: link rather than register a second time, unless staff say otherwise.
        const m = r.match;
        $('#m1-form-match').innerHTML = `Patient <strong>#${esc(m.id)} ${esc(m.patient_name)}</strong> matches this ABHA on mobile, name, gender and age.
          <div class="mt-1"><button type="button" class="btn btn-success btn-sm me-1" id="m1-fm-link">Link to patient #${esc(m.id)}</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" id="m1-fm-new">This is a different person: register new</button></div>`;
        show($('#m1-form-match'));
        bind($('#m1-fm-link'), () => attachTo(m.id));
        bind($('#m1-fm-new'), () => register(true));
        return;
      }
      ok(r.existing ? `Already registered as patient #${r.patientId}` : `Registered as patient #${r.patientId}`);
      linked(r.patientId, r.existing ? 'This ABHA is already linked to a patient.' : `New patient #${r.patientId} registered with this ABHA.`, f.mobile);
    }
    bind($('#m1-register'), () => register(!!(S.p && (S.p.candidates || []).some((c) => c.full && !c.abha_number && !c.abha_address))));

    async function search() {
      const q = $('#m1-q').value.trim();
      if (!q) return;
      const rows = await api('abha_patients?q=' + encodeURIComponent(q));
      $('#m1-patients').innerHTML = rows.length ? rows.map((r) => `<tr><td>#${esc(r.id)}</td><td>${esc(r.patient_name)}</td>
        <td class="small">${esc(r.gender)}${r.age ? ` · ${esc(r.age)}y` : ''}</td><td class="small">${esc(r.mobileno)}</td>
        <td class="text-end">${r.abha_number || r.abha_address ? `<span class="badge bg-secondary" title="${esc(r.abha_address)}">Has ABHA</span>`
          : `<button class="btn btn-success btn-sm" data-attach="${esc(r.id)}">Link</button>`}</td></tr>`).join('')
        : '<tr><td class="text-muted">No matching patients</td></tr>';
      $$('#m1-patients [data-attach]').forEach((b) => bind(b, () => attachTo(b.dataset.attach)));
    }
    bind($('#m1-search'), search);
    $('#m1-q').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('#m1-search').click(); } });
  }
})();
