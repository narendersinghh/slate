/* Slate admin — core UI behaviours (vanilla JS, no dependencies). */
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
        const [key, val] = row.dataset.showIf.split('=');
        const ctl = $('[data-key="' + key + '"]', settingsForm);
        row.hidden = ctl ? (ctl.type === 'checkbox' ? String(ctl.checked ? 1 : 0) !== val : ctl.value !== val) : false;
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
      $('[data-ai-model]').textContent = p.model || '—';
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
      if (f.type === 'textarea' || f.type === 'richtext') return '<textarea class="input" rows="3" ' + attrs + '>' + v + '</textarea>';
      if (f.type === 'select') return '<select class="input" ' + attrs + '><option value=""></option>' + (f.options || []).map((o) => { const [k, l] = o.includes(':') ? o.split(':') : [o, o]; return '<option value="' + esc(k.trim()) + '"' + (String(val) === k.trim() ? ' selected' : '') + '>' + esc(l.trim()) + '</option>'; }).join('') + '</select>';
      if (f.type === 'toggle') return '<input type="checkbox" class="switch" ' + attrs + (val ? ' checked' : '') + '>';
      if (f.type === 'image') return '<span class="input-group"><input class="input" type="number" placeholder="Media ID" ' + attrs + ' value="' + v + '"><button type="button" class="btn btn-sm" data-pick-into>Choose</button></span>';
      const type = { number: 'number', email: 'email', url: 'url', date: 'date', color: 'color' }[f.type] || 'text';
      return '<input class="input" type="' + type + '" ' + attrs + ' value="' + v + '">';
    };
    const render = () => {
      rowsEl.innerHTML = rows.map((row, ri) => '<div class="repeater-row"><div class="rr-actions"><button type="button" class="icon-btn" data-up="' + ri + '" title="Move up">↑</button><button type="button" class="icon-btn" data-del="' + ri + '" title="Remove">✕</button></div>' +
        schema.map((f) => '<label class="field"><span>' + esc(f.label) + '</span>' + inputFor(f, row[f.name], ri) + '</label>').join('') + '</div>').join('');
      store.value = JSON.stringify(rows);
    };
    rowsEl.addEventListener('input', (e) => {
      const el = e.target;
      if (el.dataset.r === undefined) return;
      rows[el.dataset.r][el.dataset.f] = el.type === 'checkbox' ? el.checked : el.value;
      store.value = JSON.stringify(rows);
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
})();
