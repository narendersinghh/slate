/* Slate styles editor (Appearance › Styles): colours, variables, typography, classes and layout with live previews. */
(function () {
  'use strict';
  const { esc, $, $$, toast } = window.Slate;
  const root = $('[data-ds-editor]');
  if (!root) return;
  const cfg = JSON.parse($('[data-ds-config]').textContent || '{}');
  const ds = cfg.ds;
  ds.vars = ds.vars || [];
  ds.classes = ds.classes || [];
  const out = $('[data-ds-json]');
  const form = root.closest('form');

  const svg = (d, s = 16) => '<svg class="ic" width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + d + '</svg>';
  const IC = {
    plus: svg('<path d="M12 5v14M5 12h14"/>'), trash: svg('<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>', 15),
    copy: svg('<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>', 13),
    search: svg('<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>', 15), dup: svg('<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M4 16V4h12"/>', 15),
    palette: svg('<circle cx="13.5" cy="6.5" r="1"/><circle cx="17.5" cy="10.5" r="1"/><circle cx="8.5" cy="7.5" r="1"/><circle cx="6.5" cy="12.5" r="1"/><path d="M12 2a10 10 0 0 0 0 20c1.7 0 2-1.4 1.3-2.4-.6-1-.2-2.6 1.4-2.6H17a5 5 0 0 0 5-5c0-5.5-4.5-10-10-10Z"/>'),
    vars: svg('<path d="M8 4c-2 0-3 1-3 3v2c0 1.5-1 3-2 3 1 0 2 1.5 2 3v2c0 2 1 3 3 3M16 4c2 0 3 1 3 3v2c0 1.5 1 3 2 3-1 0-2 1.5-2 3v2c0 2-1 3-3 3"/>'),
    type: svg('<path d="M4 7V4h16v3M9 20h6M12 4v16"/>'), cls: svg('<path d="m18 16 4-4-4-4M6 8l-4 4 4 4M14.5 4l-5 16"/>'),
    layout: svg('<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>'),
  };
  const TABS = [['colours', 'Colours', IC.palette], ['variables', 'Variables', IC.vars], ['typography', 'Typography', IC.type], ['classes', 'Classes', IC.cls], ['layout', 'Layout', IC.layout]];
  let tab = (location.hash.slice(1) || '').replace('ds-', '');
  if (!TABS.some((t) => t[0] === tab)) tab = 'colours';
  let curClass = 0;
  let classQuery = '';
  let lastArea = null;

  const slug = (v) => String(v || '').toLowerCase().trim().replace(/[\s_]+/g, '-').replace(/[^a-z0-9-]/g, '').replace(/^-+|-+$/g, '');
  const clsName = (v) => String(v || '').replace(/\s+/g, '-').replace(/[^a-zA-Z0-9_-]/g, '').replace(/^[0-9]+/, '');
  const isHex = (v) => /^#[0-9a-f]{6}$/i.test(v);
  const copy = (text) => navigator.clipboard?.writeText(text).then(() => toast('Copied ' + text));
  let ready = false; // the first render is not a change
  const sync = () => { out.value = JSON.stringify(ds); if (ready && form) form.dispatchEvent(new Event('input', { bubbles: true })); preview(); };

  // ---- fonts and preview CSS ----
  const loaded = new Set();
  const loadFont = (f) => {
    if (!f || loaded.has(f) || cfg.systemFonts[f]) return;
    loaded.add(f);
    const l = document.createElement('link');
    l.rel = 'stylesheet';
    l.href = 'https://fonts.googleapis.com/css2?family=' + f.replace(/ /g, '+') + ':wght@300;400;500;600;700&display=swap';
    document.head.appendChild(l);
  };
  const family = (f) => cfg.systemFonts[f] ? (f === 'Georgia' ? 'Georgia, serif' : f === 'ui-monospace' ? 'ui-monospace, monospace' : 'system-ui, sans-serif') : '"' + f + '", system-ui, sans-serif';
  const clean = (css) => String(css || '').replace(/[<>{}]/g, '');
  let styleEl = null;
  function preview() {
    if (!styleEl) { styleEl = document.createElement('style'); document.head.appendChild(styleEl); }
    const v = [];
    ds.colors.forEach((c) => v.push('--color-' + c.id + ':' + clean(c.value)));
    v.push('--font-heading:' + family(ds.fonts.heading), '--font-body:' + family(ds.fonts.body));
    Object.entries(ds.type).forEach(([k, t]) => { v.push('--fs-' + k + ':' + t.size + 'px', '--type-' + k + ':' + t.weight + ' ' + t.size + 'px/' + t.line + ' var(--font-' + t.font + ')'); });
    v.push('--container:' + ds.layout.container + 'px', '--radius:' + ds.layout.radius + 'px', '--radius-button:' + ds.layout.button_radius + 'px', '--section-space:' + ds.layout.section_space + 'px', '--accent:var(--color-primary)');
    ds.vars.forEach((x) => { if (x.name && x.value) v.push('--' + x.name + ':' + clean(x.value)); });
    let css = '.dse-scope{' + v.join(';') + '}.dse-stage{font:var(--type-body);color:var(--color-text, #1f1f1f)}';
    // Only inside the preview stage, so classes such as .btn never restyle the dashboard itself.
    ds.classes.forEach((c) => {
      if (!c.name) return;
      if (c.css) css += '.dse-stage .' + c.name + '{' + clean(c.css) + '}';
      if (c.hover) css += '.dse-stage .' + c.name + ':hover{' + clean(c.hover) + '}';
    });
    styleEl.textContent = css;
    loadFont(ds.fonts.heading);
    loadFont(ds.fonts.body);
  }

  // ---- shell ----
  function render() {
    root.innerHTML = '<nav class="dse-tabs" role="tablist">' + TABS.map(([k, l, ic]) => '<button type="button" role="tab" aria-selected="' + (k === tab) + '" class="' + (k === tab ? 'is-on' : '') + '" data-tab="' + k + '">' + ic + '<span>' + l + '</span></button>').join('') + '</nav>' +
      '<div class="dse-pane dse-scope" data-pane>' + ({ colours, variables, typography, classes, layout })[tab]() + '</div>';
    sync();
  }
  const pane = () => $('[data-pane]', root);
  const rerender = () => { pane().innerHTML = ({ colours, variables, typography, classes, layout })[tab](); sync(); };

  // ---- colours ----
  function colours() {
    return '<p class="dse-intro">Your brand colours. Use them anywhere as <code>var(--color-…)</code>, or add the classes <code>text-…</code> and <code>bg-…</code> to an element.</p>' +
      '<div class="dse-colors">' + ds.colors.map((c, i) =>
        '<div class="dse-color" data-i="' + i + '">' +
          '<label class="dse-swatch" style="background:' + esc(c.value) + '" title="Pick a colour"><input type="color" data-c="picker" value="' + esc(isHex(c.value) ? c.value : '#000000') + '"></label>' +
          '<div class="dse-color-body">' +
            '<input class="dse-plain dse-strong" data-c="name" value="' + esc(c.name) + '" aria-label="Colour name">' +
            '<input class="input input-sm dse-mono" data-c="value" value="' + esc(c.value) + '" aria-label="Colour value" spellcheck="false">' +
            '<button type="button" class="dse-chip" data-copy="var(--color-' + esc(c.id) + ')" title="Copy">' + IC.copy + '--color-' + esc(c.id) + '</button>' +
          '</div>' +
          '<button type="button" class="icon-btn dse-del" data-c="del" title="Remove colour" aria-label="Remove ' + esc(c.name) + '">' + IC.trash + '</button>' +
        '</div>').join('') +
        '<button type="button" class="dse-add-card" data-add-color>' + IC.plus + '<span>Add colour</span></button>' +
      '</div>';
  }

  // ---- variables ----
  const PRESETS = [
    ['Spacing', 'space-m', '24px'], ['Shadow', 'shadow-soft', '0 10px 30px rgba(0, 0, 0, .08)'],
    ['Gradient', 'gradient-brand', 'linear-gradient(135deg, var(--color-primary), var(--color-accent))'],
    ['Size', 'size-icon', '48px'], ['Font size', 'fs-lead', '20px'], ['Custom', '', ''],
  ];
  const varPreview = (val) => {
    const v = String(val || '').trim();
    if (/gradient\(|^#|^rgb|^hsl|^var\(--color/i.test(v)) return '<span class="dse-vp" style="background:' + esc(clean(v)) + '"></span>';
    if (/^-?\d|^0 /.test(v) && /\d(px|rem|em|%|vw|vh)?\s+\d/.test(v) && /rgba?|#/.test(v)) return '<span class="dse-vp is-shadow" style="box-shadow:' + esc(clean(v)) + '"></span>';
    const m = v.match(/^(\d+(?:\.\d+)?)(px|rem|em)$/);
    if (m) { const px = m[2] === 'px' ? +m[1] : +m[1] * 16; return '<span class="dse-vp is-size"><i style="width:' + Math.min(100, Math.max(4, px)) + '%"></i></span>'; }
    return '<span class="dse-vp is-text">Aa</span>';
  };
  function builtins() {
    const list = ds.colors.map((c) => '--color-' + c.id).concat(['--font-heading', '--font-body'], Object.keys(ds.type).map((k) => '--type-' + k), Object.keys(ds.type).map((k) => '--fs-' + k), ['--container', '--radius', '--radius-button', '--section-space']);
    return list;
  }
  function variables() {
    return '<p class="dse-intro">Your own values for spacing, shadows, gradients and sizes. Change a value once and every place that uses <code>var(--name)</code> follows.</p>' +
      '<div class="dse-presets"><span class="muted small">Add</span>' + PRESETS.map(([l], i) => '<button type="button" class="btn btn-sm" data-preset="' + i + '">' + IC.plus + ' ' + l + '</button>').join('') + '</div>' +
      (ds.vars.length ? '<div class="dse-vars">' + ds.vars.map((x, i) =>
        '<div class="dse-var" data-i="' + i + '">' + varPreview(x.value) +
          '<label class="dse-var-name"><span>--</span><input class="input input-sm dse-mono" data-v="name" value="' + esc(x.name) + '" placeholder="name" aria-label="Variable name" spellcheck="false"></label>' +
          '<input class="input input-sm dse-mono dse-var-value" data-v="value" value="' + esc(x.value) + '" placeholder="value, e.g. 24px" aria-label="Value" spellcheck="false">' +
          '<button type="button" class="icon-btn" data-copy="var(--' + esc(x.name) + ')" title="Copy var(--' + esc(x.name) + ')" aria-label="Copy">' + IC.copy + '</button>' +
          '<button type="button" class="icon-btn dse-del" data-v="del" title="Remove" aria-label="Remove variable">' + IC.trash + '</button>' +
        '</div>').join('') + '</div>'
        : '<div class="dse-empty">' + IC.vars + '<strong>No variables yet</strong><span class="muted small">Pick one of the starters above.</span></div>') +
      '<details class="dse-builtins"><summary>Built-in variables (' + builtins().length + ')</summary><div>' +
        builtins().map((n) => '<button type="button" class="dse-chip" data-copy="var(' + n + ')">' + IC.copy + n + '</button>').join('') + '</div></details>';
  }

  // ---- typography ----
  const fontOptions = (cur) => {
    const list = cfg.fonts.includes(cur) || cfg.systemFonts[cur] ? cfg.fonts : [cur].concat(cfg.fonts);
    return '<optgroup label="Google Fonts">' + list.map((f) => '<option' + (f === cur ? ' selected' : '') + '>' + esc(f) + '</option>').join('') + '</optgroup>' +
      '<optgroup label="System">' + Object.entries(cfg.systemFonts).map(([k, l]) => '<option value="' + esc(k) + '"' + (k === cur ? ' selected' : '') + '>' + esc(l) + '</option>').join('') + '</optgroup>';
  };
  const num = (k, f, v, step, min, max, label) => '<label class="dse-num"><span>' + label + '</span><input class="input input-sm" type="number" data-t="' + k + '" data-f="' + f + '" value="' + v + '" step="' + step + '" min="' + min + '" max="' + max + '"></label>';
  function typography() {
    return '<div class="dse-fonts">' +
        ['heading', 'body'].map((k) => '<div class="dse-font card"><span class="muted small">' + (k === 'heading' ? 'Heading font' : 'Body font') + '</span>' +
          '<div class="dse-font-sample" style="font-family:' + esc(family(ds.fonts[k])) + '">' + (k === 'heading' ? 'Aa Bb Cc' : 'The quick brown fox jumps over the lazy dog.') + '</div>' +
          '<select class="input" data-font="' + k + '">' + fontOptions(ds.fonts[k]) + '</select></div>').join('') +
      '</div>' +
      '<label class="switch-row"><input type="checkbox" class="switch" data-flag="fonts.google"' + (ds.fonts.google ? ' checked' : '') + '><span>Load fonts from Google Fonts</span></label>' +
      '<h3 class="section-title">Text styles</h3>' +
      '<div class="dse-scale">' + Object.entries(cfg.typeKeys).map(([k, label]) => {
        const t = ds.type[k];
        return '<div class="dse-type" data-k="' + k + '">' +
          '<div class="dse-type-sample"><small class="muted">' + esc(label) + ' · <code>var(--type-' + k + ')</code></small>' +
            '<div style="font:var(--type-' + k + ');letter-spacing:' + t.tracking + 'em">' + (k === 'body' || k === 'small' ? 'Readable copy for every page of the site.' : k === 'button' ? 'Get started' : 'The quick brown fox') + '</div></div>' +
          '<div class="dse-type-controls">' +
            '<label class="dse-num"><span>Font</span><select class="input input-sm" data-t="' + k + '" data-f="font"><option value="heading"' + (t.font === 'heading' ? ' selected' : '') + '>Heading</option><option value="body"' + (t.font === 'body' ? ' selected' : '') + '>Body</option></select></label>' +
            num(k, 'size', t.size, 1, 8, 160, 'Size') +
            '<label class="dse-num"><span>Weight</span><select class="input input-sm" data-t="' + k + '" data-f="weight">' + [300, 400, 500, 600, 700, 800, 900].map((w) => '<option' + (+t.weight === w ? ' selected' : '') + '>' + w + '</option>').join('') + '</select></label>' +
            num(k, 'line', t.line, 0.05, 0.8, 3, 'Line') + num(k, 'tracking', t.tracking, 0.01, -0.2, 0.5, 'Spacing') +
          '</div></div>';
      }).join('') + '</div>' +
      '<div class="stack-sm" style="margin-top:16px">' +
        '<label class="switch-row"><input type="checkbox" class="switch" data-flag="responsive"' + (ds.responsive ? ' checked' : '') + '><span>Fluid sizes: large headings scale down on phones</span></label>' +
        '<label class="switch-row"><input type="checkbox" class="switch" data-flag="apply_elements"' + (ds.apply_elements ? ' checked' : '') + '><span>Apply these styles to body text and h1 to h6 automatically <small class="muted">(turn off for mirrored designs that style their own elements)</small></span></label>' +
      '</div>';
  }

  // ---- classes ----
  const SNIPPETS = [
    ['Padding', 'padding: 16px 24px;'], ['Rounded', 'border-radius: var(--radius);'], ['Shadow', 'box-shadow: 0 10px 30px rgba(0, 0, 0, .08);'],
    ['Primary background', 'background: var(--color-primary);\ncolor: #fff;'], ['Text colour', 'color: var(--color-primary);'], ['Border', 'border: 1px solid rgba(0, 0, 0, .1);'],
    ['Flex row', 'display: flex;\nalign-items: center;\ngap: 12px;'], ['Grid', 'display: grid;\ngrid-template-columns: repeat(auto-fit, minmax(240px, 1fr));\ngap: 24px;'],
    ['Centre text', 'text-align: center;'], ['Smooth change', 'transition: all .2s ease;'], ['Lift', 'transform: translateY(-2px);'],
  ];
  const sample = (type, name) => type === 'button' ? '<a class="' + esc(name) + '" href="#" onclick="return false">Button text</a>'
    : type === 'text' ? '<p class="' + esc(name) + '">A line of text styled with this class. Hover to see the hover style.</p>'
      : '<div class="' + esc(name) + '"><strong>Card title</strong><p style="margin:.4em 0 0">Some content inside the element.</p></div>';
  let pvType = 'card';
  function classes() {
    const q = classQuery.toLowerCase();
    const list = ds.classes.map((c, i) => [c, i]).filter(([c]) => !q || c.name.toLowerCase().includes(q));
    if (curClass >= ds.classes.length) curClass = Math.max(0, ds.classes.length - 1);
    const c = ds.classes[curClass];
    const dupe = c && ds.classes.some((x, i) => i !== curClass && x.name && x.name === c.name);
    const allVars = builtins().concat(ds.vars.filter((x) => x.name).map((x) => '--' + x.name));
    return '<p class="dse-intro">Reusable styles. Add the class to any element (<code>class="name"</code>) in the editor, templates or on-page editing.</p>' +
      '<div class="dse-classes">' +
        '<aside class="dse-cls-list">' +
          '<label class="dse-q">' + IC.search + '<input class="input input-sm" type="search" data-cls-q value="' + esc(classQuery) + '" placeholder="Find a class"></label>' +
          '<div class="dse-cls-items">' + (list.length ? list.map(([x, i]) => '<button type="button" class="' + (i === curClass ? 'is-on' : '') + '" data-cls="' + i + '"><code>.' + esc(x.name || 'unnamed') + '</code>' + (x.hover ? '<small>hover</small>' : '') + '</button>').join('') : '<p class="muted small">No class matches.</p>') + '</div>' +
          '<button type="button" class="btn btn-sm btn-block" data-new-cls>' + IC.plus + ' New class</button>' +
        '</aside>' +
        (c ? '<section class="dse-cls-edit">' +
          '<div class="dse-cls-head">' +
            '<label class="dse-cls-name"><span>.</span><input class="input dse-mono" data-k="name" value="' + esc(c.name) + '" placeholder="card-shadow" spellcheck="false" aria-label="Class name"></label>' +
            '<button type="button" class="btn btn-sm btn-ghost" data-copy=\'class="' + esc(c.name) + '"\'>' + IC.copy + ' Copy</button>' +
            '<button type="button" class="btn btn-sm btn-ghost" data-dup-cls>' + IC.dup + ' Duplicate</button>' +
            '<button type="button" class="btn btn-sm btn-ghost btn-danger" data-del-cls>' + IC.trash + ' Delete</button>' +
          '</div>' +
          (dupe ? '<p class="dse-warn">Another class already has this name. Rename one of them.</p>' : '') +
          '<div class="dse-cls-preview"><div class="dse-seg">' + [['card', 'Box'], ['button', 'Button'], ['text', 'Text']].map(([k, l]) => '<button type="button" class="' + (k === pvType ? 'is-on' : '') + '" data-pv="' + k + '">' + l + '</button>').join('') + '</div>' +
            '<div class="dse-stage" data-stage>' + sample(pvType, c.name) + '</div></div>' +
          '<div class="dse-insert"><span class="muted small">Insert</span>' + SNIPPETS.map(([l], i) => '<button type="button" class="dse-chip" data-snip="' + i + '">' + esc(l) + '</button>').join('') +
            '<select class="input input-sm" data-insert-var aria-label="Insert a variable"><option value="">Variable…</option>' + allVars.map((n) => '<option>' + esc(n) + '</option>').join('') + '</select></div>' +
          '<label class="field"><span>Styles</span><textarea class="input code" rows="7" data-k="css" spellcheck="false" placeholder="padding: 24px;\nborder-radius: var(--radius);">' + esc(fmt(c.css)) + '</textarea></label>' +
          '<label class="field"><span>On hover <small class="muted">(optional)</small></span><textarea class="input code" rows="3" data-k="hover" spellcheck="false" placeholder="transform: translateY(-2px);">' + esc(fmt(c.hover || '')) + '</textarea></label>' +
          '<p class="muted small">Write CSS declarations, one per line. No selectors or braces needed.</p>' +
        '</section>' : '<section class="dse-cls-edit"><div class="dse-empty">' + IC.cls + '<strong>No classes yet</strong><span class="muted small">Create one with New class.</span></div></section>') +
      '</div>';
  }
  const fmt = (css) => String(css || '').replace(/;\s*(?!\n)/g, ';\n').replace(/\n{2,}/g, '\n').trim();

  // ---- layout ----
  const slider = (k, label, min, max, step, hint) => '<div class="dse-slider"><div class="dse-slider-head"><strong>' + label + '</strong><span class="muted small">' + hint + '</span></div>' +
    '<div class="dse-slider-row"><input type="range" data-l="' + k + '" min="' + min + '" max="' + max + '" step="' + step + '" value="' + ds.layout[k] + '">' +
    '<span class="input-suffix-wrap"><input class="input input-sm input-num" type="number" data-l="' + k + '" min="' + min + '" max="' + max + '" value="' + ds.layout[k] + '"><span class="muted small">px</span></span></div></div>';
  function layout() {
    return '<div class="dse-layout">' +
      slider('container', 'Content width', 600, 2400, 10, 'var(--container)') +
      slider('section_space', 'Space between sections', 0, 300, 4, 'var(--section-space)') +
      slider('radius', 'Corner radius', 0, 60, 1, 'var(--radius)') +
      slider('button_radius', 'Button radius', 0, 60, 1, 'var(--radius-button)') +
      '</div>' +
      '<div class="dse-shapes"><div class="dse-shape" style="border-radius:var(--radius)">Card</div><a class="dse-shape-btn" style="border-radius:var(--radius-button)">Button</a></div>' +
      '<h3 class="section-title">Dashboard</h3>' +
      '<label class="switch-row"><input type="checkbox" class="switch" data-flag="admin_match"' + (ds.admin_match ? ' checked' : '') + '><span>Use these colours and fonts in the Slate dashboard too</span></label>';
  }

  // ---- events ----
  const insertAt = (ta, text) => {
    if (!ta) return;
    const s = ta.selectionStart ?? ta.value.length;
    const before = ta.value.slice(0, s);
    const glue = before && !/\n$/.test(before) && /;\s*$/.test(text) && /\n/.test(text + '\n') && !/^var\(/.test(text) ? '\n' : '';
    ta.value = before + glue + text + ta.value.slice(ta.selectionEnd ?? s);
    ta.focus();
    ta.selectionStart = ta.selectionEnd = (before + glue + text).length;
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  };
  root.addEventListener('focusin', (e) => { if (e.target.matches('textarea[data-k]')) lastArea = e.target; });
  root.addEventListener('input', (e) => {
    const el = e.target;
    const card = el.closest('[data-i]');
    if (el.dataset.c && card) {
      const c = ds.colors[+card.dataset.i];
      if (el.dataset.c === 'picker') { c.value = el.value; $('[data-c="value"]', card).value = el.value; }
      if (el.dataset.c === 'value') { c.value = el.value.trim(); if (isHex(c.value)) $('[data-c="picker"]', card).value = c.value; }
      if (el.dataset.c === 'name') {
        c.name = el.value;
        if (c._new) { c.id = slug(el.value) || c.id; const chip = $('.dse-chip', card); chip.dataset.copy = 'var(--color-' + c.id + ')'; chip.lastChild.textContent = '--color-' + c.id; }
      }
      $('.dse-swatch', card).style.background = c.value;
      sync();
      return;
    }
    if (el.dataset.v && card) {
      const x = ds.vars[+card.dataset.i];
      if (el.dataset.v === 'name') { x.name = slug(el.value.replace(/^-+/, '')); }
      else { x.value = el.value; card.firstElementChild.outerHTML = varPreview(x.value); }
      $('[data-copy]', card).dataset.copy = 'var(--' + x.name + ')';
      sync();
      return;
    }
    if (el.matches('[data-t]')) {
      const t = ds.type[el.dataset.t];
      t[el.dataset.f] = el.dataset.f === 'font' ? el.value : +el.value;
      if (el.dataset.f === 'tracking') el.closest('.dse-type').querySelector('.dse-type-sample div').style.letterSpacing = el.value + 'em';
      sync();
      return;
    }
    if (el.matches('[data-l]')) {
      ds.layout[el.dataset.l] = +el.value;
      $$('[data-l="' + el.dataset.l + '"]', root).forEach((o) => { if (o !== el) o.value = el.value; });
      sync();
      return;
    }
    if (el.matches('[data-cls-q]')) {
      classQuery = el.value;
      const pos = el.selectionStart;
      rerender();
      const q = $('[data-cls-q]', root); q.focus(); q.selectionStart = q.selectionEnd = pos;
      return;
    }
    if (el.matches('[data-k]') && ds.classes[curClass]) {
      const c = ds.classes[curClass];
      if (el.dataset.k === 'name') {
        const v = clsName(el.value);
        if (v !== el.value) { const p = el.selectionStart - (el.value.length - v.length); el.value = v; el.selectionStart = el.selectionEnd = Math.max(0, p); }
        c.name = v;
        const btn = $('[data-cls="' + curClass + '"] code', root);
        if (btn) btn.textContent = '.' + (v || 'unnamed');
        const st = $('[data-stage]', root); st.innerHTML = sample(pvType, v);
        $('.dse-cls-head [data-copy]', root).dataset.copy = 'class="' + v + '"';
      } else {
        c[el.dataset.k] = el.value.replace(/[{}<>]/g, '');
      }
      sync();
    }
  });
  root.addEventListener('change', (e) => {
    const el = e.target;
    if (el.matches('[data-font]')) { ds.fonts[el.dataset.font] = el.value; loadFont(el.value); el.parentElement.querySelector('.dse-font-sample').style.fontFamily = family(el.value); sync(); return; }
    if (el.matches('[data-flag]')) { const [a, b] = el.dataset.flag.split('.'); if (b) ds[a][b] = el.checked; else ds[a] = el.checked; sync(); return; }
    if (el.matches('[data-insert-var]') && el.value) { insertAt(lastArea || $('textarea[data-k="css"]', root), 'var(' + el.value + ')'); el.value = ''; return; }
    if (el.matches('[data-t][data-f="font"],[data-t][data-f="weight"]')) sync();
  });
  root.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.matches('input')) e.preventDefault(); });
  root.addEventListener('click', (e) => {
    const t = e.target.closest('button');
    if (!t) return;
    if (t.dataset.copy) { copy(t.dataset.copy); return; }
    if (t.dataset.tab) { tab = t.dataset.tab; history.replaceState(null, '', '#ds-' + tab); render(); return; }
    if (t.matches('[data-add-color]')) {
      let n = ds.colors.length + 1;
      while (ds.colors.some((c) => c.id === 'color-' + n)) n++;
      ds.colors.push({ id: 'color-' + n, name: '', value: '#888888', _new: true });
      rerender();
      const inp = $$('[data-c="name"]', root).pop(); inp.placeholder = 'Name, e.g. Highlight'; inp.focus();
      return;
    }
    const card = t.closest('[data-i]');
    if (t.dataset.c === 'del' && card) {
      const c = ds.colors[+card.dataset.i];
      if (!confirm('Remove the colour “' + (c.name || c.id) + '”? Places that use var(--color-' + c.id + ') lose it.')) return;
      ds.colors.splice(+card.dataset.i, 1); rerender(); return;
    }
    if (t.dataset.preset !== undefined) {
      const [, name, value] = PRESETS[+t.dataset.preset];
      let n = name, k = 2;
      while (n && ds.vars.some((x) => x.name === n)) n = name + '-' + k++;
      ds.vars.push({ name: n, value });
      rerender();
      const inp = $$('[data-v="' + (name ? 'value' : 'name') + '"]', root).pop(); if (inp) inp.focus();
      return;
    }
    if (t.dataset.v === 'del' && card) { ds.vars.splice(+card.dataset.i, 1); rerender(); return; }
    if (t.dataset.cls !== undefined) { curClass = +t.dataset.cls; rerender(); return; }
    if (t.matches('[data-new-cls]')) {
      let n = 'my-class', k = 2;
      while (ds.classes.some((c) => c.name === n)) n = 'my-class-' + k++;
      ds.classes.push({ name: n, css: '', hover: '' }); curClass = ds.classes.length - 1; classQuery = ''; rerender();
      const inp = $('[data-k="name"]', root); inp.focus(); inp.select();
      return;
    }
    if (t.matches('[data-dup-cls]')) {
      const c = ds.classes[curClass];
      let n = c.name + '-copy', k = 2;
      while (ds.classes.some((x) => x.name === n)) n = c.name + '-copy-' + k++;
      ds.classes.splice(curClass + 1, 0, { name: n, css: c.css, hover: c.hover || '' }); curClass++; rerender(); return;
    }
    if (t.matches('[data-del-cls]')) {
      const c = ds.classes[curClass];
      if (!confirm('Delete the class .' + c.name + '? Elements that use it lose these styles.')) return;
      ds.classes.splice(curClass, 1); curClass = Math.max(0, curClass - 1); rerender(); return;
    }
    if (t.dataset.pv) { pvType = t.dataset.pv; rerender(); return; }
    if (t.dataset.snip !== undefined) { insertAt(lastArea || $('textarea[data-k="css"]', root), SNIPPETS[+t.dataset.snip][1]); }
  });

  // Saved through the settings form: strip editor-only flags first.
  if (form) form.addEventListener('submit', () => { ds.colors.forEach((c) => { delete c._new; if (!c.id) c.id = slug(c.name); }); out.value = JSON.stringify(ds); });
  render();
  ready = true;
})();
