/* Slate on-page editor for imported / AI-written pages (core/Slate/PageEditor.php).
   Click "Edit page" in the admin bar, then click any text, image, icon or repeating group on the page.
   Changes are kept as small operations and saved in one batch (one revision per save). */
(function () {
  'use strict';
  const cfgEl = document.getElementById('se-config');
  if (!cfgEl || window.__slatePageEditor) return;
  let C;
  try { C = JSON.parse(cfgEl.textContent); } catch (e) { return; }
  window.__slatePageEditor = true;

  const d = document;
  const STYLE_PROPS = ['color', 'background-color', 'font-size', 'font-weight', 'font-style', 'text-decoration', 'text-transform', 'text-align',
    'line-height', 'letter-spacing', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'padding', 'padding-top',
    'padding-right', 'padding-bottom', 'padding-left', 'border-radius', 'width', 'max-width', 'height', 'aspect-ratio', 'object-fit',
    'object-position', 'display'];
  const TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div', 'span'];
  const ICON_RE = /(^|\s)(fa|fas|far|fab|fal|fad|fa-[a-z0-9-]+|bi|bi-[a-z0-9-]+|icon|material-icons|ti|ri-[a-z0-9-]+)(\s|$)/;
  const NAMES = { h1: 'Heading 1', h2: 'Heading 2', h3: 'Heading 3', h4: 'Heading 4', h5: 'Heading 5', h6: 'Heading 6', p: 'Paragraph', a: 'Link',
    button: 'Button', li: 'List item', img: 'Image', picture: 'Image', svg: 'Icon', i: 'Icon', span: 'Text', div: 'Text', blockquote: 'Quote',
    figcaption: 'Caption', td: 'Cell', th: 'Cell', label: 'Label', small: 'Small text', strong: 'Bold text', em: 'Text', video: 'Video', iframe: 'Embed' };

  // ------------------------------------------------------------------ state
  let on = false;
  let sel = null; // selected page element
  let selKind = '';
  let hoverEl = null;
  let saving = false;
  let leaving = false;
  const P = { text: new Map(), tag: new Map(), style: new Map(), attr: new Map(), image: new Map(), icon: new Map(), remove: new Map(), bind: new Map() };
  // 'html': designed page (fields._html). 'content': stored content + the item's title/fields shown by a template or the theme. 'term': a term page.
  const MODE = C.mode || 'html';
  const BINDS = C.binds || {};
  const originals = new Map(); // id -> {html, tag, style}

  // ------------------------------------------------------------------ helpers
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const tagOf = (el) => el.tagName.toLowerCase();
  const idOf = (el) => el.getAttribute('data-se') || el.getAttribute('data-se-loop') || '';
  const cls = (el) => el.getAttribute('class') || '';
  const json = (url, body, method) => fetch(url, {
    method: method || (body ? 'POST' : 'GET'), credentials: 'same-origin',
    headers: Object.assign({ 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': C.csrf },
      body && !(body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}),
    body: body ? (body instanceof FormData ? body : JSON.stringify(body)) : undefined,
  }).then(async (r) => { let j = {}; try { j = await r.json(); } catch (e) { j = { ok: false, error: 'Unexpected answer from the server (' + r.status + ').' }; } j.status = r.status; return j; })
    .catch(() => ({ ok: false, error: 'Network error. Check your connection and try again.' }));
  const ico = {
    edit: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
    link: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg>',
    trash: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>',
    reset: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>',
    left: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h10M4 18h14"/></svg>',
    center: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M7 12h10M5 18h14"/></svg>',
    right: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M10 12h10M6 18h14"/></svg>',
    justify: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>',
    loop: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="4" width="7" height="7" rx="1.5"/><rect x="14" y="4" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
    close: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>',
    up: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 15 6-6 6 6"/></svg>',
    down: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
    copy: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/></svg>',
  };

  // ------------------------------------------------------------------ UI shell (shadow root: the site's CSS cannot reach it)
  const host = d.createElement('slate-page-editor');
  host.setAttribute('data-se-ui', '');
  host.style.cssText = 'all:initial;position:fixed;inset:0;z-index:2147483600;pointer-events:none;display:block';
  const root = host.attachShadow({ mode: 'open' });
  root.innerHTML = '<link rel="stylesheet" href="' + esc(C.css) + '">'
    + '<div class="se-ui" hidden>'
    + '<div class="se-box se-hover" hidden><span class="se-tagname"></span></div>'
    + '<div class="se-box se-sel" hidden><span class="se-tagname"></span></div>'
    + '<div class="se-toolbar" role="toolbar" aria-label="Text formatting" hidden></div>'
    + '<aside class="se-panel" role="dialog" aria-modal="false" aria-labelledby="se-panel-title" hidden></aside>'
    + '<div class="se-bar" role="region" aria-label="Page editor">'
    + '<span class="se-count" aria-live="polite">No changes</span>'
    + '<button type="button" class="se-btn se-ghost" data-act="discard">Discard</button>'
    + '<button type="button" class="se-btn se-primary" data-act="save">Save</button>'
    + '<button type="button" class="se-btn se-ghost se-icon" data-act="done" aria-label="Stop editing" title="Stop editing">' + ico.close + '</button>'
    + '</div>'
    + '</div>'
    + '<div class="se-toasts" aria-live="assertive"></div>';
  const $ = (s) => root.querySelector(s);
  const ui = $('.se-ui');
  const hoverBox = $('.se-hover');
  const selBox = $('.se-sel');
  const toolbar = $('.se-toolbar');
  const panel = $('.se-panel');
  const sbar = $('.se-bar');
  const countEl = $('.se-count');

  function mount() {
    d.body.appendChild(host);
    const bar = d.getElementById('slate-bar');
    let toggle;
    if (bar) {
      toggle = d.createElement('button');
      toggle.type = 'button';
      toggle.className = 'sb-primary';
      const after = bar.querySelector('a[href*="/content/"]') || bar.querySelector('.sb-logo');
      after ? after.after(toggle) : bar.prepend(toggle);
      ui.classList.add('se-with-bar');
    } else {
      toggle = d.createElement('button');
      toggle.type = 'button';
      toggle.className = 'se-fab';
      root.appendChild(toggle);
    }
    toggle.id = 'se-toggle';
    toggle.setAttribute('aria-pressed', 'false');
    toggle.innerHTML = ico.edit + ' <span>Edit page</span>';
    toggle.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); setOn(!on); });
    mount.toggle = toggle;
    if (/[?&]se-edit=1\b/.test(location.search)) {
      mount.reloaded = true;
      setOn(true);
      if (MODE === 'content' && C.contentShown && /[?&]se-content=1\b/.test(location.search)) {
        toast('This page was showing a saved copy of the original site. You are editing its stored content now; visitors get this version once you save.', '', 9000);
      }
      try { history.replaceState(null, '', location.href.replace(/([?&])se-(edit|content)=1&?/g, '$1').replace(/([?&])se-(edit|content)=1&?/g, '$1').replace(/[?&]$/, '')); } catch (e) { /* ignore */ }
    }
  }

  function toast(msg, kind, ms) {
    const t = d.createElement('div');
    t.className = 'se-toast' + (kind ? ' se-' + kind : '');
    t.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    t.textContent = msg;
    $('.se-toasts').appendChild(t);
    setTimeout(() => t.remove(), ms || (kind === 'error' ? 6000 : 2600));
  }

  function setOn(v) {
    if (v && !mount.reloaded && MODE === 'content' && C.byTheme && C.hasContent && !C.contentShown && !C.contentLocked) {
      // The page shows something else than the item's stored content (e.g. a snapshot of an imported page): load the editable version.
      const u = new URL(location.href);
      u.searchParams.set('se-edit', '1');
      u.searchParams.set('se-content', '1');
      location.assign(u.toString());
      return;
    }
    if (v && C.contentLocked) toast('The HTML of this page\'s content cannot be edited safely here. Edit it in the dashboard.', 'error', 7000);
    if (!v && count()) {
      toast('Save or discard your changes first.', 'error');
      return;
    }
    on = v;
    d.documentElement.classList.toggle('se-editing', on);
    ui.hidden = !on;
    mount.toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
    mount.toggle.querySelector('span').textContent = on ? 'Editing' : 'Edit page';
    if (!on) deselect();
    if (on) { refresh(); tick(); }
  }

  // ------------------------------------------------------------------ what can be selected
  function pickable(t) {
    if (!(t instanceof Element) || host.contains(t) || t === host) return null;
    const bar = d.getElementById('slate-bar');
    if (bar && bar.contains(t)) return null;
    const el = t.closest('[data-se],[data-se-loop],[data-se-bind]');
    if (!el) return null;
    if (el.hasAttribute('data-se-bind') && !BINDS[el.getAttribute('data-se-bind')]) return null;
    return el.closest('[data-se-source]') || el;
  }

  // ---------- hints for parts that are not edited here (template, theme, lists, modules)
  let marks = null;
  function buildMarks() {
    marks = new Map();
    const w = d.createTreeWalker(d.body, NodeFilter.SHOW_COMMENT);
    const starts = [];
    for (let n = w.nextNode(); n; n = w.nextNode()) { const m = /^se:(\w+)$/.exec(n.data.trim()); if (m) starts.push([n, m[1]]); }
    starts.forEach(([c, kind]) => {
      for (let n = c.nextSibling; n; n = n.nextSibling) {
        if (n.nodeType === 8 && n.data.trim() === '/se:' + kind) break;
        if (n.nodeType === 1 && !marks.has(n)) marks.set(n, kind);
      }
    });
  }
  function hintFor(t) {
    if (!(t instanceof Element) || t === d.body || t === d.documentElement) return '';
    if (!marks) buildMarks();
    for (let n = t; n && n !== d.body; n = n.parentElement) {
      const k = marks.get(n);
      if (k === 'list') return 'Part of a list: it repeats for each item';
      if (k === 'mod') return 'Added by a module';
      if (k === 'content') return 'Layout of the page content: edit it in the dashboard';
    }
    if (MODE === 'html') return '';
    return 'Comes from ' + (C.where || 'the site theme');
  }

  function kindOf(el) {
    if (el.hasAttribute('data-se-bind') && !el.hasAttribute('data-se')) return 'bind';
    if (el.hasAttribute('data-se-loop') && !el.hasAttribute('data-se')) return 'loop';
    const tag = tagOf(el);
    if (tag === 'img' || tag === 'picture') return 'image';
    if (tag === 'svg' || ((tag === 'i' || tag === 'span') && ICON_RE.test(cls(el)) && !el.textContent.trim())) return 'icon';
    if (tag === 'video' || tag === 'iframe') return 'media';
    return 'text';
  }

  function nameOf(el) {
    const k = kindOf(el);
    if (k === 'bind') { const b = BINDS[el.getAttribute('data-se-bind')]; return b ? b.label : 'Field'; }
    if (k === 'loop') return el.hasAttribute('data-se-source') ? 'Dynamic list' : 'Repeating group';
    if (k === 'icon') return 'Icon';
    if (tagOf(el) === 'a' && /\b(btn|button)\b/i.test(cls(el))) return 'Button';
    return NAMES[tagOf(el)] || 'Element';
  }

  const loopOf = (el) => {
    const l = el && el.closest('[data-se-loop]');
    return l && l !== el ? l : (el && el.hasAttribute('data-se-loop') ? el : null);
  };
  const loopData = (loopEl) => (C.loops || []).find((l) => l.id === loopEl.getAttribute('data-se-loop')) || null;

  // ------------------------------------------------------------------ boxes, positions
  function placeBox(box, el) {
    if (!el || !el.isConnected) { box.hidden = true; return; }
    let r = el.getBoundingClientRect();
    if (!r.width && !r.height && el.firstChild) { const rg = d.createRange(); rg.selectNodeContents(el); r = rg.getBoundingClientRect(); }
    if (!r.width && !r.height) { box.hidden = true; return; }
    box.hidden = false;
    box.style.transform = 'translate(' + Math.round(r.left - 3) + 'px,' + Math.round(r.top - 3) + 'px)';
    box.style.width = Math.round(r.width + 6) + 'px';
    box.style.height = Math.round(r.height + 6) + 'px';
    box.classList.toggle('se-label-below', r.top < 26);
  }

  function placeToolbar() {
    if (toolbar.hidden || !sel) return;
    let r = sel.getBoundingClientRect();
    if (!r.width && !r.height) { const rg = d.createRange(); rg.selectNodeContents(sel); r = rg.getBoundingClientRect(); }
    const tb = toolbar.getBoundingClientRect();
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    if (vw <= 640) { toolbar.style.transform = ''; toolbar.classList.add('se-docked'); return; }
    toolbar.classList.remove('se-docked');
    let top = r.top - tb.height - 30; // clear the element's name label
    if (top < 8) top = r.bottom + 12;
    if (top + tb.height > vh - 80) top = Math.max(8, Math.min(r.top + 8, vh - tb.height - 80));
    let left = Math.max(8, Math.min(r.left, vw - tb.width - 8));
    toolbar.style.transform = 'translate(' + Math.round(left) + 'px,' + Math.round(top) + 'px)';
  }

  let ticking = false;
  function tick() {
    if (ticking) return;
    ticking = true;
    const loop = () => {
      if (!on) { ticking = false; return; }
      placeBox(hoverBox, hoverEl && hoverEl !== sel ? hoverEl : null);
      placeBox(selBox, sel);
      placeToolbar();
      requestAnimationFrame(loop);
    };
    requestAnimationFrame(loop);
  }

  // ------------------------------------------------------------------ page events (capture: the site's own handlers do not run in edit mode)
  const isUiEvent = (e) => e.composedPath().includes(host) || (d.getElementById('slate-bar') || { contains: () => false }).contains(e.target);

  window.addEventListener('mousemove', (e) => {
    if (!on || isUiEvent(e)) return;
    const el = pickable(e.target);
    if (el) {
      if (el !== hoverEl) { hoverEl = el; hoverBox.classList.remove('se-hint'); hoverBox.querySelector('.se-tagname').textContent = nameOf(el); }
      return;
    }
    const hint = hintFor(e.target);
    const t = hint ? e.target : null;
    if (t !== hoverEl) {
      hoverEl = t;
      hoverBox.classList.toggle('se-hint', !!t);
      if (t) hoverBox.querySelector('.se-tagname').textContent = hint;
    }
  }, true);

  ['pointerdown', 'mousedown', 'touchstart', 'dragstart', 'pointerup', 'mouseup', 'touchend', 'dblclick'].forEach((t) => window.addEventListener(t, (e) => {
    if (!on || isUiEvent(e)) return;
    if (!(e.target instanceof Element)) return;
    e.stopPropagation(); // keeps sliders and other page scripts from reacting while editing
    if (t === 'dragstart') e.preventDefault();
  }, true));

  window.addEventListener('click', (e) => {
    if (!on || isUiEvent(e)) return;
    e.stopPropagation();
    const inside = sel && (selKind === 'text' || selKind === 'bind') && sel.contains(e.target) && sel.isContentEditable;
    e.preventDefault();
    if (inside) {
      const el = pickable(e.target);
      if (el === sel) return; // caret placement inside the edited text
      if (el && el !== sel && sel.contains(el) && kindOf(el) === 'text') return;
    }
    const el = pickable(e.target);
    if (!el) {
      deselect();
      const hint = hintFor(e.target);
      if (hint) toast(hint + '.');
      return;
    }
    select(el, e);
  }, true);

  window.addEventListener('submit', (e) => { if (on) { e.preventDefault(); e.stopPropagation(); } }, true);

  window.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase() === 's' && (on || count())) {
      e.preventDefault();
      save();
      return;
    }
    if (!on) return;
    if (e.key === 'Escape') {
      const pop = root.querySelector('.se-pop:not([hidden])');
      if (pop) { pop.hidden = true; e.preventDefault(); return; }
      if (sel) { e.preventDefault(); deselect(); }
    }
  }, true);

  window.addEventListener('beforeunload', (e) => {
    if (!leaving && count()) { e.preventDefault(); e.returnValue = ''; return ''; }
  });

  // ------------------------------------------------------------------ selection
  function select(el, ev) {
    if (el === sel) return;
    deselect();
    sel = el;
    selKind = kindOf(el);
    const id = idOf(el);
    if (!originals.has(id)) originals.set(id, { html: el.innerHTML, tag: tagOf(el), style: el.getAttribute('style') || '' });
    selBox.querySelector('.se-tagname').textContent = nameOf(el);
    selBox.classList.toggle('se-dyn', el.hasAttribute('data-se-source'));
    if (selKind === 'bind') startBind(el, ev);
    else if (selKind === 'text') startText(el, ev);
    else if (selKind === 'image') openImage(el);
    else if (selKind === 'icon') openIcon(el);
    else if (selKind === 'loop') openLoop(el, ev && ev.target);
    else openMedia(el);
  }

  function deselect() {
    if (sel && selKind === 'text') stopText(sel);
    if (sel && selKind === 'bind') stopBind(sel);
    sel = null;
    selKind = '';
    toolbar.hidden = true;
    closePanel();
  }

  // ------------------------------------------------------------------ the item's title, excerpt, fields (shown by a template)
  const bindKey = (el) => el.getAttribute('data-se-bind');
  const bindValue = (el, kind) => (kind === 'html' ? cleanHtml(el) : kind === 'textarea' ? el.innerText.replace(/\n$/, '') : el.textContent);
  const shown = new WeakMap(); // element -> the rendered (filtered) HTML, put back when nothing changed

  function startBind(el, ev) {
    const key = bindKey(el);
    const b = BINDS[key];
    if (b.kind === 'image') { openBindImage(el, key, b); return; }
    if (!shown.has(el)) shown.set(el, el.innerHTML);
    if (!P.bind.has(key)) {
      // Edit the stored value, not the filtered output (curly quotes, line breaks).
      if (b.kind === 'html') el.innerHTML = b.value || '';
      else if (b.kind === 'textarea') el.innerText = b.value || '';
      else el.textContent = b.value || '';
    }
    el.setAttribute('contenteditable', b.kind === 'html' ? 'true' : 'plaintext-only');
    if (el.contentEditable !== 'plaintext-only' && b.kind !== 'html') el.setAttribute('contenteditable', 'true');
    el.setAttribute('spellcheck', 'true');
    el.addEventListener('input', onBindInput);
    el.addEventListener('paste', onPaste);
    el.addEventListener('keydown', onBindKey);
    el.focus({ preventScroll: true });
    if (ev && d.caretRangeFromPoint) {
      const r = d.caretRangeFromPoint(ev.clientX, ev.clientY);
      if (r && el.contains(r.startContainer)) { const s = getSelection(); s.removeAllRanges(); s.addRange(r); }
    }
    buildBindToolbar(el, b);
  }

  function stopBind(el) {
    el.removeAttribute('contenteditable');
    el.removeAttribute('spellcheck');
    el.removeEventListener('input', onBindInput);
    el.removeEventListener('paste', onPaste);
    el.removeEventListener('keydown', onBindKey);
    const key = bindKey(el);
    if (!P.bind.has(key) && shown.has(el)) el.innerHTML = shown.get(el);
  }

  function markBind(el) {
    const key = bindKey(el);
    const b = BINDS[key];
    const v = bindValue(el, b.kind);
    if (v === (b.value || '') || (b.kind === 'html' && v === cleanHtmlString(b.value || ''))) P.bind.delete(key);
    else P.bind.set(key, { el, kind: b.kind });
    // The same value shown elsewhere on the page (a title in several places) follows along.
    if (b.kind === 'text') d.querySelectorAll('[data-se-bind="' + key + '"]').forEach((o) => { if (o !== el) o.textContent = v; });
    update();
  }
  function onBindInput(e) { markBind(e.currentTarget); }
  function onBindKey(e) {
    if (e.key !== 'Enter' || e.isComposing) return;
    const b = BINDS[bindKey(e.currentTarget)];
    if (b.kind === 'html') return;
    e.preventDefault();
    if (b.kind === 'textarea') d.execCommand('insertLineBreak');
  }

  function buildBindToolbar(el, b) {
    let h = '<span class="se-chip-label" title="Saved to this item">' + esc(b.label) + '</span>';
    if (b.kind === 'html') {
      h += '<span class="se-sep"></span><button type="button" class="se-tb" data-cmd="bold" title="Bold (Ctrl+B)" aria-label="Bold"><b>B</b></button>'
        + '<button type="button" class="se-tb" data-cmd="italic" title="Italic (Ctrl+I)" aria-label="Italic"><i>I</i></button>'
        + '<button type="button" class="se-tb" data-cmd="underline" title="Underline (Ctrl+U)" aria-label="Underline"><u>U</u></button>'
        + '<button type="button" class="se-tb" data-pop="link" title="Link" aria-label="Link" aria-haspopup="true">' + ico.link + '</button>';
    }
    if (C.ai) h += '<span class="se-sep"></span><button type="button" class="se-tb se-ai" data-pop="ai" title="Rewrite with AI" aria-label="Rewrite with AI" aria-haspopup="true">✦</button>';
    h += linkPop() + aiPop();
    toolbar.innerHTML = h;
    toolbar.hidden = false;
    placeToolbar();
  }

  function openBindImage(el, key, b) {
    let h = '<div class="se-preview"><img src="' + esc(el.getAttribute('src') || '') + '" alt=""></div>'
      + '<p class="se-hint">' + esc(b.label) + ' of this item. Alt text comes from the media library.</p>';
    if (C.media) {
      h += '<div class="se-row"><label class="se-btn se-primary se-file">Upload<input type="file" accept="image/*" data-upload hidden></label>'
        + '<button type="button" class="se-btn se-ghost" data-act="library">Media library</button></div><div class="se-lib" hidden></div>';
    } else {
      h += '<p class="se-hint">You cannot upload media.</p>';
    }
    openPanel(b.label, h);
    const choose = (item) => {
      el.removeAttribute('srcset');
      el.removeAttribute('sizes');
      el.src = item.url;
      if (item.alt) el.alt = item.alt;
      P.bind.set(key, { el, kind: 'image', value: item.id });
      update();
      openBindImage(el, key, b);
      toast('Image replaced.');
    };
    const up = panel.querySelector('[data-upload]');
    if (up) up.addEventListener('change', async () => {
      const f = up.files[0];
      if (!f) return;
      const fd = new FormData();
      fd.append('file', f);
      up.parentNode.classList.add('se-busy');
      const r = await json(C.media, fd);
      up.parentNode.classList.remove('se-busy');
      if (!r.ok || !r.items || !r.items[0]) { toast(r.error || 'Upload failed.', 'error'); return; }
      choose(r.items[0]);
    });
    panel.onclick = (e) => { if (sel === el && e.target.closest('[data-act="library"]')) showLibrary(el, 1, choose); };
  }

  // ------------------------------------------------------------------ text
  function startText(el, ev) {
    el.setAttribute('contenteditable', 'true');
    el.setAttribute('spellcheck', 'true');
    el.addEventListener('input', onInput);
    el.addEventListener('paste', onPaste);
    el.addEventListener('keydown', onTextKey);
    el.focus({ preventScroll: true });
    if (ev && d.caretRangeFromPoint) {
      const r = d.caretRangeFromPoint(ev.clientX, ev.clientY);
      if (r && el.contains(r.startContainer)) { const s = getSelection(); s.removeAllRanges(); s.addRange(r); }
    }
    buildToolbar(el);
  }

  function stopText(el) {
    el.removeAttribute('contenteditable');
    el.removeAttribute('spellcheck');
    el.removeEventListener('input', onInput);
    el.removeEventListener('paste', onPaste);
    el.removeEventListener('keydown', onTextKey);
    markText(el);
  }

  function onInput(e) { markText(e.currentTarget); }
  function onPaste(e) {
    e.preventDefault();
    const text = (e.clipboardData || window.clipboardData).getData('text/plain');
    d.execCommand('insertText', false, text);
  }
  function onTextKey(e) {
    if (e.key === 'Enter' && !e.isComposing) {
      e.preventDefault();
      d.execCommand('insertLineBreak');
    }
  }

  function markText(el) {
    const id = idOf(el);
    const o = originals.get(id);
    if (!o) return;
    const html = cleanHtml(el);
    if (html === cleanHtmlString(o.html)) P.text.delete(id); else P.text.set(id, el);
    update();
  }

  function cleanHtmlString(h) {
    const t = d.createElement('template');
    t.innerHTML = h;
    return cleanTree(t.content);
  }
  function cleanHtml(el) {
    const t = d.createElement('template');
    t.innerHTML = el.innerHTML;
    return cleanTree(t.content);
  }
  function cleanTree(frag) {
    frag.querySelectorAll('[contenteditable],[spellcheck]').forEach((n) => { n.removeAttribute('contenteditable'); n.removeAttribute('spellcheck'); });
    frag.querySelectorAll('strike').forEach((n) => { const s = d.createElement('s'); while (n.firstChild) s.appendChild(n.firstChild); n.replaceWith(s); });
    frag.querySelectorAll('font').forEach((n) => { const s = d.createElement('span'); if (n.color) s.style.color = n.color; while (n.firstChild) s.appendChild(n.firstChild); n.replaceWith(s); });
    const w = d.createElement('div');
    w.appendChild(frag);
    return w.innerHTML.replace(/<br>$/, '');
  }

  function setStyle(el, prop, value) {
    const id = idOf(el);
    if (!P.style.has(id)) P.style.set(id, { el, style: {} });
    const entry = P.style.get(id);
    entry.el = el;
    if (value === null || value === '') { el.style.removeProperty(prop); entry.style[prop] = null; }
    else { el.style.setProperty(prop, value); entry.style[prop] = value; }
    update();
  }

  function resetStyle(el) {
    const o = originals.get(idOf(el)) || { style: '' };
    const keys = new Set();
    (o.style + ';' + (el.getAttribute('style') || '')).split(';').forEach((dcl) => {
      const k = dcl.split(':')[0].trim().toLowerCase();
      if (STYLE_PROPS.includes(k)) keys.add(k);
    });
    const entry = P.style.get(idOf(el));
    if (entry) Object.keys(entry.style).forEach((k) => keys.add(k));
    if (!keys.size) { toast('This element has no style overrides.'); return; }
    keys.forEach((k) => setStyle(el, k, null));
    toast('Style reset. The site\'s own styles apply again.');
  }

  function changeTag(el, to) {
    if (tagOf(el) === to) return el;
    const id = idOf(el);
    const n = d.createElement(to);
    for (const a of Array.from(el.attributes)) n.setAttribute(a.name, a.value);
    while (el.firstChild) n.appendChild(el.firstChild);
    el.removeEventListener('input', onInput);
    el.removeEventListener('paste', onPaste);
    el.removeEventListener('keydown', onTextKey);
    el.replaceWith(n);
    const o = originals.get(id);
    if (o && o.tag === to) P.tag.delete(id); else P.tag.set(id, to);
    if (P.text.has(id)) P.text.set(id, n);
    if (P.style.has(id)) P.style.get(id).el = n;
    sel = n;
    n.addEventListener('input', onInput);
    n.addEventListener('paste', onPaste);
    n.addEventListener('keydown', onTextKey);
    n.focus({ preventScroll: true });
    selBox.querySelector('.se-tagname').textContent = nameOf(n);
    update();
    return n;
  }

  function removeEl(el) {
    const id = idOf(el);
    deselect();
    P.remove.set(id, el);
    el.remove();
    update();
    toast('Removed. Discard to bring it back.');
  }

  // Selection inside the edited element (kept while popovers have focus).
  let savedRange = null;
  function keepRange() {
    const s = getSelection();
    savedRange = s.rangeCount && sel && sel.contains(s.getRangeAt(0).commonAncestorContainer) ? s.getRangeAt(0).cloneRange() : null;
  }
  function restoreRange() {
    if (!savedRange || !sel) return false;
    sel.focus({ preventScroll: true });
    const s = getSelection();
    s.removeAllRanges();
    s.addRange(savedRange);
    return true;
  }

  function buildToolbar(el) {
    const tag = tagOf(el);
    const canTag = TAGS.includes(tag);
    const inLoop = loopOf(el);
    const cs = getComputedStyle(el);
    const toHex = (c) => { const m = c.match(/\d+(\.\d+)?/g); return m ? '#' + m.slice(0, 3).map((x) => (+x).toString(16).padStart(2, '0')).join('') : '#000000'; };
    let h = '';
    if (canTag) {
      h += '<label class="se-sr" for="se-tag">Element type</label><select id="se-tag" class="se-select" title="Element type">'
        + ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p'].map((t) => '<option value="' + t + '"' + (t === tag ? ' selected' : '') + '>' + (t === 'p' ? 'Paragraph' : t.toUpperCase()) + '</option>').join('')
        + (['div', 'span'].includes(tag) ? '<option value="' + tag + '" selected>' + (tag === 'div' ? 'Block' : 'Inline') + '</option>' : '')
        + '</select><span class="se-sep"></span>';
    }
    h += '<button type="button" class="se-tb" data-cmd="bold" title="Bold (Ctrl+B)" aria-label="Bold"><b>B</b></button>'
      + '<button type="button" class="se-tb" data-cmd="italic" title="Italic (Ctrl+I)" aria-label="Italic"><i>I</i></button>'
      + '<button type="button" class="se-tb" data-cmd="underline" title="Underline (Ctrl+U)" aria-label="Underline"><u>U</u></button>'
      + '<button type="button" class="se-tb" data-cmd="strikeThrough" title="Strikethrough" aria-label="Strikethrough"><s>S</s></button>'
      + '<button type="button" class="se-tb" data-pop="link" title="Link" aria-label="Link" aria-haspopup="true">' + ico.link + '</button>'
      + '<span class="se-sep"></span>'
      + '<label class="se-tb se-color" title="Text colour"><span class="se-sr">Text colour</span><input type="color" data-color value="' + toHex(cs.color) + '"><span class="se-swatch" style="background:' + esc(cs.color) + '"></span></label>'
      + '<button type="button" class="se-tb se-wide" data-pop="size" title="Text size" aria-label="Text size" aria-haspopup="true">' + Math.round(parseFloat(cs.fontSize)) + '<small>px</small></button>'
      + '<button type="button" class="se-tb" data-pop="align" title="Alignment" aria-label="Alignment" aria-haspopup="true">' + (ico[cs.textAlign] || ico.left) + '</button>'
      + '<button type="button" class="se-tb" data-act="reset" title="Reset style: use the site\'s own styles" aria-label="Reset style">' + ico.reset + '</button>';
    if (C.ai) h += '<span class="se-sep"></span><button type="button" class="se-tb se-ai" data-pop="ai" title="Rewrite with AI" aria-label="Rewrite with AI" aria-haspopup="true">✦</button>';
    if (inLoop) h += '<span class="se-sep"></span><button type="button" class="se-tb se-wide" data-act="loop" title="Repeating group: add, move or remove items">' + ico.loop + '<span>Group</span></button>';
    h += '<span class="se-sep"></span><button type="button" class="se-tb se-danger" data-act="remove" title="Remove element" aria-label="Remove element">' + ico.trash + '</button>';
    // Popovers
    h += linkPop();
    h += '<div class="se-pop" data-for="size" hidden><label class="se-label" for="se-size">Size in px</label><div class="se-row"><input id="se-size" class="se-input se-num" type="number" min="6" max="200" step="1" value="' + Math.round(parseFloat(cs.fontSize)) + '"><button type="button" class="se-btn se-primary" data-act="size-apply">Set</button></div>'
      + '<div class="se-chips">' + [14, 16, 18, 20, 24, 32, 40, 48, 64].map((s) => '<button type="button" class="se-chip" data-size="' + s + '">' + s + '</button>').join('') + '</div></div>';
    h += '<div class="se-pop" data-for="align" hidden><div class="se-row">' + ['left', 'center', 'right', 'justify'].map((a) => '<button type="button" class="se-tb" data-align="' + a + '" aria-label="Align ' + a + '" title="Align ' + a + '">' + ico[a] + '</button>').join('') + '</div></div>';
    h += aiPop();
    toolbar.innerHTML = h;
    toolbar.hidden = false;
    placeToolbar();
  }

  function linkPop() {
    return '<div class="se-pop" data-for="link" hidden><label class="se-label" for="se-href">Link address</label><input id="se-href" class="se-input" type="text" placeholder="https://… or /page" autocomplete="off">'
      + '<label class="se-check"><input type="checkbox" id="se-blank"> Open in a new tab</label>'
      + '<div class="se-row"><button type="button" class="se-btn se-primary" data-act="link-apply">Apply</button><button type="button" class="se-btn se-ghost" data-act="link-remove">Remove link</button></div></div>';
  }
  function aiPop() {
    if (!C.ai) return '';
    return '<div class="se-pop se-menu" data-for="ai" hidden role="menu">'
      + [['shorter', 'Shorter'], ['longer', 'Longer'], ['grammar', 'Fix grammar'], ['formal', 'More formal'], ['casual', 'More casual'], ['simpler', 'Simpler words']]
        .map(([a, l]) => '<button type="button" role="menuitem" class="se-mi" data-ai="' + a + '">' + l + '</button>').join('')
      + '<div class="se-row"><label class="se-sr" for="se-lang">Language</label><input id="se-lang" class="se-input" type="text" placeholder="Translate to… e.g. Spanish"><button type="button" class="se-btn se-primary" data-ai="translate">Translate</button></div></div>';
  }
  const markAny = (el) => (selKind === 'bind' ? markBind(el) : markText(el));

  toolbar.addEventListener('mousedown', (e) => {
    const t = e.target;
    if (!t.closest('input,select,textarea,label')) e.preventDefault(); // keep the text selection
    keepRange();
  });

  toolbar.addEventListener('change', (e) => {
    if (!sel) return;
    if (e.target.id === 'se-tag') changeTag(sel, e.target.value);
  });
  toolbar.addEventListener('input', (e) => {
    if (!sel) return;
    if (e.target.matches('[data-color]')) {
      setStyle(sel, 'color', e.target.value);
      toolbar.querySelector('.se-swatch').style.background = e.target.value;
    }
  });

  function openPop(name, btn) {
    toolbar.querySelectorAll('.se-pop').forEach((p) => { if (p.dataset.for !== name) p.hidden = true; });
    const p = toolbar.querySelector('.se-pop[data-for="' + name + '"]');
    if (!p) return;
    p.hidden = !p.hidden;
    if (p.hidden) return;
    p.style.left = Math.max(0, btn.offsetLeft - 8) + 'px';
    if (name === 'link') {
      const a = currentLink();
      p.querySelector('#se-href').value = a ? a.getAttribute('href') || '' : '';
      p.querySelector('#se-blank').checked = a ? a.getAttribute('target') === '_blank' : false;
      p.querySelector('[data-act="link-remove"]').hidden = !a || a === sel;
      setTimeout(() => p.querySelector('#se-href').focus(), 0);
    }
  }

  function currentLink() {
    if (!sel) return null;
    if (tagOf(sel) === 'a') return sel;
    if (savedRange) {
      let n = savedRange.commonAncestorContainer;
      if (n.nodeType === 3) n = n.parentNode;
      const a = n.closest && n.closest('a');
      if (a && sel.contains(a)) return a;
    }
    return null;
  }

  function setAttr(el, name, value) {
    const id = idOf(el);
    if (value === null) el.removeAttribute(name); else el.setAttribute(name, value);
    P.attr.set(id + '|' + name, { el, id, name, value });
    update();
  }

  const okUrl = (u) => /^(https?:\/\/|\/|#|\?|mailto:|tel:|\.{0,2}\/)/i.test(u) || /^[\w.-]+(\.[a-z]{2,})(\/.*)?$/i.test(u) || /^[\w-]+(\.html?)?([#?].*)?$/i.test(u);

  toolbar.addEventListener('click', async (e) => {
    const b = e.target.closest('button');
    if (!b || !sel) return;
    if (b.dataset.cmd) {
      restoreRange();
      d.execCommand(b.dataset.cmd, false, null);
      markAny(sel);
      return;
    }
    if (b.dataset.pop) { openPop(b.dataset.pop, b); return; }
    if (b.dataset.size) { setStyle(sel, 'font-size', b.dataset.size + 'px'); toolbar.querySelector('#se-size').value = b.dataset.size; refreshSizeLabel(); return; }
    if (b.dataset.align) { setStyle(sel, 'text-align', b.dataset.align); toolbar.querySelector('[data-pop="align"]').innerHTML = ico[b.dataset.align]; b.closest('.se-pop').hidden = true; return; }
    if (b.dataset.ai) { aiRewrite(b.dataset.ai, b); return; }
    const act = b.dataset.act;
    if (act === 'size-apply') {
      const v = parseInt(toolbar.querySelector('#se-size').value, 10);
      if (v >= 6 && v <= 200) { setStyle(sel, 'font-size', v + 'px'); refreshSizeLabel(); b.closest('.se-pop').hidden = true; }
    } else if (act === 'reset') {
      resetStyle(sel);
      buildToolbar(sel);
    } else if (act === 'remove') {
      removeEl(sel);
    } else if (act === 'loop') {
      const l = loopOf(sel);
      const from = sel;
      deselect();
      select(l, { target: from });
    } else if (act === 'link-apply') {
      let url = toolbar.querySelector('#se-href').value.trim();
      const blank = toolbar.querySelector('#se-blank').checked;
      if (!url) return;
      if (/^www\./i.test(url)) url = 'https://' + url;
      if (/^\s*(javascript|data|vbscript):/i.test(url) || !okUrl(url)) { toast('Use a web address (https://…), a page path (/about), #section, mailto: or tel:.', 'error'); return; }
      let a = currentLink();
      if (a === sel) {
        setAttr(sel, 'href', url);
        setAttr(sel, 'target', blank ? '_blank' : null);
        if (blank) setAttr(sel, 'rel', 'noopener');
      } else {
        if (!a) {
          restoreRange();
          if (!savedRange || savedRange.collapsed) { toast('Select some text first.', 'error'); return; }
          const marker = 'se-' + Date.now();
          d.execCommand('createLink', false, marker);
          a = sel.querySelector('a[href="' + marker + '"]');
          sel.querySelectorAll('a[href="' + marker + '"]').forEach((x) => x.setAttribute('href', url));
        }
        if (a) {
          a.setAttribute('href', url);
          if (blank) { a.setAttribute('target', '_blank'); a.setAttribute('rel', 'noopener'); } else { a.removeAttribute('target'); }
        }
        markAny(sel);
      }
      b.closest('.se-pop').hidden = true;
    } else if (act === 'link-remove') {
      const a = currentLink();
      if (a && a !== sel) {
        while (a.firstChild) a.parentNode.insertBefore(a.firstChild, a);
        a.remove();
        markAny(sel);
      }
      b.closest('.se-pop').hidden = true;
    }
  });

  toolbar.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.id === 'se-href') { e.preventDefault(); toolbar.querySelector('[data-act="link-apply"]').click(); }
    if (e.key === 'Enter' && e.target.id === 'se-size') { e.preventDefault(); toolbar.querySelector('[data-act="size-apply"]').click(); }
    if (e.key === 'Enter' && e.target.id === 'se-lang') { e.preventDefault(); toolbar.querySelector('[data-ai="translate"]').click(); }
  });

  function refreshSizeLabel() {
    const b = toolbar.querySelector('[data-pop="size"]');
    if (b && sel) b.innerHTML = Math.round(parseFloat(getComputedStyle(sel).fontSize)) + '<small>px</small>';
  }

  async function aiRewrite(action, btn) {
    const el = sel;
    const lang = action === 'translate' ? (toolbar.querySelector('#se-lang').value || '').trim() : '';
    if (action === 'translate' && !lang) { toast('Type a language first.', 'error'); return; }
    btn.closest('.se-pop').hidden = true;
    const ai = toolbar.querySelector('.se-ai');
    ai.classList.add('se-busy');
    ai.disabled = true;
    const r = await json(C.ai, { action, text: cleanHtml(el), lang });
    ai.classList.remove('se-busy');
    ai.disabled = false;
    if (!r.ok) { toast(r.error || 'The AI request failed.', 'error'); return; }
    if (selKind === 'bind' && BINDS[bindKey(el)].kind !== 'html') { const t = d.createElement('template'); t.innerHTML = r.html; el.textContent = t.content.textContent; } else el.innerHTML = r.html;
    markAny(el);
    toast('Rewritten. Press Discard to undo all changes.');
  }

  // ------------------------------------------------------------------ side panel
  function openPanel(title, html) {
    panel.innerHTML = '<header class="se-ph"><h2 id="se-panel-title">' + esc(title) + '</h2><button type="button" class="se-btn se-ghost se-icon" data-act="close" aria-label="Close panel">' + ico.close + '</button></header><div class="se-pb">' + html + '</div>';
    panel.hidden = false;
    ui.classList.add('se-panel-open');
  }
  function closePanel() {
    panel.hidden = true;
    panel.innerHTML = '';
    panel.onclick = null;
    ui.classList.remove('se-panel-open');
  }
  panel.addEventListener('click', (e) => { if (e.target.closest('[data-act="close"]')) deselect(); });

  // ---------- images
  const imgOf = (el) => (tagOf(el) === 'img' ? el : el.querySelector('img'));
  function imageOp(el, patch) {
    const id = idOf(el);
    const cur = P.image.get(id) || { el, op: {} };
    cur.el = el;
    Object.assign(cur.op, patch);
    P.image.set(id, cur);
    update();
  }

  function applySrc(el, item) {
    const img = imgOf(el);
    img.removeAttribute('srcset');
    img.removeAttribute('sizes');
    const pic = img.closest('picture');
    if (pic) pic.querySelectorAll('source').forEach((s) => s.remove());
    img.src = item.url;
    img.setAttribute('data-media-id', String(item.id));
    if (item.width && item.height) { img.setAttribute('width', item.width); img.setAttribute('height', item.height); }
    if (item.alt) img.alt = item.alt;
    const cur = P.image.get(idOf(el));
    if (cur) delete cur.op.src;
    imageOp(el, { media: item.id });
    openImage(el);
  }

  function openImage(el) {
    const img = imgOf(el);
    if (!img) { openMedia(el); return; }
    const st = img.style;
    const asp = (st.aspectRatio || '').replace(/\s/g, '');
    const aspects = [['', 'Original'], ['1/1', '1:1'], ['4/3', '4:3'], ['3/2', '3:2'], ['16/9', '16:9'], ['9/16', '9:16']];
    const known = aspects.some(([v]) => v === asp);
    const media = img.getAttribute('data-media-id');
    const pos = (st.objectPosition || '').trim();
    const fit = st.objectFit || '';
    const sizeNow = (() => { const mw = st.maxWidth; if (/thumb/.test(img.src)) return 'thumb'; return mw === '640px' ? 'medium' : mw === '1024px' ? 'large' : 'full'; })();
    const arrow = (deg) => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="transform:rotate(' + deg + 'deg)"><path d="M12 19V5M5 12l7-7 7 7"/></svg>';
    const presets = [['left top', arrow(-45)], ['center top', arrow(0)], ['right top', arrow(45)], ['left center', arrow(-90)], ['center center', '<svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><circle cx="5" cy="5" r="3.5" fill="currentColor"/></svg>'], ['right center', arrow(90)], ['left bottom', arrow(-135)], ['center bottom', arrow(180)], ['right bottom', arrow(135)]];
    let h = '<div class="se-preview"><img src="' + esc(img.getAttribute('src') || '') + '" alt=""></div>';
    if (C.media) {
      h += '<div class="se-row"><label class="se-btn se-primary se-file">Upload<input type="file" accept="image/*" data-upload hidden></label>'
        + '<button type="button" class="se-btn se-ghost" data-act="library">Media library</button></div><div class="se-lib" hidden></div>';
    } else {
      h += '<label class="se-label" for="se-src">Image address</label><div class="se-row"><input id="se-src" class="se-input" type="url" value="' + esc(img.getAttribute('src')) + '"><button type="button" class="se-btn se-ghost" data-act="src">Use</button></div>';
    }
    h += '<label class="se-label" for="se-alt">Alt text <span class="se-hint">(describes the image for screen readers and search)</span></label>'
      + '<textarea id="se-alt" class="se-input" rows="2">' + esc(img.getAttribute('alt') || '') + '</textarea>'
      + (C.ai ? '<button type="button" class="se-link" data-act="alt-ai">✦ Suggest alt text</button>' : '');
    if (media) {
      h += '<label class="se-label" for="se-isize">Size</label><select id="se-isize" class="se-input">'
        + [['thumb', 'Thumbnail'], ['medium', 'Medium'], ['large', 'Large'], ['full', 'Full size']].map(([v, l]) => '<option value="' + v + '"' + (v === sizeNow ? ' selected' : '') + '>' + l + '</option>').join('') + '</select>';
    } else {
      h += '<div class="se-grid2"><div><label class="se-label" for="se-w">Width</label><input id="se-w" class="se-input" type="number" min="1" value="' + esc(img.getAttribute('width') || '') + '" placeholder="auto"></div>'
        + '<div><label class="se-label" for="se-h">Height</label><input id="se-h" class="se-input" type="number" min="1" value="' + esc(img.getAttribute('height') || '') + '" placeholder="auto"></div></div>';
    }
    h += '<span class="se-label" id="se-asp-l">Aspect ratio</span><div class="se-chips" role="radiogroup" aria-labelledby="se-asp-l">'
      + aspects.map(([v, l]) => '<button type="button" class="se-chip" role="radio" aria-checked="' + (v === asp || (!asp && !v)) + '" data-aspect="' + v + '">' + l + '</button>').join('')
      + '<button type="button" class="se-chip" role="radio" aria-checked="' + (!!asp && !known) + '" data-aspect="custom">Custom</button></div>'
      + '<div class="se-row se-custom" ' + (asp && !known ? '' : 'hidden') + '><input class="se-input se-num" id="se-asp" type="text" placeholder="e.g. 5/4" value="' + esc(asp && !known ? asp : '') + '" aria-label="Custom aspect ratio"><button type="button" class="se-btn se-ghost" data-act="aspect-custom">Set</button></div>'
      + '<span class="se-label" id="se-fit-l">Fit</span><div class="se-seg" role="radiogroup" aria-labelledby="se-fit-l">'
      + [['cover', 'Fill (crop)'], ['contain', 'Fit inside']].map(([v, l]) => '<button type="button" role="radio" aria-checked="' + (fit === v) + '" data-fit="' + v + '">' + l + '</button>').join('') + '</div>'
      + '<span class="se-label" id="se-pos-l">Focus point</span><div class="se-focus" role="radiogroup" aria-labelledby="se-pos-l">'
      + presets.map(([v, l]) => '<button type="button" role="radio" aria-label="' + v + '" aria-checked="' + (pos === v || (!pos && v === 'center center')) + '" data-pos="' + v + '">' + l + '</button>').join('') + '</div>'
      + '<div class="se-row se-end"><button type="button" class="se-btn se-danger-btn" data-act="img-remove">' + ico.trash + ' Remove image</button></div>';
    if (loopOf(el)) h += '<button type="button" class="se-btn se-ghost se-full" data-act="loop">' + ico.loop + ' Repeating group</button>';
    openPanel('Image', h);
    wireImage(el);
  }

  function wireImage(el) {
    const img = imgOf(el);
    const q = (s) => panel.querySelector(s);
    const up = q('[data-upload]');
    if (up) up.addEventListener('change', async () => {
      const f = up.files[0];
      if (!f) return;
      const fd = new FormData();
      fd.append('file', f);
      const lbl = up.parentNode;
      lbl.classList.add('se-busy');
      const r = await json(C.media, fd);
      lbl.classList.remove('se-busy');
      if (!r.ok || !r.items || !r.items[0]) { toast(r.error || 'Upload failed.', 'error'); return; }
      applySrc(el, r.items[0]);
      toast('Image replaced.');
    });
    const alt = q('#se-alt');
    alt.addEventListener('input', () => { img.alt = alt.value; imageOp(el, { alt: alt.value }); });
    const isize = q('#se-isize');
    if (isize) isize.addEventListener('change', () => {
      const v = isize.value;
      img.style.maxWidth = v === 'medium' ? '640px' : v === 'large' ? '1024px' : '';
      if (v === 'medium' || v === 'large') img.style.width = '100%'; else if (img.style.width === '100%') img.style.width = '';
      imageOp(el, { size: v });
    });
    ['w', 'h'].forEach((k) => {
      const inp = q('#se-' + k);
      if (inp) inp.addEventListener('change', () => {
        const v = inp.value.trim();
        const name = k === 'w' ? 'width' : 'height';
        if (v === '') img.removeAttribute(name); else img.setAttribute(name, String(parseInt(v, 10)));
        imageOp(el, { [name]: v === '' ? null : parseInt(v, 10) });
      });
    });
    panel.onclick = async (e) => {
      if (sel !== el) return;
      const b = e.target.closest('button');
      if (!b) return;
      if (b.dataset.aspect !== undefined) {
        const v = b.dataset.aspect;
        if (v === 'custom') { q('.se-custom').hidden = false; q('#se-asp').focus(); return; }
        setAspect(el, img, v);
        return;
      }
      if (b.dataset.fit) { img.style.objectFit = b.dataset.fit; imageOp(el, { fit: b.dataset.fit }); mark(b, '[data-fit]'); return; }
      if (b.dataset.pos) { img.style.objectPosition = b.dataset.pos; imageOp(el, { position: b.dataset.pos }); mark(b, '[data-pos]'); return; }
      const act = b.dataset.act;
      if (act === 'aspect-custom') {
        const v = q('#se-asp').value.trim().replace(':', '/');
        if (!/^\d+(\.\d+)?\s*\/\s*\d+(\.\d+)?$/.test(v)) { toast('Write the ratio as width/height, for example 5/4.', 'error'); return; }
        setAspect(el, img, v.replace(/\s/g, ''));
      } else if (act === 'library') {
        showLibrary(el);
      } else if (act === 'src') {
        const v = q('#se-src').value.trim();
        if (!/^(https?:\/\/|\/)/i.test(v)) { toast('Use an https:// address or a path starting with /.', 'error'); return; }
        img.removeAttribute('srcset');
        img.src = v;
        imageOp(el, { src: v });
      } else if (act === 'alt-ai') {
        b.classList.add('se-busy');
        const near = (el.closest('section,article,figure,li,div') || el.parentNode || el).textContent.replace(/\s+/g, ' ').slice(0, 800);
        const r = await json(C.ai, { action: 'alt', text: near, context: (img.getAttribute('src') || '').split('/').pop() });
        b.classList.remove('se-busy');
        if (!r.ok) { toast(r.error || 'The AI request failed.', 'error'); return; }
        q('#se-alt').value = r.html;
        img.alt = r.html;
        imageOp(el, { alt: r.html });
      } else if (act === 'img-remove') {
        removeEl(el);
      } else if (act === 'loop') {
        const l = loopOf(el);
        deselect();
        select(l, { target: el });
      }
    };
  }

  function mark(b, group) {
    panel.querySelectorAll(group).forEach((x) => x.setAttribute('aria-checked', x === b ? 'true' : 'false'));
  }

  function setAspect(el, img, v) {
    if (!v) {
      img.style.aspectRatio = '';
      if (img.style.height === 'auto') img.style.height = '';
      imageOp(el, { aspect: 'original' });
    } else {
      img.style.aspectRatio = v.replace('/', ' / ');
      img.style.height = 'auto';
      if (!img.style.objectFit) img.style.objectFit = 'cover';
      imageOp(el, { aspect: v });
    }
    panel.querySelectorAll('[data-aspect]').forEach((x) => x.setAttribute('aria-checked', x.dataset.aspect === v || (x.dataset.aspect === 'custom' && v && !['1/1', '4/3', '3/2', '16/9', '9/16'].includes(v)) ? 'true' : 'false'));
  }

  async function showLibrary(el, page, choose) {
    const box = panel.querySelector('.se-lib');
    box.hidden = false;
    box.innerHTML = '<p class="se-hint">Loading…</p>';
    const r = await json(C.media + '?filter=image&per=40&page=' + (page || 1));
    if (!r.ok) { box.innerHTML = '<p class="se-hint">' + esc(r.error || 'Could not load the library.') + '</p>'; return; }
    if (!r.items.length) { box.innerHTML = '<p class="se-hint">No images yet. Upload one.</p>'; return; }
    box.innerHTML = '<div class="se-libgrid">' + r.items.map((m, i) => '<button type="button" data-i="' + i + '" title="' + esc(m.title || m.original_name || '') + '"><img src="' + esc(m.thumb || m.url) + '" alt="' + esc(m.alt || m.title || '') + '" loading="lazy"></button>').join('') + '</div>'
      + (r.pages > 1 ? '<div class="se-row">' + ((page || 1) > 1 ? '<button type="button" class="se-btn se-ghost" data-page="' + ((page || 1) - 1) + '">Previous</button>' : '') + ((page || 1) < r.pages ? '<button type="button" class="se-btn se-ghost" data-page="' + ((page || 1) + 1) + '">Next</button>' : '') + '</div>' : '');
    box.onclick = (e) => {
      const b = e.target.closest('button');
      if (!b) return;
      e.stopPropagation();
      if (b.dataset.page) { showLibrary(el, +b.dataset.page, choose); return; }
      (choose || ((it) => applySrc(el, it)))(r.items[+b.dataset.i]);
    };
  }

  // ---------- icons
  function openIcon(el) {
    const isSvg = tagOf(el) === 'svg';
    let h = '<div class="se-preview se-iconprev">' + (isSvg ? el.outerHTML.replace(/\son\w+=/gi, ' x=') : '<i class="' + esc(cls(el)) + '"></i>') + '</div>';
    if (!isSvg) {
      h += '<label class="se-label" for="se-icls">Icon classes</label><div class="se-row"><input id="se-icls" class="se-input" type="text" value="' + esc(cls(el)) + '" spellcheck="false"><button type="button" class="se-btn se-primary" data-act="icon-class">Apply</button></div>'
        + '<p class="se-hint">For example <code>fa-solid fa-star</code> (Font Awesome) or <code>bi bi-star</code> (Bootstrap Icons), whichever the site uses.</p>';
    }
    h += '<label class="se-label" for="se-isvg">' + (isSvg ? 'Replace with SVG code' : 'Or paste SVG code') + '</label><textarea id="se-isvg" class="se-input se-code" rows="5" spellcheck="false" placeholder="&lt;svg …&gt;…&lt;/svg&gt;"></textarea>'
      + '<div class="se-row"><button type="button" class="se-btn se-primary" data-act="icon-svg">Use SVG</button></div>'
      + '<div class="se-row se-end"><button type="button" class="se-btn se-danger-btn" data-act="icon-remove">' + ico.trash + ' Remove icon</button></div>';
    if (loopOf(el)) h += '<button type="button" class="se-btn se-ghost se-full" data-act="loop">' + ico.loop + ' Repeating group</button>';
    openPanel('Icon', h);
    panel.onclick = (e) => {
      const b = e.target.closest('button');
      if (!b || sel !== el) return;
      const id = idOf(el);
      if (b.dataset.act === 'icon-class') {
        const v = panel.querySelector('#se-icls').value.trim().replace(/\s+/g, ' ');
        if (!ICON_RE.test(v)) { toast('Use icon classes such as "fa-solid fa-star" or "bi bi-star".', 'error'); return; }
        el.setAttribute('class', v);
        P.icon.set(id, { el, op: { class: v } });
        panel.querySelector('.se-iconprev').innerHTML = '<i class="' + esc(v) + '"></i>';
        update();
      } else if (b.dataset.act === 'icon-svg') {
        const v = panel.querySelector('#se-isvg').value.trim();
        if (!/^<svg[\s>][\s\S]*<\/svg>$/i.test(v)) { toast('Paste a complete <svg>…</svg> element.', 'error'); return; }
        const t = d.createElement('template');
        t.innerHTML = v;
        const svg = t.content.querySelector('svg');
        if (!svg) return;
        svg.querySelectorAll('script,foreignObject').forEach((n) => n.remove());
        [svg, ...svg.querySelectorAll('*')].forEach((n) => Array.from(n.attributes).forEach((a) => { if (/^on/i.test(a.name)) n.removeAttribute(a.name); }));
        svg.setAttribute('data-se', id);
        if (isSvg && el.getAttribute('class') && !svg.getAttribute('class')) svg.setAttribute('class', el.getAttribute('class'));
        if (!isSvg && !svg.getAttribute('width') && !svg.getAttribute('height')) { svg.setAttribute('width', '1em'); svg.setAttribute('height', '1em'); }
        el.replaceWith(svg);
        P.icon.set(id, { el: svg, op: { svg: v } });
        update();
        sel = svg;
        openIcon(svg);
      } else if (b.dataset.act === 'icon-remove') {
        removeEl(el);
      } else if (b.dataset.act === 'loop') {
        const l = loopOf(el);
        deselect();
        select(l, { target: el });
      }
    };
  }

  // ---------- video / embeds: remove only
  function openMedia(el) {
    let h = '<p class="se-hint">This ' + esc(nameOf(el).toLowerCase()) + ' can be removed here. To change it, edit the page in the dashboard.</p>'
      + '<div class="se-row se-end"><button type="button" class="se-btn se-danger-btn" data-act="m-remove">' + ico.trash + ' Remove</button></div>';
    openPanel(nameOf(el), h);
    panel.onclick = (e) => { if (e.target.closest('[data-act="m-remove"]') && sel === el) removeEl(el); };
  }

  // ---------- repeating groups
  function itemIndex(loopEl, target, data) {
    if (!target || !data) return -1;
    const item = target.closest && target.closest('[data-se-item]');
    if (!item || !loopEl.contains(item)) return -1;
    const ids = Array.from(item.querySelectorAll('[data-se]')).map((n) => n.getAttribute('data-se'));
    if (item.hasAttribute('data-se')) ids.unshift(item.getAttribute('data-se'));
    for (let i = 0; i < data.items.length; i++) if (ids.some((x) => data.items[i].ids.includes(x))) return i;
    return -1;
  }

  function openLoop(el, target) {
    const data = loopData(el);
    if (!data) { openPanel('Repeating group', '<p class="se-hint">Save your changes and reload the page to edit this group.</p>'); return; }
    if (data.dynamic) {
      const s = data.dynamic;
      const src = s.kind === 'posts' ? ((C.sources.types.find((t) => t.slug === s.type) || {}).label || s.type) : ((C.sources.taxonomies.find((t) => t.slug === s.taxonomy) || {}).label || s.taxonomy);
      openPanel('Dynamic list', '<p>This group shows <strong>' + esc(s.limit) + ' ' + esc(src.toLowerCase()) + '</strong>' + (s.term ? ' filtered by <code>' + esc(s.term) + '</code>' : '')
        + ', updated automatically when content changes.</p><p class="se-hint">Its items cannot be edited one by one. Edit the content in the dashboard, or make the group static again to get the original items back.</p>'
        + '<div class="se-row"><button type="button" class="se-btn se-primary" data-act="static">Make static again</button></div>');
      panel.onclick = (e) => { if (e.target.closest('[data-act="static"]')) loopAction({ op: 'loop_static', id: data.id }, 'Making the group static'); };
      return;
    }
    const cur = itemIndex(el, target, data);
    const n = data.items.length;
    let h = '<p class="se-hint">Items repeat the same design (cards, slides, list entries). Changes here save the page and reload it so sliders start fresh.</p><ol class="se-items">'
      + data.items.map((it, i) => '<li class="' + (i === cur ? 'se-cur' : '') + '"><span class="se-thumb">' + (it.thumb ? '<img src="' + esc(it.thumb) + '" alt="">' : '<span>' + (i + 1) + '</span>') + '</span>'
        + '<span class="se-itl">' + esc(it.label || 'Item ' + (i + 1)) + '</span><span class="se-ita">'
        + '<button type="button" class="se-ib" data-li="' + i + '" data-la="up" aria-label="Move item ' + (i + 1) + ' up" title="Move up"' + (i === 0 ? ' disabled' : '') + '>' + ico.up + '</button>'
        + '<button type="button" class="se-ib" data-li="' + i + '" data-la="down" aria-label="Move item ' + (i + 1) + ' down" title="Move down"' + (i === n - 1 ? ' disabled' : '') + '>' + ico.down + '</button>'
        + '<button type="button" class="se-ib" data-li="' + i + '" data-la="duplicate" aria-label="Duplicate item ' + (i + 1) + '" title="Duplicate">' + ico.copy + '</button>'
        + '<button type="button" class="se-ib se-danger" data-li="' + i + '" data-la="remove" aria-label="Delete item ' + (i + 1) + '" title="Delete"' + (n <= 1 ? ' disabled' : '') + '>' + ico.trash + '</button>'
        + '</span></li>').join('') + '</ol>';
    if (MODE === 'html') h += '<details class="se-dyn"' + (n ? '' : ' open') + '><summary>Make dynamic</summary>' + dynamicForm(data) + '</details>';
    openPanel('Repeating group', h);
    wireDynamic(data);
    panel.onclick = (e) => {
      const b = e.target.closest('button');
      if (!b) return;
      if (b.dataset.la) {
        const i = +b.dataset.li;
        const a = b.dataset.la;
        if (a === 'remove' && !confirm('Delete item ' + (i + 1) + '?')) return;
        const op = a === 'up' ? { op: 'loop', id: data.id, action: 'move', index: i, to: i - 1 }
          : a === 'down' ? { op: 'loop', id: data.id, action: 'move', index: i, to: i + 1 }
            : { op: 'loop', id: data.id, action: a, index: i };
        loopAction(op, a === 'duplicate' ? 'Duplicating' : a === 'remove' ? 'Deleting' : 'Moving');
      } else if (b.dataset.act === 'dynamic') {
        makeDynamic(data);
      }
    };
  }

  function dynamicForm(data) {
    const opts = C.sources.types.map((t) => '<option value="posts:' + esc(t.slug) + '">' + esc(t.label) + '</option>').join('');
    const topts = C.sources.taxonomies.map((t) => '<option value="terms:' + esc(t.slug) + '">' + esc(t.label) + '</option>').join('');
    return '<label class="se-label" for="se-dsrc">Show</label><select id="se-dsrc" class="se-input"><optgroup label="Content">' + opts + '</optgroup>'
      + (topts ? '<optgroup label="Taxonomy terms">' + topts + '</optgroup>' : '') + '</select>'
      + '<div class="se-grid2"><div><label class="se-label" for="se-dlim">How many</label><input id="se-dlim" class="se-input" type="number" min="1" max="50" value="' + Math.max(1, data.items.length) + '"></div>'
      + '<div><label class="se-label" for="se-dord">Order</label><select id="se-dord" class="se-input"></select></div></div>'
      + '<div class="se-dfilter"><label class="se-label" for="se-dtax">Only items in</label><div class="se-grid2"><select id="se-dtax" class="se-input"></select><select id="se-dterm" class="se-input" aria-label="Term" disabled><option value="">Any</option></select></div></div>'
      + '<span class="se-label">What goes where</span><div class="se-map"></div>'
      + '<div class="se-row"><button type="button" class="se-btn se-primary" data-act="dynamic">Make dynamic</button></div>'
      + '<p class="se-hint">The first item becomes the design for every item. The current items are kept, so you can make the group static again.</p>';
  }

  function wireDynamic(data) {
    const q = (s) => panel.querySelector(s);
    const src = q('#se-dsrc');
    if (!src) return;
    const render = () => {
      const [kind, slug] = src.value.split(':');
      const ord = q('#se-dord');
      ord.innerHTML = (kind === 'posts'
        ? [['published_at:desc', 'Newest first'], ['published_at:asc', 'Oldest first'], ['title:asc', 'Title A to Z'], ['updated_at:desc', 'Recently updated'], ['menu_order:asc', 'Manual order']]
        : [['name:asc', 'Name A to Z'], ['count:desc', 'Most used first'], ['id:desc', 'Newest first']]).map(([v, l]) => '<option value="' + v + '">' + l + '</option>').join('');
      const filter = q('.se-dfilter');
      const type = C.sources.types.find((t) => t.slug === slug);
      filter.hidden = kind !== 'posts' || !type || !type.taxonomies.length;
      if (!filter.hidden) {
        q('#se-dtax').innerHTML = '<option value="">No filter</option>' + type.taxonomies.map((t) => '<option value="' + esc(t.slug) + '">' + esc(t.label) + '</option>').join('');
        q('#se-dterm').innerHTML = '<option value="">Any</option>';
        q('#se-dterm').disabled = true;
      }
      renderMap(data, kind, slug);
    };
    src.addEventListener('change', render);
    q('#se-dtax').addEventListener('change', async () => {
      const tax = q('#se-dtax').value;
      const term = q('#se-dterm');
      term.innerHTML = '<option value="">Any</option>';
      term.disabled = !tax;
      if (!tax) return;
      const r = await json(C.terms + '?taxonomy=' + encodeURIComponent(tax));
      if (r.ok) term.innerHTML = '<option value="">Any</option>' + r.items.map((t) => '<option value="' + esc(t.slug) + '">' + esc(t.name) + '</option>').join('');
    });
    render();
  }

  function mapOptions(kind, slug, fieldKind) {
    const none = [['', 'Keep as is']];
    if (kind === 'posts') {
      const type = C.sources.types.find((t) => t.slug === slug) || { fields: [] };
      if (fieldKind === 'image') return none.concat([['image', 'Featured image']], type.fields.filter((f) => f.type === 'image').map((f) => ['field:' + f.name, f.label]));
      if (fieldKind === 'link') return none.concat([['url', 'Link to the item']]);
      return none.concat([['title', 'Title'], ['excerpt', 'Excerpt'], ['date', 'Date'], ['author', 'Author']], type.fields.filter((f) => f.type !== 'image').map((f) => ['field:' + f.name, f.label]));
    }
    const tax = C.sources.taxonomies.find((t) => t.slug === slug) || { fields: [] };
    if (fieldKind === 'image') return none.concat(tax.fields.filter((f) => f.type === 'image').map((f) => ['term:field:' + f.name, f.label]));
    if (fieldKind === 'link') return none.concat([['term:url', 'Link to the term']]);
    return none.concat([['term:name', 'Name'], ['term:description', 'Description'], ['term:count', 'Number of items']], tax.fields.filter((f) => f.type !== 'image').map((f) => ['term:field:' + f.name, f.label]));
  }

  function guessMap(fields, kind, slug) {
    const g = {};
    const P2 = kind === 'posts' ? { title: 'title', url: 'url', text: 'excerpt' } : { title: 'term:name', url: 'term:url', text: 'term:description' };
    const heading = fields.find((f) => f.kind === 'text' && /^h[1-6]$/.test(f.tag)) || fields.find((f) => f.kind === 'text' && ['strong', 'a'].includes(f.tag));
    if (heading) g[heading.key] = P2.title;
    const img = fields.find((f) => f.kind === 'image');
    if (img && kind === 'posts') g[img.key] = 'image';
    if (img && kind === 'terms') {
      const tf = ((C.sources.taxonomies.find((t) => t.slug === slug) || {}).fields || []).find((f) => f.type === 'image');
      if (tf) g[img.key] = 'term:field:' + tf.name;
    }
    const link = fields.find((f) => f.kind === 'link' && f.key !== 'link:item') || fields.find((f) => f.kind === 'link');
    if (link) g[link.key] = P2.url;
    const para = fields.find((f) => f.kind === 'text' && f.tag === 'p' && f !== heading);
    if (para) g[para.key] = P2.text;
    return g;
  }

  function renderMap(data, kind, slug) {
    const box = panel.querySelector('.se-map');
    const guess = guessMap(data.fields, kind, slug);
    if (!data.fields.length) { box.innerHTML = '<p class="se-hint">Nothing in the first item can show content.</p>'; return; }
    box.innerHTML = data.fields.map((f, i) => {
      const label = f.kind === 'image' ? 'Image' : f.kind === 'link' ? 'Link' : (NAMES[f.tag] || 'Text');
      const opts = mapOptions(kind, slug, f.kind);
      return '<div class="se-maprow"><label for="se-m' + i + '"><strong>' + esc(label) + '</strong> <span class="se-hint">' + esc(f.text || '') + '</span></label>'
        + '<select id="se-m' + i + '" class="se-input" data-key="' + esc(f.key) + '">' + opts.map(([v, l]) => '<option value="' + esc(v) + '"' + (guess[f.key] === v ? ' selected' : '') + '>' + esc(l) + '</option>').join('') + '</select></div>';
    }).join('');
  }

  function makeDynamic(data) {
    const q = (s) => panel.querySelector(s);
    const [kind, slug] = q('#se-dsrc').value.split(':');
    const [orderby, order] = q('#se-dord').value.split(':');
    const limit = parseInt(q('#se-dlim').value, 10) || 3;
    const source = { kind, limit, orderby, order };
    if (kind === 'posts') {
      source.type = slug;
      const tax = q('#se-dtax').value;
      const term = q('#se-dterm').value;
      if (tax && term) source.term = tax + ':' + term;
    } else {
      source.taxonomy = slug;
    }
    const map = {};
    panel.querySelectorAll('.se-map select').forEach((s) => { if (s.value) map[s.dataset.key] = s.value; });
    if (!Object.keys(map).length) { toast('Choose at least one thing to show.', 'error'); return; }
    loopAction({ op: 'loop_dynamic', id: data.id, source, map }, 'Making the group dynamic');
  }

  async function loopAction(op, label) {
    toast(label + '…');
    const ok = await save([op], true);
    if (!ok) return;
  }

  // ------------------------------------------------------------------ saving
  function buildOps() {
    const removed = Array.from(P.remove.values());
    const gone = (el) => removed.some((r) => r !== el && r.contains(el));
    const ops = [];
    P.remove.forEach((el, id) => { if (!gone(el)) ops.push({ op: 'remove', id }); });
    const alive = (el) => !P.remove.has(idOf(el)) && !gone(el);
    P.tag.forEach((tag, id) => { const el = P.text.get(id) || (P.style.get(id) || {}).el || d.querySelector('[data-se="' + id + '"]'); if (!el || alive(el)) ops.push({ op: 'tag', id, tag }); });
    P.text.forEach((el, id) => { if (alive(el)) ops.push({ op: 'text', id, html: cleanHtml(el) }); });
    P.style.forEach((v, id) => { if (alive(v.el) && Object.keys(v.style).length) ops.push({ op: 'style', id, style: v.style }); });
    P.attr.forEach((v) => { if (alive(v.el)) ops.push({ op: 'attr', id: v.id, name: v.name, value: v.value }); });
    P.image.forEach((v, id) => { if (alive(v.el)) ops.push(Object.assign({ op: 'image', id }, v.op)); });
    P.icon.forEach((v, id) => { if (alive(v.el)) ops.push(Object.assign({ op: 'icon', id }, v.op)); });
    P.bind.forEach((v, bind) => ops.push({ op: 'field', bind, value: v.kind === 'image' ? v.value : bindValue(v.el, v.kind) }));
    return ops;
  }

  function count() {
    let n = 0;
    Object.values(P).forEach((m) => { n += m.size; });
    return n;
  }

  function update() {
    const n = count();
    countEl.textContent = n === 0 ? 'No changes' : n === 1 ? '1 unsaved change' : n + ' unsaved changes';
    sbar.classList.toggle('se-dirty', n > 0);
    sbar.querySelector('[data-act="save"]').disabled = n === 0 || saving;
  }

  async function save(extra, reload) {
    if (saving) return false;
    if (sel && selKind === 'text') markText(sel);
    if (sel && selKind === 'bind' && sel.isContentEditable) markBind(sel);
    const hasBinds = P.bind.size > 0;
    const ops = buildOps().concat(extra || []);
    if (!ops.length) { toast('No changes to save.'); return false; }
    saving = true;
    const btn = sbar.querySelector('[data-act="save"]');
    btn.textContent = 'Saving…';
    update();
    const r = await json(C.save, { ops, base: C.updated });
    saving = false;
    btn.textContent = 'Save';
    if (!r.ok) {
      update();
      if (r.conflict) {
        if (confirm(r.error + '\n\nReload now? Your unsaved changes will be lost.')) { leaving = true; location.reload(); }
      } else {
        toast(r.error || 'Could not save.', 'error');
      }
      return false;
    }
    C.updated = r.updated;
    if (r.loops) C.loops = r.loops;
    Object.values(P).forEach((m) => m.clear());
    originals.clear();
    if (sel) originals.set(idOf(sel), { html: sel.innerHTML, tag: tagOf(sel), style: sel.getAttribute('style') || '' });
    update();
    if (reload || hasBinds) {
      // Values of the item are shown through the template's filters again (curly quotes, line breaks…).
      leaving = true;
      const u = new URL(location.href);
      u.searchParams.set('se-edit', '1');
      location.replace(u.toString());
      return true;
    }
    toast('Saved.', 'ok');
    return true;
  }

  sbar.addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.act === 'save') save();
    else if (b.dataset.act === 'discard') {
      if (count() && !confirm('Discard all unsaved changes?')) return;
      leaving = true;
      location.reload();
    } else if (b.dataset.act === 'done') setOn(false);
  });

  function refresh() { update(); }

  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', mount); else mount();
})();
