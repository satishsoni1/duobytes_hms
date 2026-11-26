// Shared core of the ABDM functional-test runners (abdm-ft/m1.mjs, abdm-ft/m2.mjs).
// Follows the skill's run.md, "When the run is a script": an interactive terminal runner that walks NHA's cases
// by case id, prompts the tester for every identity and OTP with input hidden, never writes a typed value anywhere,
// updates the run log after every case, and writes the results file (for abdm_workbook.py) and the manifest
// (case id -> REQUEST-IDs, for abdm_report.py) at the end.
//
// Drives the live ABDM bridge through Smart Hospital's API key (ABDM_BRIDGE_URL, ABDM_API_KEY in ../.env),
// the same calls Smart Hospital's screens make. Requires Node 18+ (global fetch); no dependencies.
import fs from 'node:fs';
import path from 'node:path';
import readline from 'node:readline';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

export const FT_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const REPO = path.resolve(FT_DIR, '..');

// ---------------------------------------------------------------- configuration
/** Bridge URL and API key: FT_BRIDGE_URL / FT_API_KEY, else Smart Hospital's .env. Never printed. */
export function loadConfig() {
  const env = {};
  const file = path.join(REPO, '.env');
  if (fs.existsSync(file)) {
    for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
      const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
      if (m && !(m[1] in env)) env[m[1]] = m[2].replace(/^(['"])(.*)\1$/, '$2');
    }
  }
  const bridge = (process.env.FT_BRIDGE_URL || env.ABDM_BRIDGE_URL || '').replace(/\/$/, '');
  const key = process.env.FT_API_KEY || env.ABDM_API_KEY || '';
  if (!bridge || !key) throw new Error('Set ABDM_BRIDGE_URL and ABDM_API_KEY in Smart Hospital\'s .env (or FT_BRIDGE_URL / FT_API_KEY)');
  return { bridge, key, mrnPrefix: env.ABDM_MRN_PREFIX || 'SH' };
}

// ---------------------------------------------------------------- bridge client
let CFG = null;
const cfg = () => (CFG ||= loadConfig());

/**
 * Call the bridge's HMS API. Returns { status, body, ms, contentType }. Never throws on an HTTP error: a refusal is
 * often what a case expects. body is parsed JSON, a Buffer for binary (card/QR), or null when empty.
 */
export async function hms(method, p, body, { binary = false } = {}) {
  const t = Date.now();
  const res = await fetch(cfg().bridge + '/api/hms' + p, {
    method,
    headers: { Authorization: `Bearer ${cfg().key}`, 'X-Acting-User': 'ft-runner', ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}) },
    body: body !== undefined ? JSON.stringify(body) : undefined,
    signal: AbortSignal.timeout(60_000),
  });
  const contentType = res.headers.get('content-type') || '';
  let out = null;
  if (binary && res.ok) out = Buffer.from(await res.arrayBuffer());
  else {
    const text = await res.text();
    try { out = text ? JSON.parse(text) : null; } catch { out = { raw: text.slice(0, 500) }; }
  }
  return { status: res.status, body: out, ms: Date.now() - t, contentType };
}
export const get = (p, o) => hms('GET', p, undefined, o);
export const post = (p, b = {}, o) => hms('POST', p, b, o);

// ---------------------------------------------------------------- evidence: REQUEST-IDs from the bridge audit log
/** Highest audit id now; pass it to auditSince() after a step to get what that step caused. */
export async function auditMark() {
  const r = await get('/admin/audit');
  return r.status === 200 && r.body.length ? Number(r.body[0].id) : 0;
}

/** Audit rows newer than mark, oldest first (pages back as needed). Each: { id, at, actor_type, action, request_id, status }. */
export async function auditSince(mark, { waitMs = 0, until } = {}) {
  const end = Date.now() + waitMs;
  for (;;) {
    const rows = [];
    let before;
    for (let page = 0; page < 10; page++) {
      const r = await get('/admin/audit' + (before ? `?before=${before}` : ''));
      if (r.status !== 200 || !r.body.length) break;
      const fresh = r.body.filter((x) => Number(x.id) > mark);
      rows.push(...fresh);
      if (fresh.length < r.body.length) break;
      before = r.body[r.body.length - 1].id;
    }
    rows.sort((a, b) => Number(a.id) - Number(b.id));
    if (!until || until(rows) || Date.now() >= end) return rows;
    await sleep(2000);
  }
}

/** REQUEST-IDs of ABDM calls in audit rows, optionally only those whose action matches re. */
export function requestIds(rows, re) {
  return [...new Set(rows.filter((r) => r.request_id && (!re || re.test(r.action))).map((r) => r.request_id))];
}

/** One-line summary of audit rows for the run record: "OUT POST /v3/... 200 [rid]". */
export const describeAudit = (rows) => rows.map((r) => `${r.action} ${r.status ?? ''}${r.request_id ? ` [${r.request_id}]` : ''}`.trim());

// ---------------------------------------------------------------- redaction
const SECRET_KEYS = /^(otp|otpValue|loginId|token|tToken|xToken|accessToken|refreshToken|linkToken|aadhaar|mobile|phoneNumber|password|profilePhoto|photo|content|keyValue|nonce|privateKey|tokens)$/i;
/** A copy safe for the run log: secrets and identities replaced, long strings cut. */
export function redact(v, depth = 0) {
  if (v == null || depth > 6) return v;
  if (Buffer.isBuffer(v)) return `[${v.length} bytes]`;
  if (Array.isArray(v)) return v.slice(0, 20).map((x) => redact(x, depth + 1));
  if (typeof v === 'object') {
    return Object.fromEntries(Object.entries(v).map(([k, x]) => [k, SECRET_KEYS.test(k) ? '[redacted]' : redact(x, depth + 1)]));
  }
  if (typeof v === 'string') {
    let s = v.replace(/\b\d{12}\b/g, '[aadhaar]').replace(/(?<!\d)(?:\+91[ -]?)?[6-9]\d{9}(?!\d)/g, (m) => `******${m.slice(-4)}`)
      .replace(/\beyJ[\w-]+\.[\w-]+\.[\w-]*/g, '[token]');
    return s.length > 300 ? s.slice(0, 300) + '…' : s;
  }
  return v;
}
export const masked = (s, keep = 4) => { const d = String(s ?? '').replace(/\D/g, ''); return d ? '*'.repeat(Math.max(0, d.length - keep)) + d.slice(-keep) : ''; };
/** "POST /m1/enrol/aadhaar/otp HTTP 200 txnId present" style line, redacted. */
export const responseLine = (method, p, r, note = '') =>
  `${method} ${p} HTTP ${r.status}${r.body?.code ? ` ${r.body.code}` : ''}${r.body?.error ? ` "${redact(r.body.error)}"` : ''}${note ? ` ${note}` : ''}`;

// ---------------------------------------------------------------- prompts (run.md: authentication is never skipped)
// Created on first prompt, so importing this file (e.g. for --help) does not hold stdin open.
let rl = null;
const lines = [];
let waiting = null;
let muted = false;
function prompter() {
  if (rl) return rl;
  rl = readline.createInterface({ input: process.stdin, output: process.stdout, terminal: process.stdin.isTTY });
  rl.on('line', (l) => { if (waiting) { const w = waiting; waiting = null; w(l); } else lines.push(l); });
  const write = rl._writeToOutput?.bind(rl);
  if (write) rl._writeToOutput = (s) => { if (!muted) write(s); else if (/\r|\n/.test(s)) write('\n'); };
  return rl;
}
const readLine = (q, secret) => new Promise((resolve) => {
  prompter();
  process.stdout.write(q);
  muted = !!secret && !!process.stdin.isTTY;
  const done = (l) => { muted = false; resolve(l.trim()); };
  if (lines.length) done(lines.shift()); else waiting = done;
});
export const close = () => { rl?.close(); rl = null; };

/** Free answer (not a secret). */
export const ask = (q) => readLine(`${q} `, false);

/**
 * A value only the tester can give (identity, mobile, OTP, approval). Asked; a blank answer asks once more naming
 * the field; a second blank, or "skip", returns null and the caller records needs-human. secret hides the input.
 */
export async function need(caseId, what, { secret = false, check } = {}) {
  for (let i = 0; i < 2; i++) {
    const v = await readLine(`  ${caseId}: ${i ? `still needed: ${what}` : what} (blank or "skip" if you cannot provide it in this run): `, secret);
    if (v && v.toLowerCase() !== 'skip') {
      const bad = check?.(v);
      if (bad) { console.log(`  ${bad}`); i--; continue; }
      return v;
    }
    if (v.toLowerCase() === 'skip') return null;
  }
  return null;
}

/** Tester attests a screen check: y / n / blank (= not observed). Returns true, false or null. */
export async function attest(caseId, check) {
  for (let i = 0; i < 2; i++) {
    const v = (await readLine(`  ${caseId}: ${check}\n    passed? y/n (blank if it cannot be checked in this run): `, false)).toLowerCase();
    if (v === 'y' || v === 'yes') return true;
    if (v === 'n' || v === 'no') return false;
  }
  return null;
}

/** Wait for the tester to do something on their phone or in the app; true when they say done, null otherwise. */
export async function waitFor(caseId, instruction) {
  for (let i = 0; i < 2; i++) {
    const v = (await readLine(`  ${caseId}: ${instruction}\n    reply "done" when finished (blank or "skip" if not possible): `, false)).toLowerCase();
    if (v === 'done' || v === 'd' || v === 'y') return true;
    if (v === 'skip') return null;
  }
  return null;
}

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---------------------------------------------------------------- the run: log, results, manifest
const OUTCOMES = ['passed', 'conditional-pass', 'failed', 'needs-human', 'not-run'];
const stamp = (d = new Date()) => `${d.getFullYear()}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}-${String(d.getHours()).padStart(2, '0')}${String(d.getMinutes()).padStart(2, '0')}`;
const nowIso = () => new Date().toISOString().replace(/\.\d+Z$/, 'Z');

export class Run {
  /**
   * module: 'm1' | 'm2'; skill: folder name; cases: [{ id, title, kind: 'sandbox'|'screen'|'human', needs: [] }]
   * in plan order. resume: path of an earlier run log whose finished rows are kept.
   */
  constructor({ module, skill, set, entity = 'private', cases, resume }) {
    Object.assign(this, { module, skill, set, entity, cases });
    this.started = new Date();
    this.logPath = resume || path.join(FT_DIR, `${module}-${stamp(this.started)}.md`);
    this.rows = new Map(cases.map((c) => [c.id, { outcome: 'pending', requestIds: [], observed: '' }]));
    this.records = [];
    this.results = [];
    this.readiness = [];
    if (resume && fs.existsSync(resume)) this.loadResume(resume);
  }

  loadResume(file) {
    const text = fs.readFileSync(file, 'utf8');
    for (const m of text.matchAll(/^\| ([A-Z0-9_#-]+) \| (passed|conditional-pass|failed|needs-human|not-run) \| ([^|]*) \| ([^|]*) \|$/gm)) {
      if (this.rows.has(m[1])) this.rows.set(m[1], { outcome: m[2], requestIds: m[3].split(',').map((x) => x.trim()).filter(Boolean), observed: m[4].trim(), kept: true });
    }
    const resJson = this.resultsPath();
    if (fs.existsSync(resJson)) this.results = JSON.parse(fs.readFileSync(resJson, 'utf8')).filter((r) => this.rows.get(r.id)?.kept);
    const recs = text.split('\n## Run records\n')[1];
    if (recs) this.records.push(recs.trim());
  }

  /** Case ids still to run (pending or running), in plan order. */
  todo(only) { return this.cases.filter((c) => (!only || only.includes(c.id)) && !this.rows.get(c.id)?.kept); }

  setReadiness(rows) { this.readiness = rows; this.save(); }

  running(id) { this.rows.set(id, { ...this.rows.get(id), outcome: 'running' }); this.save(); console.log(`\n== ${id}: ${this.cases.find((c) => c.id === id)?.title || ''}`); }

  /**
   * Finish a case. r: { outcome, requestIds, observed, remarks, response, iterations, steps, evidence }.
   * Updates the run log at once and appends to the results file (both written on every case).
   */
  record(id, r) {
    if (!OUTCOMES.includes(r.outcome)) throw new Error(`bad outcome ${r.outcome} for ${id}`);
    if (r.outcome === 'conditional-pass' && !r.remarks) throw new Error(`${id}: a conditional pass names its condition in remarks`);
    if (r.outcome === 'needs-human' && !r.remarks) throw new Error(`${id}: needs-human names what was asked in remarks`);
    const rids = [...new Set(r.requestIds || [])];
    const observed = String(redact(r.observed || r.remarks || '')).replace(/\|/g, '/').replace(/\n/g, ' ');
    this.rows.set(id, { outcome: r.outcome, requestIds: rids, observed });
    this.records.push([`case: ${id}   pass: ${r.iterations || 1} of 3   at: ${nowIso()}`,
      ...(r.trace || []).map((t) => `observed: ${redact(t)}`),
      `matched: ${r.outcome}${r.remarks ? ` (${redact(r.remarks)})` : ''}`,
      ...(r.evidence ? [`evidence: ${r.evidence}`] : [])].join('\n'));
    const res = { id, outcome: r.outcome, iterations: r.iterations || 1 };
    if (r.response) res.response = String(redact(r.response)).slice(0, 500);
    if (r.remarks) res.remarks = String(redact(r.remarks)).slice(0, 500);
    const STEP_OK = ['passed', 'failed', 'needs-human', 'not-run'];
    const badStep = (r.steps || []).find((s) => !STEP_OK.includes(s.outcome));
    if (badStep) throw new Error(`${id}: a step outcome must be one of ${STEP_OK.join(', ')} (got ${badStep.outcome}); put a condition in its note`);
    if (r.steps?.length) res.steps = r.steps.map((s, i) => ({ step: s.step ?? i + 1, outcome: s.outcome, ...(s.note ? { note: String(redact(s.note)) } : {}) }));
    this.results = this.results.filter((x) => x.id !== id).concat(res);
    fs.writeFileSync(this.resultsPath(), JSON.stringify(this.results, null, 2) + '\n');
    this.save();
    console.log(`   ${id}  ${r.outcome}${rids.length ? `  REQUEST-ID ${rids.join(', ')}` : ''}${r.remarks ? `  (${redact(r.remarks)})` : ''}`);
  }

  resultsPath() { return path.join(FT_DIR, `${this.module}-ft-results.json`); }

  save(finished = false) {
    const counts = OUTCOMES.map((o) => [o, [...this.rows.values()].filter((r) => r.outcome === o).length]).filter(([, n]) => n);
    const out = [
      `# ${this.module.toUpperCase()} functional test run, ${this.started.toISOString().slice(0, 16).replace('T', ' ')}`,
      '',
      `Skill: ${this.skill} · Set: ${this.set} · Entity type: ${this.entity} · Run type: ft (terminal) · Started: ${this.started.toISOString()}`,
      finished ? `Finished: ${nowIso()} · ${counts.map(([o, n]) => `${n} ${o}`).join(', ')}` : 'Status: in progress',
      '',
      '## Readiness',
      '',
      ...(this.readiness.length ? this.readiness.map((r) => `- ${r}`) : ['- (not run yet)']),
      '',
      '## Cases',
      '',
      '| Case | Outcome | Request ids | Observed |',
      '|---|---|---|---|',
      ...this.cases.map((c) => { const r = this.rows.get(c.id); return `| ${c.id} | ${r.outcome} | ${r.requestIds.join(', ')} | ${r.observed} |`; }),
      '',
      '## Run records',
      '',
      ...this.records.map((b) => '```text\n' + b + '\n```\n'),
    ];
    fs.writeFileSync(this.logPath, out.join('\n'));
  }

  /** Mark the log finished, write the manifest beside it, print one line per case. */
  finish() {
    this.save(true);
    const manifest = Object.fromEntries(this.cases.map((c) => [c.id, this.rows.get(c.id).requestIds]));
    const mpath = this.logPath.replace(/\.md$/, '.json');
    fs.writeFileSync(mpath, JSON.stringify(manifest, null, 2) + '\n');
    console.log('\n== Summary');
    for (const c of this.cases) { const r = this.rows.get(c.id); console.log(`${c.id.padEnd(40)} ${r.outcome.padEnd(16)} ${r.requestIds.join(', ')}`); }
    console.log(`\nRun log:  ${this.logPath}\nResults:  ${this.resultsPath()}  (python3 -I abdm-ft/abdm_workbook.py write <workbook> --run ft --results ${path.relative(REPO, this.resultsPath())} ...)\nManifest: ${mpath}`);
    return { log: this.logPath, manifest: mpath, results: this.resultsPath() };
  }
}

/** Parse "--cases A,B --resume file" style arguments. */
export function args(argv = process.argv.slice(2)) {
  const out = {};
  for (let i = 0; i < argv.length; i++) {
    if (argv[i] === '--cases') out.cases = argv[++i].split(',').map((s) => s.trim()).filter(Boolean);
    else if (argv[i] === '--resume') out.resume = path.resolve(argv[++i]);
    else if (argv[i] === '--dry-run') out.dryRun = true;
    else if (argv[i] === '--help' || argv[i] === '-h') out.help = true;
  }
  return out;
}

/** Readiness check shared by both runners: bridge reachable, key valid, facility, and what the tester has. */
export async function readinessCheck(caseNeeds) {
  const rows = [];
  const ping = await get('/hip/ping').catch((e) => ({ status: 0, body: { error: e.message } }));
  rows.push(ping.status === 200 ? `bridge: ${cfg().bridge} reachable, facility ${ping.body.facility.id} (${ping.body.facility.name})` : `bridge: NOT ready (HTTP ${ping.status} ${redact(ping.body?.error || '')})`);
  const audit = await get('/admin/audit');
  rows.push(audit.status === 200 ? `evidence: audit log readable${audit.body[0] && !('request_id' in audit.body[0]) ? ' but without request_id (deploy bridge 664198b or later)' : ''}` : `evidence: audit log NOT readable (HTTP ${audit.status})`);
  const needs = [...new Set(caseNeeds.flat())];
  const have = [];
  for (const n of needs) {
    const v = (await ask(`  readiness: do you have "${n}" for this run? y/n`)).toLowerCase();
    have.push(`${n}: ${v.startsWith('y') ? 'ready' : 'not available'}`);
  }
  rows.push(...have);
  return { rows, ok: ping.status === 200, facility: ping.body?.facility, have: Object.fromEntries(needs.map((n, i) => [n, have[i].endsWith('ready')])) };
}

export const newId = () => crypto.randomUUID();
