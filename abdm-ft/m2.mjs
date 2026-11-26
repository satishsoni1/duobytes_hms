#!/usr/bin/env node
// ABDM M2 functional test runner (this hospital as HIP): NHA's 13 cases, by case id, in the set's order.
// Follows .claude/skills/abdm-m2/references/test.md: interactive, the tester present for every PHR-app step,
// nothing a person types is written anywhere, the run log updated after every case, then the results file
// (for abdm_workbook.py) and the manifest (case id -> REQUEST-IDs, for abdm_report.py).
//
//   FT_HMS_URL=https://hrms.duobytes.in node abdm-ft/m2.mjs [--cases ID,ID] [--resume abdm-ft/m2-<stamp>.md]
//
// Smart Hospital is driven as staff drive it (admin/abdm/* through a staff login); the bridge's HMS API
// (ABDM_BRIDGE_URL / ABDM_API_KEY from Smart Hospital's .env) is read for state and REQUEST-IDs.
import { Run, args, readinessCheck, auditMark, auditSince, requestIds, describeAudit, need, attest, waitFor, ask,
  masked, redact, close, sleep } from './lib/ft.mjs';
import { Hmis } from './lib/hmis.mjs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

export const CASES = [
  { id: 'Health_RECORD_CREATION_101', title: 'Digital health records are created (FHIR, SNOMED CT / LOINC)', kind: 'screen', needs: ['a patient with an ABHA and records in Smart Hospital'] },
  { id: 'HIP_INTI_LINK_504', title: 'Link token generated, or a valid one reused', kind: 'sandbox', needs: ['a patient with an ABHA and records in Smart Hospital'] },
  { id: 'HIP_INTI_LINK_505', title: 'Care context linked automatically; update notified', kind: 'sandbox', needs: ['a patient with an ABHA and records in Smart Hospital'] },
  { id: 'HIP_INTI_LINK_506', title: 'Linked records can be pulled in the PHR app', kind: 'human', needs: ['abha-app'] },
  { id: 'USER_INIT_LINK_601', title: 'The person logs in to the PHR app', kind: 'human', needs: ['abha-app'] },
  { id: 'USER_INIT_LINK_602', title: 'Your facility can be found in the PHR app', kind: 'human', needs: ['abha-app'] },
  { id: 'USER_INIT_LINK_603', title: 'The profile reaches your HIP (discover)', kind: 'sandbox', needs: ['a patient without ABHA whose mobile, name, gender, year of birth match the tester\'s ABHA'] },
  { id: 'USER_INIT_LINK_606', title: 'Your HIP finds the patient by demographic details', kind: 'sandbox', needs: [] },
  { id: 'USER_INIT_LINK_604', title: 'The records found are returned', kind: 'sandbox', needs: ['abha-app'] },
  { id: 'USER_INIT_LINK_605', title: 'The person links with an OTP your HIP sends', kind: 'sandbox', needs: ['abha-app', 'the patient\'s mobile, for the link OTP'] },
  { id: 'USER_INIT_LINK_607', title: 'The linked records can be pulled', kind: 'human', needs: ['abha-app'] },
  { id: 'HIP_INIT_NOTIFY_HIECM', title: 'A new record triggers the SMS with a deep link', kind: 'sandbox', needs: ['a patient with a mobile and no ABHA, and that phone'] },
  { id: 'HIP_INIT_SHARE_CARECONTEXT', title: 'Records are shared on request (consent, transfer, notify)', kind: 'sandbox', needs: ['abha-app'] },
];

const HELP = `ABDM M2 functional test runner (HIP), NHA M2 set, entity type private (all 13 cases mandatory)

  node abdm-ft/m2.mjs [--cases ID,ID,...] [--resume abdm-ft/m2-<stamp>.md] [--help]

Environment
  FT_HMS_URL     Smart Hospital's address, e.g. https://hrms.duobytes.in (required)
  FT_BRIDGE_URL  bridge URL; default ABDM_BRIDGE_URL from Smart Hospital's .env
  FT_API_KEY     bridge API key; default ABDM_API_KEY from Smart Hospital's .env

The tester must be present with the ABHA (PHR) app; every login, OTP and approval happens on their phone or at a
prompt here with input hidden. Nothing typed is written to disk.

Writes abdm-ft/m2-<stamp>.md (run log), abdm-ft/m2-<stamp>.json (manifest) and abdm-ft/m2-ft-results.json.
Afterwards:
  python3 -I abdm-ft/abdm_workbook.py write <M2 workbook> --run ft --results abdm-ft/m2-ft-results.json --executed-by "<tester>" --application "Smart Hospital"
  python3 -I abdm-ft/abdm_report.py check <M2 workbook> --run ft --entity private
  python3 -I abdm-ft/abdm_report.py build <M2 workbook> --run ft --meta abdm-ft/meta.json --manifest abdm-ft/m2-<stamp>.json --out abdm-ft/report-m2.pdf [--draft]
Cases: ${CASES.map((c) => c.id).join(', ')}`;

// ------------------------------------------------------------------ helpers
const A = {
  generate: /OUT POST .*\/token\/generate-token/,
  onGenerate: /IN \/api\/v3\/hip\/token\/on-generate-token/,
  link: /OUT POST .*\/hip\/v3\/link\/carecontext/,
  onLink: /IN \/api\/v3\/link\/on_carecontext/,
  notify: /OUT POST .*\/hip\/v3\/link\/context\/notify/,
  onNotify: /IN \/api\/v3\/links\/context\/on-notify/,
  discover: /IN \/api\/v3\/hip\/patient\/care-context\/discover/,
  onDiscover: /OUT POST .*\/care-context\/on-discover/,
  linkInit: /IN \/api\/v3\/hip\/link\/care-context\/init/,
  onLinkInit: /OUT POST .*\/link\/care-context\/on-init/,
  linkConfirm: /IN \/api\/v3\/hip\/link\/care-context\/confirm/,
  onLinkConfirm: /OUT POST .*\/link\/care-context\/on-confirm/,
  sms: /OUT POST .*\/link\/patient\/links\/sms\/notify2/,
  onSms: /IN \/api\/v3\/patients\/sms\/on-notify/,
  consentNotify: /IN \/api\/v3\/consent\/request\/hip\/notify/,
  consentOnNotify: /OUT POST .*\/consent\/v3\/request\/hip\/on-notify/,
  hiRequest: /IN \/api\/v3\/hip\/health-information\/request/,
  hiOnRequest: /OUT POST .*\/health-information\/hip\/on-request/,
  hiNotify: /OUT POST .*\/data-flow\/v3\/health-information\/notify/,
};
const only = (rows, re) => rows.filter((r) => re.test(r.action));
const last = (rows, re) => only(rows, re).at(-1);
const det = (row) => (row && typeof row.details === 'object' && row.details) || {};
const ok202 = (row) => row && (Number(row.status) === 202 || Number(row.status) === 200);
/** Wait (polling the audit log) until every pattern has appeared after mark, or the time runs out. */
async function observe(mark, patterns, waitMs = 90_000) {
  return auditSince(mark, { waitMs, until: (rows) => patterns.every((re) => only(rows, re).length) });
}
// A step's outcome is one the workbook writer knows (passed, failed, needs-human, not-run). A conditional pass is
// a passed step with its condition noted; the case then becomes conditional-pass.
const step = (outcome, note) => (outcome === 'conditional-pass'
  ? { outcome: 'passed', cond: true, note: `Condition: ${note}` }
  : { outcome, ...(note ? { note } : {}) });
/** Case outcome from its steps (run.md: failed if any step failed; needs-human if a step could not be run). */
function fromSteps(steps) {
  if (steps.some((s) => s.outcome === 'failed')) return 'failed';
  if (steps.some((s) => s.outcome === 'needs-human')) return 'needs-human';
  if (steps.some((s) => s.cond)) return 'conditional-pass';
  return 'passed';
}
const remarksOf = (steps, expected) => {
  const parts = steps.map((s, i) => (s.note ? `step ${i + 1}: ${s.note}` : null)).filter(Boolean);
  return [`Expected: ${expected}`, ...parts].join('; ');
};

// ------------------------------------------------------------------ the run
async function main() {
  const a = args();
  if (a.help) { console.log(HELP); close(); return; }
  const cases = CASES.filter((c) => !a.cases || a.cases.includes(c.id));
  const run = new Run({ module: 'm2', skill: 'abdm-m2', set: 'NHA M2 Building HIP, revised 21 Oct 2026', cases: CASES, resume: a.resume });
  const todo = run.todo(cases.map((c) => c.id));
  console.log(`ABDM M2 functional test: ${todo.length} case(s) to run. Run log: ${run.logPath}`);

  // Readiness: bridge, evidence, staff login, and what the tester has.
  const hmis = new Hmis();
  const ready = await readinessCheck(todo.map((c) => c.needs));
  const loggedIn = await hmis.login('readiness');
  ready.rows.push(loggedIn ? `Smart Hospital: signed in at ${hmis.base}` : 'Smart Hospital: NOT signed in');
  run.setReadiness(ready.rows);
  if (!ready.ok || !loggedIn) {
    console.log('Readiness failed: the bridge or Smart Hospital is not reachable. Fix it and run again (--resume to keep this log).');
    run.finish(); close(); return;
  }

  const ctx = { hmis, run, facility: ready.facility };
  for (const c of todo) {
    run.running(c.id);
    try {
      await (HANDLERS[c.id])(ctx, c);
    } catch (e) {
      run.record(c.id, { outcome: 'failed', remarks: `runner error: ${e.message}`, trace: [e.stack?.split('\n')[0]] });
    }
  }
  run.finish();
  close();
}

// ------------------------------------------------------------------ patients
/** Ask for a Smart Hospital patient id and check it against what the case needs. Returns the bridge's view. */
async function choosePatient(ctx, caseId, { purpose, abha }) {
  if (ctx.patients?.[purpose]) return ctx.patients[purpose];
  for (let i = 0; i < 2; i++) {
    const id = await need(caseId, `the Smart Hospital patient id of ${purpose}`, { check: (v) => (/^\d+$/.test(v) ? null : 'a patient id is a number, e.g. 1234') });
    if (!id) return null;
    await ctx.hmis.call(`sync_patient/${id}`, {}); // the bridge has the patient and its records
    const b = await ctx.hmis.call(`bridge_status/${id}`);
    const rows = await ctx.hmis.call(`abha_patients?q=${id}`);
    const p = (Array.isArray(rows.body) ? rows.body : []).find((r) => String(r.id) === id) || {};
    const hasAbha = !!(p.abha_number || p.abha_address || b.body?.abha_address);
    if (abha !== hasAbha) { console.log(`  patient ${id} ${hasAbha ? 'has' : 'has no'} ABHA; this case needs one that ${abha ? 'has' : 'has none'}.`); continue; }
    const out = { id, mrn: b.body?.mrn, bridge: b.body || {}, careContexts: b.body?.careContexts || [] };
    (ctx.patients ||= {})[purpose] = out;
    return out;
  }
  return null;
}
const refreshPatient = async (ctx, p) => { const b = await ctx.hmis.call(`bridge_status/${p.id}`); p.bridge = b.body || {}; p.careContexts = b.body?.careContexts || []; return p; };
const WITH_ABHA = { purpose: 'a patient with a verified ABHA and records', abha: true };
const NO_ABHA = { purpose: 'a patient registered without ABHA whose mobile, name, gender and year of birth match the tester\'s ABHA, with an unlinked record', abha: false };
const SMS_PATIENT = { purpose: 'a patient with a mobile and no ABHA (for the SMS)', abha: false };

/**
 * The tester creates a record in Smart Hospital; the runner syncs it. Returns 'ok' when a new care context appeared,
 * 'no-answer' when the tester could not (needs-human), 'not-synced' when they did but no new care context reached
 * the bridge (a finding: the record type is not shared, or not yet approved).
 */
async function newRecord(ctx, caseId, p, what = 'a new record (an OPD visit, a prescription or an approved lab report)') {
  const before = new Set(p.careContexts.map((c) => c.reference));
  const done = await waitFor(caseId, `in Smart Hospital, create ${what} for patient #${p.id}`);
  if (!done) return 'no-answer';
  for (let i = 0; i < 3; i++) {
    await ctx.hmis.call(`sync_patient/${p.id}`, {});
    await refreshPatient(ctx, p);
    if (p.careContexts.some((c) => !before.has(c.reference))) return 'ok';
    await sleep(3000);
  }
  return 'not-synced';
}
const NEW_RECORD_NOTE = { 'no-answer': ['needs-human', 'a new record for the patient; asked twice'],
  'not-synced': ['failed', 'the tester created a record but no new care context reached the bridge after 3 syncs (record type not shared, or report not approved)'] };

// ------------------------------------------------------------------ cases
const HANDLERS = {
  // Group 1 ---------------------------------------------------------------------------------------
  async Health_RECORD_CREATION_101(ctx, c) {
    const p = await choosePatient(ctx, c.id, WITH_ABHA);
    if (!p) return ctx.run.record(c.id, { outcome: 'needs-human', remarks: `needs ${WITH_ABHA.purpose}; asked twice` });
    const types = [...new Set(p.careContexts.map((x) => x.hi_type))];
    const steps = [];
    steps.push(types.length ? step('passed', `records shared as FHIR care contexts of HI types ${types.join(', ')}`) : step('failed', 'no care context for this patient in the bridge'));
    const page = await ctx.hmis.page('/admin/abdm/loinc');
    const coded = (page.html.match(/badge bg-success">(\d+) coded/) || [])[1];
    const coding = await attest(c.id, `open one record of each HI type above (bridge console, FHIR view) and check: coded elements carry SNOMED CT or LOINC codes (Lab codes page: ${coded ?? '?'} lab parameters coded)`);
    steps.push(coding === true ? step('passed', `SNOMED CT / LOINC coding seen by the tester; ${coded ?? '?'} lab parameters LOINC-coded`) : coding === false ? step('failed', 'tester saw uncoded clinical elements') : step('needs-human', 'coding check asked twice, not answered'));
    steps.push(p.careContexts.every((x) => x.reference) ? step('passed', 'every bundle is stored against a visit (care context reference)') : step('failed', 'a record without a care context reference'));
    ctx.run.record(c.id, { outcome: fromSteps(steps), steps, remarks: remarksOf(steps, 'FHIR records with SNOMED CT / LOINC codes, each stored against a care context'),
      evidence: `screen: HI types ${types.join(', ') || 'none'}`, response: `bridge_status care contexts: ${p.careContexts.length}` });
  },

  // Group 2 ---------------------------------------------------------------------------------------
  async HIP_INTI_LINK_504(ctx, c) {
    const p = await choosePatient(ctx, c.id, WITH_ABHA);
    if (!p) return ctx.run.record(c.id, { outcome: 'needs-human', remarks: `needs ${WITH_ABHA.purpose}; asked twice` });
    const steps = [], rids = [], trace = [];
    // 1. First link: a token is generated (or a valid one is already held and reused).
    if (!p.careContexts.some((x) => !x.linked_at)) {
      const nr = await newRecord(ctx, c.id, p);
      if (nr !== 'ok') return ctx.run.record(c.id, { outcome: NEW_RECORD_NOTE[nr][0], remarks: `needs an unlinked record: ${NEW_RECORD_NOTE[nr][1]}` });
    }
    let mark = await auditMark();
    const l1 = await ctx.hmis.call(`link/${p.id}`, {});
    trace.push(`POST link/${p.id} HTTP ${l1.status}`);
    let rows = await observe(mark, [A.link], 120_000);
    trace.push(...describeAudit(rows));
    const gen = last(rows, A.generate), onGen = last(rows, A.onGenerate), lk = last(rows, A.link);
    if (gen && onGen) {
      const d = det(onGen);
      const fine = ok202(gen) && !d.error && (d.linkToken || d.linkToken === '***') && (!d.response?.requestId || d.response.requestId === gen.request_id);
      steps.push(fine ? step('passed', `generate-token ${gen.status}; on-generate-token with linkToken [masked], response.requestId matches`) : step('failed', `generate-token ${gen.status}; on-generate-token ${d.error ? `error ${JSON.stringify(redact(d.error))}` : 'without linkToken'}`));
      rids.push(...requestIds([gen, onGen]));
    } else if (lk && !gen) {
      steps.push(step('conditional-pass', 'no generate-token: a valid link token was already held and reused (precondition was "no token stored yet")'));
    } else {
      steps.push(step('failed', `no generate-token or link call observed within 120 s (link HTTP ${l1.status} ${redact(l1.body?.error || '')})`));
    }
    if (lk) rids.push(lk.request_id);
    await waitLinked(ctx, p, l1.body?.linkJobId);
    // 2. Stored against the patient: shown by the reuse in step 3.
    // 3. Reuse: a second record links with the stored token, no new generate-token.
    const second = await newRecord(ctx, c.id, p, 'a second record (another visit or prescription)');
    if (second !== 'ok') {
      steps.push(step(NEW_RECORD_NOTE[second][0], `second record: ${NEW_RECORD_NOTE[second][1]}`), step(NEW_RECORD_NOTE[second][0], 'reuse needs the second record'));
    } else {
      mark = await auditMark();
      const l2 = await ctx.hmis.call(`link/${p.id}`, {});
      rows = await observe(mark, [A.link], 120_000);
      trace.push(`POST link/${p.id} (second) HTTP ${l2.status}`, ...describeAudit(rows));
      const again = only(rows, A.generate).length, lk2 = last(rows, A.link);
      const reused = !again && lk2;
      steps.push(reused ? step('passed', 'token stored against the patient (reused on the next link)') : step('failed', 'token not kept'));
      steps.push(reused ? step('passed', `second link sent with the stored X-LINK-TOKEN, no new generate-token [${lk2.request_id}]`) : step('failed', `${again} new generate-token call(s) on the second link`));
      if (lk2) rids.push(lk2.request_id);
      await waitLinked(ctx, p, l2.body?.linkJobId);
    }
    ctx.run.record(c.id, { outcome: fromSteps(steps), steps, requestIds: rids.filter(Boolean), trace,
      remarks: remarksOf(steps, 'a link token is created, or a valid one (6 months) reused for the same patient'), response: trace.find((t) => t.startsWith('OUT')) });
  },

  async HIP_INTI_LINK_505(ctx, c) {
    const p = await choosePatient(ctx, c.id, WITH_ABHA);
    if (!p) return ctx.run.record(c.id, { outcome: 'needs-human', remarks: `needs ${WITH_ABHA.purpose}; asked twice` });
    const steps = [], rids = [], trace = [];
    // 1. A new record is linked with no click: the sync run (cron / "Sync now") links it.
    let mark = await auditMark();
    const nr = await newRecord(ctx, c.id, p, 'a new record to be linked automatically');
    if (nr !== 'ok') return ctx.run.record(c.id, { outcome: NEW_RECORD_NOTE[nr][0], remarks: NEW_RECORD_NOTE[nr][1] });
    const s = await ctx.hmis.call('sync_all', {});
    trace.push(`POST sync_all HTTP ${s.status} linking ${JSON.stringify(redact(s.body?.linking || {}))}`);
    let rows = await observe(mark, [A.link, A.onLink], 180_000);
    trace.push(...describeAudit(only(rows, /token|carecontext|on_carecontext/)));
    const lk = last(rows, A.link), on = last(rows, A.onLink);
    const d = det(on);
    const linked = lk && on && !d.error && /Successfully Linked/i.test(d.status || '') && (!d.response?.requestId || d.response.requestId === lk.request_id);
    steps.push(linked ? step('passed', `linked with no click: link/carecontext ${lk.status}, on_carecontext "${d.status}"`) : step('failed', lk ? `on_carecontext ${on ? JSON.stringify(redact(d.error || d.status)) : 'not received in 180 s'}` : 'no automatic link call after the sync'));
    rids.push(...requestIds([lk, on].filter(Boolean)));
    // 2. An update to the linked record is notified.
    mark = await auditMark();
    const edited = await waitFor(c.id, `in Smart Hospital, edit the record just linked for patient #${p.id} (e.g. change a lab value or the prescription)`);
    if (!edited) steps.push(step('needs-human', 'an edit to the linked record; asked twice'));
    else {
      await ctx.hmis.call(`sync_patient/${p.id}`, {});
      rows = await observe(mark, [A.notify, A.onNotify], 120_000);
      trace.push(...describeAudit(only(rows, /context\/notify|on-notify/)));
      const n = last(rows, A.notify), on2 = last(rows, A.onNotify);
      const ack = det(on2).acknowledgement?.status;
      steps.push(n && on2 && /SUCCESS/i.test(ack || '') ? step('passed', `context/notify ${n.status}, on-notify acknowledgement ${ack}`)
        : step('failed', n ? `on-notify ${on2 ? `acknowledgement ${ack}` : 'not received'}` : 'no context/notify: an edit made the same day is re-sent by the hourly re-check; run --resume with this case after an hour if so'));
      rids.push(...requestIds([n, on2].filter(Boolean)));
    }
    ctx.run.record(c.id, { outcome: fromSteps(steps), steps, requestIds: rids, trace,
      remarks: remarksOf(steps, 'care context linked using the token, automatically once the record is saved; update notified'), response: trace.find((t) => t.startsWith('OUT')) });
  },

  async HIP_INTI_LINK_506(ctx, c) {
    const done = await waitFor(c.id, 'on the phone, open the ABHA app, go to your facility and tap "Pull Records"');
    if (!done) return ctx.run.record(c.id, { outcome: 'needs-human', remarks: 'the pull in the ABHA app; asked twice' });
    const seen = await attest(c.id, 'the record linked in HIP_INTI_LINK_505 is shown in the ABHA app (take a screenshot for the report)');
    ctx.run.record(c.id, { outcome: seen === true ? 'passed' : seen === false ? 'failed' : 'needs-human', evidence: 'screen: PHR app screenshot',
      remarks: seen === null ? 'the PHR app screen; asked twice' : `Expected: linked records can be pulled with "Pull Records". Observed: ${seen ? 'shown' : 'not shown'} in the app` });
  },

  // Group 3 ---------------------------------------------------------------------------------------
  async USER_INIT_LINK_601(ctx, c) {
    ctx.g3mark = await auditMark();
    const done = await waitFor(c.id, 'log in to the ABDM sandbox ABHA (PHR) app on the phone');
    ctx.run.record(c.id, done ? { outcome: 'passed', remarks: 'Expected: user logs in to the PHR app. Observed: tester confirmed the login' }
      : { outcome: 'needs-human', remarks: 'the PHR app login; asked twice' });
  },

  async USER_INIT_LINK_602(ctx, c) {
    const p = await choosePatient(ctx, c.id, NO_ABHA);
    if (!p) return ctx.run.record(c.id, { outcome: 'needs-human', remarks: `needs ${NO_ABHA.purpose}; asked twice` });
    if (!p.careContexts.some((x) => !x.linked_at)) {
      const nr = await newRecord(ctx, c.id, p);
      if (nr !== 'ok') return ctx.run.record(c.id, { outcome: NEW_RECORD_NOTE[nr][0], remarks: `needs an unlinked record for user-initiated linking: ${NEW_RECORD_NOTE[nr][1]}` });
    }
    ctx.g3mark = await auditMark();
    const name = ctx.facility?.name || 'your facility';
    const done = await waitFor(c.id, `in the ABHA app: Linked facilities -> "Link New facility" -> search "${name}" and select it (do not continue yet)`);
    if (!done) return ctx.run.record(c.id, { outcome: 'needs-human', remarks: 'the facility search in the PHR app; asked twice' });
    const seen = await attest(c.id, `"${name}" is listed in the search and can be selected (take a screenshot)`);
    ctx.run.record(c.id, { outcome: seen === true ? 'passed' : seen === false ? 'failed' : 'needs-human', evidence: 'screen: PHR app screenshot',
      remarks: seen === null ? 'the PHR app search screen; asked twice' : `Expected: user can search and select the HIP. Observed: ${seen ? 'listed and selected' : 'not listed (check the bridge services registration)'}` });
  },

  async USER_INIT_LINK_603(ctx, c) {
    const done = await waitFor(c.id, 'in the ABHA app, continue with the selected facility so it shares the profile (it starts the search for records)');
    if (!done) return ctx.run.record(c.id, { outcome: 'needs-human', remarks: 'sharing the profile from the PHR app; asked twice' });
    const rows = await observe(ctx.g3mark ?? 0, [A.discover, A.onDiscover], 90_000);
    const dsc = last(rows, A.discover);
    ctx.discover = { rows, dsc, on: last(rows, A.onDiscover) };
    const d = det(dsc);
    const okBody = dsc && d.transactionId && d.patient?.id && Array.isArray(d.patient?.verifiedIdentifiers);
    ctx.run.record(c.id, { outcome: okBody ? 'passed' : 'failed', requestIds: requestIds([dsc].filter(Boolean)), trace: describeAudit(only(rows, /discover/)),
      response: dsc ? `IN discover transactionId ${d.transactionId ? 'present' : 'MISSING'}, patient.id ${d.patient?.id ? 'present' : 'MISSING'}, verifiedIdentifiers ${(d.patient?.verifiedIdentifiers || []).map((x) => x.type).join(',')}` : 'no discover within 90 s',
      remarks: `Expected: the gateway sends the profile to the HIP. Observed: ${dsc ? 'discover received with transactionId, patient.id and verifiedIdentifiers' : 'no discover arrived (check the callback URL / facility registration)'}` });
  },

  async USER_INIT_LINK_606(ctx, c) {
    const p = ctx.patients?.[NO_ABHA.purpose];
    const { dsc, on } = ctx.discover || {};
    if (!dsc) return ctx.run.record(c.id, { outcome: 'needs-human', remarks: 'needs the discover from USER_INIT_LINK_603' });
    const d = det(dsc), o = det(on);
    const matched = on && ok202(on) && (o.matchedBy || []).includes('MOBILE') && (o.patient || []).some((x) => (x.careContexts || []).length);
    const mob = (d.patient?.verifiedIdentifiers || []).find((x) => x.type === 'MOBILE')?.value;
    const b = p?.bridge || {};
    const demo = p && String(b.mobile || '').slice(-4) === String(mob || '').replace(/\D/g, '').slice(-4)
      && (!d.patient?.gender || b.gender === d.patient.gender) && (!d.patient?.yearOfBirth || !b.year_of_birth || Math.abs(b.year_of_birth - Number(d.patient.yearOfBirth)) <= 5);
    const steps = [matched ? step('passed', `on-discover ${on.status} matchedBy ${o.matchedBy.join(',')}, ${(o.patient || []).flatMap((x) => x.careContexts || []).length} care context(s)`) : step('failed', on ? `on-discover ${o.error ? JSON.stringify(redact(o.error)) : 'without MOBILE match or care contexts'}` : 'no on-discover sent'),
      p ? (demo ? step('passed', `matched patient #${p.id}: mobile ending ${masked(mob).slice(-4)}, gender and year of birth (within 5) agree`) : step('failed', 'matched patient\'s mobile, gender or year of birth differ from the discover')) : step('needs-human', 'the expected patient from USER_INIT_LINK_602')];
    ctx.run.record(c.id, { outcome: fromSteps(steps), steps, requestIds: requestIds([on].filter(Boolean)), remarks: remarksOf(steps, 'HIP searches on the verified mobile and fuzzy-matches name, gender, year of birth') });
  },

  async USER_INIT_LINK_604(ctx, c) {
    const { dsc, on } = ctx.discover || {};
    if (!dsc || !on) return ctx.run.record(c.id, { outcome: 'needs-human', remarks: 'needs the discover and on-discover from USER_INIT_LINK_603/606' });
    const d = det(dsc), o = det(on);
    const ccs = (o.patient || []).flatMap((x) => x.careContexts || []);
    const body = ok202(on) && o.transactionId === d.transactionId && (!o.response?.requestId || o.response.requestId === dsc.request_id) && ccs.length && (o.matchedBy || []).length;
    const steps = [body ? step('passed', `on-discover transactionId and response.requestId match the discover; ${ccs.length} care context(s); matchedBy ${o.matchedBy.join(',')}`) : step('failed', 'on-discover does not carry the discover\'s transactionId/requestId, or no care contexts')];
    ctx.g3cc = ccs.map((x) => x.referenceNumber);
    const shown = await waitFor(c.id, 'in the ABHA app, check the records listed for the facility and select the ones to link (do not confirm yet)');
    steps.push(shown ? step('passed', 'tester sees the care contexts and selects them') : step('needs-human', 'selecting records in the PHR app; asked twice'));
    ctx.run.record(c.id, { outcome: fromSteps(steps), steps, requestIds: requestIds([dsc, on]), remarks: remarksOf(steps, 'available records listed for the user to select') });
  },

  async USER_INIT_LINK_605(ctx, c) {
    const steps = [], rids = [], trace = [];
    let mark = await auditMark();
    const go = await waitFor(c.id, 'in the ABHA app, tap Link for the selected records (an OTP is sent to the patient\'s mobile by the hospital)');
    if (!go) return ctx.run.record(c.id, { outcome: 'needs-human', remarks: 'starting the link in the PHR app; asked twice' });
    let rows = await observe(mark, [A.linkInit, A.onLinkInit], 90_000);
    const init = last(rows, A.linkInit), onInit = last(rows, A.onLinkInit);
    const di = det(init), oi = det(onInit);
    const ref = oi.link?.referenceNumber;
    steps.push(init && onInit && ok202(onInit) && ref && oi.link?.authenticationType === 'DIRECT' && oi.link?.meta?.communicationMedium === 'MOBILE'
      ? step('passed', `link init received (${(di.patient || []).flatMap((x) => x.careContexts || []).length} care contexts); on-init DIRECT via MOBILE`)
      : step('failed', onInit ? `on-init ${oi.error ? JSON.stringify(redact(oi.error)) : 'without DIRECT/MOBILE or referenceNumber'}` : 'no link init/on-init within 90 s'));
    rids.push(...requestIds([init, onInit].filter(Boolean)));
    mark = await auditMark();
    const entered = await waitFor(c.id, 'enter the OTP the patient received by SMS into the ABHA app and confirm');
    if (!entered) steps.push(step('needs-human', 'the link OTP from the patient\'s phone; asked twice'), step('needs-human', 'on-confirm needs the OTP'));
    else {
      rows = await observe(mark, [A.linkConfirm, A.onLinkConfirm], 90_000);
      const conf = last(rows, A.linkConfirm), onConf = last(rows, A.onLinkConfirm);
      const dc = det(conf), oc = det(onConf);
      steps.push(conf && dc.confirmation?.linkRefNumber === ref ? step('passed', 'confirm received with the on-init referenceNumber and a token [masked]') : step('failed', conf ? 'confirm linkRefNumber does not match' : 'no confirm within 90 s'));
      const selected = new Set((di.patient || []).flatMap((x) => (x.careContexts || []).map((y) => y.referenceNumber)));
      const linked = (oc.patient || []).flatMap((x) => (x.careContexts || []).map((y) => y.referenceNumber));
      steps.push(onConf && ok202(onConf) && !oc.error && linked.length && linked.every((r) => selected.has(r))
        ? step('passed', `on-confirm ${onConf.status} with only the ${linked.length} selected care context(s)`)
        : step('failed', onConf ? `on-confirm ${oc.error ? JSON.stringify(redact(oc.error)) : 'care contexts differ from the selection'}` : 'no on-confirm'));
      rids.push(...requestIds([conf, onConf].filter(Boolean)));
      trace.push(...describeAudit(only(rows, /care-context/)));
      const lf = await attest(c.id, 'the linked records appear under "Linked Facilities" in the ABHA app');
      if (lf === false) steps.push(step('failed', 'not under Linked Facilities'));
      else if (lf === null) steps.push(step('needs-human', 'Linked Facilities screen; asked twice'));
      else steps.push(step('passed', 'shown under Linked Facilities'));
    }
    ctx.run.record(c.id, { outcome: fromSteps(steps), steps, requestIds: rids, trace, remarks: remarksOf(steps, 'valid OTP links the selected records; visible under Linked Facilities') });
  },

  async USER_INIT_LINK_607(ctx, c) {
    const done = await waitFor(c.id, 'in the ABHA app, tap "Pull Records" for the facility linked in USER_INIT_LINK_605');
    if (!done) return ctx.run.record(c.id, { outcome: 'needs-human', remarks: 'the pull in the PHR app; asked twice' });
    const seen = await attest(c.id, 'the records linked in USER_INIT_LINK_605 are shown (take a screenshot)');
    ctx.run.record(c.id, { outcome: seen === true ? 'passed' : seen === false ? 'failed' : 'needs-human', evidence: 'screen: PHR app screenshot',
      remarks: seen === null ? 'the PHR app screen; asked twice' : `Expected: linking complete, records pulled. Observed: ${seen ? 'shown' : 'not shown'}` });
  },

  // Group 4 ---------------------------------------------------------------------------------------
  async HIP_INIT_NOTIFY_HIECM(ctx, c) {
    const p = await choosePatient(ctx, c.id, SMS_PATIENT);
    if (!p) return ctx.run.record(c.id, { outcome: 'needs-human', remarks: `needs ${SMS_PATIENT.purpose}; asked twice` });
    const steps = [], rids = [];
    const nr = await newRecord(ctx, c.id, p, 'a new record (a diagnostic report or a prescription)');
    if (nr !== 'ok') steps.push(step(NEW_RECORD_NOTE[nr][0], NEW_RECORD_NOTE[nr][1]));
    const mark = await auditMark();
    // Smart Hospital sends the deep-link SMS from the patient's ABDM page ("SMS link"), not on record save.
    const r = await ctx.hmis.call(`sms_link/${p.id}`, {});
    const rows = await observe(mark, [A.sms, A.onSms], 90_000);
    const s = last(rows, A.sms), on = last(rows, A.onSms);
    const sd = det(s), od = det(on);
    const body = s && ok202(s) && sd.notification?.hip && Object.keys(sd.notification || {}).every((k) => ['phoneNo', 'hip'].includes(k));
    steps.push(body ? step('conditional-pass', `sms/notify2 ${s.status} with only phoneNo and hip; sent from the "SMS link" action, not on its own when the record was saved`) : step('failed', `sms/notify2 ${s ? s.status : 'not sent'} (sms_link HTTP ${r.status} ${redact(r.body?.error || '')})`));
    steps.push(on && /SUCCESS/i.test(od.acknowledgement?.status || '') && (!od.response?.requestId || od.response.requestId === s?.request_id) ? step('passed', 'sms on-notify acknowledgement SUCCESS, requestId matches') : step('failed', on ? `on-notify ${JSON.stringify(redact(od.acknowledgement || od.error))}` : 'no sms on-notify in 90 s'));
    rids.push(...requestIds([s, on].filter(Boolean)));
    const got = await attest(c.id, `the SMS with the deep link arrived on the patient's phone (mobile ending ${String(p.bridge.mobile || '').slice(-4) || '????'})`);
    steps.push(got === true ? step('passed', 'SMS received') : got === false ? step('failed', 'SMS not received') : step('needs-human', 'the patient\'s phone; asked twice'));
    ctx.run.record(c.id, { outcome: fromSteps(steps), steps, requestIds: rids,
      remarks: remarksOf(steps, 'SMS with deep link is sent to the patient\'s mobile'), response: s ? `POST sms/notify2 HTTP ${s.status}` : undefined });
  },

  // Group 5 ---------------------------------------------------------------------------------------
  async HIP_INIT_SHARE_CARECONTEXT(ctx, c) {
    const steps = [], rids = [], trace = [];
    const mark = await auditMark();
    const go = await waitFor(c.id, 'in the ABHA app, open the linked facility and start "Get data" for the linked records (approve the consent)');
    if (!go) return ctx.run.record(c.id, { outcome: 'needs-human', remarks: '"Get data" in the PHR app; asked twice' });
    const rows = await observe(mark, [A.consentNotify, A.hiRequest, A.hiNotify], 240_000);
    trace.push(...describeAudit(only(rows, /consent|health-information/)));
    const cn = last(rows, A.consentNotify), cno = last(rows, A.consentOnNotify), hr = last(rows, A.hiRequest), hro = last(rows, A.hiOnRequest), hn = last(rows, A.hiNotify);
    const n = det(cn).notification || {}, cd = n.consentDetail || {};
    const hipId = ctx.facility?.id;
    steps.push(cn && n.status === 'GRANTED' && cd.consentId && (!hipId || cd.hip?.id === hipId) ? step('passed', `consent notify GRANTED for this HIP (${hipId})`) : step('failed', cn ? `consent notify ${n.status} hip ${cd.hip?.id}` : 'no consent notify'));
    const ack = det(cno).acknowledgement || {};
    const consents = (await ctx.hmis.call('hip/consents')).body || [];
    const stored = Array.isArray(consents) && consents.find((x) => x.consent_id === cd.consentId);
    steps.push(cno && ok202(cno) && ack.consentId === cd.consentId && /OK/i.test(ack.status || '') && stored ? step('passed', 'on-notify OK; consent stored with its HI types, date range, purpose and expiry (Our records shared)') : step('failed', `on-notify ${cno ? `${ack.status} ${ack.consentId === cd.consentId ? '' : 'other consent'}` : 'not sent'}${stored ? '' : '; consent not stored'}`));
    const h = det(hr), hreq = h.hiRequest || {};
    steps.push(hr && h.transactionId && hreq.consent?.id && hreq.dateRange && hreq.dataPushUrl && hreq.keyMaterial ? step('passed', 'health-information request received with consent, dateRange, dataPushUrl and keyMaterial') : step('failed', hr ? 'health-information request missing fields' : 'no health-information request'));
    steps.push(hro && ok202(hro) && det(hro).hiRequest?.sessionStatus === 'ACKNOWLEDGED' ? step('passed', 'hip/on-request ACKNOWLEDGED') : step('failed', hro ? `hip/on-request ${det(hro).hiRequest?.sessionStatus}` : 'no hip/on-request'));
    const transfers = (await ctx.hmis.call('hip/transfers')).body || [];
    const tr = Array.isArray(transfers) && transfers.find((x) => x.transaction_id === h.transactionId);
    steps.push(tr && /TRANSFERRED/i.test(tr.status) && tr.entries ? step('passed', `transfer ${tr.status}, ${tr.entries} entr${tr.entries === 1 ? 'y' : 'ies'} pushed to the dataPushUrl (ECDH Curve25519, per bridge)`) : step('failed', tr ? `transfer ${tr.status} ${redact(tr.error || '')}` : 'no transfer recorded'));
    const allowed = new Set((cd.careContexts || []).map((x) => x.careContextReference));
    const sent = (det(hn).notification?.statusNotification?.statusResponses || []).map((x) => x.careContextReference);
    const range = cd.permission?.dateRange || {}, dr = hreq.dateRange || {};
    const inside = (!range.from || !dr.from || new Date(dr.from) >= new Date(range.from)) && (!range.to || !dr.to || new Date(dr.to) <= new Date(range.to));
    steps.push(sent.length && sent.every((r) => allowed.has(r)) && inside ? step('passed', `pushed care contexts ⊆ consent's (${sent.length}/${allowed.size}); request range inside consent range`) : step('failed', `${sent.filter((r) => !allowed.has(r)).length} pushed outside the consent; range inside: ${inside}`));
    const sn = det(hn).notification || {};
    steps.push(hn && ok202(hn) && sn.notifier?.type === 'HIP' && sn.statusNotification?.sessionStatus === 'TRANSFERRED' && (sn.statusNotification?.statusResponses || []).every((x) => x.hiStatus === 'DELIVERED')
      ? step('passed', 'health-information notify: HIP, TRANSFERRED, all DELIVERED') : step('failed', hn ? `notify ${sn.statusNotification?.sessionStatus} ${(sn.statusNotification?.statusResponses || []).map((x) => x.hiStatus).join(',')}` : 'no health-information notify'));
    rids.push(...requestIds([cn, cno, hr, hro, hn].filter(Boolean)));
    const seen = await attest(c.id, 'the records open in the ABHA app, readable (decrypted) and as NRCeS FHIR documents (take a screenshot)');
    steps.push(seen === true ? step('passed', 'records shown decrypted in the PHR app') : seen === false ? step('failed', 'records not readable in the PHR app') : step('needs-human', 'the PHR app screen; asked twice'));
    ctx.run.record(c.id, { outcome: fromSteps(steps), steps, requestIds: rids, trace,
      remarks: remarksOf(steps, 'records received in the PHR app, decryptable with the HIP key and nonce, NRCeS FHIR'), response: hn ? `POST health-information/notify HTTP ${hn.status}` : undefined });
  },
};

/** Wait for a link job to end (LINKED / FAILED), so the next step starts from a settled state. */
async function waitLinked(ctx, p, jobId) {
  if (!jobId) return null;
  for (let i = 0; i < 30; i++) {
    const j = await ctx.hmis.call(`link_status/${jobId}`);
    if (['LINKED', 'FAILED'].includes(j.body?.status)) return j.body.status;
    await sleep(3000);
  }
  return null;
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) main().catch((e) => { console.error(e); close(); process.exit(1); });
