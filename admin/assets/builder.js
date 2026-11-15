/* Slate visual builder — GrapesJS configured for Slate: design-system colours and fonts first, the media library,
   class-based styles, three breakpoints, saved blocks, HTML/CSS editing, and lean output (HTML + CSS only). */
(() => {
  'use strict';
  const PB = window.PB;
  let loaded = false;
  const { toast, modal, esc, $, $$ } = window.Slate;
  const qs = (s, r = document) => r.querySelector(s);

  // ------------------------------------------------------------------ blocks
  const ph = (w, h) => 'data:image/svg+xml;utf8,' + encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}"><rect width="100%" height="100%" fill="#e9e5df"/><path d="M${w / 2 - 30} ${h / 2 + 18}l18-24 14 16 10-12 18 20z" fill="#c9c2b8"/></svg>`);
  const svgIcon = (d) => `<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">${d}</svg>`;
  const I = {
    section: svgIcon('<rect x="3" y="5" width="18" height="14" rx="2"/>'),
    cols2: svgIcon('<rect x="3" y="5" width="8" height="14" rx="1.5"/><rect x="13" y="5" width="8" height="14" rx="1.5"/>'),
    cols3: svgIcon('<rect x="2" y="5" width="6" height="14" rx="1.2"/><rect x="9" y="5" width="6" height="14" rx="1.2"/><rect x="16" y="5" width="6" height="14" rx="1.2"/>'),
    heading: svgIcon('<path d="M6 4v16M18 4v16M6 12h12"/>'),
    text: svgIcon('<path d="M4 6h16M4 10h16M4 14h10M4 18h12"/>'),
    image: svgIcon('<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/>'),
    button: svgIcon('<rect x="3" y="8" width="18" height="8" rx="4"/>'),
    link: svgIcon('<path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>'),
    list: svgIcon('<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>'),
    divider: svgIcon('<path d="M3 12h18"/>'),
    spacer: svgIcon('<path d="M12 4v16M8 8l4-4 4 4M8 16l4 4 4-4"/>'),
    video: svgIcon('<rect x="2" y="5" width="20" height="14" rx="2"/><path d="m10 9 5 3-5 3z"/>'),
    quote: svgIcon('<path d="M7 7h4v4H7zM7 11c0 3-1 5-3 6M15 7h4v4h-4zM15 11c0 3-1 5-3 6"/>'),
    hero: svgIcon('<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M6 10h12M8 14h8"/>'),
    cards: svgIcon('<rect x="2" y="6" width="6" height="12" rx="1"/><rect x="9" y="6" width="6" height="12" rx="1"/><rect x="16" y="6" width="6" height="12" rx="1"/>'),
    cta: svgIcon('<rect x="2" y="6" width="20" height="12" rx="2"/><rect x="14" y="10" width="5" height="4" rx="2"/>'),
    faq: svgIcon('<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3 2.5c-.5.2-.5.8-.5 1.5M12 17h.01"/>'),
    code: svgIcon('<path d="m8 8-4 4 4 4M16 8l4 4-4 4"/>'),
    tag: svgIcon('<path d="M4 7V4h16v3M9 20h6M12 4v16"/>'),
    form: svgIcon('<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h10M7 13h10M7 17h5"/>'),
    star: svgIcon('<path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/>'),
  };
  const blocks = [
    // Layout
    { id: 'section', label: 'Section', category: 'Layout', media: I.section, content: '<section class="section"><div class="container"><h2>Section title</h2><p>Write something here.</p></div></section>' },
    { id: 'container', label: 'Container', category: 'Layout', media: I.section, content: '<div class="container" style="min-height:80px"></div>' },
    { id: 'cols-2', label: '2 columns', category: 'Layout', media: I.cols2, content: '<div class="pb-row" style="display:flex;flex-wrap:wrap;gap:24px"><div style="flex:1 1 280px;min-height:80px"><p>Column one</p></div><div style="flex:1 1 280px;min-height:80px"><p>Column two</p></div></div>' },
    { id: 'cols-3', label: '3 columns', category: 'Layout', media: I.cols3, content: '<div class="pb-row" style="display:flex;flex-wrap:wrap;gap:24px"><div style="flex:1 1 220px;min-height:80px"><p>One</p></div><div style="flex:1 1 220px;min-height:80px"><p>Two</p></div><div style="flex:1 1 220px;min-height:80px"><p>Three</p></div></div>' },
    { id: 'spacer', label: 'Spacer', category: 'Layout', media: I.spacer, content: '<div style="height:48px"></div>' },
    { id: 'divider', label: 'Divider', category: 'Layout', media: I.divider, content: '<hr>' },
    // Basic
    { id: 'heading', label: 'Heading', category: 'Basic', media: I.heading, content: '<h2>Heading</h2>' },
    { id: 'text', label: 'Text', category: 'Basic', media: I.text, content: '<p>Start writing. Select text to change its style.</p>' },
    { id: 'image', label: 'Image', category: 'Basic', media: I.image, activate: true, content: { type: 'image', style: { 'max-width': '100%', height: 'auto' } } },
    { id: 'button', label: 'Button', category: 'Basic', media: I.button, content: '<a class="btn" href="#">Get started</a>' },
    { id: 'link', label: 'Link', category: 'Basic', media: I.link, content: { type: 'link', content: 'Link text', attributes: { href: '#' } } },
    { id: 'list', label: 'List', category: 'Basic', media: I.list, content: '<ul><li>First point</li><li>Second point</li><li>Third point</li></ul>' },
    { id: 'quote', label: 'Quote', category: 'Basic', media: I.quote, content: '<blockquote><p>“A short, memorable quote.”</p><cite>— Someone</cite></blockquote>' },
    { id: 'video', label: 'Video', category: 'Basic', media: I.video, content: { type: 'video', src: '', style: { width: '100%', height: '360px' } } },
    { id: 'html', label: 'Custom HTML', category: 'Basic', media: I.code, content: '<div class="pb-html"><!-- your HTML --><p>Custom HTML</p></div>' },
    // Sections (starting points — everything stays editable)
    { id: 'hero', label: 'Hero', category: 'Sections', media: I.hero, content: `<section class="section" style="text-align:center"><div class="container"><p class="eyebrow">Welcome</p><h1>A clear headline that says what you do</h1><p style="max-width:640px;margin:16px auto 28px">One or two sentences that explain the value for the visitor.</p><a class="btn" href="#">Primary action</a> <a class="btn-outline" href="#">Learn more</a></div></section>` },
    { id: 'hero-split', label: 'Hero with image', category: 'Sections', media: I.cols2, content: `<section class="section"><div class="container" style="display:flex;flex-wrap:wrap;gap:40px;align-items:center"><div style="flex:1 1 320px"><p class="eyebrow">New</p><h1>Headline next to a picture</h1><p>Explain the offer in a sentence or two.</p><a class="btn" href="#">Get started</a></div><div style="flex:1 1 320px"><img src="${ph(640, 420)}" alt="" style="width:100%;height:auto;border-radius:var(--radius)"></div></div></section>` },
    { id: 'features', label: 'Features', category: 'Sections', media: I.cards, content: `<section class="section"><div class="container"><h2 style="text-align:center">Why people choose us</h2><div style="display:flex;flex-wrap:wrap;gap:24px;margin-top:32px">${['Fast', 'Simple', 'Reliable'].map((t) => `<div style="flex:1 1 240px;padding:24px;border:1px solid rgba(0,0,0,.08);border-radius:var(--radius)"><h3>${t}</h3><p>Describe this benefit in one or two sentences.</p></div>`).join('')}</div></div></section>` },
    { id: 'cta', label: 'Call to action', category: 'Sections', media: I.cta, content: '<section class="section" style="background:var(--color-primary);color:#fff;text-align:center"><div class="container"><h2 style="color:inherit">Ready to start?</h2><p>Tell visitors what happens next.</p><a class="btn" href="#" style="background:#fff;color:var(--color-primary)">Contact us</a></div></section>' },
    { id: 'testimonial', label: 'Testimonial', category: 'Sections', media: I.quote, content: '<section class="section"><div class="container" style="max-width:760px;text-align:center"><p style="font-size:1.4em">“Working with them was the best decision we made this year.”</p><p><strong>Alex Morgan</strong> · Founder, Company</p></div></section>' },
    { id: 'faq', label: 'FAQ', category: 'Sections', media: I.faq, content: `<section class="section"><div class="container" style="max-width:800px"><h2>Frequently asked questions</h2>${['How does it work?', 'What does it cost?', 'Can I cancel any time?'].map((q) => `<details style="border-bottom:1px solid rgba(0,0,0,.1);padding:14px 0"><summary style="cursor:pointer;font-weight:600">${q}</summary><p>Answer the question clearly and briefly.</p></details>`).join('')}</div></section>` },
    { id: 'gallery', label: 'Image grid', category: 'Sections', media: I.image, content: `<section class="section"><div class="container" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px">${[1, 2, 3, 4, 5, 6].map(() => `<img src="${ph(400, 300)}" alt="" style="width:100%;height:auto;border-radius:var(--radius)">`).join('')}</div></section>` },
    // Dynamic (rendered by Slate for visitors)
    { id: 'dyn-title', label: 'Page title', category: 'Dynamic', media: I.tag, content: '<h1>{{title}}</h1>' },
    { id: 'dyn-content', label: 'Excerpt', category: 'Dynamic', media: I.text, content: '<p>{{excerpt}}</p>' },
    { id: 'dyn-form', label: 'Form', category: 'Dynamic', media: I.form, content: '<div class="pb-form">{{form:contact}}</div>' },
    { id: 'dyn-posts', label: 'Latest posts', category: 'Dynamic', media: I.list, content: '<div class="pb-query">{{#query type="post" limit="3"}}<article style="margin-bottom:24px"><h3><a href="{{url}}">{{title}}</a></h3><p>{{excerpt}}</p></article>{{/query}}</div>' },
  ];
  (PB.saved || []).forEach((b) => blocks.push({ id: 'saved-' + b.id, label: b.label, category: 'Saved blocks', media: I.star,
    content: (b.css ? '<style>' + b.css + '</style>' : '') + b.html, attributes: { 'data-saved-id': b.id } }));

  // ------------------------------------------------------------------ editor
  const devices = [
    { id: 'Desktop', name: 'Desktop', width: '' },
    { id: 'Tablet', name: 'Tablet', width: '820px', widthMedia: '992px' },
    { id: 'Mobile', name: 'Mobile', width: '390px', widthMedia: '480px' },
  ];
  const palette = PB.colors.map((c) => c.value);
  const editor = grapesjs.init({
    container: '#pb-editor',
    height: '100%',
    width: 'auto',
    fromElement: false,
    storageManager: false,
    avoidInlineStyle: true,
    forceClass: true,
    showOffsets: true,
    noticeOnUnload: false,
    panels: { defaults: [] },
    deviceManager: { devices },
    mediaCondition: 'max-width',
    blockManager: { appendTo: '#pb-blocks', blocks },
    layerManager: { appendTo: '#pb-layers' },
    selectorManager: { componentFirst: false },
    traitManager: { appendTo: '#pb-traits' },
    styleManager: { appendTo: '#pb-styles' },
    colorPicker: { showPalette: true, palette: [palette], preferredFormat: 'hex', showInput: true },
    canvas: { styles: PB.styles },
    assetManager: { upload: false, custom: true },
    projectData: PB.project || undefined,
    components: PB.project ? undefined : (PB.html || ''),
    protectedCss: '',
    baseCss: '* ::-webkit-scrollbar{width:8px;height:8px}* ::-webkit-scrollbar-thumb{background:rgba(0,0,0,.18);border-radius:8px}',
  });
  window.pbEditor = editor;

  // Design system: CSS variables, fonts and base styles inside the canvas (the theme stylesheet comes from PB.styles).
  function injectDs() {
    const doc = editor.Canvas.getDocument();
    if (!doc || doc.getElementById('slate-ds')) return;
    const st = doc.createElement('style');
    st.id = 'slate-ds';
    st.textContent = PB.dsCss + 'body{margin:0;min-height:100vh}.pb-html:empty,.pb-row>div:empty{min-height:40px}';
    doc.head.insertBefore(st, doc.head.firstChild);
  }
  editor.on('canvas:frame:load', injectDs);
  editor.on('load', injectDs);

  editor.on('load', () => {
    // Fonts: the site's design fonts first, then common stacks. CSS variables work in every field.
    const sm = editor.StyleManager;
    const ff = sm.getProperty('typography', 'font-family');
    if (ff) {
      const opts = [{ id: 'var(--font-heading)', label: 'Heading font (' + (PB.fonts[0] || 'site') + ')' }, { id: 'var(--font-body)', label: 'Body font (' + (PB.fonts[1] || PB.fonts[0] || 'site') + ')' }]
        .concat(['Arial, Helvetica, sans-serif', 'Georgia, serif', 'system-ui, sans-serif', 'ui-monospace, monospace'].map((f) => ({ id: f, label: f.split(',')[0] })));
      ff.set('options', opts);
    }
    paintSwatches();
    setState('');
  });

  // Class-based styling: every styled element gets a short class and its styles live on that class, never on an
  // element id (inline styles from blocks or pasted HTML are moved to the class too).
  const rnd = () => 'pb-' + Math.random().toString(36).slice(2, 7);
  const styleable = (c) => c && !c.is('wrapper') && !c.is('textnode') && c.get('stylable') !== false;
  function classify(c) {
    if (!styleable(c)) return;
    const st = c.getStyle ? c.getStyle() : {};
    const has = st && Object.keys(st).length;
    if (!has) return;
    let cls = c.getClasses()[0];
    if (!cls || !/^pb-/.test(cls)) { cls = rnd(); c.addClass(cls); }
    editor.Css.setRule('.' + cls, Object.assign({}, editor.Css.getRule('.' + cls)?.getStyle() || {}, st));
    c.setStyle({});
  }
  const classifyTree = (c) => { classify(c); c.components().forEach(classifyTree); };
  editor.on('component:add', (c) => { if (loaded) classifyTree(c); });
  editor.on('load', () => classifyTree(editor.getWrapper()));
  editor.on('component:selected', (c) => {
    qs('[data-pb-noselect]').hidden = true;
    qs('[data-pb-saveblock]').hidden = false;
    if (styleable(c) && !c.getClasses().length) c.addClass(rnd());
  });
  editor.on('component:deselected', () => {
    if (!editor.getSelected()) { qs('[data-pb-noselect]').hidden = false; qs('[data-pb-saveblock]').hidden = true; }
  });

  // Design colours as one-click swatches above the style list.
  function paintSwatches() {
    const host = qs('#pb-styles');
    if (!host || qs('.pb-swatches')) return;
    const box = document.createElement('div');
    box.className = 'pb-swatches';
    box.innerHTML = '<small class="muted">Site colours — click to apply as text, ⇧-click as background</small><div>' +
      PB.colors.map((c) => `<button type="button" title="${esc(c.name)} — var(--color-${esc(c.id)})" data-var="--color-${esc(c.id)}" style="background:${esc(c.value)}"></button>`).join('') + '</div>';
    box.addEventListener('click', (e) => {
      const b = e.target.closest('[data-var]');
      const sel = editor.getSelected();
      if (!b || !sel) { if (b) toast('Select an element first.', 'error'); return; }
      const rule = editor.Css.setRule('.' + (sel.getClasses()[0] || rnd()), {}, deviceMedia());
      if (!sel.getClasses().length) sel.addClass(rule.selectorsToString().slice(1));
      rule.addStyle({ [e.shiftKey ? 'background-color' : 'color']: `var(${b.dataset.var})` });
      editor.trigger('component:styleUpdate');
    });
    host.parentNode.insertBefore(box, host);
  }
  const deviceMedia = () => {
    const d = editor.Devices.getSelected();
    return d && d.get('widthMedia') ? { atRuleType: 'media', atRuleParams: `(max-width: ${d.get('widthMedia')})` } : {};
  };

  // Media library instead of GrapesJS' asset manager.
  editor.Commands.add('open-assets', {
    async run(ed, sender, opts = {}) {
      const items = await window.Slate.pickMedia({ multiple: false, kind: 'image' });
      const it = items && items[0];
      const target = opts.target || ed.getSelected();
      if (it && target) {
        target.set('src', it.url);
        if (it.alt) target.addAttributes({ alt: it.alt });
      } else if (!it && target && opts.target && !target.get('src')) {
        target.remove(); // dropped image block, picker cancelled
      }
    },
  });
  editor.on('block:drag:stop', (c, block) => {
    if (c && block && block.get('activate') && c.get('type') === 'image') editor.runCommand('open-assets', { target: c });
  });

  // ------------------------------------------------------------------ toolbar
  $$('[data-device]').forEach((b) => b.addEventListener('click', () => {
    editor.setDevice(b.dataset.device);
    $$('[data-device]').forEach((x) => x.classList.toggle('is-on', x === b));
  }));
  const layoutSel = qs('[data-pb-layout]');
  layoutSel.value = PB.layout;
  layoutSel.addEventListener('change', () => markDirty());

  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-cmd]');
    if (!b) return;
    const cmd = b.dataset.cmd;
    const um = editor.UndoManager;
    if (cmd === 'undo') um.undo();
    else if (cmd === 'redo') um.redo();
    else if (cmd === 'outline') { const on = editor.Commands.isActive('sw-visibility'); on ? editor.stopCommand('sw-visibility') : editor.runCommand('sw-visibility'); b.classList.toggle('is-on', !on); }
    else if (cmd === 'preview') { editor.runCommand('preview'); document.body.classList.add('pb-previewing'); }
    else if (cmd === 'code') openCode();
    else if (cmd === 'clear') { if (confirm('Remove everything from this page? You can still undo, or leave without saving.')) { editor.setComponents(''); editor.setStyle(''); } }
    else if (cmd === 'classic') switchClassic();
    else if (cmd === 'save-block') saveBlock();
  });
  editor.on('stop:preview', () => document.body.classList.remove('pb-previewing'));
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && document.body.classList.contains('pb-previewing')) editor.stopCommand('preview');
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save('draft'); }
  });

  // Side panel tabs + block search
  $$('.pb-tabs').forEach((nav) => nav.addEventListener('click', (e) => {
    const b = e.target.closest('[data-pane]');
    if (!b) return;
    const side = nav.parentElement;
    $$('[data-pane]', nav).forEach((x) => x.classList.toggle('is-on', x === b));
    $$('[data-pane-body]', side).forEach((p) => { p.hidden = p.dataset.paneBody !== b.dataset.pane; });
  }));
  qs('[data-block-search]').addEventListener('input', (e) => {
    const q = e.target.value.trim().toLowerCase();
    const all = editor.Blocks.getAll();
    editor.Blocks.render(q ? all.filter((bl) => (bl.get('label') + ' ' + bl.get('category')?.id).toLowerCase().includes(q)) : all.models);
  });

  // ------------------------------------------------------------------ code view
  function openCode() {
    const body = document.createElement('div');
    body.className = 'pb-code';
    body.innerHTML = '<label class="field"><span>HTML</span><textarea class="input code" rows="14" spellcheck="false" data-html></textarea></label>' +
      '<label class="field"><span>CSS</span><textarea class="input code" rows="10" spellcheck="false" data-css></textarea></label>' +
      '<p class="muted small">Template tags such as {{title}}, {{field:name}} and {{form:slug}} are filled in for visitors. Scripts are removed for users without the “any HTML” permission.</p>';
    qs('[data-html]', body).value = bodyHtml();
    qs('[data-css]', body).value = editor.getCss({ avoidProtected: true }) || '';
    const foot = document.createElement('div');
    foot.innerHTML = '<button type="button" class="btn" data-close>Cancel</button> <button type="button" class="btn btn-primary" data-apply>Apply</button>';
    const m = modal({ title: 'Edit HTML & CSS', body, foot, size: 'lg' });
    qs('[data-apply]', foot).addEventListener('click', () => {
      editor.setComponents(qs('[data-html]', body).value);
      editor.setStyle(qs('[data-css]', body).value);
      m.close();
      markDirty();
    });
  }
  // Output HTML without the <body> wrapper and without GrapesJS' auto ids (styles live on classes; ids the CSS
  // still uses, or that a person typed, are kept).
  const bodyHtml = () => {
    const css = editor.getCss({ avoidProtected: true }) || '';
    return editor.getHtml().replace(/^<body[^>]*>/i, '').replace(/<\/body>\s*$/i, '')
      .replace(/\s+id="(i[a-z0-9]{2,5}(?:-\d+)?)"/g, (m, id) => (css.includes('#' + id) ? m : ''));
  };

  // ------------------------------------------------------------------ saving
  let dirty = false;
  let saving = false;
  const setState = (t) => { qs('[data-pb-state]').textContent = t; };
  function markDirty() { if (!dirty) { dirty = true; setState('Unsaved changes'); } }
  editor.on('load', () => setTimeout(() => { loaded = true; }, 300));
  editor.on('update', () => { if (loaded) markDirty(); });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  async function post(url, data) {
    const r = await fetch(url, { method: 'POST', credentials: 'same-origin', body: JSON.stringify(data),
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': PB.csrf, 'X-Requested-With': 'XMLHttpRequest' } });
    return r.json().catch(() => ({ ok: false, error: 'Unexpected response (' + r.status + ').' }));
  }
  async function save(status) {
    if (saving) return;
    saving = true;
    setState('Saving…');
    $$('[data-pb-save]').forEach((b) => { b.disabled = true; });
    try {
      const res = await post(PB.save, { status: status === 'publish' ? (PB.canPublish ? 'publish' : 'pending') : '', layout: layoutSel.value,
        html: bodyHtml(), css: editor.getCss({ avoidProtected: true }) || '', project: editor.getProjectData() });
      if (!res.ok) throw new Error(res.error || 'Could not save.');
      dirty = false;
      setState('Saved ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
      toast(res.message || 'Saved.');
      if (res.status) { PB.status = res.status; if (res.status === 'publish') qs('[data-pb-save="publish"]').textContent = 'Update'; }
    } catch (err) { setState('Not saved'); toast(err.message, 'error', 6000); }
    $$('[data-pb-save]').forEach((b) => { b.disabled = false; });
    saving = false;
  }
  $$('[data-pb-save]').forEach((b) => b.addEventListener('click', () => save(b.dataset.pbSave)));

  async function switchClassic() {
    if (!confirm('Edit this item in the classic editor? The visual design is kept, and you can switch back any time.')) return;
    if (dirty) await save('draft');
    const res = await post(PB.mode, { mode: 'classic' });
    if (res.ok) { dirty = false; location.href = PB.edit; } else toast(res.error || 'Could not switch.', 'error');
  }

  async function saveBlock() {
    const sel = editor.getSelected();
    if (!sel) return;
    const label = prompt('Name for this block (it appears under “Saved blocks”):');
    if (!label) return;
    const html = sel.toHTML();
    const classes = new Set();
    [sel, ...sel.find('*')].forEach((c) => c.getClasses().forEach((k) => classes.add(k)));
    const css = editor.Css.getRules().filter((r) => r.selectorsToString().split(/[\s,>+~:]+/).some((s) => classes.has(s.replace(/^\./, '')))).map((r) => r.toCSS()).join('\n');
    const res = await post(PB.blocksUrl, { label, html, css });
    if (!res.ok) { toast(res.error || 'Could not save the block.', 'error'); return; }
    editor.Blocks.add('saved-' + res.id, { label, category: 'Saved blocks', media: I.star, content: (css ? '<style>' + css + '</style>' : '') + html });
    toast('Saved as a block.');
  }
})();
