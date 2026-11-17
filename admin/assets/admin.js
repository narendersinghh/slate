/* Slate admin, core UI behaviours (vanilla JS, no dependencies). */
(function () {
  'use strict';
  const S = window.SLATE || {};
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  async function api(path, opts = {}) {
    const isForm = opts.body instanceof FormData;
    const res = await fetch(S.api + path, {
      method: opts.method || (opts.body ? 'POST' : 'GET'),
      headers: Object.assign({ 'X-CSRF-Token': S.csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        isForm || !opts.body ? {} : { 'Content-Type': 'application/json' }),
      body: opts.body ? (isForm ? opts.body : JSON.stringify(opts.body)) : undefined,
      credentials: 'same-origin',
    });
    let data;
    try { data = await res.json(); } catch (e) { data = { ok: false, error: 'Unexpected server response (' + res.status + ')' }; }
    if (!res.ok && data.ok !== false) data.ok = false;
    return data;
  }

  function toast(message, type = 'success', ms = 3800) {
    const wrap = $('#toasts');
    if (!wrap) return;
    const el = document.createElement('div');
    el.className = 'toast toast-' + type;
    el.innerHTML = '<span>' + esc(message) + '</span>';
    wrap.appendChild(el);
    setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 250); }, ms);
  }

  function modal({ title = '', body = '', foot = '', size = '' } = {}) {
    const m = document.createElement('div');
    m.className = 'modal ' + (size ? 'modal-' + size : '');
    m.innerHTML = '<div class="modal-box" role="dialog" aria-modal="true"><div class="modal-head"><h2>' + esc(title) +
      '</h2><button type="button" class="icon-btn" data-close aria-label="Close">✕</button></div><div class="modal-body"></div>' +
      (foot !== null ? '<div class="modal-foot"></div>' : '') + '</div>';
    if (typeof body === 'string') $('.modal-body', m).innerHTML = body; else $('.modal-body', m).appendChild(body);
    if ($('.modal-foot', m)) { if (typeof foot === 'string') $('.modal-foot', m).innerHTML = foot; else if (foot) $('.modal-foot', m).appendChild(foot); }
    const close = () => { m.remove(); document.removeEventListener('keydown', onKey); };
    const onKey = (e) => { if (e.key === 'Escape') close(); };
    m.addEventListener('click', (e) => { if (e.target === m || e.target.closest('[data-close]')) close(); });
    document.addEventListener('keydown', onKey);
    document.body.appendChild(m);
    m.close = close;
    return m;
  }

  function debounce(fn, ms = 250) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }

  window.Slate = Object.assign(window.Slate || {}, { api, toast, modal, esc, $, $$, debounce });

  // ---------- Toasts from server ----------
  $$('#toasts .toast').forEach((el, i) => setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 250); }, 4200 + i * 400));

  // ---------- Dropdowns ----------
  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-dropdown]');
    $$('.dropdown.open').forEach((d) => { if (!trigger || d !== trigger.parentElement) d.classList.remove('open'); });
    if (trigger) trigger.parentElement.classList.toggle('open');
  });

  // ---------- Theme / nav ----------
  document.addEventListener('click', (e) => {
    const a = e.target.closest('[data-action]');
    if (!a) return;
    const act = a.dataset.action;
    const root = document.documentElement;
    if (act === 'theme') {
      const next = root.dataset.mode === 'dark' ? 'light' : 'dark';
      root.dataset.mode = next;
      document.cookie = 'slate_theme=' + next + ';path=/;max-age=31536000;samesite=lax';
    } else if (act === 'collapse-nav') {
      root.classList.toggle('nav-collapsed');
      try { localStorage.setItem('slate_nav', root.classList.contains('nav-collapsed') ? 'collapsed' : ''); } catch (err) {}
    } else if (act === 'open-nav') root.classList.add('nav-open');
    else if (act === 'close-nav') root.classList.remove('nav-open');
    else if (act === 'palette') openPalette();
  });

  // ---------- Confirm ----------
  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-confirm]');
    if (el && !confirm(el.dataset.confirm)) { e.preventDefault(); e.stopImmediatePropagation(); }
  }, true);

  // ---------- Copy ----------
  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-copy]');
    if (!el) return;
    navigator.clipboard?.writeText(el.dataset.copy).then(() => toast('Copied to clipboard'));
  });

  // ---------- Bulk selection ----------
  $$('[data-bulk]').forEach((form) => {
    const bar = $('.bulk-bar', form);
    const all = $('[data-check-all]', form);
    const update = () => {
      const n = $$('input[name="ids[]"]:checked', form).length;
      if (bar) { bar.hidden = n === 0; $('.bulk-count', bar).textContent = n + ' selected'; }
    };
    all?.addEventListener('change', () => { $$('input[name="ids[]"]', form).forEach((c) => { c.checked = all.checked; }); update(); });
    form.addEventListener('change', update);
  });

  // ---------- Command palette ----------
  const palette = $('#palette');
  let palIndex = 0;
  function openPalette() {
    if (!palette) return;
    palette.hidden = false;
    const input = $('input', palette);
    input.value = '';
    input.focus();
    runPalette('');
  }
  function closePalette() { if (palette) palette.hidden = true; }
  const runPalette = debounce(async (q) => {
    const res = await api('/search?q=' + encodeURIComponent(q));
    const box = $('.palette-results', palette);
    if (!res.ok) return;
    let html = '';
    let group = '';
    const quick = q === '' ? [
      { group: 'Quick actions', label: 'Go to dashboard', url: S.admin },
      { group: 'Quick actions', label: 'New post', url: S.admin + '/content/post/new' },
      { group: 'Quick actions', label: 'New page', url: S.admin + '/content/page/new' },
      { group: 'Quick actions', label: 'Media library', url: S.admin + '/media' },
    ] : [];
    quick.concat(res.items).forEach((it, i) => {
      if (it.group !== group) { group = it.group; html += '<div class="palette-group">' + esc(group) + '</div>'; }
      html += '<a class="palette-item" role="option" href="' + esc(it.url) + '">' + esc(it.label) + (it.hint ? '<small>' + esc(it.hint) + '</small>' : '') + '</a>';
    });
    box.innerHTML = html || '<div class="palette-group">No results</div>';
    palIndex = 0;
    highlight();
  }, 120);
  function highlight() {
    const items = $$('.palette-item', palette);
    items.forEach((el, i) => el.classList.toggle('active', i === palIndex));
    items[palIndex]?.scrollIntoView({ block: 'nearest' });
  }
  if (palette) {
    $('input', palette).addEventListener('input', (e) => runPalette(e.target.value));
    palette.addEventListener('click', (e) => { if (e.target === palette) closePalette(); });
    palette.addEventListener('keydown', (e) => {
      const items = $$('.palette-item', palette);
      if (e.key === 'ArrowDown') { palIndex = Math.min(items.length - 1, palIndex + 1); highlight(); e.preventDefault(); }
      else if (e.key === 'ArrowUp') { palIndex = Math.max(0, palIndex - 1); highlight(); e.preventDefault(); }
      else if (e.key === 'Enter' && items[palIndex]) { location.href = items[palIndex].href; }
      else if (e.key === 'Escape') closePalette();
    });
  }
  document.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); palette?.hidden ? openPalette() : closePalette(); }
  });

  // ---------- Settings: show_if, counters, dirty state ----------
  const settingsForm = $('[data-settings-form]');
  if (settingsForm) {
    const evalShow = () => {
      $$('[data-show-if]', settingsForm).forEach((row) => {
        const neg = row.dataset.showIf.includes('!=');
        const [key, val] = row.dataset.showIf.split(neg ? '!=' : '=');
        const ctl = $('[data-key="' + key + '"]', settingsForm);
        const cur = ctl ? (ctl.type === 'checkbox' ? String(ctl.checked ? 1 : 0) : ctl.value) : null;
        row.hidden = ctl ? (neg ? cur === val : cur !== val) : false;
      });
    };
    settingsForm.addEventListener('change', evalShow);
    evalShow();
    settingsForm.addEventListener('input', () => { const d = $('[data-dirty]', settingsForm); if (d) d.hidden = false; });
  }
  $$('[data-count]').forEach((el) => {
    const max = +el.dataset.count;
    const c = document.createElement('small');
    c.className = 'counter';
    el.after(c);
    const upd = () => { const n = el.value.length; c.textContent = n + ' / ' + max; c.className = 'counter ' + (n > max ? 'over' : n > max * 0.5 ? 'ok' : 'short'); };
    el.addEventListener('input', upd);
    upd();
  });

  // ---------- Repeatable table rows (redirects) ----------
  $$('[data-add-row]').forEach((btn) => btn.addEventListener('click', () => {
    const table = btn.previousElementSibling.querySelector('tbody') || $('[data-repeat-table] tbody');
    const last = table.lastElementChild;
    const clone = last.cloneNode(true);
    $$('input[type=text], input:not([type])', clone).forEach((i) => { i.value = ''; });
    $$('input[type=checkbox]', clone).forEach((i) => { i.checked = false; });
    $$('input[type=hidden]', clone).forEach((i) => { i.value = '0'; });
    table.appendChild(clone);
  }));
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-remove-row]');
    if (!b) return;
    const tr = b.closest('tr');
    if (tr.parentElement.children.length > 1) tr.remove(); else $$('input', tr).forEach((i) => { i.value = ''; });
  });

  // ---------- Password tools ----------
  $$('[data-generate-password]').forEach((btn) => btn.addEventListener('click', () => {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*-_';
    const arr = new Uint32Array(20);
    crypto.getRandomValues(arr);
    const pw = Array.from(arr, (n) => chars[n % chars.length]).join('');
    const input = btn.parentElement.querySelector('input');
    input.type = 'text';
    input.value = pw;
    input.dispatchEvent(new Event('input'));
    navigator.clipboard?.writeText(pw).then(() => toast('Strong password generated and copied'));
  }));
  $$('[data-password]').forEach((input) => {
    const bar = input.closest('.field')?.querySelector('[data-strength] i');
    input.addEventListener('input', () => {
      const v = input.value;
      let s = 0;
      if (v.length >= 10) s++; if (v.length >= 14) s++; if (/[A-Z]/.test(v) && /[a-z]/.test(v)) s++; if (/\d/.test(v)) s++; if (/[^A-Za-z0-9]/.test(v)) s++;
      if (bar) { bar.style.width = (s / 5 * 100) + '%'; bar.style.background = s < 3 ? 'var(--danger)' : s < 4 ? 'var(--warn)' : 'var(--success)'; }
    });
  });

  // ---------- Slug helpers ----------
  const slugify = (s) => s.toString().normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  window.Slate.slugify = slugify;
  $$('form').forEach((form) => {
    const plural = $('[data-plural]', form), singular = $('[data-singular]', form), key = $('[data-key]', form), rw = $('[data-rewrite]', form);
    if (singular && key && !key.value && !key.disabled) {
      singular.addEventListener('input', () => { if (!key.dataset.touched) key.value = slugify(singular.value).replace(/-/g, '_').slice(0, 38); });
      key.addEventListener('input', () => { key.dataset.touched = 1; });
    }
    if (plural && rw && !rw.value) {
      plural.addEventListener('input', () => { if (!rw.dataset.touched) { rw.value = slugify(plural.value); rw.dispatchEvent(new Event('input')); } });
      rw.addEventListener('input', (e) => { if (e.isTrusted) rw.dataset.touched = 1; });
    }
    if (rw) rw.addEventListener('input', () => { const p = $('[data-rewrite-preview]', form); if (p) p.textContent = rw.value; });
    const title = $('[data-title]', form), slug = $('[data-slug]', form);
    if (title && slug && !slug.value && !form.matches('[data-post-form]')) {
      title.addEventListener('input', () => { if (!slug.dataset.touched) slug.placeholder = slugify(title.value) || 'auto'; });
      slug.addEventListener('input', () => { slug.dataset.touched = 1; });
    }
  });

  // ---------- AI provider presets (settings) ----------
  const presetsEl = $('[data-ai-presets]');
  if (presetsEl) {
    const presets = JSON.parse(presetsEl.textContent);
    const sel = $('[data-key="ai_provider"]');
    const upd = () => {
      const p = presets[sel.value];
      if (!p) return;
      $('[data-ai-label]').textContent = p.label;
      $('[data-ai-model]').textContent = p.model || '-';
      $('[data-ai-models]').textContent = (p.models || []).join(', ') || 'enter any model id';
      $('[data-ai-keys]').innerHTML = p.keys ? ' · <a href="' + esc(p.keys) + '" target="_blank" rel="noopener">Get an API key</a>' : '';
      const model = $('#s_ai_model');
      if (model) model.placeholder = p.model || 'model-id';
      const base = $('#s_ai_base_url');
      if (base) base.placeholder = p.base || 'https://your-gateway/v1';
    };
    sel?.addEventListener('change', upd);
    upd();
    $('[data-ai-test]')?.addEventListener('click', async (e) => {
      const b = e.currentTarget;
      b.classList.add('is-loading');
      const r = await api('/ai/test', { body: {} });
      b.classList.remove('is-loading');
      r.ok ? toast('Connected: ' + r.text) : toast(r.error || 'Failed', 'error', 7000);
    });
  }

  // ---------- Relation fields ----------
  function initRelation(root) {
    const hidden = $('input[type=hidden]', root);
    const chips = $('[data-chips]', root);
    const search = $('[data-relation-search]', root);
    const sug = $('[data-suggest]', root);
    const sync = () => { hidden.value = $$('.chip', chips).map((c) => c.dataset.id).join(','); };
    chips.addEventListener('click', (e) => { if (e.target.tagName === 'BUTTON') { e.target.parentElement.remove(); sync(); } });
    search.addEventListener('input', debounce(async () => {
      const q = search.value.trim();
      if (!q) { sug.hidden = true; return; }
      const r = await api('/posts?type=' + encodeURIComponent(root.dataset.type || '') + '&q=' + encodeURIComponent(q));
      sug.innerHTML = (r.items || []).map((p) => '<button type="button" data-id="' + p.id + '">' + esc(p.title) + '<small>' + esc(p.type) + '</small></button>').join('') || '<small style="padding:8px">No matches</small>';
      sug.hidden = false;
    }));
    sug.addEventListener('click', (e) => {
      const b = e.target.closest('button');
      if (!b || $('.chip[data-id="' + b.dataset.id + '"]', chips)) return;
      chips.insertAdjacentHTML('beforeend', '<span class="chip" data-id="' + b.dataset.id + '">' + esc(b.firstChild.textContent) + '<button type="button" aria-label="Remove">×</button></span>');
      sync();
      sug.hidden = true;
      search.value = '';
    });
    document.addEventListener('click', (e) => { if (!root.contains(e.target)) sug.hidden = true; });
  }
  $$('[data-relation]').forEach(initRelation);

  // ---------- Tag inputs ----------
  function initTags(root) {
    const hidden = $('input[type=hidden]', root);
    const chips = $('[data-chips]', root);
    const entry = $('[data-tag-entry]', root);
    const sug = $('[data-suggest]', root);
    const render = () => {
      const tags = hidden.value.split(',').map((t) => t.trim()).filter(Boolean);
      chips.innerHTML = tags.map((t) => '<span class="chip" data-name="' + esc(t) + '">' + esc(t) + '<button type="button" aria-label="Remove">×</button></span>').join('');
    };
    const add = (name) => {
      const tags = hidden.value.split(',').map((t) => t.trim()).filter(Boolean);
      name.split(',').map((t) => t.trim()).filter(Boolean).forEach((t) => { if (!tags.some((x) => x.toLowerCase() === t.toLowerCase())) tags.push(t); });
      hidden.value = tags.join(',');
      render();
    };
    chips.addEventListener('click', (e) => {
      if (e.target.tagName !== 'BUTTON') return;
      const name = e.target.parentElement.dataset.name;
      hidden.value = hidden.value.split(',').map((t) => t.trim()).filter((t) => t && t !== name).join(',');
      render();
    });
    entry.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add(entry.value); entry.value = ''; sug.hidden = true; }
    });
    entry.addEventListener('blur', () => setTimeout(() => { if (entry.value.trim()) { add(entry.value); entry.value = ''; } sug.hidden = true; }, 150));
    entry.addEventListener('input', debounce(async () => {
      const q = entry.value.trim();
      if (q.length < 1) { sug.hidden = true; return; }
      const r = await api('/terms?taxonomy=' + encodeURIComponent(entry.dataset.taxonomy) + '&q=' + encodeURIComponent(q));
      sug.innerHTML = (r.items || []).map((t) => '<button type="button">' + esc(t.name) + '</button>').join('');
      sug.hidden = !(r.items || []).length;
    }));
    sug.addEventListener('mousedown', (e) => { const b = e.target.closest('button'); if (b) { e.preventDefault(); add(b.textContent); entry.value = ''; sug.hidden = true; } });
    render();
  }
  $$('[data-tags]').forEach(initTags);

  // ---------- Quick-add hierarchical term ----------
  $$('[data-add-term]').forEach((box) => {
    const input = $('[data-new-term]', box);
    input.addEventListener('keydown', (e) => {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      const name = input.value.trim();
      if (!name) return;
      const list = box.previousElementSibling;
      list.insertAdjacentHTML('beforeend', '<label class="choice"><input type="checkbox" name="terms[' + esc(box.dataset.addTerm) + '][]" value="' + esc(name) + '" checked> ' + esc(name) + ' <small class="muted">(new)</small></label>');
      input.value = '';
    });
  });

  // ---------- Repeater fields ----------
  function initRepeater(root) {
    const store = $('[data-repeater-value]', root);
    const schema = JSON.parse($('[data-repeater-schema]', root).textContent || '[]');
    const rowsEl = $('[data-rows]', root);
    let rows = [];
    try { rows = JSON.parse(store.value || '[]'); } catch (e) { rows = []; }
    const inputFor = (f, val, ri) => {
      const v = esc(val ?? '');
      const attrs = 'data-r="' + ri + '" data-f="' + esc(f.name) + '"';
      if (f.type === 'richtext') return '<div class="rr-rich"><div class="rr-rich-bar"><button type="button" data-rt="bold" title="Bold"><b>B</b></button><button type="button" data-rt="italic" title="Italic"><i>I</i></button><button type="button" data-rt="insertUnorderedList" title="List">•≡</button><button type="button" data-rt="createLink" title="Link">🔗</button><button type="button" data-rt="unlink" title="Remove link">⛓︎</button></div><div class="rr-rich-area input" contenteditable="true" ' + attrs + '>' + (val ?? '') + '</div></div>';
      if (f.type === 'textarea') return '<textarea class="input" rows="3" ' + attrs + '>' + v + '</textarea>';
      if (f.type === 'select') return '<select class="input" ' + attrs + '><option value=""></option>' + (f.options || []).map((o) => { const [k, l] = o.includes(':') ? o.split(':') : [o, o]; return '<option value="' + esc(k.trim()) + '"' + (String(val) === k.trim() ? ' selected' : '') + '>' + esc(l.trim()) + '</option>'; }).join('') + '</select>';
      if (f.type === 'toggle') return '<input type="checkbox" class="switch" ' + attrs + (val ? ' checked' : '') + '>';
      if (f.type === 'image') return '<span class="input-group"><input class="input" type="number" placeholder="Media ID" ' + attrs + ' value="' + v + '"><button type="button" class="btn btn-sm" data-pick-into>Choose</button></span>';
      const type = { number: 'number', email: 'email', url: 'url', date: 'date', color: 'color' }[f.type] || 'text';
      return '<input class="input" type="' + type + '" ' + attrs + ' value="' + v + '">';
    };
    const render = () => {
      rowsEl.innerHTML = rows.map((row, ri) => '<div class="repeater-row"><div class="rr-actions"><button type="button" class="icon-btn" data-up="' + ri + '" title="Move up" aria-label="Move up"><svg class="ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg></button><button type="button" class="icon-btn" data-del="' + ri + '" title="Remove" aria-label="Remove"><svg class="ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button></div>' +
        schema.map((f) => '<label class="field"' + (f.show_if ? ' data-show-if="' + esc(f.show_if.field) + '" data-show-in="' + esc((f.show_if.in || []).join(',')) + '"' : '') + (f.type === 'richtext' ? ' style="grid-column:1/-1"' : '') + '><span>' + esc(f.label) + '</span>' + inputFor(f, row[f.name], ri) + '</label>').join('') + '</div>').join('');
      store.value = JSON.stringify(rows);
      applyShowIf();
    };
    // Sub-fields shown only for some values of another sub-field (e.g. "Choices" for dropdowns).
    const applyShowIf = () => {
      $$('.repeater-row', rowsEl).forEach((rowEl, ri) => {
        $$('[data-show-if]', rowEl).forEach((lab) => { lab.hidden = !lab.dataset.showIn.split(',').includes(String(rows[ri][lab.dataset.showIf] ?? '')); });
      });
    };
    rowsEl.addEventListener('mousedown', (e) => { if (e.target.closest('[data-rt]')) e.preventDefault(); });
    rowsEl.addEventListener('click', (e) => {
      const b = e.target.closest('[data-rt]');
      if (!b) return;
      const area = b.closest('.rr-rich').querySelector('.rr-rich-area');
      area.focus();
      if (b.dataset.rt === 'createLink') { const u = prompt('Link URL:', 'https://'); if (u && !/^\s*javascript:/i.test(u)) document.execCommand('createLink', false, u); }
      else document.execCommand(b.dataset.rt);
      area.dispatchEvent(new Event('input', { bubbles: true }));
    });
    rowsEl.addEventListener('input', (e) => {
      const el = e.target;
      if (el.dataset.r === undefined) return;
      rows[el.dataset.r][el.dataset.f] = el.type === 'checkbox' ? el.checked : (el.isContentEditable ? el.innerHTML.replace(/^<br>$/, '') : el.value);
      store.value = JSON.stringify(rows);
      if (el.tagName === 'SELECT') applyShowIf();
    });
    rowsEl.addEventListener('change', (e) => rowsEl.dispatchEvent(new Event('input', { bubbles: true })) && e);
    rowsEl.addEventListener('click', async (e) => {
      const del = e.target.closest('[data-del]'), up = e.target.closest('[data-up]'), pick = e.target.closest('[data-pick-into]');
      if (del) { rows.splice(+del.dataset.del, 1); render(); }
      if (up && +up.dataset.up > 0) { const i = +up.dataset.up; [rows[i - 1], rows[i]] = [rows[i], rows[i - 1]]; render(); }
      if (pick && window.Slate.pickMedia) {
        const items = await window.Slate.pickMedia({ multiple: false, kind: 'image' });
        if (items && items[0]) { const inp = pick.previousElementSibling; inp.value = items[0].id; inp.dispatchEvent(new Event('input', { bubbles: true })); }
      }
    });
    $('[data-repeater-add]', root).addEventListener('click', () => { rows.push({}); render(); });
    render();
  }
  $$('[data-repeater]').forEach(initRepeater);

  // ---------- Field tabs (a "Tab" field groups every field after it) ----------
  $$('.field-grid').forEach((grid) => {
    const markers = $$(':scope > .cf-tab-marker', grid);
    if (!markers.length) return;
    const bar = document.createElement('div');
    bar.className = 'cf-tabs';
    bar.setAttribute('role', 'tablist');
    const panes = [];
    let pane = null;
    Array.from(grid.children).forEach((el) => {
      if (el.classList.contains('cf-tab-marker')) {
        pane = document.createElement('div');
        pane.className = 'field-grid cf-pane';
        panes.push({ label: el.dataset.tabLabel || 'Tab', pane });
        el.replaceWith(pane);
        return;
      }
      if (pane) pane.append(el);
    });
    const show = (i) => {
      panes.forEach((p, j) => { p.pane.hidden = i !== j; });
      $$('button', bar).forEach((b, j) => { b.classList.toggle('active', i === j); b.setAttribute('aria-selected', String(i === j)); });
    };
    bar.innerHTML = panes.map((p, i) => '<button type="button" role="tab" data-tab="' + i + '">' + esc(p.label) + '</button>').join('');
    bar.addEventListener('click', (e) => { const b = e.target.closest('[data-tab]'); if (b) show(+b.dataset.tab); });
    grid.classList.add('has-tabs');
    grid.prepend(bar);
    show(0);
  });

  // ---------- Filter long term lists ----------
  $$('[data-term-filter]').forEach((box) => {
    const inp = $('[data-term-filter-input]', box);
    inp.addEventListener('input', () => {
      const q = inp.value.trim().toLowerCase();
      $$('.choice', box).forEach((c) => { c.hidden = q !== '' && !c.textContent.toLowerCase().includes(q); });
    });
  });

  // ---------- Sidebar groups (expand without navigating) ----------
  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-nav-toggle]');
    if (!t) return;
    e.preventDefault(); e.stopPropagation();
    t.closest('.nav-group').classList.toggle('open');
  });

  // ---------- Quick settings switches (dashboard, settings overview) ----------
  const postForm = (url, data) => {
    const fd = new FormData();
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    fd.append('_csrf', S.csrf);
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': S.csrf, 'X-Requested-With': 'XMLHttpRequest' } })
      .then((r) => r.json().catch(() => ({ ok: false, error: 'Unexpected response (' + r.status + ')' })));
  };
  $$('[data-quick-settings]').forEach((box) => {
    box.addEventListener('change', async (e) => {
      const sw = e.target.closest('[data-quick]');
      if (!sw) return;
      sw.disabled = true;
      try {
        const d = await postForm(box.dataset.url, { key: sw.dataset.quick, on: sw.checked ? '1' : '0' });
        if (!d.ok) throw new Error(d.error || 'Could not save.');
        toast(d.message);
        setTimeout(() => location.reload(), 800);
      } catch (err) { sw.checked = !sw.checked; toast(err.message, 'error'); }
      sw.disabled = false;
    });
  });

  // ---------- Settings search ----------
  $$('[data-settings-search]').forEach((inp) => {
    const index = JSON.parse(inp.dataset.index || '[]');
    const box = inp.parentElement.querySelector('.settings-search-results');
    const show = () => {
      const q = inp.value.trim().toLowerCase();
      if (q.length < 2) { box.hidden = true; return; }
      const words = q.split(/\s+/);
      const hits = index.filter((r) => { const hay = (r[0] + ' ' + r[1] + ' ' + r[2] + ' ' + r[3]).toLowerCase(); return words.every((w) => hay.includes(w)); }).slice(0, 12);
      box.innerHTML = hits.length
        ? hits.map((r) => '<a href="' + esc(inp.dataset.base + '/' + r[4]) + '"><strong>' + esc(r[0]) + '</strong><small>' + esc(r[2] + (r[3] ? ' › ' + r[3] : '')) + '</small></a>').join('')
        : '<div class="muted small">No setting matches “' + esc(inp.value) + '”.</div>';
      box.hidden = false;
    };
    inp.addEventListener('input', debounce(show, 80));
    inp.addEventListener('focus', show);
    inp.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { const a = $('a', box); if (a) { e.preventDefault(); location.href = a.href; } }
      if (e.key === 'Escape') box.hidden = true;
    });
    document.addEventListener('click', (e) => { if (!e.target.closest('.settings-search')) box.hidden = true; });
  });

  // ---------- List tables ----------
  $$('[data-lt]').forEach((lt) => {
    const id = lt.dataset.lt;
    const save = (data) => fetch(lt.dataset.prefs, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': S.csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(Object.assign({ id }, data)) });
    const table = $('.lt-table', lt);
    const menu = $('[data-lt-colmenu]', lt);
    const order = () => (menu ? $$('.lt-colitem', menu).map((l) => l.dataset.col) : []);
    const visible = () => (menu ? $$('.lt-colitem input:checked', menu).map((i) => i.value) : []);
    const persist = () => save({ cols: order().filter((k) => visible().includes(k)) });
    const apply = () => {
      if (!table) return;
      const vis = visible();
      const ord = order();
      $$('tr', table).forEach((tr) => {
        const cells = $$('[data-col]', tr);
        cells.forEach((c) => { c.hidden = !vis.includes(c.dataset.col); });
        ord.forEach((k) => { const c = cells.find((x) => x.dataset.col === k); if (c) tr.appendChild(c); });
      });
    };
    if (menu) {
      menu.addEventListener('click', (e) => e.stopPropagation());
      menu.addEventListener('change', () => { apply(); persist(); });
      let drag = null;
      $$('.lt-colitem', menu).forEach((it) => {
        it.addEventListener('dragstart', (e) => { drag = it; it.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
        it.addEventListener('dragend', () => { it.classList.remove('dragging'); drag = null; apply(); persist(); });
        it.addEventListener('dragover', (e) => {
          e.preventDefault();
          if (!drag || drag === it) return;
          const r = it.getBoundingClientRect();
          it.parentElement.insertBefore(drag, e.clientY > r.top + r.height / 2 ? it.nextSibling : it);
        });
      });
    }
    const reset = $('[data-lt-reset]', lt);
    if (reset) reset.addEventListener('click', async () => { await save({ reset: 1 }); location.reload(); });
    const dens = $('[data-lt-density]', lt);
    if (dens) dens.addEventListener('click', () => {
      const next = dens.dataset.ltDensity === 'compact' ? 'comfortable' : 'compact';
      dens.dataset.ltDensity = next;
      if (table) { table.classList.toggle('density-compact', next === 'compact'); table.classList.toggle('density-comfortable', next !== 'compact'); }
      save({ density: next });
    });
    const per = $('[data-lt-per]', lt);
    if (per) per.addEventListener('change', () => {
      const u = new URL(location.href); u.searchParams.set('per', per.value); u.searchParams.delete('p'); location.href = u.toString();
    });
    $$('[data-autosubmit]', lt).forEach((sel) => sel.addEventListener('change', () => sel.form.submit()));
    $$('.lt-filter[list]', lt).forEach((inp) => inp.addEventListener('change', () => inp.form.submit()));
    const all = $('[data-bulk-all]', lt);
    if (all) all.addEventListener('change', () => {
      $$('input[name="ids[]"]', lt).forEach((c) => { c.checked = all.checked; });
      const head = $('[data-check-all]', lt); if (head) head.checked = all.checked;
      const cnt = $('.bulk-count', lt);
      if (cnt && all.checked) setTimeout(() => { cnt.textContent = all.parentElement.textContent.replace('Select ', '').trim() + ' selected'; }, 0);
    });
  });

  // ---------- Quick edit (content lists) ----------
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-quick-edit]');
    if (!b) return;
    e.preventDefault();
    const tpl = $('#quick-edit-tpl');
    if (!tpl) return;
    const d = JSON.parse(b.dataset.quickEdit);
    const form = tpl.content.firstElementChild.cloneNode(true);
    form.action = d.action;
    form.elements.title.value = d.title || '';
    form.elements.slug.value = d.slug || '';
    form.elements.status.value = d.status;
    form.elements.published_at.value = d.date || '';
    modal({ title: 'Quick edit', body: form, foot: null });
    setTimeout(() => form.elements.title.focus(), 30);
  });

  // ---------- Private dashboard domain ----------
  $$('[data-admin-host]').forEach((box) => {
    const inp = $('input', box);
    const wrap = box.parentElement;
    const res = $('[data-admin-host-result]', wrap);
    const sw = $('[data-admin-host-switch]', wrap);
    const bridge = $('[data-admin-host-bridge]', box);
    const host = () => inp.value.trim() || inp.placeholder;
    const sync = () => { bridge.href = bridge.href.split('?')[0] + '?host=' + encodeURIComponent(host()); };
    inp.addEventListener('input', () => { sync(); sw.hidden = true; res.hidden = true; });
    inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('[data-admin-host-check]', box).click(); } });
    sync();
    $('[data-admin-host-check]', box).addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      btn.disabled = true;
      res.hidden = false; res.className = 'alert'; res.textContent = 'Checking ' + host() + '…';
      try {
        const d = await postForm(box.dataset.check, { host: host() });
        res.className = 'alert ' + (d.ok ? 'alert-success' : 'alert-error');
        res.textContent = d.message || d.error;
        sw.hidden = !d.ok;
        $('input[name="host"]', sw).value = host();
      } catch (err) { res.className = 'alert alert-error'; res.textContent = String(err); }
      btn.disabled = false;
    });
  });

  // ---------- Module lists: search + All/Active/Inactive/Updates ----------
  (() => {
    const q = $('[data-module-filter]');
    const views = $('[data-module-view]');
    if (!q && !views) return;
    let view = 'all';
    const run = () => {
      const term = q ? q.value.trim().toLowerCase() : '';
      $$('[data-module-card]').forEach((c) => {
        const st = (c.dataset.state || '').split(' ');
        c.hidden = (term !== '' && !c.dataset.moduleCard.includes(term)) || (view !== 'all' && !st.includes(view));
      });
    };
    if (q) q.addEventListener('input', run);
    if (views) views.addEventListener('click', (e) => {
      const b = e.target.closest('[data-view]');
      if (!b) return;
      view = b.dataset.view;
      $$('[data-view]', views).forEach((x) => x.classList.toggle('active', x === b));
      run();
    });
  })();

  // ---------- Small screens: show/hide list filters ----------
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-lt-filter-toggle]');
    if (b) b.closest('.lt-filters').classList.toggle('show-filters');
  });

  // ---------- File fields: highlight while a file is dragged over ----------
  document.addEventListener('dragenter', (e) => { if (e.target.matches?.('input[type=file]')) e.target.classList.add('is-over'); });
  ['dragleave', 'drop'].forEach((ev) => document.addEventListener(ev, (e) => { if (e.target.matches?.('input[type=file]')) e.target.classList.remove('is-over'); }));

  // ---------- Import pages: zip, files or a whole folder (relative paths kept) ----------
  $$('[data-import-form]').forEach((form) => {
    const files = $('[data-import-files]', form);
    const folder = $('[data-import-folder]', form);
    const drop = $('[data-import-drop]', form);
    const picked = $('[data-import-picked]', form);
    const msg = $('[data-import-msg]', form);
    let chosen = [];
    const show = () => { picked.textContent = chosen.length ? chosen.length + ' file' + (chosen.length === 1 ? '' : 's') + ' ready' : ''; };
    const take = (list) => { chosen = Array.from(list).map((f) => ({ file: f, path: f.webkitRelativePath || f._path || f.name })); show(); };
    files.addEventListener('change', () => take(files.files));
    folder.addEventListener('change', () => take(folder.files));
    ['dragover', 'dragenter'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.remove('is-over')));
    drop.addEventListener('drop', async (e) => {
      e.preventDefault();
      const items = Array.from(e.dataTransfer.items || []).map((i) => i.webkitGetAsEntry && i.webkitGetAsEntry()).filter(Boolean);
      if (!items.length) { take(e.dataTransfer.files); return; }
      const out = [];
      const walk = (entry, prefix) => new Promise((resolve) => {
        if (entry.isFile) entry.file((f) => { f._path = prefix + f.name; out.push(f); resolve(); });
        else {
          const reader = entry.createReader();
          const all = [];
          const read = () => reader.readEntries(async (batch) => {
            if (!batch.length) { for (const c of all) await walk(c, prefix + entry.name + '/'); resolve(); return; }
            all.push(...batch); read();
          });
          read();
        }
      });
      for (const it of items) await walk(it, '');
      take(out);
    });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!chosen.length) { msg.hidden = false; msg.className = 'alert alert-error'; msg.textContent = 'Choose a .zip, some files or a folder first.'; return; }
      const fd = new FormData();
      fd.append('_csrf', S.csrf);
      chosen.forEach((c) => fd.append('files[]', c.file, c.path));
      const btn = $('[data-import-go]', form);
      btn.disabled = true;
      msg.hidden = false; msg.className = 'alert'; msg.textContent = 'Uploading ' + chosen.length + ' file(s)…';
      try {
        const r = await fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': S.csrf } });
        const d = await r.json().catch(() => ({ ok: false, error: 'Upload failed (' + r.status + '). The files may be larger than the server allows.' }));
        if (!d.ok) throw new Error(d.error || 'Upload failed.');
        location.href = d.next;
      } catch (err) { msg.className = 'alert alert-error'; msg.textContent = err.message; btn.disabled = false; }
    });
  });
  $$('[data-check-all-import]').forEach((all) => all.addEventListener('change', () => {
    $$('input[name="paths[]"]', all.closest('form')).forEach((c) => { c.checked = all.checked; });
  }));

  // ---------- Goraiya: the AI drawer ----------
  (() => {
    const box = $('#goraiya');
    if (!box) return;
    const log = $('[data-goraiya-log]', box);
    const form = $('[data-goraiya-form]', box);
    const text = $('[data-goraiya-text]', box);
    const welcome = $('[data-goraiya-welcome]', box);
    const store = 'goraiya_' + box.dataset.user;
    let history = [];
    try { history = JSON.parse(sessionStorage.getItem(store) || '[]'); } catch (e) { history = []; }
    const keep = () => { try { sessionStorage.setItem(store, JSON.stringify(history.slice(-30))); } catch (e) {} };
    const open = () => { box.hidden = false; document.documentElement.classList.add('goraiya-open'); setTimeout(() => text && !form.hidden && text.focus(), 50); };
    const close = () => { box.hidden = true; document.documentElement.classList.remove('goraiya-open'); };
    document.addEventListener('click', (e) => {
      if (e.target.closest('[data-goraiya-open]')) { box.hidden ? open() : close(); }
      else if (e.target.closest('[data-goraiya-close]')) close();
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !box.hidden) close();
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'j') { e.preventDefault(); box.hidden ? open() : close(); }
    });
    const bubble = (role, html) => {
      const el = document.createElement('div');
      el.className = 'goraiya-msg goraiya-' + role;
      el.innerHTML = html;
      log.appendChild(el);
      log.scrollTop = log.scrollHeight;
      return el;
    };
    const resultCard = (r) => r.ok
      ? '<div class="goraiya-result"><strong>' + esc(r.title || '') + '</strong><small>' + esc(r.message || '') + '</small><span>'
        + (r.preview ? '<a class="btn btn-sm btn-primary" href="' + esc(r.preview) + '" target="_blank" rel="noopener">Preview and edit</a>' : '')
        + (r.edit ? '<a class="btn btn-sm btn-ghost" href="' + esc(r.edit) + '">Open</a>' : '') + '</span></div>'
      : '<div class="goraiya-result is-error">' + esc(r.error || 'That did not work.') + '</div>';
    const replay = () => history.forEach((m) => bubble(m.role, m.role === 'user' ? esc(m.text) : esc(m.text).replace(/\n/g, '<br>') + (m.results || []).map(resultCard).join('')));
    if (history.length) { welcome.hidden = true; replay(); }
    $('[data-goraiya-new]', box).addEventListener('click', () => {
      history = []; keep();
      $$('.goraiya-msg', log).forEach((m) => m.remove());
      if (box.dataset.ready === '1') welcome.hidden = false;
    });
    $$('[data-goraiya-chip]', box).forEach((c) => c.addEventListener('click', () => { text.value = c.textContent; text.focus(); }));
    const send = async () => {
      const msg = text.value.trim();
      if (!msg) return;
      welcome.hidden = true;
      bubble('user', esc(msg));
      text.value = '';
      const wait = bubble('assistant', '<span class="goraiya-typing"><i></i><i></i><i></i></span> Working on it… a full page can take a minute.');
      const btn = $('button[type=submit]', form);
      btn.disabled = true;
      try {
        const r = await fetch(box.dataset.url, { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': S.csrf, 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify({ message: msg, history: history.map((h) => ({ role: h.role, text: h.text })), context: { post_id: +box.dataset.post || 0, path: location.pathname } }) });
        const d = await r.json().catch(() => ({ ok: false, error: 'Unexpected response (' + r.status + ').' }));
        if (!d.ok) throw new Error(d.error || 'Goraiya could not answer.');
        wait.innerHTML = esc(d.reply || 'Done.').replace(/\n/g, '<br>') + (d.results || []).map(resultCard).join('');
        history.push({ role: 'user', text: msg }, { role: 'assistant', text: d.reply || '', results: d.results || [] });
        keep();
      } catch (err) {
        wait.classList.add('is-error');
        wait.textContent = err.message;
      }
      btn.disabled = false;
      log.scrollTop = log.scrollHeight;
    };
    form.addEventListener('submit', (e) => { e.preventDefault(); send(); });
    text.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });

    // Quick setup
    const setup = $('[data-goraiya-setup]', box);
    if (setup) {
      const prov = $('[data-goraiya-provider]', setup);
      const keys = $('[data-goraiya-keys]', setup);
      const sync = () => { if (!prov || !keys) return; const u = prov.selectedOptions[0].dataset.keys; keys.href = u || '#'; keys.hidden = !u; };
      if (prov) { prov.addEventListener('change', sync); sync(); }
      const go = $('[data-goraiya-connect]', setup);
      if (go) go.addEventListener('click', async () => {
        const msgEl = $('[data-goraiya-setup-msg]', setup);
        go.disabled = true; msgEl.hidden = false; msgEl.className = 'alert'; msgEl.textContent = 'Checking the connection…';
        try {
          const r = await fetch(box.dataset.connect, { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': S.csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ provider: prov.value, key: $('[data-goraiya-key]', setup).value }) });
          const d = await r.json().catch(() => ({ ok: false, error: 'Unexpected response.' }));
          if (!d.ok) throw new Error(d.error);
          setup.remove(); box.dataset.ready = '1'; welcome.hidden = false; form.hidden = false; text.focus();
          toast('Goraiya is ready.');
        } catch (err) { msgEl.className = 'alert alert-error'; msgEl.textContent = err.message; go.disabled = false; }
      });
    }
    if (location.hash === '#goraiya') open();
    document.addEventListener('click', (e) => {
      const b = e.target.closest('[data-goraiya-ask]');
      if (!b) return;
      open();
      if (!form.hidden) { text.value = b.dataset.goraiyaAsk; text.focus(); }
    });
  })();
})();
