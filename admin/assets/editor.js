/* Slate content editor: rich text, autosave UX, SEO analysis, AI assistant. */
(function () {
  'use strict';
  const { api, toast, modal, esc, $, $$, debounce, slugify } = window.Slate;
  const S = window.SLATE;

  // ---------- Toolbar icons (24px stroke set, same style as core/Slate/Icons.php) ----------
  const ICONS = {
    bold: '<path d="M7 5h6a3.5 3.5 0 0 1 0 7H7zM7 12h7a3.5 3.5 0 0 1 0 7H7z"/>',
    italic: '<path d="M19 4h-9M14 20H5M15 4 9 20"/>',
    underline: '<path d="M6 4v6a6 6 0 0 0 12 0V4M4 20h16"/>',
    strike: '<path d="M16 6.5A4 4 0 0 0 12.5 5H11a3.5 3.5 0 0 0 0 7h2a3.5 3.5 0 0 1 0 7h-1.5A4 4 0 0 1 8 17.5M4 12h16"/>',
    list: '<path d="M9 6h12M9 12h12M9 18h12"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
    'list-ordered': '<path d="M10 6h11M10 12h11M10 18h11M4 4h1v4M4 8h2M4.5 14H6l-2 3h2"/>',
    quote: '<path d="M3 21c3 0 7-1 7-8V5H3v7h4M14 21c3 0 7-1 7-8V5h-7v7h4"/>',
    link: '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
    unlink: '<path d="m18.8 13.4 1.7-1.7a5 5 0 0 0-7.1-7.1l-1.7 1.7M5.2 10.6l-1.7 1.7a5 5 0 0 0 7.1 7.1l1.7-1.7M8 2v3M2 8h3M16 22v-3M22 16h-3"/>',
    image: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/>',
    video: '<rect x="2" y="5" width="20" height="14" rx="3"/><path d="m10 9 5 3-5 3z"/>',
    table: '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18M15 3v18"/>',
    divider: '<path d="M3 12h18M8 6h8M8 18h8"/>',
    form: '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 8h10M7 12h10M7 16h5"/>',
    eraser: '<path d="m7 21-4.3-4.3a1 1 0 0 1 0-1.4l10-10a1 1 0 0 1 1.4 0l5.6 5.6a1 1 0 0 1 0 1.4L12 20.6a1 1 0 0 1-.7.3zM22 21H7M5 11l9 9"/>',
    undo: '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
    redo: '<path d="m15 14 5-5-5-5"/><path d="M20 9H9.5a5.5 5.5 0 0 0 0 11H13"/>',
    code: '<path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    external: '<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    check: '<path d="M20 6 9 17l-5-5"/>',
  };
  const svg = (name, size = 17) => '<svg class="ic" width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[name] || '') + '</svg>';

  // ---------- HTML paste cleaner ----------
  const ALLOWED = { P: [], BR: [], STRONG: [], B: [], EM: [], I: [], U: [], S: [], A: ['href', 'target'], UL: [], OL: [], LI: [], H2: [], H3: [], H4: [],
    BLOCKQUOTE: [], PRE: [], CODE: [], IMG: ['src', 'alt', 'width', 'height'], TABLE: [], THEAD: [], TBODY: [], TR: [], TD: [], TH: [], HR: [], FIGURE: [], FIGCAPTION: [] };
  function cleanHtml(html) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const walk = (node) => {
      Array.from(node.childNodes).forEach((child) => {
        if (child.nodeType === 8) { child.remove(); return; }
        if (child.nodeType !== 1) return;
        let tag = child.tagName;
        if (tag === 'H1') { const h = doc.createElement('h2'); h.innerHTML = child.innerHTML; child.replaceWith(h); child = h; tag = 'H2'; }
        if (['SCRIPT', 'STYLE', 'META', 'LINK', 'IFRAME', 'OBJECT'].includes(tag)) { child.remove(); return; }
        walk(child);
        if (!ALLOWED[tag]) { child.replaceWith(...child.childNodes); return; }
        Array.from(child.attributes).forEach((a) => { if (!ALLOWED[tag].includes(a.name)) child.removeAttribute(a.name); });
        if (tag === 'A' && /^\s*javascript:/i.test(child.getAttribute('href') || '')) child.removeAttribute('href');
      });
    };
    walk(doc.body);
    return doc.body.innerHTML.replace(/<p>\s*<\/p>/g, '');
  }

  // ---------- Form picker (Forms module) ----------
  function formWidget(f) {
    return '<div class="slate-form-widget ed-form-widget" data-slate-form-widget="' + (+f.id) + '" contenteditable="false" title="Form: click to change or edit it">' + esc(f.title) + ' [form id="' + (+f.id) + '"]</div>';
  }
  function pickerStyles() {
    if (document.getElementById('ed-fp-css')) return;
    const st = document.createElement('style');
    st.id = 'ed-fp-css';
    st.textContent = [
      '.ed-form-widget{cursor:pointer;display:flex!important;align-items:center;gap:10px}',
      '.ed-form-widget::before{content:""!important;flex:0 0 20px;height:20px;background:var(--accent);-webkit-mask:var(--ed-fp-icon) center/20px no-repeat;mask:var(--ed-fp-icon) center/20px no-repeat}',
      '.ed-form-widget:hover{background:var(--accent-soft-2,var(--accent-soft))}',
      ':root{--ed-fp-icon:url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 24 24%27 fill=%27none%27 stroke=%27black%27 stroke-width=%272%27 stroke-linecap=%27round%27%3E%3Crect x=%273%27 y=%273%27 width=%2718%27 height=%2718%27 rx=%272%27/%3E%3Cpath d=%27M7 8h10M7 12h10M7 16h5%27/%3E%3C/svg%3E")}',
      '.ed-fp{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.15fr);gap:16px;min-height:440px}',
      '.ed-fp-side{display:flex;flex-direction:column;gap:10px;min-width:0}',
      '.ed-fp-search{position:relative}.ed-fp-search .ic{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted)}.ed-fp-search .input{padding-left:36px;width:100%}',
      '.ed-fp-list{display:flex;flex-direction:column;gap:4px;overflow:auto;max-height:360px;padding:2px}',
      '.ed-fp-item{display:flex;align-items:center;gap:10px;width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--surface);color:var(--text);text-align:left;cursor:pointer;font:inherit}',
      '.ed-fp-item:hover{border-color:var(--border-strong);background:var(--surface-2)}',
      '.ed-fp-item.is-on{border-color:var(--accent);background:var(--accent-soft)}',
      '.ed-fp-item strong{display:block;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
      '.ed-fp-item small{display:block;color:var(--muted);font-size:12.5px;margin-top:1px}',
      '.ed-fp-item .ed-fp-main{min-width:0;flex:1}',
      '.ed-fp-check{flex:0 0 18px;height:18px;border-radius:50%;border:1.5px solid var(--border-strong);display:grid;place-items:center;color:var(--on-accent,#fff)}',
      '.ed-fp-item.is-on .ed-fp-check{background:var(--accent);border-color:var(--accent)}',
      '.ed-fp-preview{display:flex;flex-direction:column;border:1px solid var(--border);border-radius:12px;overflow:hidden;background:var(--surface);min-width:0}',
      '.ed-fp-preview-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 12px;border-bottom:1px solid var(--border);font-size:13px;color:var(--muted)}',
      '.ed-fp-preview iframe{flex:1;width:100%;min-height:380px;border:0;background:#fff}',
      '.ed-fp-empty{padding:30px 16px;text-align:center;color:var(--muted)}',
      '.ed-fp-new{display:flex;flex-direction:column;gap:12px;padding:14px;border:1px dashed var(--border-strong);border-radius:12px}',
      '.ed-fp-tpls{display:flex;flex-wrap:wrap;gap:6px}',
      '.ed-fp-tpls label{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid var(--border);border-radius:99px;cursor:pointer;font-size:13px;background:var(--surface)}',
      '.ed-fp-tpls label:has(input:checked){border-color:var(--accent);background:var(--accent-soft);color:var(--accent-text)}',
      '.ed-fp-tpls input{position:absolute;opacity:0;pointer-events:none}',
      '.ed-fp-row{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap}',
      '.modal-lg .modal-box{width:min(980px,100%)}',
      '@media (max-width:760px){.ed-fp{grid-template-columns:1fr;min-height:0}.ed-fp-preview iframe{min-height:300px}.ed-fp-list{max-height:240px}}',
    ].join('\n');
    document.head.appendChild(st);
  }
  /** Search, preview and pick a form, or create one from a template. Resolves to {id, title} or null. */
  async function pickForm(current) {
    pickerStyles();
    let data;
    const res = await fetch(S.admin + '/m/forms/picker', { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then((r) => r.ok ? r.json() : null).catch(() => null);
    let items, templates = [], canCreate = false;
    if (res && res.ok) { items = res.items; templates = res.templates || []; canCreate = !!res.can_create; }
    else {
      // Older Forms module: published forms from the content API, no preview.
      data = await api('/posts?type=form');
      items = (data.items || []).filter((f) => f.status === 'publish').map((f) => ({ id: f.id, title: f.title, status: f.status, fields: null, entries: null }));
    }
    let sel = items.find((f) => String(f.id) === String(current) || f.slug === current) || items.find((f) => f.status === 'publish') || items[0] || null;
    let creating = !items.length && canCreate;
    const body = document.createElement('div');
    const m = modal({ title: current ? 'Change form' : 'Insert a form', body, size: 'lg',
      foot: '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-fp-insert>' + (current ? 'Use this form' : 'Insert form') + '</button>' });
    const insertBtn = $('[data-fp-insert]', m);
    let q = '';
    const meta = (f) => [f.fields != null ? f.fields + ' field' + (f.fields === 1 ? '' : 's') : '', f.entries != null ? f.entries + ' entr' + (f.entries === 1 ? 'y' : 'ies') : '', f.status !== 'publish' ? 'Draft' : ''].filter(Boolean).join(' · ');
    const draw = () => {
      const list = items.filter((f) => !q || (f.title + ' ' + (f.slug || '') + ' ' + (f.labels || []).join(' ')).toLowerCase().includes(q));
      const side = creating
        ? '<div class="ed-fp-new"><strong>New form</strong><label class="field"><span>Name</span><input class="input" data-fp-name placeholder="e.g. Contact us" maxlength="190"></label>' +
          '<div><span class="small-label">Start from</span><div class="ed-fp-tpls">' + templates.map((t, i) => '<label title="' + esc(t.description) + '"><input type="radio" name="ed-fp-tpl" value="' + esc(t.key) + '"' + (i === 1 ? ' checked' : '') + '>' + esc(t.title) + '</label>').join('') + '</div></div>' +
          '<div class="ed-fp-row">' + (items.length ? '<button type="button" class="btn btn-ghost btn-sm" data-fp-cancel-new>Back to the list</button>' : '<span></span>') + '<button type="button" class="btn btn-primary btn-sm" data-fp-create>' + svg('plus', 15) + ' Create form</button></div>' +
          '<p class="muted small">You can change its fields in the form builder at any time.</p></div>'
        : '<div class="ed-fp-row"><div class="ed-fp-search" style="flex:1">' + svg('search', 15) + '<input class="input" type="search" data-fp-q placeholder="Search forms" value="' + esc(q) + '" aria-label="Search forms"></div>' +
          (canCreate ? '<button type="button" class="btn btn-sm" data-fp-new>' + svg('plus', 15) + ' New form</button>' : '') + '</div>' +
          '<div class="ed-fp-list" role="listbox" aria-label="Forms">' + (list.length ? list.map((f) => '<button type="button" role="option" class="ed-fp-item' + (sel && f.id === sel.id ? ' is-on' : '') + '" aria-selected="' + (sel && f.id === sel.id) + '" data-fp-id="' + f.id + '">' +
            '<span class="ed-fp-check">' + (sel && f.id === sel.id ? svg('check', 12) : '') + '</span><span class="ed-fp-main"><strong>' + esc(f.title) + '</strong><small>' + esc(meta(f)) + '</small></span></button>').join('')
            : '<div class="ed-fp-empty">No forms match "' + esc(q) + '".</div>') + '</div>';
      const prev = sel && sel.preview_url
        ? '<div class="ed-fp-preview"><div class="ed-fp-preview-head"><span>Preview' + (sel.status !== 'publish' ? ' (draft: publish it to show it on the site)' : '') + '</span><a href="' + esc(sel.edit_url) + '" target="_blank" rel="noopener" class="link">' + svg('external', 14) + ' Edit form</a></div><iframe title="Form preview" src="' + esc(sel.preview_url) + '" loading="lazy"></iframe></div>'
        : '<div class="ed-fp-preview"><div class="ed-fp-empty">' + (creating ? 'Pick a starting point. The new form opens here.' : 'Select a form to preview it.') + '</div></div>';
      body.innerHTML = '<div class="ed-fp"><div class="ed-fp-side">' + side + '</div>' + prev + '</div>';
      insertBtn.disabled = !sel || creating;
    };
    draw();
    (creating ? $('[data-fp-name]', body) : $('[data-fp-q]', body))?.focus();
    return new Promise((resolve) => {
      let done = false;
      const finish = (v) => { if (done) return; done = true; m.close(); resolve(v); };
      body.addEventListener('input', (e) => {
        if (!e.target.matches('[data-fp-q]')) return;
        q = e.target.value.trim().toLowerCase();
        const pos = e.target.selectionStart;
        draw();
        const inp = $('[data-fp-q]', body);
        inp.focus(); inp.setSelectionRange(pos, pos);
      });
      body.addEventListener('click', async (e) => {
        const it = e.target.closest('[data-fp-id]');
        if (it) { sel = items.find((f) => String(f.id) === it.dataset.fpId); draw(); $('[data-fp-id="' + sel.id + '"]', body)?.focus(); return; }
        if (e.target.closest('[data-fp-new]')) { creating = true; draw(); $('[data-fp-name]', body)?.focus(); return; }
        if (e.target.closest('[data-fp-cancel-new]')) { creating = false; draw(); return; }
        const cb = e.target.closest('[data-fp-create]');
        if (cb) {
          const fd = new FormData();
          fd.append('_csrf', S.csrf);
          fd.append('title', $('[data-fp-name]', body).value);
          fd.append('template', ($('input[name="ed-fp-tpl"]:checked', body) || {}).value || 'blank');
          cb.classList.add('is-loading');
          const r = await fetch(S.admin + '/m/forms/create', { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }).then((x) => x.json()).catch(() => ({ ok: false }));
          cb.classList.remove('is-loading');
          if (!r.ok || !r.form) { toast(r.error || 'The form could not be created.', 'error'); return; }
          items.unshift(r.form);
          sel = r.form;
          creating = false;
          q = '';
          draw();
          toast('Form created. Change its fields any time with "Edit form".');
        }
      });
      body.addEventListener('dblclick', (e) => { if (e.target.closest('[data-fp-id]') && sel) finish(sel); });
      body.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && e.target.matches('[data-fp-name]')) { e.preventDefault(); $('[data-fp-create]', body)?.click(); }
        if (e.key === 'Enter' && e.target.matches('[data-fp-q]')) { e.preventDefault(); const first = $('[data-fp-id]', body); if (first) { sel = items.find((f) => String(f.id) === first.dataset.fpId); draw(); } }
      });
      insertBtn.addEventListener('click', () => { if (sel) finish(sel); });
      // Closed with the X, Cancel, Escape or a click outside.
      new MutationObserver((_, obs) => { if (!m.isConnected) { obs.disconnect(); if (!done) { done = true; resolve(null); } } }).observe(document.body, { childList: true });
    });
  }

  // ---------- Rich text editor ----------
  class Editor {
    constructor(root) {
      this.root = root;
      this.store = $('textarea', root);
      this.source = false;
      root.insertAdjacentHTML('afterbegin',
        '<div class="ed-toolbar" role="toolbar">' +
        '<select data-block title="Text style"><option value="p">Paragraph</option><option value="h2">Heading 2</option><option value="h3">Heading 3</option><option value="h4">Heading 4</option><option value="pre">Code block</option></select>' +
        '<span class="ed-sep"></span>' +
        this.btn('bold', svg('bold'), 'Bold (Ctrl+B or Cmd+B)') + this.btn('italic', svg('italic'), 'Italic (Ctrl+I or Cmd+I)') + this.btn('underline', svg('underline'), 'Underline') + this.btn('strikeThrough', svg('strike'), 'Strikethrough') +
        '<span class="ed-sep"></span>' +
        this.btn('insertUnorderedList', svg('list'), 'Bulleted list') + this.btn('insertOrderedList', svg('list-ordered'), 'Numbered list') + this.btn('quote', svg('quote'), 'Quote') +
        '<span class="ed-sep"></span>' +
        this.btn('link', svg('link'), 'Link (Ctrl+K or Cmd+K)') + this.btn('unlink', svg('unlink'), 'Remove link') + this.btn('image', svg('image'), 'Insert image') + this.btn('embed', svg('video'), 'Embed video (YouTube/Vimeo)') +
        this.btn('table', svg('table'), 'Insert table') + this.btn('hr', svg('divider'), 'Divider') + (S.forms ? this.btn('form', svg('form'), 'Insert a form') : '') +
        '<span class="ed-sep"></span>' +
        this.btn('removeFormat', svg('eraser'), 'Clear formatting') + this.btn('undo', svg('undo'), 'Undo') + this.btn('redo', svg('redo'), 'Redo') +
        '<span class="ed-right"></span>' + this.btn('source', svg('code'), 'Edit HTML') +
        '</div>');
      this.area = document.createElement('div');
      this.area.className = 'ed-area';
      this.area.contentEditable = 'true';
      this.area.dataset.placeholder = 'Start writing, or paste content…';
      this.area.innerHTML = this.store.value;
      // Form widgets are placeholders: keep them as one locked block while editing.
      this.area.querySelectorAll('[data-slate-form-widget]').forEach((w) => { w.contentEditable = 'false'; w.classList.add('ed-form-widget'); w.title = 'Form: click to change or edit it'; });
      this.src = document.createElement('textarea');
      this.src.className = 'ed-source';
      this.src.hidden = true;
      this.src.spellcheck = false;
      this.foot = document.createElement('div');
      this.foot.className = 'ed-foot';
      root.append(this.area, this.src, this.foot);
      this.bind();
      this.count();
    }
    btn(cmd, label, title) { return '<button type="button" data-cmd="' + cmd + '" title="' + esc(title) + '" aria-label="' + esc(title) + '">' + label + '</button>'; }
    bind() {
      const tb = $('.ed-toolbar', this.root);
      tb.addEventListener('mousedown', (e) => { if (e.target.closest('button')) e.preventDefault(); });
      tb.addEventListener('click', (e) => { const b = e.target.closest('[data-cmd]'); if (b) this.exec(b.dataset.cmd); });
      $('[data-block]', tb).addEventListener('change', (e) => { this.area.focus(); document.execCommand('formatBlock', false, e.target.value); this.sync(); });
      this.area.addEventListener('input', () => this.sync());
      this.area.addEventListener('keyup', () => this.state());
      this.area.addEventListener('mouseup', () => this.state());
      this.area.addEventListener('paste', (e) => {
        const html = e.clipboardData.getData('text/html');
        const text = e.clipboardData.getData('text/plain');
        e.preventDefault();
        if (html) document.execCommand('insertHTML', false, cleanHtml(html));
        else document.execCommand('insertHTML', false, text.split(/\n{2,}/).map((p) => '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>').join(''));
        this.sync();
      });
      this.area.addEventListener('keydown', (e) => {
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); e.stopPropagation(); this.exec('link'); }
      });
      this.area.addEventListener('click', async (e) => {
        const w = e.target.closest('[data-slate-form-widget]');
        if (!w || !this.area.contains(w) || !S.forms) return;
        e.preventDefault();
        const picked = await pickForm(w.dataset.slateFormWidget);
        if (!picked) return;
        const tmp = document.createElement('div');
        tmp.innerHTML = formWidget(picked);
        w.replaceWith(tmp.firstChild);
        this.sync();
      });
      this.area.addEventListener('dblclick', (e) => {
        if (e.target.tagName === 'IMG') {
          const alt = prompt('Image alt text (describe the image):', e.target.getAttribute('alt') || '');
          if (alt !== null) { e.target.setAttribute('alt', alt); this.sync(); }
        }
      });
      this.src.addEventListener('input', () => { this.store.value = this.src.value; this.root.dispatchEvent(new Event('editor-change', { bubbles: true })); });
    }
    state() {
      $$('[data-cmd]', this.root).forEach((b) => {
        try { b.classList.toggle('on', ['bold', 'italic', 'underline', 'strikeThrough', 'insertUnorderedList', 'insertOrderedList'].includes(b.dataset.cmd) && document.queryCommandState(b.dataset.cmd)); } catch (e) {}
      });
      const block = (document.queryCommandValue('formatBlock') || 'p').toLowerCase().replace(/[<>]/g, '');
      const sel = $('[data-block]', this.root);
      if ([...sel.options].some((o) => o.value === block)) sel.value = block;
    }
    async exec(cmd) {
      if (cmd === 'source') return this.toggleSource();
      if (this.source) return;
      this.area.focus();
      if (cmd === 'link') {
        const url = prompt('Link URL (https://… or /page/):', 'https://');
        if (url && !/^\s*javascript:/i.test(url)) {
          document.execCommand('createLink', false, url);
          if (/^https?:\/\//.test(url) && !url.startsWith(location.origin)) {
            const a = window.getSelection().anchorNode?.parentElement?.closest('a');
            if (a && confirm('Open in a new tab?')) { a.target = '_blank'; a.rel = 'noopener'; }
          }
        }
      } else if (cmd === 'quote') document.execCommand('formatBlock', false, 'blockquote');
      else if (cmd === 'hr') document.execCommand('insertHorizontalRule');
      else if (cmd === 'image') {
        const range = this.saveRange();
        const items = await window.Slate.pickMedia({ multiple: true, kind: 'image' });
        if (!items) return;
        this.restoreRange(range);
        document.execCommand('insertHTML', false, items.map((m) => '<img src="' + esc(m.url) + '" alt="' + esc(m.alt) + '"' + (m.width ? ' width="' + m.width + '" height="' + m.height + '"' : '') + ' data-media-id="' + m.id + '">').join(''));
      } else if (cmd === 'embed') {
        const url = prompt('YouTube or Vimeo URL:');
        if (!url) return;
        let src = '';
        let m = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/shorts\/)([\w-]{11})/);
        if (m) src = 'https://www.youtube-nocookie.com/embed/' + m[1];
        m = url.match(/vimeo\.com\/(\d+)/);
        if (m) src = 'https://player.vimeo.com/video/' + m[1];
        if (!src) return toast('Unsupported video URL', 'error');
        document.execCommand('insertHTML', false, '<figure class="embed"><iframe src="' + src + '" width="560" height="315" title="Video" allow="accelerometer; encrypted-media; picture-in-picture" allowfullscreen loading="lazy"></iframe></figure><p></p>');
      } else if (cmd === 'form') {
        const range = this.saveRange();
        const picked = await pickForm();
        if (!picked) return;
        this.restoreRange(range);
        document.execCommand('insertHTML', false, formWidget(picked) + '<p></p>');
        this.sync();
        return;
      } else if (cmd === 'table') {
        const rows = Math.min(20, +prompt('Rows?', '3') || 3), cols = Math.min(10, +prompt('Columns?', '3') || 3);
        let h = '<table><thead><tr>' + '<th>Heading</th>'.repeat(cols) + '</tr></thead><tbody>';
        for (let r = 0; r < rows - 1; r++) h += '<tr>' + '<td>&nbsp;</td>'.repeat(cols) + '</tr>';
        document.execCommand('insertHTML', false, h + '</tbody></table><p></p>');
      } else document.execCommand(cmd);
      this.sync();
      this.state();
    }
    saveRange() { const s = window.getSelection(); return s.rangeCount ? s.getRangeAt(0).cloneRange() : null; }
    restoreRange(r) { this.area.focus(); if (r) { const s = window.getSelection(); s.removeAllRanges(); s.addRange(r); } }
    toggleSource() {
      this.source = !this.source;
      if (this.source) { this.src.value = this.pretty(this.area.innerHTML); this.src.style.height = Math.max(380, this.area.offsetHeight) + 'px'; }
      else { this.area.innerHTML = this.src.value; this.sync(); }
      this.src.hidden = !this.source;
      this.area.hidden = this.source;
      $('[data-cmd="source"]', this.root).classList.toggle('on', this.source);
    }
    pretty(html) { return html.replace(/></g, '>\n<').replace(/\n<\/(strong|em|a|b|i|u|s|code|span)>/g, '</$1>').replace(/>\n<(strong|em|a|b|i|u|s|code|span)/g, '><$1'); }
    sync() {
      this.store.value = this.area.innerHTML.replace(/<p><br><\/p>$/, '');
      this.count();
      this.root.dispatchEvent(new Event('editor-change', { bubbles: true }));
    }
    count() {
      const text = (this.area.innerText || '').trim();
      const words = text ? text.split(/\s+/).length : 0;
      this.foot.innerHTML = '<span>' + words + ' words · ' + Math.max(1, Math.round(words / 220)) + ' min read</span><span>Double-click an image to edit its alt text</span>';
    }
    getSelectionHtml() {
      const s = window.getSelection();
      if (!s.rangeCount || !this.area.contains(s.anchorNode) || s.isCollapsed) return '';
      const div = document.createElement('div');
      div.appendChild(s.getRangeAt(0).cloneContents());
      return div.innerHTML;
    }
    setHtml(html) { this.area.innerHTML = html; if (this.source) this.src.value = html; this.sync(); }
  }
  const editors = $$('[data-editor]').map((el) => new Editor(el));
  const mainEditor = editors.find((ed) => ed.root.hasAttribute('data-main-editor'));
  // Make sure every editor writes back before any submit.
  document.addEventListener('submit', () => editors.forEach((ed) => { if (!ed.source) ed.store.value = ed.area.innerHTML; }), true);

  // ---------- Post form: AJAX save ----------
  const form = $('[data-post-form]');
  let dirty = false;
  if (form) {
    const idInput = $('input[name=id]', form);
    const title = $('[data-title]', form);
    const slug = $('[data-slug]', form);
    const state = $('[data-save-state]');
    const statusSel = $('[data-status-select]', form);
    form.addEventListener('input', () => { dirty = true; if (state) state.textContent = 'Unsaved changes'; });
    form.addEventListener('editor-change', () => { dirty = true; if (state) state.textContent = 'Unsaved changes'; });
    window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

    if (title && slug && !slug.value) {
      title.addEventListener('input', () => { if (!slug.dataset.touched) slug.placeholder = slugify(title.value) || 'auto'; });
      slug.addEventListener('input', () => { slug.dataset.touched = 1; });
    }
    slug?.addEventListener('blur', () => { if (slug.value) slug.value = slugify(slug.value); });

    const save = async (status, button) => {
      if (mainEditor && !mainEditor.source) mainEditor.store.value = mainEditor.area.innerHTML;
      const fd = new FormData(form);
      fd.delete('ids[]');
      fd.set('status', status);
      button?.classList.add('is-loading');
      if (state) state.textContent = 'Saving…';
      let data;
      try {
        const res = await fetch(form.action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin' });
        data = await res.json();
      } catch (e) { data = { ok: false, error: 'Network error, your changes were not saved.' }; }
      button?.classList.remove('is-loading');
      if (!data.ok) { if (state) state.textContent = 'Not saved'; toast(data.error || 'Save failed', 'error', 6000); return; }
      dirty = false;
      idInput.value = data.id;
      if (slug) { slug.value = data.slug; }
      if (state) state.textContent = 'Saved ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      const badge = $('[data-status-badge]');
      if (badge) { badge.className = 'status status-' + data.status; badge.textContent = { publish: 'Published', draft: 'Draft', pending: 'Pending review', private: 'Private', scheduled: 'Scheduled' }[data.status] || data.status; }
      if (statusSel && [...statusSel.options].some((o) => o.value === data.status)) statusSel.value = data.status;
      const primary = $('[data-save="publish"]', form);
      if (primary && data.status === 'publish') primary.textContent = 'Update';
      const draftBtn = $('[data-save="draft"]', form);
      if (draftBtn) draftBtn.textContent = data.status === 'publish' ? 'Switch to draft' : 'Save draft';
      if (location.pathname.endsWith('/new')) history.replaceState(null, '', data.edit_url);
      let view = $('[data-view-link]');
      if (view) view.href = data.status === 'publish' ? data.permalink : data.permalink + (data.permalink.includes('?') ? '&' : '?') + 'preview=' + data.id;
      $$('[data-serp-url]').forEach((el) => { el.textContent = data.permalink; });
      toast(data.message);
    };
    form.addEventListener('submit', (e) => {
      const btn = e.submitter;
      if (btn && btn.formAction && btn.getAttribute('formaction')) { dirty = false; return; } // duplicate/trash use normal submit
      e.preventDefault();
      let status = btn?.value || 'draft';
      if (btn?.dataset.save === 'publish' && statusSel && ['private', 'pending'].includes(statusSel.value)) status = statusSel.value;
      save(status, btn);
    });
    document.addEventListener('keydown', (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        const current = statusSel?.value || 'draft';
        save(current === 'publish' ? 'publish' : current, null);
      }
    });
  }

  // ---------- SEO preview & analysis ----------
  const panel = $('[data-seo-panel]');
  if (panel) {
    const seoTitle = $('#seo_title'), seoDesc = $('#seo_description'), focus = $('#seo_focus');
    const pageTitle = $('[data-title]');
    const excerpt = $('textarea[name=excerpt]');
    const site = $('[data-serp-site]').textContent;
    const sep = '–';
    const textOf = (html) => { const d = document.createElement('div'); d.innerHTML = html; return (d.innerText || d.textContent || '').replace(/\s+/g, ' ').trim(); };
    const applyVars = (t) => t.replace(/%title%/g, pageTitle?.value || '').replace(/%site%|%sitename%/g, site).replace(/%sep%/g, sep).replace(/%tagline%/g, '').replace(/%year%/g, new Date().getFullYear()).replace(/\s+/g, ' ').trim();
    const counter = (input, max) => {
      const c = $('[data-counter-for="' + input.id + '"]');
      if (!c) return;
      const n = (input.value || input.placeholder || '').length;
      c.textContent = n + ' / ' + max;
      c.className = 'counter ' + (n > max ? 'over' : n >= max * 0.6 ? 'ok' : 'short');
    };
    const update = () => {
      const tpl = seoTitle.value || seoTitle.dataset.template || '%title% %sep% %site%';
      const t = applyVars(tpl) || site;
      if (!seoTitle.value) seoTitle.placeholder = t;
      const contentText = mainEditor ? textOf(mainEditor.store.value) : '';
      const desc = seoDesc.value || (excerpt?.value || '') || contentText.slice(0, 158);
      $('[data-serp-title]').textContent = t.length > 62 ? t.slice(0, 60) + '…' : t;
      $('[data-serp-desc]').textContent = desc || 'Add a meta description to control how this page appears in search results.';
      counter(seoTitle, 60);
      counter(seoDesc, 160);
      const checks = $('[data-seo-checks]');
      if (!checks) return;
      const kw = (focus?.value || '').trim().toLowerCase();
      const html = mainEditor?.store.value || '';
      const words = contentText ? contentText.split(/\s+/).length : 0;
      const slugVal = ($('[data-slug]')?.value || $('[data-slug]')?.placeholder || '').toLowerCase();
      const first = textOf((html.match(/<p[^>]*>([\s\S]*?)<\/p>/i) || [])[1] || '').toLowerCase();
      const imgs = (html.match(/<img\b[^>]*>/gi) || []);
      const noAlt = imgs.filter((i) => !/alt="[^"]+"/i.test(i)).length;
      const list = [];
      const add = (ok, msg, warn) => list.push('<li class="' + (ok ? 'good' : warn ? '' : 'bad') + '">' + esc(msg) + '</li>');
      if (kw) {
        add(t.toLowerCase().includes(kw), 'Focus keyphrase in SEO title');
        add(desc.toLowerCase().includes(kw), 'Focus keyphrase in meta description');
        add(slugVal.includes(slugify(kw)), 'Focus keyphrase in URL');
        add(first.includes(kw), 'Focus keyphrase in the first paragraph', true);
        add(/<h[2-4][^>]*>[^<]*/i.test(html) && new RegExp('<h[2-4][^>]*>[^<]*' + kw.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i').test(html), 'Focus keyphrase in a subheading', true);
      }
      add(t.length >= 30 && t.length <= 60, 'SEO title length (' + t.length + ' chars, aim for 30–60)', true);
      add(desc.length >= 120 && desc.length <= 160, 'Meta description length (' + desc.length + ', aim for 120–160)', true);
      if (mainEditor) {
        add(words >= 300, 'Content length: ' + words + ' words' + (words < 300 ? ' (300+ recommended)' : ''), true);
        add(/<a\s[^>]*href="(\/|https?:\/\/[^"]*?)/i.test(html), 'Contains links', true);
        if (imgs.length) add(noAlt === 0, noAlt ? noAlt + ' image(s) missing alt text' : 'All images have alt text');
        add(!!$('input[name=featured_media_id]')?.value, 'Featured image set', true);
      }
      checks.innerHTML = list.join('');
    };
    const deb = debounce(update, 200);
    document.addEventListener('input', deb);
    document.addEventListener('editor-change', deb);
    document.addEventListener('change', deb);
    update();

    // Tabs inside the SEO panel
    $$('[data-tabs] [data-tab]', panel).forEach((b) => b.addEventListener('click', () => {
      $$('[data-tabs] [data-tab]', panel).forEach((x) => x.classList.toggle('active', x === b));
      $$('[data-tab-panel]', panel).forEach((p) => { p.hidden = p.dataset.tabPanel !== b.dataset.tab; });
    }));

    $('[data-ai-seo]', panel)?.addEventListener('click', async (e) => {
      const b = e.currentTarget;
      b.classList.add('is-loading');
      const r = await api('/ai', { body: { action: 'seo_meta', title: pageTitle?.value || '', content: mainEditor ? textOf(mainEditor.store.value).slice(0, 8000) : ($('textarea[name=description]')?.value || '') } });
      b.classList.remove('is-loading');
      if (!r.ok) return toast(r.error, 'error', 6000);
      if (r.data.title) seoTitle.value = r.data.title;
      if (r.data.description) seoDesc.value = r.data.description;
      update();
      dirty = true;
      toast('SEO title & description generated, review and save.');
    });
  }

  // ---------- AI assistant ----------
  const runBtn = $('[data-ai-run]');
  function aiResult(text, isHtml, onApply) {
    const body = document.createElement('div');
    body.innerHTML = '<div class="ai-preview">' + (isHtml ? text : esc(text).replace(/\n/g, '<br>')) + '</div>';
    const foot = document.createElement('div');
    foot.style.cssText = 'display:flex;gap:8px';
    foot.innerHTML = '<button class="btn" data-close type="button">Discard</button>' + (isHtml ? '<button class="btn" data-mode="append" type="button">Insert below</button>' : '') + '<button class="btn btn-primary" data-mode="replace" type="button">' + (isHtml ? 'Replace' : 'Use') + '</button>';
    const m = modal({ title: 'AI suggestion', body, foot });
    $$('[data-mode]', m).forEach((b) => b.addEventListener('click', () => { onApply(b.dataset.mode); m.close(); }));
  }
  runBtn?.addEventListener('click', async () => {
    const action = $('[data-ai-action]').value;
    const input = $('[data-ai-input]').value;
    const selection = mainEditor?.getSelectionHtml() || '';
    const content = selection || mainEditor?.store.value || '';
    if (['custom', 'draft'].includes(action) && !input.trim()) return toast('Write an instruction or brief first.', 'error');
    runBtn.classList.add('is-loading');
    const r = await api('/ai', { body: { action, input, content, title: $('[data-title]')?.value || '' } });
    runBtn.classList.remove('is-loading');
    if (!r.ok) return toast(r.error, 'error', 7000);
    aiResult(r.text, r.html, (mode) => {
      if (!mainEditor) return;
      if (!r.html) { navigator.clipboard?.writeText(r.text); toast('Copied to clipboard'); return; }
      if (selection && mode === 'replace') { mainEditor.area.focus(); document.execCommand('insertHTML', false, r.text); mainEditor.sync(); }
      else if (mode === 'replace') mainEditor.setHtml(r.text);
      else mainEditor.setHtml(mainEditor.store.value + r.text);
      toast('Applied. Remember to save.');
    });
  });
  $('[data-ai-excerpt]')?.addEventListener('click', async (e) => {
    const b = e.currentTarget;
    b.classList.add('is-loading');
    const r = await api('/ai', { body: { action: 'excerpt', content: mainEditor?.store.value || '', title: $('[data-title]')?.value || '' } });
    b.classList.remove('is-loading');
    if (!r.ok) return toast(r.error, 'error');
    $('textarea[name=excerpt]').value = r.text;
    dirty = true;
  });
})();
