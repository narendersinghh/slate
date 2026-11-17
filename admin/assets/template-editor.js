/* Slate template editor: code with line numbers, a searchable tag palette and a live preview of unsaved changes. */
(function () {
  'use strict';
  const { $, $$, esc, toast, debounce } = window.Slate;
  const form = $('[data-tpl-form]');
  if (!form) return;
  const frame = $('[data-frame]', form);
  const msg = $('[data-pv-msg]', form);
  const openLink = $('[data-open]', form);
  const itemSel = $('[data-item]', form);
  const state = $('[data-save-state]', form);
  let last = $('textarea[name="html"]', form);
  let dirty = false;
  const markDirty = (on) => { dirty = on; state.textContent = on ? 'Unsaved changes' : state.textContent; };

  // ---- code areas: line numbers, indentation, auto-close ----
  $$('.tpe-editor', form).forEach((wrap) => {
    const ta = $('textarea', wrap);
    const gutter = $('.tpe-gutter', wrap);
    const lines = () => {
      const n = ta.value.split('\n').length;
      if (gutter.childElementCount !== n) gutter.textContent = Array.from({ length: n }, (_, i) => i + 1).join('\n');
      gutter.scrollTop = ta.scrollTop;
    };
    lines();
    ta.addEventListener('scroll', () => { gutter.scrollTop = ta.scrollTop; });
    ta.addEventListener('focus', () => { last = ta; });
    ta.addEventListener('input', () => { lines(); markDirty(true); schedule(); });
    ta.addEventListener('keydown', (e) => {
      const s = ta.selectionStart, en = ta.selectionEnd, v = ta.value;
      if (e.key === 'Tab') {
        e.preventDefault();
        const ls = v.lastIndexOf('\n', s - 1) + 1;
        if (s === en && !e.shiftKey) { ta.setRangeText('  ', s, en, 'end'); }
        else {
          const block = v.slice(ls, en);
          const out = e.shiftKey ? block.replace(/^ {1,2}/gm, '') : block.replace(/^/gm, '  ');
          ta.setRangeText(out, ls, en, 'select');
        }
        ta.dispatchEvent(new Event('input'));
      } else if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey) {
        e.preventDefault();
        const ls = v.lastIndexOf('\n', s - 1) + 1;
        const indent = (v.slice(ls, s).match(/^\s*/) || [''])[0];
        const opener = /<([a-z][\w-]*)(\s[^>]*)?>$/i.exec(v.slice(ls, s));
        const extra = opener && !/^(br|img|input|meta|link|hr|source)$/i.test(opener[1]) || /\{\{#[^}]*\}\}$/.test(v.slice(ls, s)) ? '  ' : '';
        ta.setRangeText('\n' + indent + extra, s, en, 'end');
        ta.dispatchEvent(new Event('input'));
      } else if (e.key === '{' && v.slice(s - 1, s) === '{' && s === en && ta.name === 'html') {
        e.preventDefault();
        ta.setRangeText('{}}', s, en, 'start');
        ta.selectionStart = ta.selectionEnd = s + 1;
        ta.dispatchEvent(new Event('input'));
      }
    });
  });

  // ---- tabs, panels ----
  form.addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.codeTab) {
      $$('[data-code-tab]', form).forEach((x) => x.classList.toggle('is-on', x === b));
      $$('[data-editor]', form).forEach((x) => { x.hidden = x.dataset.editor !== b.dataset.codeTab; });
      $('[data-editor="' + b.dataset.codeTab + '"] textarea', form).focus();
    } else if (b.hasAttribute('data-toggle-settings')) {
      const s = $('[data-settings]', form); s.hidden = !s.hidden; b.setAttribute('aria-expanded', String(!s.hidden));
    } else if (b.hasAttribute('data-toggle-palette')) {
      form.classList.toggle('no-palette');
    } else if (b.hasAttribute('data-toggle-preview')) {
      form.classList.toggle('no-preview'); if (!form.classList.contains('no-preview')) refresh();
    } else if (b.dataset.insert !== undefined) {
      const ta = last || $('textarea[name="html"]', form);
      const ls = ta.value.lastIndexOf('\n', ta.selectionStart - 1) + 1;
      const indent = (ta.value.slice(ls, ta.selectionStart).match(/^\s*/) || [''])[0];
      ta.focus();
      ta.setRangeText(b.dataset.insert.replace(/\n/g, '\n' + indent), ta.selectionStart, ta.selectionEnd, 'end');
      ta.dispatchEvent(new Event('input'));
    } else if (b.dataset.device) {
      $$('[data-device]', form).forEach((x) => x.classList.toggle('is-on', x === b));
      frame.style.width = b.dataset.device;
    } else if (b.hasAttribute('data-refresh')) {
      refresh();
    } else if (b.hasAttribute('data-starter')) {
      starter();
    } else if (b.hasAttribute('data-ask-goraiya')) {
      const text = 'Improve the template "' + form.name.value + '" (' + $('[data-where]', form).textContent + ', id ' + (form.id.value || 'not saved yet') + '): make it look great in my site\'s style and use its fields. Keep it as a draft.';
      const tmp = document.createElement('button'); tmp.type = 'button'; tmp.hidden = true; tmp.dataset.goraiyaAsk = text; document.body.appendChild(tmp); tmp.click(); tmp.remove();
    }
  });

  // tag search
  const find = $('[data-tag-find]', form);
  find.addEventListener('input', () => {
    const q = find.value.trim().toLowerCase();
    $$('.tpl-pal-group', form).forEach((g) => {
      let n = 0;
      $$('[data-insert]', g).forEach((t) => { const show = !q || t.dataset.search.includes(q); t.hidden = !show; if (show) n++; });
      g.hidden = !n; if (q && n) g.open = true;
    });
  });

  // kind / target change the summary and the preview items
  const where = () => {
    const opt = form.target.selectedOptions[0];
    $('[data-where]', form).textContent = form.kind.selectedOptions[0].textContent + ': ' + (opt ? opt.textContent : '');
  };
  ['kind', 'target', 'wrap'].forEach((n) => form[n].addEventListener('change', () => { where(); markDirty(true); loadItems(); schedule(); }));
  form.name.addEventListener('input', () => markDirty(true));
  form.enabled.addEventListener('change', () => markDirty(true));

  // ---- preview ----
  async function loadItems() {
    const kind = form.kind.value;
    itemSel.hidden = !['single', 'term'].includes(kind);
    if (itemSel.hidden) return;
    try {
      const r = await fetch(form.dataset.itemsUrl + '?kind=' + kind + '&target=' + encodeURIComponent(form.target.value), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const d = await r.json();
      const cur = itemSel.value;
      itemSel.innerHTML = '<option value="">Preview with: latest</option>' + (d.items || []).map((x) => '<option value="' + x.id + '"' + (String(x.id) === cur ? ' selected' : '') + '>' + esc(x.label) + '</option>').join('');
    } catch (e) { /* keep the default */ }
  }
  itemSel.addEventListener('change', () => refresh());
  let busy = false, again = false;
  async function refresh() {
    if (form.classList.contains('no-preview')) return;
    if (busy) { again = true; return; }
    busy = true;
    const fd = new FormData(form);
    fd.set('item', itemSel.value || '');
    try {
      const r = await fetch(form.dataset.previewUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
      const d = await r.json();
      if (d.ok) {
        msg.hidden = true;
        const y = (() => { try { return frame.contentWindow.scrollY; } catch (e) { return 0; } })();
        frame.onload = () => {
          let ok = false;
          try { const doc = frame.contentDocument; ok = !!(doc && doc.body && doc.body.childElementCount); if (ok) { frame.contentWindow.scrollTo(0, y); doc.getElementById('slate-bar')?.remove(); } } catch (e) { ok = false; }
          if (!ok) { msg.innerHTML = 'The site\'s security headers do not allow the preview inside the dashboard (Settings › Security, frame-ancestors). <a href="' + esc(d.url) + '" target="_blank" rel="noopener">Open the preview in a new tab</a>.'; msg.hidden = false; }
        };
        frame.src = d.url;
        openLink.href = d.url;
      } else { msg.textContent = d.error || 'Preview is not available.'; msg.hidden = false; }
    } catch (e) { msg.textContent = 'Preview failed. Save and try again.'; msg.hidden = false; }
    busy = false;
    if (again) { again = false; refresh(); }
  }
  const schedule = debounce(refresh, 700);

  async function starter() {
    if (!confirm('Replace the HTML with a fresh starter that shows every field of this page type?')) return;
    const res = await fetch(form.dataset.starterUrl + '?kind=' + encodeURIComponent(form.kind.value) + '&target=' + encodeURIComponent(form.target.value), { credentials: 'same-origin' });
    const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
    const src = doc.querySelector('textarea[name="html"]');
    if (src) { const ta = $('textarea[name="html"]', form); ta.value = src.value; ta.dispatchEvent(new Event('input')); }
  }

  // ---- save (button or Ctrl/⌘ S) ----
  async function save(btn) {
    btn?.classList.add('is-loading');
    const res = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
    let d; try { d = await res.json(); } catch (err) { d = { ok: false, error: 'Save failed' }; }
    btn?.classList.remove('is-loading');
    if (!d.ok) return toast(d.error || 'Save failed', 'error');
    form.id.value = d.id;
    if (location.pathname.endsWith('/new')) history.replaceState(null, '', d.edit_url);
    dirty = false;
    state.textContent = 'Saved ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    toast(d.message);
  }
  form.addEventListener('submit', (e) => {
    const btn = e.submitter;
    if (btn && btn.getAttribute('formaction')) return; // delete
    e.preventDefault();
    save(btn);
  });
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save($('button[type="submit"]:not([formaction])', form)); }
  });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  $$('a[href]', form).forEach((a) => a.addEventListener('click', (e) => {
    if (a.target === '_blank') return;
    if (dirty && !confirm('Leave without saving your changes?')) e.preventDefault(); else dirty = false;
  }));

  where();
  loadItems();
  refresh();
})();
