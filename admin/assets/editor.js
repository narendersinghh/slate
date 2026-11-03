/* Slate content editor: rich text, autosave UX, SEO analysis, AI assistant. */
(function () {
  'use strict';
  const { api, toast, modal, esc, $, $$, debounce, slugify } = window.Slate;
  const S = window.SLATE;

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
        this.btn('bold', '<b>B</b>', 'Bold (⌘B)') + this.btn('italic', '<i>I</i>', 'Italic (⌘I)') + this.btn('underline', '<u>U</u>', 'Underline') + this.btn('strikeThrough', '<s>S</s>', 'Strikethrough') +
        '<span class="ed-sep"></span>' +
        this.btn('insertUnorderedList', '•≡', 'Bulleted list') + this.btn('insertOrderedList', '1≡', 'Numbered list') + this.btn('quote', '❝', 'Quote') +
        '<span class="ed-sep"></span>' +
        this.btn('link', '🔗', 'Link (⌘K)') + this.btn('unlink', '⛓︎', 'Remove link') + this.btn('image', '🖼', 'Insert image') + this.btn('embed', '▶', 'Embed video (YouTube/Vimeo)') +
        this.btn('table', '▦', 'Insert table') + this.btn('hr', '―', 'Divider') +
        '<span class="ed-sep"></span>' +
        this.btn('removeFormat', 'Tx', 'Clear formatting') + this.btn('undo', '↶', 'Undo') + this.btn('redo', '↷', 'Redo') +
        '<span class="ed-right"></span>' + this.btn('source', '&lt;/&gt;', 'Edit HTML') +
        '</div>');
      this.area = document.createElement('div');
      this.area.className = 'ed-area';
      this.area.contentEditable = 'true';
      this.area.dataset.placeholder = 'Start writing, or paste content…';
      this.area.innerHTML = this.store.value;
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
      } catch (e) { data = { ok: false, error: 'Network error — your changes were not saved.' }; }
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
      toast('SEO title & description generated — review and save.');
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
    const m = modal({ title: '✨ AI suggestion', body, foot });
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
