/* PM-JAY module UI helpers (vanilla JS, loaded with defer). */
(function () {
  'use strict';
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // Patient picker: <div data-pmjay-picker data-search="…/patients" data-go="…?patient_id="> with input + button + list inside.
  document.querySelectorAll('[data-pmjay-picker]').forEach((box) => {
    const input = box.querySelector('input');
    const list = box.querySelector('[data-results]');
    async function run() {
      const q = input.value.trim();
      if (!q) return;
      list.innerHTML = '<div class="list-group-item text-muted small">Searching…</div>';
      const res = await fetch(box.dataset.search + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const rows = res.ok ? await res.json() : [];
      list.innerHTML = rows.length ? rows.map((r) => `<a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="${box.dataset.go}${encodeURIComponent(r.id)}">
          <span>#${esc(r.id)} · <strong>${esc(r.patient_name)}</strong> <span class="text-muted small">${esc(r.gender)}${r.age ? ' · ' + esc(r.age) + 'y' : ''} · ${esc(r.mobileno)}</span></span>
          ${r.ben_id ? `<span class="badge bg-${r.verified === '1' ? 'success' : 'secondary'}">PM-JAY ${esc(r.pmjay_id)}</span>` : '<span class="badge bg-light text-dark">Not registered</span>'}</a>`).join('')
        : '<div class="list-group-item text-muted small">No matching patients</div>';
    }
    box.querySelector('button').addEventListener('click', (e) => { e.preventDefault(); run(); });
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); run(); } });
  });

  // Status form: show only the fields relevant to the chosen next status.
  const move = document.getElementById('pmjay-move');
  if (move) {
    const sel = move.querySelector('select[name=to]');
    const sync = () => {
      const opt = sel.selectedOptions[0];
      const need = (opt && opt.dataset.fields ? opt.dataset.fields : '').split(',');
      move.querySelectorAll('[data-field]').forEach((el) => el.classList.toggle('d-none', !need.includes(el.dataset.field)));
      const amt = move.querySelector('input[name=amount]');
      if (amt && opt && opt.dataset.amount) amt.value = opt.dataset.amount;
      const lbl = move.querySelector('[data-amount-label]');
      if (lbl && opt) lbl.textContent = opt.dataset.amountLabel || 'Amount (₹)';
    };
    sel.addEventListener('change', sync);
    sync();
  }

  // Document checklist: live counter.
  const docs = document.getElementById('pmjay-docs');
  if (docs) {
    const out = document.getElementById('pmjay-docs-count');
    const boxes = docs.querySelectorAll('input[type=checkbox]');
    const sync = () => { out.textContent = `${[...boxes].filter((b) => b.checked).length} of ${boxes.length}`; };
    boxes.forEach((b) => b.addEventListener('change', sync));
    sync();
  }
})();
