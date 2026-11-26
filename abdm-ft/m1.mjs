#!/usr/bin/env node
// M1 (ABHA) functional-test runner, entity type private: NHA's mandatory M1 cases for functionalities 1-7, by case id.
// Interactive: the tester types every Aadhaar number, mobile and OTP (hidden, never stored) and attests every screen
// check. Drives Smart Hospital's own ABDM endpoints (as its screens do) and reads the REQUEST-IDs each step caused
// from the bridge's audit log. Writes abdm-ft/m1-<stamp>.md (run log), m1-ft-results.json (for abdm_workbook.py)
// and m1-<stamp>.json (manifest: case id -> REQUEST-IDs). Rules: .claude/skills/abdm-m1/references/run.md.
import {
  Run, args, close, need, attest, waitFor, ask, get, auditMark, auditSince, requestIds, describeAudit,
  readinessCheck, redact, masked, sleep,
} from './lib/ft.mjs';
import { Hmis } from './lib/hmis.mjs';

const SKILL = 'abdm-m1 (catalogue 2026.09.16)';
const SET = 'M1 ABHA creation, verification, scan and share (revised 21 Oct 2026)';
const OPTIONAL = 'optional for a private entity, not claimed';

// ---------------------------------------------------------------- the cases, in plan order (SKILL.md table, 1-7)
// kind: sandbox | screen | human (several when a case has more than one kind). needs: run.md "What each need means".
const CASES = [
  // 1. Create ABHA by Aadhaar OTP
  { id: 'CRT_ABHA_101', title: 'Create ABHA Option', group: 'create', kind: 'sandbox', needs: ['aadhaar-holder', 'otp'] },
  { id: 'CRT_ABHA_104', title: 'Aadhaar Collection and Error Handling', group: 'create', kind: 'screen', needs: [] },
  { id: 'CRT_ABHA_102', title: 'Consent collection', group: 'create', kind: 'screen', needs: ['aadhaar-holder', 'otp'] },
  { id: 'CRT_ABHA_103', title: 'Consent collection should be multilingual', group: 'create', kind: 'screen', needs: [], optional: true },
  { id: 'CRT_ABHA_105', title: 'Aadhaar OTP Collection', group: 'create', kind: 'sandbox, human', needs: ['aadhaar-holder', 'otp'] },
  { id: 'CRT_ABHA_106', title: 'Resend OTP', group: 'create', kind: 'screen', needs: ['aadhaar-holder', 'otp'] },
  { id: 'CRT_ABHA_107', title: 'OTP based Aadhaar Authentication', group: 'create', kind: 'sandbox, human', needs: ['aadhaar-holder', 'otp'] },
  { id: 'CRT_ABHA_108', title: 'Communication Mobile Number Verification - I', group: 'create', kind: 'sandbox', needs: ['otp', 'aadhaar-without-abha'] },
  { id: 'CRT_ABHA_109', title: 'Communication Mobile Number verification-II', group: 'create', kind: 'sandbox, human', needs: ['otp', 'aadhaar-without-abha', 'second-mobile'] },
  { id: 'CRT_ABHA_112', title: 'Suggested ABHA Address (conditional)', group: 'create', kind: 'sandbox, screen', needs: ['aadhaar-holder', 'otp'] },
  { id: 'CRT_ABHA_113', title: 'Display of ABHA Number', group: 'create', kind: 'screen', needs: ['aadhaar-holder', 'otp'] },
  { id: 'CRT_ABHA_114', title: 'View and Download ABHA details (card)', group: 'create', kind: 'sandbox, screen', needs: ['aadhaar-holder', 'otp'] },
  { id: 'CRT_ABHA_115', title: 'View and Download ABHA details (own card)', group: 'create', kind: 'screen', needs: ['aadhaar-holder', 'otp'], optional: true },
  { id: 'CRT_ABHA_116', title: 'Returning patient', group: 'create', kind: 'screen', needs: ['patient-registered'] },
  // 2. Log in with ABHA number
  { id: 'VRFY_ABHA_101', title: 'ABHA Number Verification using Aadhaar OTP', group: 'abha-number', kind: 'sandbox, human', needs: ['aadhaar-holder', 'otp', 'second-mobile'] },
  { id: 'VRFY_ABHA_201', title: 'ABHA Number Verification using Mobile OTP', group: 'abha-number', kind: 'sandbox, human', needs: ['aadhaar-holder', 'otp', 'second-mobile'] },
  // 3. Log in with ABHA address
  { id: 'VRFY_ABHA_102', title: 'ABHA Address Verification using Aadhaar OTP', group: 'abha-address', kind: 'sandbox, human', needs: ['aadhaar-holder', 'otp'] },
  { id: 'VRFY_ABHA_202', title: 'ABHA address Verification using Mobile OTP', group: 'abha-address', kind: 'sandbox, human', needs: ['aadhaar-holder', 'otp'] },
  // 4. Find a forgotten ABHA by mobile
  { id: 'VRFY_ABHA_301', title: 'Validate Communication Mobile Number Input', group: 'find', kind: 'screen', needs: [] },
  { id: 'VRFY_ABHA_302', title: 'Fetch Associated ABHA Profiles Using Communication Mobile Number', group: 'find', kind: 'sandbox, screen', needs: ['aadhaar-holder'] },
  { id: 'VRFY_ABHA_303', title: 'No ABHA Profile Linked with Communication Mobile Number', group: 'find', kind: 'sandbox', needs: ['mobile-without-abha'] },
  { id: 'VRFY_ABHA_304', title: 'Select Required ABHA Profile and Trigger OTP', group: 'find', kind: 'sandbox, human', needs: ['aadhaar-holder', 'otp'] },
  { id: 'VRFY_ABHA_305', title: 'Verify OTP and Open Selected ABHA Profile', group: 'find', kind: 'sandbox, human', needs: ['aadhaar-holder', 'otp'] },
  { id: 'VRFY_ABHA_306', title: 'Auto-populate ABHA Profile Details in HMIS Registration Form', group: 'find', kind: 'screen', needs: ['aadhaar-holder', 'otp'] },
  { id: 'VRFY_ABHA_307', title: 'Incorrect OTP Handling', group: 'find', kind: 'sandbox, human', needs: ['aadhaar-holder', 'otp'] },
  { id: 'VRFY_ABHA_308', title: 'Selected ABHA Profile Already Linked in HMIS', group: 'find', kind: 'screen', needs: ['aadhaar-holder', 'otp', 'patient-registered'] },
  // 5. Log in with Aadhaar OTP
  { id: 'VRFY_ABHA_401', title: 'Fetch ABHA Details Using Aadhaar Number (invalid number)', group: 'aadhaar', kind: 'screen', needs: [] },
  { id: 'VRFY_ABHA_402', title: 'Incorrect OTP', group: 'aadhaar', kind: 'sandbox, human', needs: ['aadhaar-holder', 'otp'] },
  { id: 'VRFY_ABHA_403', title: 'ABHA Details Not Found for Aadhaar Number', group: 'aadhaar', kind: 'sandbox, human', needs: ['aadhaar-without-abha', 'otp'] },
  { id: 'VRFY_ABHA_404', title: 'ABHA Details Exist for Aadhaar Number', group: 'aadhaar', kind: 'sandbox, human', needs: ['aadhaar-holder', 'otp'] },
  { id: 'VRFY_ABHA_405', title: 'Resend OTP Functionality', group: 'aadhaar', kind: 'screen', needs: ['aadhaar-holder', 'otp'] },
  // 6. Link one ABHA number to one patient record
  { id: 'TAGGING_UNIQUEPATIENTID_UNIQUEABHANUMBER', title: 'One ABHA Number linked to the unique patient ID', group: 'tagging', kind: 'screen', needs: ['patient-registered'] },
  // 7. Scan and share at a counter
  { id: 'SHARE_PATIENT_PROFILE_701', title: 'Share Patient Profile', group: 'share', kind: 'sandbox, human, screen', needs: ['abha-app', 'counter-qr', 'public-callback'] },
  { id: 'SHARE_PATIENT_PROFILE_702', title: 'Returning patient (scan and share)', group: 'share', kind: 'sandbox, human, screen', needs: ['abha-app', 'counter-qr', 'public-callback', 'second-abha-address', 'patient-registered', 'second-counter-qr'] },
];

const NEED_TEXT = {
  'aadhaar-holder': 'a person present with their Aadhaar number and the mobile linked to it',
  'aadhaar-without-abha': 'an Aadhaar that holds no ABHA (fresh, or freed on https://abhasbx.abdm.gov.in/abha/v3), its holder present',
  otp: 'the person can read out OTPs sent to their phone',
  'second-mobile': 'a second mobile number the person can receive an OTP on',
  'mobile-without-abha': 'a mobile number linked to no ABHA',
  'second-abha-address': 'a second ABHA address on the same ABHA number',
  'patient-registered': 'the patient registered in Smart Hospital from an earlier case (the runner does this in CRT_ABHA_116)',
  'abha-app': 'the ABHA sandbox app on a phone, logged in',
  'counter-qr': 'the facility counter QR code, displayed for scanning',
  'second-counter-qr': 'the QR code of a second counter or queue',
  'public-callback': 'the bridge callback URL registered with ABDM and reachable (https://abdm-bridge.duobytes.in)',
};

// ---------------------------------------------------------------- helpers
const ok = (r) => r.status >= 200 && r.status < 300;
const maskAbha = (n) => { const d = String(n || '').replace(/\D/g, ''); return d ? `xx-xxxx-xxxx-${d.slice(-4)}` : ''; };
const digits = (s) => String(s || '').replace(/\D/g, '');
const mobileHint = (msg) => (String(msg || '').match(/[*xX]{2,}\s*\d{4}/) || [])[0] || '';
const ABDM_CALL = /^(ABHA |ABHA-PHR |OUT )/;
const abdmCalls = (rows) => rows.filter((r) => ABDM_CALL.test(r.action));
// UIDAI's Verhoeff checksum, which every Aadhaar number passes and the bridge enforces: catches a typo in hidden input.
const VD = [[0,1,2,3,4,5,6,7,8,9],[1,2,3,4,0,6,7,8,9,5],[2,3,4,0,1,7,8,9,5,6],[3,4,0,1,2,8,9,5,6,7],[4,0,1,2,3,9,5,6,7,8],
  [5,9,8,7,6,0,4,3,2,1],[6,5,9,8,7,1,0,4,3,2],[7,6,5,9,8,2,1,0,4,3],[8,7,6,5,9,3,2,1,0,4],[9,8,7,6,5,4,3,2,1,0]];
const VP = [[0,1,2,3,4,5,6,7,8,9],[1,5,7,6,2,8,3,0,9,4],[5,8,0,3,7,9,6,1,4,2],[8,9,1,6,0,4,3,5,2,7],[9,4,5,3,1,2,6,8,7,0],
  [4,6,8,7,9,3,0,2,1,5],[2,7,9,3,8,0,6,4,1,5],[7,0,4,6,9,1,3,2,5,8]];
const verhoeff = (d) => { let c = 0; [...d].reverse().forEach((x, i) => { c = VD[c][VP[i % 8][Number(x)]]; }); return c === 0; };
const isAadhaar = (v) => {
  const d = v.replace(/\s/g, '');
  if (!/^\d{12}$/.test(d)) return 'The Aadhaar number is 12 digits; type it again.';
  if (/^[01]/.test(d) || !verhoeff(d)) return 'That is not a valid Aadhaar number (checksum): probably a typo, since the input is hidden. Type it again.';
  return null;
};
const isOtp = (v) => (/^\d{6}$/.test(v) ? null : 'The OTP is 6 digits; type it again.');
const isMobile = (v) => (/^[6-9]\d{9}$/.test(digits(v).slice(-10)) && digits(v).length >= 10 ? null : 'A 10-digit mobile number, please.');
const isAbhaNumber = (v) => (digits(v).length === 14 ? null : 'The ABHA number is 14 digits; type it again.');

/**
 * Smart Hospital call with the bridge's OTP rate limit handled (15 OTP calls per 10 minutes per user, invalid
 * inputs included): on HTTP 429 say so, wait a minute and try again, for up to 12 minutes, instead of failing a case.
 */
async function call(hmis, action, body, opts) {
  for (let i = 1; ; i++) {
    const r = await hmis.call(action, body, opts);
    if (r.status !== 429 || i > 12) return r;
    console.log(`  bridge rate limit on ${action} (HTTP 429: ${redact(r.body?.error || '')}); waiting 60 s, then trying again (${i}/12)`);
    await sleep(60_000);
  }
}

/** Run fn up to 3 passes (run.md loop limit); fn returns a result for Run.record. A failed pass may be retried. */
async function runCase(run, id, fn) {
  run.running(id);
  let res;
  for (let pass = 1; pass <= 3; pass++) {
    const mark = await auditMark().catch(() => 0);
    try {
      res = await fn({ pass, mark });
    } catch (e) {
      res = { outcome: 'failed', remarks: `runner error: ${e.message}`, trace: [String(e.stack || e.message).split('\n').slice(0, 2).join(' ')] };
    }
    const rows = await auditSince(mark).catch(() => []);
    res.requestIds = [...new Set([...(res.requestIds || []), ...requestIds(rows)])];
    res.trace = [...(res.trace || []), ...describeAudit(abdmCalls(rows))];
    res.iterations = pass;
    if (res.outcome !== 'failed' || pass === 3) break;
    const again = (await ask(`  ${id} failed (pass ${pass} of 3): ${redact(res.remarks || '')}. Run it again? y/n`)).toLowerCase();
    if (!again.startsWith('y')) break;
  }
  if (res.outcome === 'failed' && res.iterations === 3) res.remarks = `${res.remarks || ''} Loop limit reached (3 passes): escalate per run.md with the request ids above.`.trim();
  run.record(id, res);
  return res;
}

/** A screen check: attested y/n/blank. alongside=true means a gap is a conditional pass, not a fail. */
async function screen(id, check, { alongside = false } = {}) {
  const v = await attest(id, check);
  return { v, check };
}

/** Combine a sandbox result with screen checks (run.md "Judging a case"). */
function judge(sandboxOk, checks, { sandboxRemarks = '', needsHuman = null, screenCase = false } = {}) {
  if (needsHuman) return { outcome: 'needs-human', remarks: needsHuman };
  if (!sandboxOk) return { outcome: 'failed', remarks: sandboxRemarks || 'exit condition not observed' };
  const failed = checks.filter((c) => c.v === false), unseen = checks.filter((c) => c.v === null);
  if (screenCase) {
    if (failed.length) return { outcome: 'failed', remarks: `screen check failed: ${failed.map((c) => c.check).join('; ')}` };
    if (unseen.length) return { outcome: 'needs-human', remarks: `screen check not attested after two asks: ${unseen.map((c) => c.check).join('; ')}` };
    return { outcome: 'passed', remarks: sandboxRemarks || undefined };
  }
  if (failed.length || unseen.length) {
    return { outcome: 'conditional-pass', remarks: `Expected Result met${sandboxRemarks ? ` (${sandboxRemarks})` : ''}. Condition: ${[...failed.map((c) => `not met: ${c.check}`), ...unseen.map((c) => `not attested: ${c.check}`)].join('; ')}` };
  }
  return { outcome: 'passed', remarks: sandboxRemarks || undefined };
}

/** A journey step: recorded as case id when selected, otherwise run silently (its calls still count for CRT_ABHA_101). */
async function step(run, sel, id, fn) {
  if (sel.has(id)) return runCase(run, id, fn);
  try { return await fn({ pass: 1, mark: 0 }); } catch (e) { return { outcome: 'failed', remarks: e.message }; }
}

const notRun = (run, id, remarks) => { run.running(id); run.record(id, { outcome: 'not-run', remarks }); };
const humanMissing = (what) => `Needs tester: ${what}, asked twice`;

// ---------------------------------------------------------------- state carried within the run (memory only)
const S = {};
const wipe = () => { for (const k of Object.keys(S)) delete S[k]; };

async function otpFor(id, hint) {
  return need(id, `the OTP sent to ${hint || 'the mobile'} (input hidden)`, { secret: true, check: isOtp });
}

async function lookup(hmis, token, kind = 'abha') {
  return call(hmis, 'abha_lookup', { token, kind });
}

// ---------------------------------------------------------------- 1. Create ABHA by Aadhaar OTP
async function groupCreate(run, hmis, sel) {
  const want = (id) => sel.has(id);

  if (want('CRT_ABHA_104')) {
    await runCase(run, 'CRT_ABHA_104', async () => {
      const bad = ['12345678901', '1234567890123', '12345678901a', '1234-5678-90@'];
      const seen = [];
      let allRefused = true;
      for (const a of bad) {
        const r = await call(hmis, 'm1/enrol_otp', { aadhaar: a, consent: true });
        const msg = String(r.body?.error || '');
        seen.push(`len ${a.length}: HTTP ${r.status} "${msg}"`);
        if (r.status !== 400 || !/Aadhaar Number is not valid/.test(msg)) allRefused = false;
      }
      const c1 = await screen('CRT_ABHA_104', 'Create ABHA screen: fewer/more than 12 digits, letters and special characters each show "Aadhaar Number is not valid" and nothing is sent');
      const c2 = await screen('CRT_ABHA_104', 'the Aadhaar number field is masked (dots, with a show/hide toggle)', { alongside: true });
      const r = judge(allRefused, [c1], { screenCase: true, sandboxRemarks: 'Expected "Aadhaar Number is not valid"; observed: ' + seen.join('; ') });
      if (r.outcome === 'passed' && c2.v !== true) { r.outcome = 'conditional-pass'; r.remarks = `${r.remarks}. Condition: Aadhaar masking ${c2.v === false ? 'not met' : 'not attested'} (Suggestions for the Functional Tester)`; }
      return { ...r, response: 'POST m1/enrol_otp (invalid Aadhaar x4) HTTP 400, no ABDM call', trace: seen, evidence: 'screen (attested) + empty ABDM call log for the invalid attempts' };
    });
  }

  // The creation journey: one Aadhaar OTP enrolment, carried through 102/105/107/108/112/113/114.
  const journey = ['CRT_ABHA_101', 'CRT_ABHA_102', 'CRT_ABHA_105', 'CRT_ABHA_106', 'CRT_ABHA_107', 'CRT_ABHA_108', 'CRT_ABHA_112', 'CRT_ABHA_113', 'CRT_ABHA_114', 'CRT_ABHA_115'];
  if (journey.some(want)) {
    console.log('\n  Creation journey. An Aadhaar with no ABHA gives the full journey; an existing ABHA is not a stop (isNew false).');
    S.aadhaar = await need('CRT_ABHA_101', 'the 12-digit Aadhaar number of the person present (input hidden)', { secret: true, check: isAadhaar });
    if (S.aadhaar) S.aadhaar = S.aadhaar.replace(/\s/g, '');
  }
  if (journey.some(want) && !S.aadhaar) {
    for (const id of journey.filter(want)) {
      if (CASES.find((c) => c.id === id).optional && !run.optIn.has(id)) notRun(run, id, OPTIONAL);
      else { run.running(id); run.record(id, { outcome: 'needs-human', remarks: humanMissing('an Aadhaar number (aadhaar-holder)') }); }
    }
    return;
  }

  if (want('CRT_ABHA_102')) {
    await runCase(run, 'CRT_ABHA_102', async () => {
      const r = await call(hmis, 'm1/enrol_otp', { aadhaar: S.aadhaar, consent: false });
      const refused = r.status === 400 && /consent/i.test(String(r.body?.error || ''));
      const c1 = await screen('CRT_ABHA_102', 'Create ABHA screen: the approved ABDM consent text is shown before the OTP is sent, with an "I agree" checkbox that must be ticked');
      const c2 = await screen('CRT_ABHA_102', 'the consent is recorded (Smart Hospital stores staff, time and text version in abdm_consents when the OTP is sent)');
      return { ...judge(refused, [c1, c2], { screenCase: true, sandboxRemarks: `without consent: HTTP ${r.status} "${redact(r.body?.error || '')}"` }),
        response: `POST m1/enrol_otp consent=false HTTP ${r.status}`, evidence: 'screen (attested)' };
    });
  }

  // CRT_ABHA_105: request the Aadhaar OTP (one request serves 105, 107, 101).
  let otpReq = null;
  const sendAadhaarOtp = async () => {
    otpReq = await call(hmis, 'm1/enrol_otp', { aadhaar: S.aadhaar, consent: true });
    if (ok(otpReq)) S.txnId = otpReq.body.txnId;
    return otpReq;
  };
  const journeyMark = await auditMark().catch(() => 0);
  if (['CRT_ABHA_101', 'CRT_ABHA_105', 'CRT_ABHA_107', 'CRT_ABHA_108', 'CRT_ABHA_112', 'CRT_ABHA_113', 'CRT_ABHA_114'].some(want)) {
    await step(run, sel, 'CRT_ABHA_105', async () => {
      const r = await sendAadhaarOtp();
      const hint = mobileHint(r.body?.message);
      const got = ok(r) && r.body?.txnId && hint;
      const c1 = !sel.has('CRT_ABHA_105') ? { v: true, check: '' } : await screen('CRT_ABHA_105', `the OTP prompt says it went to the Aadhaar-linked mobile and shows ${hint || 'the masked mobile'}; the OTP field takes 6 digits only`, { alongside: true });
      return { ...judge(!!got, [c1], { sandboxRemarks: `POST m1/enrol_otp HTTP ${r.status}, txnId ${r.body?.txnId ? 'present' : 'absent'}, message "${redact(r.body?.message || r.body?.error || '')}"` }),
        response: `POST m1/enrol_otp HTTP ${r.status}${hint ? ` message names ${hint}` : ''}` };
    });
  }

  if (want('CRT_ABHA_106')) {
    await runCase(run, 'CRT_ABHA_106', async () => {
      const c = await screen('CRT_ABHA_106', 'Resend OTP on the Aadhaar OTP screen: disabled at send, enabled after 60 s, a visible counter, two resends, and a third shows "Maximum OTP resend attempts reached. Please try again after 30 mins."');
      return { ...judge(true, [c], { screenCase: true }), evidence: 'screen (attested): recording or timestamped screenshots' };
    });
  }

  // CRT_ABHA_107 (wrong then right OTP) + 101 step 1 + 108 (Aadhaar mobile as communication mobile).
  let verify = null;
  if (S.txnId && ['CRT_ABHA_101', 'CRT_ABHA_107', 'CRT_ABHA_108', 'CRT_ABHA_112', 'CRT_ABHA_113', 'CRT_ABHA_114'].some(want)) {
    const mobile = await need('CRT_ABHA_108', 'the communication mobile: for CRT_ABHA_108 give the mobile LINKED TO THIS AADHAAR (input hidden)', { secret: true, check: isMobile });
    const doVerify = async (otp) => call(hmis, 'm1/enrol_verify', { txnId: S.txnId, otp, mobile: digits(mobile).slice(-10) });
    await step(run, sel, 'CRT_ABHA_107', async () => {
      if (!mobile) return { outcome: 'needs-human', remarks: humanMissing('the communication mobile') };
      const wrong = !sel.has('CRT_ABHA_107') ? null : await need('CRT_ABHA_107', 'a WRONG 6-digit OTP (anything but the one received), to check the refusal (input hidden)', { secret: true, check: isOtp });
      let refusedOk = false, wrongLine = 'wrong OTP not entered';
      if (wrong) {
        const w = await doVerify(wrong);
        refusedOk = w.status >= 400 && w.status < 500;
        wrongLine = `wrong OTP: HTTP ${w.status} ${w.body?.code || ''} "${redact(w.body?.error || '')}"`;
      }
      const right = await otpFor('CRT_ABHA_107', mobileHint(otpReq?.body?.message));
      if (!right) return { outcome: 'needs-human', remarks: humanMissing('the Aadhaar OTP'), trace: [wrongLine] };
      verify = await doVerify(right);
      const v = verify.body || {};
      S.token = v.tokens?.token; S.kind = 'abha'; S.profile = v.ABHAProfile || {}; S.txnId = v.txnId || S.txnId; S.isNew = v.isNew;
      const passed = ok(verify) && S.token && S.profile.ABHANumber;
      const c1 = !sel.has('CRT_ABHA_107') ? { v: true, check: '' } : await screen('CRT_ABHA_107', 'after the wrong OTP the screen shows the API error and lets the person retry without typing the Aadhaar again; the Verify button enables itself at 6 digits', { alongside: true });
      const r = judge(passed && (refusedOk || !wrong), [c1], {
        sandboxRemarks: `${wrongLine}; right OTP: HTTP ${verify.status}, tokens.token ${S.token ? 'present' : 'absent'}, ABHAProfile.ABHANumber ${maskAbha(S.profile.ABHANumber) || 'absent'}, isNew ${v.isNew}, message "${redact(v.message || '')}"` });
      if (!wrong && sel.has('CRT_ABHA_107') && r.outcome !== 'failed') return { outcome: 'needs-human', remarks: `${humanMissing('a wrong OTP for the refusal check')}; right-OTP step passed`, steps: [{ outcome: 'needs-human', note: 'wrong OTP' }, { outcome: 'passed' }] };
      return { ...r, steps: [{ outcome: refusedOk ? 'passed' : 'failed', note: wrongLine }, { outcome: passed ? 'passed' : 'failed' }],
        response: `POST m1/enrol_verify HTTP ${verify.status}${S.isNew === false ? ' (This account already exist, isNew false)' : ''}` };
    });
    if (want('CRT_ABHA_108')) {
      await runCase(run, 'CRT_ABHA_108', async () => {
        if (!verify || !ok(verify)) return { outcome: 'failed', remarks: 'depends on the enrolment verify (CRT_ABHA_107), which did not succeed' };
        const needs = verify.body.mobileVerificationNeeded;
        const c = await screen('CRT_ABHA_108', 'with the Aadhaar-linked mobile the screen went straight on to the ABHA address step, with no mobile OTP');
        return { ...judge(needs === false, [c], { sandboxRemarks: `mobileVerificationNeeded ${needs}; no mobile-verify OTP request expected` }),
          response: `POST m1/enrol_verify HTTP ${verify.status} mobileVerificationNeeded=${needs}` };
      });
    }
  }

  if (want('CRT_ABHA_109')) {
    await runCase(run, 'CRT_ABHA_109', async () => {
      // A second enrolment pass with another communication mobile (needs a fresh Aadhaar OTP).
      const mob2 = await need('CRT_ABHA_109', 'a SECOND mobile, not the one linked to the Aadhaar (input hidden)', { secret: true, check: isMobile });
      if (!mob2) return { outcome: 'needs-human', remarks: humanMissing('a second mobile (second-mobile)') };
      const r1 = await sendAadhaarOtp();
      if (!ok(r1)) return { outcome: 'failed', remarks: `Aadhaar OTP request: HTTP ${r1.status} "${redact(r1.body?.error || '')}"` };
      const o1 = await otpFor('CRT_ABHA_109', `${mobileHint(r1.body?.message) || 'the Aadhaar-linked mobile'} (Aadhaar OTP)`);
      if (!o1) return { outcome: 'needs-human', remarks: humanMissing('the Aadhaar OTP') };
      const v = await call(hmis, 'm1/enrol_verify', { txnId: S.txnId, otp: o1, mobile: digits(mob2).slice(-10) });
      if (!ok(v)) return { outcome: 'failed', remarks: `enrol verify: HTTP ${v.status} "${redact(v.body?.error || '')}"` };
      S.txnId = v.body.txnId || S.txnId; S.token = v.body.tokens?.token || S.token; S.profile = v.body.ABHAProfile || S.profile;
      if (v.body.mobileVerificationNeeded !== true) return { outcome: 'failed', remarks: `a different mobile was not sent for verification (mobileVerificationNeeded ${v.body.mobileVerificationNeeded})` };
      const m = await call(hmis, 'm1/mobile_otp', { txnId: S.txnId, mobile: digits(mob2).slice(-10) });
      if (!ok(m)) return { outcome: 'failed', remarks: `mobile OTP request: HTTP ${m.status} "${redact(m.body?.error || '')}"` };
      S.txnId = m.body.txnId || S.txnId;
      const wrong = await need('CRT_ABHA_109', 'a WRONG 6-digit OTP for the mobile, to check the refusal (input hidden)', { secret: true, check: isOtp });
      let wrongLine = 'wrong OTP not entered', wrongOk = false;
      if (wrong) { const w = await call(hmis, 'm1/mobile_verify', { txnId: S.txnId, otp: wrong }); wrongOk = w.status >= 400 || w.body?.authResult === 'failed'; wrongLine = `wrong mobile OTP: HTTP ${w.status} "${redact(w.body?.error || w.body?.message || '')}"`; }
      const o2 = await otpFor('CRT_ABHA_109', `${masked(mob2)} (mobile OTP)`);
      if (!o2) return { outcome: 'needs-human', remarks: humanMissing('the mobile OTP'), trace: [wrongLine] };
      const mv = await call(hmis, 'm1/mobile_verify', { txnId: S.txnId, otp: o2 });
      const passed = ok(mv) && (!mv.body.authResult || mv.body.authResult === 'success');
      const c1 = await screen('CRT_ABHA_109', 'the mobile OTP screen has Resend as in CRT_ABHA_106, and the ABHA card shows this communication mobile', { alongside: true });
      const r = judge(passed && (wrongOk || !wrong), [c1], { sandboxRemarks: `${wrongLine}; mobile verify HTTP ${mv.status} authResult ${mv.body?.authResult || '-'}` });
      if (!wrong && r.outcome !== 'failed') return { outcome: 'needs-human', remarks: `${humanMissing('a wrong mobile OTP')}; the right OTP verified the mobile`, steps: [{ outcome: 'passed' }, { outcome: 'needs-human', note: 'wrong OTP' }, { outcome: 'passed' }] };
      return { ...r, steps: [{ outcome: 'passed', note: 'mobile OTP sent' }, { outcome: wrongOk ? 'passed' : 'failed', note: wrongLine }, { outcome: passed ? 'passed' : 'failed' }],
        response: `POST m1/mobile_otp HTTP ${m.status}; POST m1/mobile_verify HTTP ${mv.status}` };
    });
  }

  if (want('CRT_ABHA_112')) {
    await runCase(run, 'CRT_ABHA_112', async () => {
      if (!S.txnId || !S.token) return { outcome: 'failed', remarks: 'depends on a completed enrolment (CRT_ABHA_107)' };
      const rules = await call(hmis, 'm1/address_rules', {});
      const sug = await call(hmis, 'm1/address_suggestions', { txnId: S.txnId });
      const list = sug.body?.abhaAddressList || [];
      S.txnId = sug.body?.txnId || S.txnId;
      console.log(`  CRT_ABHA_112: suggestions: ${list.map((a, i) => `[${i + 1}] ${a}`).join('  ') || '(none)'}  · suffix ${rules.body?.suffix || '?'}`);
      const pick = await ask('  CRT_ABHA_112: choose a suggestion number, or type your own ABHA address (part before @):');
      const chosen = /^\d+$/.test(pick) && list[Number(pick) - 1] ? list[Number(pick) - 1].replace(/@.*$/, '') : pick.replace(/@.*$/, '');
      if (!chosen) return { outcome: 'needs-human', remarks: humanMissing('an ABHA address choice') };
      const c = await call(hmis, 'm1/address_create', { txnId: S.txnId, abhaAddress: chosen });
      const exists = c.status === 409 && c.body?.code === 'ABHA_ADDRESS_EXISTS';
      const created = ok(c) && c.body?.preferredAbhaAddress;
      if (created) S.address = c.body.preferredAbhaAddress;
      const c1 = await screen('CRT_ABHA_112', 'at least three suggestions shown; the free field shows the rules (8-18 characters incl. @sbx, letters/digits, one dot and/or one underscore, not first or last), each green when met and red when not', { alongside: true });
      const c2 = await screen('CRT_ABHA_112', 'an address already taken shows "ABHA address already exists." with two choices: proceed with the existing address, or create a new one', { alongside: true });
      const passed = list.length >= 3 && created && String(c.body.preferredAbhaAddress).startsWith(chosen);
      return { ...judge(!!passed, [c1, c2], { sandboxRemarks: `${list.length} suggestions; create "${chosen}": HTTP ${c.status}${exists ? ' "ABHA address already exists." (choose another and run again)' : ''} preferredAbhaAddress ${c.body?.preferredAbhaAddress || '-'}` }),
        response: `GET suggestions ${list.length}; POST m1/address_create HTTP ${c.status}` };
    });
  }

  if (want('CRT_ABHA_113')) {
    await runCase(run, 'CRT_ABHA_113', async () => {
      if (!S.token) return { outcome: 'failed', remarks: 'depends on a completed enrolment (CRT_ABHA_107)' };
      const l = await lookup(hmis, S.token, 'abha');
      S.lookup = l.body;
      const num = digits(l.body?.number);
      const c = await screen('CRT_ABHA_113', `the profile shows the 14-digit ABHA number ending ${num.slice(-4)} and the ABHA address${S.address ? ` ${S.address}` : ''} together with the profile details`);
      return { ...judge(ok(l) && num.length === 14 && (!S.address || (l.body.addresses || [l.body.address]).includes(S.address)), [c], { screenCase: true,
        sandboxRemarks: `profile: ABHA number ${maskAbha(num)}, address ${l.body?.address || '-'}` }), response: `POST abha_lookup HTTP ${l.status}` };
    });
  }

  if (want('CRT_ABHA_114')) {
    await runCase(run, 'CRT_ABHA_114', async () => {
      if (!S.token) return { outcome: 'failed', remarks: 'depends on a completed enrolment (CRT_ABHA_107)' };
      const card = await call(hmis, 'm1/card', { token: S.token, kind: 'abha' }, { binary: true });
      const isImg = ok(card) && /^image\/|application\/pdf/.test(card.contentType);
      const c = await screen('CRT_ABHA_114', 'View ABHA card shows the card as ABDM returned it, and Download ABHA card saves it (a non-KYC ABHA cannot download a card)', { alongside: true });
      return { ...judge(isImg, [c], { sandboxRemarks: `card: HTTP ${card.status} ${card.contentType} ${card.body?.length || 0} bytes` }), response: `POST m1/card HTTP ${card.status} ${card.contentType}` };
    });
  }

  if (want('CRT_ABHA_101')) {
    // The option exists and the journey completes: enrolment, address, then the profile with the same ABHA number.
    await runCase(run, 'CRT_ABHA_101', async () => {
      const rows = await auditSince(journeyMark).catch(() => []);
      const prof = S.token ? await lookup(hmis, S.token, 'abha') : null;
      const enrolled = !!(verify && ok(verify) && S.token && S.profile?.ABHANumber);
      const addressed = !!S.address || (S.isNew === false && (S.profile?.phrAddress || []).length > 0);
      const same = prof && ok(prof) && digits(prof.body.number) === digits(S.profile?.ABHANumber);
      const c = await screen('CRT_ABHA_101', 'Smart Hospital offers "Create new ABHA" (ABDM / ABHA → Create / Verify ABHA, and on the patient page)');
      return { ...judge(enrolled && addressed && same, [c], { screenCase: true,
        sandboxRemarks: `enrolment ${enrolled ? `ABHA ${maskAbha(S.profile?.ABHANumber)}${S.isNew === false ? ' (existing ABHA, isNew false: carried on)' : ' (new)'}` : 'not completed'}; address ${S.address || (addressed ? 'existing' : 'none')}; profile ABHA ${maskAbha(prof?.body?.number)} same: ${!!same}` }),
        requestIds: requestIds(rows), response: `enrol_verify ${verify?.status}; address ${S.address ? 'created' : '-'}; abha_lookup ${prof?.status}` };
    });
  }

  for (const id of ['CRT_ABHA_103', 'CRT_ABHA_115']) {
    if (!want(id)) continue;
    if (!run.optIn.has(id)) { notRun(run, id, OPTIONAL); continue; }
    await runCase(run, id, async () => {
      const c = await screen(id, id === 'CRT_ABHA_103' ? 'the consent is offered in at least one language other than English' : 'your own card prints the ABHA number and ABHA address, both stored against your patient id');
      return { ...judge(true, [c], { screenCase: true }), evidence: 'screen (attested)' };
    });
  }

  if (want('CRT_ABHA_116')) {
    await runCase(run, 'CRT_ABHA_116', async () => {
      if (!S.token) return { outcome: 'needs-human', remarks: 'Needs tester: a completed creation or verification in this run (patient-registered), none available' };
      const l1 = await lookup(hmis, S.token, S.kind || 'abha');
      if (!ok(l1)) return { outcome: 'failed', remarks: `abha_lookup HTTP ${l1.status} "${redact(l1.body?.error || '')}"` };
      let pid = l1.body.hms_patient_id, how = 'already linked';
      if (!pid) {
        const full = (l1.body.candidates || []).find((c) => c.full && !c.abha_number);
        if (full) {
          const s = await call(hmis, `save_abha/${full.id}`, { token: S.token, kind: S.kind || 'abha' });
          if (!ok(s) || s.body?.mismatch) return { outcome: 'failed', remarks: `linking to the matched patient #${full.id}: HTTP ${s.status} ${s.body?.mismatch ? 'mismatch' : redact(s.body?.error || '')}` };
          pid = full.id; how = 'matched on mobile, name, gender and age; linked to the existing patient';
        } else {
          const reg = await call(hmis, 'abha_register', { token: S.token, kind: S.kind || 'abha', form: {} });
          if (reg.body?.match) { pid = reg.body.match.id; how = `register returned the full match #${pid} instead of a new patient`; }
          else if (ok(reg)) { pid = reg.body.patientId; how = reg.body.existing ? 'already registered' : 'registered as a new patient (first visit)'; }
          else return { outcome: 'failed', remarks: `abha_register HTTP ${reg.status} "${redact(reg.body?.error || '')}"` };
        }
      }
      S.patientId = pid;
      const l2 = await lookup(hmis, S.token, S.kind || 'abha');
      const same = ok(l2) && Number(l2.body.hms_patient_id) === Number(pid);
      const c = await screen('CRT_ABHA_116', `the second visit opens the same patient #${pid} (ABHA vs hospital comparison) and no second patient is created; a likely returning patient is offered with mobile/name/gender/age ticks`);
      return { ...judge(same, [c], { screenCase: true, sandboxRemarks: `first visit: ${how}; second lookup: patient #${l2.body?.hms_patient_id}` }), response: `POST abha_lookup x2, patient #${pid}` };
    });
  }
}

// ---------------------------------------------------------------- 2. Log in with ABHA number
async function groupAbhaNumber(run, hmis, sel) {
  const ids = ['VRFY_ABHA_101', 'VRFY_ABHA_201'].filter((id) => sel.has(id));
  if (!ids.length) return;
  let abha = S.profile?.ABHANumber && (await ask(`  Use the ABHA number from creation (${maskAbha(S.profile.ABHANumber)})? y/n`)).toLowerCase().startsWith('y') ? S.profile.ABHANumber : null;
  if (!abha) abha = await need(ids[0], 'the 14-digit ABHA number of the person present (input hidden)', { secret: true, check: isAbhaNumber });
  for (const id of ids) {
    await runCase(run, id, async () => {
      if (!abha) return { outcome: 'needs-human', remarks: humanMissing('an ABHA number (aadhaar-holder)') };
      const method = id === 'VRFY_ABHA_101' ? 'abha-number:aadhaar' : 'abha-number:mobile';
      const o = await call(hmis, 'm1/login_otp', { method, loginId: digits(abha) });
      if (!ok(o)) return { outcome: 'failed', remarks: `login OTP (${method}): HTTP ${o.status} "${redact(o.body?.error || '')}"` };
      const txn = o.body.txnId;
      const wrong = await need(id, 'a WRONG 6-digit OTP, to check the refusal (input hidden)', { secret: true, check: isOtp });
      let wrongLine = 'wrong OTP not entered', wrongOk = false;
      if (wrong) { const w = await call(hmis, 'm1/login_verify', { method, txnId: txn, otp: wrong }); wrongOk = w.status >= 400 || (w.body?.authResult && w.body.authResult !== 'success'); wrongLine = `wrong OTP: HTTP ${w.status} "${redact(w.body?.error || w.body?.message || '')}"`; }
      const otp = await otpFor(id, mobileHint(o.body.message) || (id === 'VRFY_ABHA_101' ? 'the Aadhaar-linked mobile' : 'the ABHA-linked mobile'));
      if (!otp) return { outcome: 'needs-human', remarks: humanMissing('the login OTP'), trace: [wrongLine] };
      const v = await call(hmis, 'm1/login_verify', { method, txnId: txn, otp });
      const acct = (v.body?.accounts || [])[0];
      const token = v.body?.token;
      const l = token ? await lookup(hmis, token, 'abha') : null;
      const sameNum = l && ok(l) && digits(l.body.number) === digits(acct?.ABHANumber || abha);
      if (token) { S.token = token; S.kind = 'abha'; }
      const checks = [
        await screen(id, 'Resend OTP works as in CRT_ABHA_106 on this login screen', { alongside: true }),
        await screen(id, 'the registration form: name, date of birth and gender read-only; address, email, mobile, state, district, city editable', { alongside: true }),
        await screen(id, 'the verified profile is stored against the patient id (Register / Link), and when the hospital mobile differs the screen offers "Update the mobile on the ABHA profile? Yes/No"', { alongside: true }),
      ];
      const passed = ok(v) && v.body.authResult === 'success' && acct?.ABHANumber && sameNum;
      const r = judge(!!passed && (wrongOk || !wrong), checks, { sandboxRemarks: `${wrongLine}; verify HTTP ${v.status} authResult ${v.body?.authResult}; accounts[0].ABHANumber ${maskAbha(acct?.ABHANumber)}; profile ${l ? `HTTP ${l.status} ${maskAbha(l.body?.number)}` : 'not read'}` });
      if (!wrong && r.outcome !== 'failed') return { outcome: 'needs-human', remarks: `${humanMissing('a wrong OTP for the refusal check')}; the right OTP verified`, steps: [{ outcome: 'needs-human', note: 'wrong OTP' }, { outcome: 'passed' }] };
      return { ...r, response: `POST m1/login_otp HTTP ${o.status}; POST m1/login_verify HTTP ${v.status}; POST abha_lookup HTTP ${l?.status}` };
    });
  }
}

// ---------------------------------------------------------------- 3. Log in with ABHA address
async function groupAbhaAddress(run, hmis, sel) {
  const ids = ['VRFY_ABHA_102', 'VRFY_ABHA_202'].filter((id) => sel.has(id));
  if (!ids.length) return;
  let addr = S.address && (await ask(`  Use the ABHA address from creation (${S.address})? y/n`)).toLowerCase().startsWith('y') ? S.address : null;
  if (!addr) addr = await need(ids[0], 'the ABHA address of the person present, e.g. name@sbx');
  for (const id of ids) {
    await runCase(run, id, async () => {
      if (!addr) return { outcome: 'needs-human', remarks: humanMissing('an ABHA address') };
      const s = await call(hmis, 'm1/address_search', { abhaAddress: addr });
      if (!ok(s)) return { outcome: 'failed', remarks: `search: HTTP ${s.status} ${s.body?.code || ''} "${redact(s.body?.error || '')}"` };
      const method = id === 'VRFY_ABHA_102' ? 'abha-address:aadhaar' : 'abha-address:mobile';
      if (!(s.body.methods || []).includes(method)) {
        return { outcome: 'needs-human', remarks: `ABDM offers ${JSON.stringify(s.body.authMethods)} for this address, not ${method}: needs an ABHA address with that method`, response: `POST m1/address_search HTTP ${s.status} authMethods ${JSON.stringify(s.body.authMethods)}` };
      }
      const o = await call(hmis, 'm1/address_otp', { abhaAddress: s.body.abhaAddress, method });
      if (!ok(o)) return { outcome: 'failed', remarks: `OTP request: HTTP ${o.status} "${redact(o.body?.error || '')}"` };
      const otp = await otpFor(id, mobileHint(o.body.message) || (method.endsWith('aadhaar') ? 'the Aadhaar-linked mobile' : 'the ABHA-linked mobile'));
      if (!otp) return { outcome: 'needs-human', remarks: humanMissing('the OTP') };
      const v = await call(hmis, 'm1/address_verify', { method, txnId: o.body.txnId, otp });
      const token = v.body?.token;
      const l = token ? await lookup(hmis, token, 'phr') : null;
      const addrMatch = l && ok(l) && (l.body.addresses || [l.body.address]).map((a) => String(a).toLowerCase()).includes(String(s.body.abhaAddress).toLowerCase());
      if (token) { S.token = token; S.kind = 'phr'; }
      const checks = [
        await screen(id, `the ways to log in offered on screen are exactly ABDM's authMethods (${(s.body.authMethods || []).join(', ')}), not a fixed list`, { alongside: true }),
        await screen(id, 'Resend, the read-only name/DOB/gender, the stored record and the mobile update offer as in VRFY_ABHA_101', { alongside: true }),
      ];
      return { ...judge(!!(ok(v) && token && l && ok(l) && l.body.number && addrMatch), checks, { sandboxRemarks: `search authMethods ${JSON.stringify(s.body.authMethods)}; verify HTTP ${v.status} token ${token ? 'present' : 'absent'}; profile ABHA ${maskAbha(l?.body?.number)} address ${l?.body?.address || '-'}` }),
        response: `POST m1/address_search ${s.status}; address_otp ${o.status}; address_verify ${v.status}; abha_lookup ${l?.status}` };
    });
  }
}

// ---------------------------------------------------------------- 4. Find a forgotten ABHA by mobile
async function groupFind(run, hmis, sel) {
  const want = (id) => sel.has(id);
  if (want('VRFY_ABHA_301')) {
    await runCase(run, 'VRFY_ABHA_301', async ({ mark }) => {
      const bad = ['98765', '987654321012', 'abcdefghij', '98765#3210', ''];
      const seen = []; let all = true;
      for (const m of bad) {
        const r = await call(hmis, 'm1/find_search', { mobile: m });
        const msg = String(r.body?.error || '');
        seen.push(`"${m.replace(/\d/g, '9')}": HTTP ${r.status}`);
        if (r.status !== 400 || !/Please enter a valid mobile number\./.test(msg)) all = false;
      }
      const calls = abdmCalls(await auditSince(mark));
      const c = await screen('VRFY_ABHA_301', 'Mobile only: fewer/more than 10 digits, letters, special characters and blank each show "Please enter a valid mobile number." and no search is sent');
      return { ...judge(all && !calls.length, [c], { screenCase: true, sandboxRemarks: `Expected "Please enter a valid mobile number."; ${seen.join('; ')}; ABDM calls: ${calls.length}` }), evidence: 'screen (attested) + no ABDM call' };
    });
  }

  const listIds = ['VRFY_ABHA_302', 'VRFY_ABHA_304', 'VRFY_ABHA_305', 'VRFY_ABHA_306', 'VRFY_ABHA_307', 'VRFY_ABHA_308'];
  let mob = null, found = null, pick = null, otpTxn = null, token = null, l = null;
  if (listIds.some(want)) mob = await need('VRFY_ABHA_302', 'the communication mobile that holds the person\'s ABHA (input hidden)', { secret: true, check: isMobile });

  if (want('VRFY_ABHA_302')) {
    await runCase(run, 'VRFY_ABHA_302', async ({ mark }) => {
      if (!mob) return { outcome: 'needs-human', remarks: humanMissing('a mobile that holds an ABHA') };
      found = await call(hmis, 'm1/find_search', { mobile: digits(mob).slice(-10) });
      const acc = found.body?.accounts || [];
      const noOtp = !abdmCalls(await auditSince(mark)).some((r) => /login\/request\/otp/.test(r.action));
      acc.forEach((a, i) => console.log(`   [${i + 1}] ${a.name} ${a.gender} ${a.abhaNumber}${a.kycVerified ? ' KYC' : ''}`));
      const c = await screen('VRFY_ABHA_302', 'every ABHA on the mobile is listed with enough to tell them apart (masked ABHA number, name, gender) and no OTP is requested before one is picked', { alongside: true });
      return { ...judge(ok(found) && found.body.txnId && acc.length >= 1 && acc.every((a) => a.index != null && a.abhaNumber && a.name) && noOtp, [c],
        { sandboxRemarks: `search HTTP ${found.status}: txnId ${found.body?.txnId ? 'present' : 'absent'}, ${acc.length} account(s), no OTP before a pick: ${noOtp}` }), response: `POST m1/find_search HTTP ${found.status} ${acc.length} accounts` };
    });
  }

  if (want('VRFY_ABHA_303')) {
    await runCase(run, 'VRFY_ABHA_303', async ({ mark }) => {
      const m = await need('VRFY_ABHA_303', 'a mobile number linked to NO ABHA (input hidden)', { secret: true, check: isMobile });
      if (!m) return { outcome: 'needs-human', remarks: humanMissing('a mobile without ABHA (mobile-without-abha)') };
      const r = await call(hmis, 'm1/find_search', { mobile: digits(m).slice(-10) });
      const rows = abdmCalls(await auditSince(mark));
      const searched = rows.find((x) => /abha\/search/.test(x.action));
      const c = await screen('VRFY_ABHA_303', 'the screen says "ABHA details not found. We did not find any ABHA number linked to this mobile number. Please use an ABHA-linked mobile number.", opens no OTP screen, and offers to create a new ABHA');
      return { ...judge(ok(r) && (r.body.accounts || []).length === 0 && !rows.some((x) => /login\/request\/otp/.test(x.action)), [c], { screenCase: false,
        sandboxRemarks: `search HTTP ${r.status} accounts ${(r.body?.accounts || []).length}; ABDM search status ${searched?.status ?? '-'} (spec: 404 ABDM-1114)` }), response: `POST m1/find_search HTTP ${r.status} no accounts` };
    });
  }

  if (['VRFY_ABHA_304', 'VRFY_ABHA_305', 'VRFY_ABHA_306', 'VRFY_ABHA_307', 'VRFY_ABHA_308'].some(want)) {
    if (!found && mob) found = await call(hmis, 'm1/find_search', { mobile: digits(mob).slice(-10) });
    const acc = found?.body?.accounts || [];
    if (acc.length) {
      const i = acc.length === 1 ? 1 : Number(await ask(`  VRFY_ABHA_304: pick the person's ABHA [1-${acc.length}]:`));
      pick = acc[(i || 1) - 1];
    }
  }

  if (want('VRFY_ABHA_304')) {
    await runCase(run, 'VRFY_ABHA_304', async () => {
      if (!pick) return { outcome: 'needs-human', remarks: humanMissing('a mobile that lists an ABHA (VRFY_ABHA_302)') };
      const o = await call(hmis, 'm1/find_otp', { txnId: found.body.txnId, index: pick.index });
      otpTxn = o.body?.txnId;
      const c = await screen('VRFY_ABHA_304', `the OTP screen opens for ${pick.name} (${pick.abhaNumber}) only after the pick, the pick stays selected, and Resend works as in CRT_ABHA_106`, { alongside: true });
      return { ...judge(!!(ok(o) && otpTxn), [c], { sandboxRemarks: `find OTP HTTP ${o.status} txnId ${otpTxn ? 'present' : 'absent'} message "${redact(o.body?.message || o.body?.error || '')}"` }), response: `POST m1/find_otp HTTP ${o.status}` };
    });
  }

  if (want('VRFY_ABHA_307')) {
    await runCase(run, 'VRFY_ABHA_307', async () => {
      if (!otpTxn) return { outcome: 'needs-human', remarks: 'depends on VRFY_ABHA_304 (an OTP sent for the picked ABHA)' };
      const wrong = await need('VRFY_ABHA_307', 'a WRONG 6-digit OTP (input hidden)', { secret: true, check: isOtp });
      if (!wrong) return { outcome: 'needs-human', remarks: humanMissing('a wrong OTP') };
      const w = await call(hmis, 'm1/find_verify', { txnId: otpTxn, otp: wrong });
      const c = await screen('VRFY_ABHA_307', 'the screen shows "Invalid OTP. Please enter a valid OTP.", opens no profile and allows a retry without re-entering the mobile');
      return { ...judge(w.status >= 400 && w.status < 500, [c], { screenCase: false, sandboxRemarks: `wrong OTP: HTTP ${w.status} "${redact(w.body?.error || '')}" (expected 400 invalid OTP)` }), response: `POST m1/find_verify (wrong) HTTP ${w.status}` };
    });
  }

  if (want('VRFY_ABHA_305')) {
    await runCase(run, 'VRFY_ABHA_305', async () => {
      if (!otpTxn) return { outcome: 'needs-human', remarks: 'depends on VRFY_ABHA_304 (an OTP sent for the picked ABHA)' };
      const otp = await otpFor('VRFY_ABHA_305', 'the communication mobile (for the picked ABHA)');
      if (!otp) return { outcome: 'needs-human', remarks: humanMissing('the OTP') };
      const v = await call(hmis, 'm1/find_verify', { txnId: otpTxn, otp });
      token = v.body?.token;
      l = token ? await lookup(hmis, token, 'abha') : null;
      if (token) { S.token = token; S.kind = 'abha'; }
      const chosenLast4 = digits(pick.abhaNumber).slice(-4);
      const same = l && ok(l) && digits(l.body.number).endsWith(chosenLast4) && (v.body.accounts || []).some((a) => digits(a.ABHANumber).endsWith(chosenLast4));
      const c = await screen('VRFY_ABHA_305', 'only the picked profile is shown, with name, DOB/age, gender, mobile, ABHA number, ABHA address, state, district, photo and address as ABDM returned them', { alongside: true });
      return { ...judge(ok(v) && v.body.authResult === 'success' && token && same, [c], { sandboxRemarks: `verify HTTP ${v.status} authResult ${v.body?.authResult}; profile ${maskAbha(l?.body?.number)} matches the pick: ${same}` }), response: `POST m1/find_verify HTTP ${v.status}; abha_lookup HTTP ${l?.status}` };
    });
  }

  if (want('VRFY_ABHA_306')) {
    await runCase(run, 'VRFY_ABHA_306', async () => {
      if (!token) return { outcome: 'needs-human', remarks: 'depends on VRFY_ABHA_305 (a verified profile)' };
      const c1 = await screen('VRFY_ABHA_306', 'Register as a new patient: the form is filled from ABHA; name, DOB and gender read-only; address, email, mobile, state, district and city editable');
      const c2 = await screen('VRFY_ABHA_306', 'after Register (or Link) the ABHA number and address are stored on the patient id');
      return { ...judge(true, [c1, c2], { screenCase: true }), evidence: 'screen (attested): the form and the stored link' };
    });
  }

  if (want('VRFY_ABHA_308')) {
    await runCase(run, 'VRFY_ABHA_308', async () => {
      if (!token) return { outcome: 'needs-human', remarks: 'depends on VRFY_ABHA_305 (a verified profile)' };
      let lk = await lookup(hmis, token, 'abha');
      if (ok(lk) && !lk.body.hms_patient_id) {
        console.log('  VRFY_ABHA_308: this ABHA is not linked yet; registering it so the "already linked" screen can be checked.');
        const reg = await call(hmis, 'abha_register', { token, kind: 'abha', form: {} });
        if (reg.body?.match) await call(hmis, `save_abha/${reg.body.match.id}`, { token, kind: 'abha' });
        lk = await lookup(hmis, token, 'abha');
      }
      const pid = lk.body?.hms_patient_id;
      const reg2 = pid ? await call(hmis, 'abha_register', { token, kind: 'abha', form: {} }) : null;
      const noDup = reg2 && ok(reg2) && reg2.body.existing === true && Number(reg2.body.patientId) === Number(pid);
      const c = await screen('VRFY_ABHA_308', `the screen says "This ABHA profile is already linked to HMIS Beneficiary ID #${pid}." and shows ABHA vs hospital details side by side`);
      return { ...judge(!!(pid && noDup && lk.body.hms), [c], { screenCase: true, sandboxRemarks: `lookup: linked to patient #${pid}; register again: existing ${reg2?.body?.existing} #${reg2?.body?.patientId}` }), response: `POST abha_lookup HTTP ${lk.status}; abha_register HTTP ${reg2?.status}` };
    });
  }
}

// ---------------------------------------------------------------- 5. Log in with Aadhaar OTP
async function groupAadhaar(run, hmis, sel) {
  const want = (id) => sel.has(id);
  if (want('VRFY_ABHA_401')) {
    await runCase(run, 'VRFY_ABHA_401', async ({ mark }) => {
      const r = await call(hmis, 'm1/login_otp', { method: 'aadhaar:aadhaar', loginId: '123456789012' });
      const calls = abdmCalls(await auditSince(mark));
      const c = await screen('VRFY_ABHA_401', 'Verify by Aadhaar: an invalid Aadhaar shows "Aadhaar Number is not valid" (or "Invalid LoginId") and nothing is sent; the input is masked');
      return { ...judge(r.status === 400 && /Aadhaar Number is not valid|Invalid LoginId/i.test(String(r.body?.error)) && !calls.length, [c], { screenCase: true,
        sandboxRemarks: `Expected "Aadhaar Number is not valid"; observed HTTP ${r.status} "${redact(r.body?.error || '')}", ABDM calls ${calls.length}` }), evidence: 'screen (attested) + no ABDM call' };
    });
  }
  if (want('VRFY_ABHA_403')) {
    await runCase(run, 'VRFY_ABHA_403', async () => {
      const a = await need('VRFY_ABHA_403', 'an Aadhaar number that holds NO ABHA (input hidden)', { secret: true, check: isAadhaar });
      if (!a) return { outcome: 'needs-human', remarks: humanMissing('an Aadhaar with no ABHA (aadhaar-without-abha)') };
      const o = await call(hmis, 'm1/login_otp', { method: 'aadhaar:aadhaar', loginId: a.replace(/\s/g, '') });
      if (!ok(o)) return { outcome: 'failed', remarks: `OTP request HTTP ${o.status} "${redact(o.body?.error || '')}"` };
      const otp = await otpFor('VRFY_ABHA_403', mobileHint(o.body.message) || 'the Aadhaar-linked mobile');
      if (!otp) return { outcome: 'needs-human', remarks: humanMissing('the Aadhaar OTP') };
      const v = await call(hmis, 'm1/login_verify', { method: 'aadhaar:aadhaar', txnId: o.body.txnId, otp });
      const c = await screen('VRFY_ABHA_403', 'the screen says "NO ABHA user registered with this Aadhaar Number" and offers "Create ABHA with this Aadhaar", which does not ask for the Aadhaar again');
      return { ...judge(v.status === 404 && /ABDM-1114/.test(String(v.body?.code)), [c], { sandboxRemarks: `request: message "${redact(o.body.message || '')}"; verify HTTP ${v.status} ${v.body?.code || ''} "${redact(v.body?.error || '')}"` }), response: `POST m1/login_verify HTTP ${v.status} ${v.body?.code || ''}` };
    });
  }
  const ids = ['VRFY_ABHA_402', 'VRFY_ABHA_404'].filter(want);
  if (ids.length) {
    let a = S.aadhaar && (await ask('  Use the Aadhaar number from creation for VRFY_ABHA_402/404? y/n')).toLowerCase().startsWith('y') ? S.aadhaar : null;
    if (!a) a = await need(ids[0], 'the 12-digit Aadhaar number of a person WITH an ABHA (input hidden)', { secret: true, check: isAadhaar });
    let o = null;
    if (a) o = await call(hmis, 'm1/login_otp', { method: 'aadhaar:aadhaar', loginId: a.replace(/\s/g, '') });
    if (want('VRFY_ABHA_402')) {
      await runCase(run, 'VRFY_ABHA_402', async () => {
        if (!a) return { outcome: 'needs-human', remarks: humanMissing('an Aadhaar with an ABHA (aadhaar-holder)') };
        if (!ok(o)) return { outcome: 'failed', remarks: `OTP request HTTP ${o.status} "${redact(o.body?.error || '')}"` };
        const wrong = await need('VRFY_ABHA_402', 'a WRONG 6-digit OTP (input hidden)', { secret: true, check: isOtp });
        if (!wrong) return { outcome: 'needs-human', remarks: humanMissing('a wrong OTP') };
        const w = await call(hmis, 'm1/login_verify', { method: 'aadhaar:aadhaar', txnId: o.body.txnId, otp: wrong });
        const c = await screen('VRFY_ABHA_402', 'the screen shows the error and goes no further');
        return { ...judge(w.status >= 400 && w.status < 500, [c], { sandboxRemarks: `wrong OTP: HTTP ${w.status} "${redact(w.body?.error || '')}" (expected 400 invalid OTP)` }), response: `POST m1/login_verify (wrong) HTTP ${w.status}` };
      });
    }
    if (want('VRFY_ABHA_404')) {
      await runCase(run, 'VRFY_ABHA_404', async () => {
        if (!a) return { outcome: 'needs-human', remarks: humanMissing('an Aadhaar with an ABHA (aadhaar-holder)') };
        if (!ok(o)) return { outcome: 'failed', remarks: `OTP request HTTP ${o.status} "${redact(o.body?.error || '')}"` };
        const otp = await otpFor('VRFY_ABHA_404', mobileHint(o.body.message) || 'the Aadhaar-linked mobile');
        if (!otp) return { outcome: 'needs-human', remarks: humanMissing('the Aadhaar OTP') };
        const v = await call(hmis, 'm1/login_verify', { method: 'aadhaar:aadhaar', txnId: o.body.txnId, otp });
        let token = v.body?.token;
        const accts = v.body?.accounts || [];
        if (accts.length > 1 && token) {
          accts.forEach((x, i) => console.log(`   [${i + 1}] ${x.name} ${maskAbha(x.ABHANumber)} ${x.preferredAbhaAddress || ''}`));
          const i = Number(await ask(`  VRFY_ABHA_404: this Aadhaar holds ${accts.length} ABHAs; pick one [1-${accts.length}]:`)) || 1;
          const s = await call(hmis, 'm1/login_select', { txnId: v.body.txnId, abhaNumber: accts[i - 1].ABHANumber, tToken: token });
          token = s.body?.token;
        }
        const l = token ? await lookup(hmis, token, 'abha') : null;
        const card = token ? await call(hmis, 'm1/card', { token, kind: 'abha' }, { binary: true }) : null;
        if (token) { S.token = token; S.kind = 'abha'; }
        const c = await screen('VRFY_ABHA_404', 'the profile is shown, the ABHA card can be downloaded (KYC-verified ABHA), and Register / Link to the patient id is offered if not yet linked', { alongside: true });
        return { ...judge(ok(v) && v.body.authResult === 'success' && accts[0]?.ABHANumber && l && ok(l) && l.body.number, [c],
          { sandboxRemarks: `verify HTTP ${v.status} authResult ${v.body?.authResult}, ${accts.length} account(s); profile ${maskAbha(l?.body?.number)}; card HTTP ${card?.status} ${card?.contentType || ''}` }),
          response: `POST m1/login_verify HTTP ${v.status}; abha_lookup ${l?.status}; m1/card ${card?.status}` };
      });
    }
  }
  if (want('VRFY_ABHA_405')) {
    await runCase(run, 'VRFY_ABHA_405', async () => {
      const c = await screen('VRFY_ABHA_405', 'Resend OTP on the Aadhaar login screen: enabled after 60 s, two resends, counter visible, a third shows "Maximum OTP resend attempts reached. Please try again after 30 mins."');
      return { ...judge(true, [c], { screenCase: true }), evidence: 'screen (attested)' };
    });
  }
}

// ---------------------------------------------------------------- 6. One ABHA per patient id
async function groupTagging(run, hmis, sel) {
  if (!sel.has('TAGGING_UNIQUEPATIENTID_UNIQUEABHANUMBER')) return;
  await runCase(run, 'TAGGING_UNIQUEPATIENTID_UNIQUEABHANUMBER', async () => {
    if (!S.token) return { outcome: 'needs-human', remarks: 'Needs tester: a verified ABHA in this run (patient-registered), none available' };
    const l1 = await lookup(hmis, S.token, S.kind || 'abha');
    let pid = l1.body?.hms_patient_id;
    if (!pid) {
      const reg = await call(hmis, 'abha_register', { token: S.token, kind: S.kind || 'abha', form: {} });
      pid = reg.body?.patientId || reg.body?.match?.id;
      if (reg.body?.match) await call(hmis, `save_abha/${reg.body.match.id}`, { token: S.token, kind: S.kind || 'abha' });
    }
    // Verify the same ABHA again: no second patient id (read-only for every other patient).
    const again = await call(hmis, 'abha_register', { token: S.token, kind: S.kind || 'abha', form: {} });
    const l2 = await lookup(hmis, S.token, S.kind || 'abha');
    const one = ok(again) && again.body.existing === true && Number(again.body.patientId) === Number(pid) && Number(l2.body?.hms_patient_id) === Number(pid);
    const c = await screen('TAGGING_UNIQUEPATIENTID_UNIQUEABHANUMBER', `verifying the same ABHA again says it is already linked to patient #${pid}; attaching it to another patient is refused ("already linked to HMIS Beneficiary ID #${pid}")`);
    return { ...judge(!!(pid && one), [c], { screenCase: true, sandboxRemarks: `first: patient #${pid}; again: existing ${again.body?.existing} #${again.body?.patientId}; lookup #${l2.body?.hms_patient_id}` }),
      response: `POST abha_register x2 -> one patient #${pid}`, evidence: 'screen (attested) + one patient id' };
  });
}

// ---------------------------------------------------------------- 7. Scan and share
const SHARE_IN = /^IN \/api\/v3\/hip\/patient\/share/;
const ON_SHARE = /^OUT POST .*on-share/;
async function waitShare(mark) {
  const rows = await auditSince(mark, { waitMs: 90_000, until: (r) => r.some((x) => SHARE_IN.test(x.action)) && r.some((x) => ON_SHARE.test(x.action)) });
  const inn = rows.filter((x) => SHARE_IN.test(x.action)).pop();
  const out = rows.filter((x) => ON_SHARE.test(x.action)).pop();
  return { rows, inn, out };
}
async function newestToken(hmis, since) {
  for (let i = 0; i < 10; i++) {
    const q = await call(hmis, 'share_queue');
    const row = (q.body || []).find((r) => new Date(r.created_at) >= since);
    if (row) return row;
    await sleep(3000);
  }
  return null;
}
async function groupShare(run, hmis, sel) {
  const want = (id) => sel.has(id);
  let first = null;
  if (want('SHARE_PATIENT_PROFILE_701')) {
    await runCase(run, 'SHARE_PATIENT_PROFILE_701', async ({ mark }) => {
      const since = new Date(Date.now() - 5000);
      const done = await waitFor('SHARE_PATIENT_PROFILE_701', 'with a person NOT registered here: in the ABHA app scan the counter QR, tap Get Token, read the profile and consent, tap I Agree, and note the token shown');
      if (!done) return { outcome: 'needs-human', remarks: humanMissing('a scan with the ABHA app (abha-app, counter-qr)') };
      const { inn, out } = await waitShare(mark);
      const tok = await newestToken(hmis, since);
      first = tok;
      const shown = await ask(`  SHARE_PATIENT_PROFILE_701: which token number does the ABHA app show?`);
      const checks = [await screen('SHARE_PATIENT_PROFILE_701', `the patient was registered from the shared details with nothing typed (patient #${tok?.hms_patient_id}: ABHA number, address, name, gender, DOB, mobile, address)`)];
      const passed = inn && out && String(out.status) === '202' && tok && tok.hms_patient_id && String(tok.token_number) === String(shown).trim();
      return { ...judge(!!passed, checks, { sandboxRemarks: `inbound share ${inn ? `[${inn.request_id}] ${inn.status ?? ''}` : 'not seen'}; on-share ${out ? `HTTP ${out.status}` : 'not seen'}; queue token ${tok?.token_number} (app shows ${shown}); registered ${tok?.registered} #${tok?.hms_patient_id}` }),
        response: `IN patient/share; OUT on-share ${out?.status}; token ${tok?.token_number}`, steps: [{ outcome: inn ? 'passed' : 'failed', note: 'share received' }, { outcome: out && String(out.status) === '202' ? 'passed' : 'failed', note: 'on-share 202' }, { outcome: String(tok?.token_number) === String(shown).trim() ? 'passed' : 'failed', note: 'token shown in app' }] };
    });
  }
  if (want('SHARE_PATIENT_PROFILE_702')) {
    await runCase(run, 'SHARE_PATIENT_PROFILE_702', async () => {
      const steps = [];
      const scan = async (n, instruction, check) => {
        const mark = await auditMark();
        const since = new Date(Date.now() - 5000);
        const done = await waitFor('SHARE_PATIENT_PROFILE_702', `step ${n}: ${instruction}`);
        if (!done) { steps.push({ outcome: 'needs-human', note: instruction }); return null; }
        const { inn, out } = await waitShare(mark);
        const tok = await newestToken(hmis, since);
        const okCall = inn && out && String(out.status) === '202' && tok;
        const v = okCall ? check(tok) : false;
        const att = await attest('SHARE_PATIENT_PROFILE_702', `step ${n} screen: ${v === false ? '(the runner saw a problem) ' : ''}confirm in Smart Hospital: ${instruction.split(':')[0]}`);
        steps.push({ outcome: !okCall ? 'failed' : v && att === true ? 'passed' : att === null ? 'needs-human' : 'failed', note: `token ${tok?.token_number} counter ${tok?.context} patient #${tok?.hms_patient_id} ${tok?.abha_address || ''}` });
        return tok;
      };
      const base = first || (await call(hmis, 'share_queue')).body?.[0];
      const t1 = await scan(1, 'revisit: the same person scans the SAME QR with the SAME ABHA address (close their queue entry first if your desk does that)', (t) => Number(t.hms_patient_id) === Number(base?.hms_patient_id));
      const t2 = await scan(2, 'another ABHA address: the person scans with their SECOND ABHA address (same ABHA number)', (t) => Number(t.hms_patient_id) === Number(base?.hms_patient_id) && t.abha_address !== base?.abha_address);
      const t3 = await scan(3, 'repeat: straight after, scan the SAME QR with the same ABHA address as step 2', (t) => t2 && t.token_number === t2.token_number && t.context === t2.context);
      await scan(4, 'a different counter: scan the SECOND counter QR', (t) => t3 && t.context !== t3.context && Number(t.hms_patient_id) === Number(base?.hms_patient_id));
      const outcome = steps.some((s) => s.outcome === 'failed') ? 'failed' : steps.some((s) => s.outcome === 'needs-human') ? 'needs-human' : 'passed';
      return { outcome, steps, remarks: outcome === 'passed' ? 'one beneficiary id across all four scans' : steps.map((s, i) => `${i + 1}: ${s.outcome} (${s.note})`).join('; '), response: steps.map((s) => s.note).join(' | ') };
    });
  }
}

// ---------------------------------------------------------------- main
function usage() {
  console.log(`ABDM M1 functional-test runner (entity type private)

  FT_HMS_URL=<Smart Hospital URL> node abdm-ft/m1.mjs [--cases ID,ID] [--resume abdm-ft/m1-<stamp>.md]

Environment
  FT_HMS_URL      Smart Hospital, e.g. https://hrms.duobytes.in (required). You sign in as staff at the start.
  FT_BRIDGE_URL   ABDM bridge (default: ABDM_BRIDGE_URL from Smart Hospital's .env)
  FT_API_KEY      bridge API key (default: ABDM_API_KEY from .env), used only to read REQUEST-IDs from the audit log

The tester types every Aadhaar number, mobile and OTP (hidden; never written anywhere) and attests screen checks.

Cases (kind; needs)
${CASES.map((c) => `  ${c.id.padEnd(42)} ${c.kind.padEnd(22)} ${c.needs.join(', ') || '-'}${c.optional ? '  [optional]' : ''}`).join('\n')}

Writes
  abdm-ft/m1-<stamp>.md        run log (resume with --resume)
  abdm-ft/m1-ft-results.json   results, then: python3 -I abdm-ft/abdm_workbook.py write <workbook> --run ft --results abdm-ft/m1-ft-results.json --executed-by "<tester>" --application "Smart Hospital"
  abdm-ft/m1-<stamp>.json      manifest, then: python3 -I abdm-ft/abdm_report.py build <workbook> --run ft --meta abdm-ft/meta.json --manifest abdm-ft/m1-<stamp>.json --out abdm-ft/draft-report-m1.pdf --draft`);
}

async function main() {
  const a = args();
  if (a.help) { usage(); close(); return; }
  const selected = a.cases ? CASES.filter((c) => a.cases.includes(c.id)) : CASES;
  const unknown = (a.cases || []).filter((id) => !CASES.some((c) => c.id === id));
  if (unknown.length) { console.error(`Unknown case id(s): ${unknown.join(', ')}. Run --help for the list.`); close(); process.exitCode = 2; return; }
  const run = new Run({ module: 'm1', skill: SKILL, set: SET, cases: CASES, resume: a.resume });
  run.optIn = new Set();
  const todo = run.todo(selected.map((c) => c.id));
  console.log(`M1 functional test · ${todo.length} case(s) to run · run log ${run.logPath}`);

  console.log('\nReadiness. A fresh Aadhaar (no ABHA) gives the full creation journey; an existing ABHA can be deleted on https://abhasbx.abdm.gov.in/abha/v3.');
  const ready = await readinessCheck(todo.filter((c) => !c.optional).map((c) => c.needs));
  for (const c of todo.filter((x) => x.optional)) {
    if ((await ask(`  readiness: claim optional ${c.id} (${c.title})? y/n`)).toLowerCase().startsWith('y')) run.optIn.add(c.id);
  }
  const hmis = new Hmis(process.env.FT_HMS_URL);
  const signedIn = await hmis.login('readiness');
  ready.rows.push(`Smart Hospital: ${hmis.base} ${signedIn ? 'signed in, ABDM page token read' : 'NOT signed in'}`);
  run.setReadiness(ready.rows);
  ready.rows.forEach((r) => console.log(`  ${r}`));
  if (!ready.ok || !signedIn) {
    console.log('\nNot ready: fix the rows above and run again (the run log keeps this readiness check).');
    close(); return;
  }

  const sel = new Set(todo.map((c) => c.id));
  wipe();
  await groupCreate(run, hmis, sel);
  await groupAbhaNumber(run, hmis, sel);
  await groupAbhaAddress(run, hmis, sel);
  await groupFind(run, hmis, sel);
  await groupAadhaar(run, hmis, sel);
  await groupTagging(run, hmis, sel);
  await groupShare(run, hmis, sel);
  wipe();
  run.finish();
  close();
}

main().catch((e) => { console.error(e); close(); process.exitCode = 1; });
