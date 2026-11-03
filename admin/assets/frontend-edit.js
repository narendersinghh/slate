/* Slate on-page editing: click "Edit on page" in the admin bar. */
(function () {
  'use strict';
  const S = window.SLATE || {};
  const editBtn = document.getElementById('sb-edit');
  if (!editBtn) return;
  const saveBtn = document.getElementById('sb-save');
  const cancelBtn = document.getElementById('sb-cancel');
  const postId = +editBtn.dataset.id;
  const regions = () => Array.from(document.querySelectorAll('[data-slate-edit][data-post="' + postId + '"]'));
  const originals = new Map();
  const changes = { fields: {} };
  let fmt;

  const toast = (msg, ms = 2500) => { const t = document.createElement('div'); t.className = 'slate-toast'; t.textContent = msg; document.body.appendChild(t); setTimeout(() => t.remove(), ms); };
  const api = (path, opts = {}) => fetch(S.api + path, {
    method: opts.body ? 'POST' : 'GET', credentials: 'same-origin',
    headers: { 'X-CSRF-Token': S.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body: opts.body ? JSON.stringify(opts.body) : undefined,
  }).then((r) => r.json()).catch(() => ({ ok: false, error: 'Network error' }));

  function record(el) {
    const what = el.dataset.slateEdit;
    const kind = el.dataset.slateKind;
    const val = kind === 'html' ? el.innerHTML : (kind === 'textarea' ? el.innerText : el.textContent.trim());
    if (what.startsWith('field:')) changes.fields[what.slice(6)] = val; else changes[what] = val;
    el.classList.add('slate-changed');
  }

  function showFmt(el) {
    if (!fmt) {
      fmt = document.createElement('div');
      fmt.id = 'slate-fmt';
      fmt.innerHTML = '<button data-c="bold"><b>B</b></button><button data-c="italic"><i>I</i></button><button data-c="h2">H2</button><button data-c="h3">H3</button><button data-c="p">¶</button><button data-c="insertUnorderedList">•</button><button data-c="link">🔗</button>';
      fmt.addEventListener('mousedown', (e) => e.preventDefault());
      fmt.addEventListener('click', (e) => {
        const c = e.target.closest('button')?.dataset.c;
        if (!c) return;
        if (c === 'link') { const u = prompt('Link URL'); if (u && !/^\s*javascript:/i.test(u)) document.execCommand('createLink', false, u); }
        else if (['h2', 'h3', 'p'].includes(c)) document.execCommand('formatBlock', false, c);
        else document.execCommand(c);
        const r = document.activeElement.closest('[data-slate-edit]');
        if (r) record(r);
      });
      document.body.appendChild(fmt);
    }
    const r = el.getBoundingClientRect();
    fmt.style.top = (window.scrollY + r.top - 42) + 'px';
    fmt.style.left = (window.scrollX + r.left) + 'px';
    fmt.hidden = false;
  }

  function pickImage(el) {
    const wrap = document.createElement('div');
    wrap.id = 'slate-picker';
    wrap.innerHTML = '<div class="box"><div class="head"><strong>Choose an image</strong><span><label>Upload<input type="file" accept="image/*" hidden></label> &nbsp; <button type="button" data-x>✕</button></span></div><div class="grid">Loading…</div></div>';
    document.body.appendChild(wrap);
    const close = () => wrap.remove();
    wrap.addEventListener('click', (e) => { if (e.target === wrap || e.target.closest('[data-x]')) close(); });
    const choose = (m) => {
      el.innerHTML = '<img src="' + m.url + '" alt="' + (m.alt || '').replace(/"/g, '&quot;') + '"' + (m.width ? ' width="' + m.width + '" height="' + m.height + '"' : '') + '>';
      changes.fields[el.dataset.slateEdit.slice(6)] = m.id;
      el.classList.add('slate-changed');
      close();
    };
    api('/media?filter=image&per=60').then((r) => {
      const grid = wrap.querySelector('.grid');
      grid.innerHTML = '';
      (r.items || []).forEach((m) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.innerHTML = '<img src="' + m.thumb + '" alt="">';
        b.onclick = () => choose(m);
        grid.appendChild(b);
      });
      if (!grid.children.length) grid.textContent = 'No images yet — upload one.';
    });
    wrap.querySelector('input[type=file]').addEventListener('change', async (e) => {
      const f = e.target.files[0];
      if (!f) return;
      const fd = new FormData();
      fd.append('file', f);
      toast('Uploading & optimizing…');
      const r = await fetch(S.api + '/media', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': S.csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }).then((x) => x.json());
      r.ok ? choose(r.items[0]) : toast(r.error || 'Upload failed', 5000);
    });
  }

  editBtn.addEventListener('click', () => {
    document.body.classList.add('slate-editing');
    editBtn.hidden = true; saveBtn.hidden = false; cancelBtn.hidden = false;
    regions().forEach((el) => {
      originals.set(el, el.innerHTML);
      if (el.dataset.slateKind === 'image') { el.addEventListener('click', (e) => { e.preventDefault(); pickImage(el); }); return; }
      el.contentEditable = el.dataset.slateKind === 'html' ? 'true' : 'plaintext-only';
      if (el.contentEditable !== 'plaintext-only' && el.dataset.slateKind !== 'html') el.contentEditable = 'true';
      el.addEventListener('input', () => record(el));
      if (el.dataset.slateKind === 'html') el.addEventListener('focus', () => showFmt(el));
      el.addEventListener('blur', () => { if (fmt) setTimeout(() => { if (!document.activeElement.closest?.('[data-slate-kind="html"]')) fmt.hidden = true; }, 150); });
      el.addEventListener('click', (e) => { if (e.target.closest('a')) e.preventDefault(); });
    });
    regions()[0]?.focus();
    toast('Editing — click any outlined area');
  });
  cancelBtn.addEventListener('click', () => { if (!Object.keys(changes).length || confirm('Discard your changes?')) location.reload(); });
  saveBtn.addEventListener('click', async () => {
    const body = Object.assign({ id: postId }, changes);
    if (!Object.keys(body.fields).length) delete body.fields;
    saveBtn.textContent = 'Saving…';
    const r = await api('/inline-save', { body });
    if (r.ok) { toast('Saved ✓'); setTimeout(() => location.reload(), 600); }
    else { toast(r.error || 'Save failed', 5000); saveBtn.textContent = 'Save changes'; }
  });
  window.addEventListener('beforeunload', (e) => { if (document.querySelector('.slate-changed') && saveBtn.textContent !== 'Saving…') { e.preventDefault(); e.returnValue = ''; } });
})();
