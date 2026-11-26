// Smart Hospital (HMIS) client for the functional-test runners: signs in as a staff member and calls the same
// admin/abdm/* endpoints the ABDM screens call, with the page's per-session token. Never follows redirects
// (base_url is hard-coded to the live domain). The password is asked with input hidden and held only in memory.
import { need } from './ft.mjs';

export class Hmis {
  /** base: e.g. https://hrms.duobytes.in or http://127.0.0.1:8092 (FT_HMS_URL). */
  constructor(base) {
    this.base = (base || process.env.FT_HMS_URL || '').replace(/\/$/, '');
    if (!this.base) throw new Error('Set FT_HMS_URL to Smart Hospital\'s address, e.g. https://hrms.duobytes.in');
    this.cookies = new Map();
    this.token = null;
  }

  cookieHeader() { return [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; '); }

  keep(res) {
    for (const c of res.headers.getSetCookie?.() || []) {
      const [pair] = c.split(';');
      const i = pair.indexOf('=');
      this.cookies.set(pair.slice(0, i).trim(), pair.slice(i + 1).trim());
    }
  }

  async raw(method, p, { form, json, headers = {} } = {}) {
    const res = await fetch(this.base + p, {
      method, redirect: 'manual',
      headers: { cookie: this.cookieHeader(), ...(form ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}),
        ...(json !== undefined ? { 'Content-Type': 'application/json' } : {}), ...headers },
      body: form ? new URLSearchParams(form).toString() : json !== undefined ? JSON.stringify(json) : undefined,
      signal: AbortSignal.timeout(60_000),
    });
    this.keep(res);
    return res;
  }

  /** Sign in; asks for the staff email and password (hidden). Returns true when the ABDM page opens. */
  async login(caseId = 'readiness') {
    const user = await need(caseId, 'the Smart Hospital staff login (email or username)');
    if (!user) return false;
    const pass = await need(caseId, `the password for ${user}`, { secret: true });
    if (!pass) return false;
    await this.raw('GET', '/site/login');
    await this.raw('POST', '/site/login', { form: { username: user, password: pass } });
    return this.openToken();
  }

  /** Read the per-session ABDM token from the Create / Verify ABHA page. */
  async openToken(page = '/admin/abdm/abha') {
    const res = await this.raw('GET', page);
    if (res.status !== 200) return false;
    const m = (await res.text()).match(/data-token="([^"]+)"/);
    this.token = m ? m[1] : null;
    return !!this.token;
  }

  /** GET a page; returns { status, html }. */
  async page(p) { const res = await this.raw('GET', p); return { status: res.status, html: await res.text() }; }

  /**
   * Call an ABDM AJAX action, e.g. call('m1/enrol_otp', {...}) or call('share_queue'). POST when body is given.
   * Returns { status, body, contentType } (body: parsed JSON, or a Buffer for card/QR when binary).
   */
  async call(action, body, { binary = false } = {}) {
    const res = await this.raw(body !== undefined ? 'POST' : 'GET', '/admin/abdm/' + action, {
      json: body, headers: { 'X-ABDM-Token': this.token || '', 'X-Requested-With': 'XMLHttpRequest' } });
    const contentType = res.headers.get('content-type') || '';
    if (binary && res.ok) return { status: res.status, body: Buffer.from(await res.arrayBuffer()), contentType };
    const text = await res.text();
    let out;
    try { out = text ? JSON.parse(text) : null; } catch { out = { raw: text.slice(0, 300) }; }
    return { status: res.status, body: out, contentType };
  }
}
