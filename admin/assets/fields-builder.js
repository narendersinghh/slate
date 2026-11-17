/* Slate field group builder (Content model › Custom fields): add fields from a palette, drag to order, edit inline, live preview. */
(function () {
  'use strict';
  const { esc, $, $$, toast } = window.Slate;
  const form = $('[data-field-builder]');
  if (!form) return;
  const cfg = JSON.parse($('[data-fb-config]', form).textContent || '{}');
  const group = cfg.group;
  const TYPES = cfg.types;
  const IC = cfg.icons;
  const slugify = window.Slate.slugify;
  group.fields = Array.isArray(group.fields) ? group.fields : [];
  group.location = group.location || { post_types: [], taxonomies: [] };

  // Fields that already exist keep their name: content is stored under it. Only new fields follow their label.
  const markSaved = (f) => { f._saved = true; (f.sub_fields || []).forEach(markSaved); };
  group.fields.forEach(markSaved);

  const list = $('[data-fields]', form);
  const titleInput = $('[data-g="title"]', form);
  let open = null; // the field object whose settings are open
  let ready = false;

  const CHOICES = ['select', 'radio', 'checkbox'];
  const NO_DEFAULT = ['tab', 'repeater', 'gallery', 'image', 'file', 'relation', 'taxonomy', 'form', 'checkbox', 'richtext', 'code'];
  const NO_PLACEHOLDER = NO_DEFAULT.concat(['select', 'radio', 'toggle', 'date', 'datetime', 'color']);
  const WIDTHS = [['100', 'Full width'], ['75', 'Three quarters'], ['66', 'Two thirds'], ['50', 'Half'], ['33', 'One third'], ['25', 'One quarter']];
  const typeOf = (t) => TYPES[t] || { label: t, icon: '', hint: '' };
  const toName = (s) => slugify(String(s || '')).replace(/-/g, '_').slice(0, 60) || 'field';
  function uniqueName(base, siblings, self) {
    const taken = new Set(siblings.filter((x) => x !== self).map((x) => x.name));
    let n = base, i = 2;
    while (taken.has(n)) n = base + '_' + i++;
    return n;
  }

  // ---- state helpers ----
  function changed() {
    if (!ready) return;
    form.classList.add('is-dirty');
    $('.cm-save-bar', form)?.classList.add('is-dirty');
  }
  function addField(type) {
    const t = typeOf(type);
    const f = { label: t.label, name: '', type, required: false, width: '100', help: '', placeholder: '', default: '', options: [] };
    if (type === 'tab') f.label = 'New tab';
    if (type === 'repeater') f.sub_fields = [];
    f.name = uniqueName(type === 'tab' ? 'tab_' + toName(f.label) : toName(f.label), group.fields, f);
    group.fields.push(f);
    open = f;
    render();
    changed();
    const li = list.lastElementChild;
    li?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    const inp = li?.querySelector('[data-k="label"]');
    if (inp) { inp.focus(); inp.select(); }
  }

  // ---- palette ----
  function renderPalette() {
    const groups = {};
    Object.entries(TYPES).forEach(([k, t]) => { (groups[t.group] = groups[t.group] || []).push([k, t]); });
    $('[data-fb-palette]', form).innerHTML = Object.entries(groups).map(([g, items]) =>
      '<div class="fb-pal-group"><span class="small-label">' + esc(g) + '</span><div class="fb-pal">' +
      items.map(([k, t]) => '<button type="button" class="fb-pal-item" data-add-type="' + esc(k) + '" title="' + esc(t.hint) + '">' + t.icon + '<span>' + esc(t.label) + '</span></button>').join('') +
      '</div></div>').join('');
  }

  // ---- list ----
  function summary(f) {
    const bits = [];
    if (f.type !== 'tab') bits.push('<code>' + esc(f.name) + '</code>');
    bits.push(esc(typeOf(f.type).label));
    if (f.required) bits.push('<span class="fb-req">Required</span>');
    if (f.type !== 'tab' && String(f.width || '100') !== '100') bits.push(esc(f.width) + '% wide');
    if (f.type === 'repeater') bits.push((f.sub_fields || []).length + ' in each row');
    if (CHOICES.includes(f.type) && !(f.options || []).length) bits.push('<span class="fb-warn">' + IC.alert + ' No choices yet</span>');
    if (f.type === 'taxonomy' && !f.taxonomy) bits.push('<span class="fb-warn">' + IC.alert + ' Pick a taxonomy</span>');
    return bits.join(' · ');
  }

  function itemHtml(f, i) {
    const isOpen = open === f;
    const n = group.fields.length;
    return '<li class="fb-field' + (isOpen ? ' is-open' : '') + (f.type === 'tab' ? ' is-tab' : '') + '" data-i="' + i + '">' +
      '<div class="fb-row" draggable="true">' +
      '<span class="fb-grip" title="Drag to move">' + IC.grip + '</span>' +
      '<span class="fb-type-icon" title="' + esc(typeOf(f.type).label) + '">' + typeOf(f.type).icon + '</span>' +
      '<button type="button" class="fb-title" data-toggle aria-expanded="' + isOpen + '"><strong>' + esc(f.label || 'Untitled') + '</strong><small>' + summary(f) + '</small></button>' +
      '<span class="fb-tools">' +
      '<button type="button" class="icon-btn fb-up" data-act="up" title="Move up" aria-label="Move up"' + (i === 0 ? ' disabled' : '') + '>' + IC.up + '</button>' +
      '<button type="button" class="icon-btn" data-act="down" title="Move down" aria-label="Move down"' + (i === n - 1 ? ' disabled' : '') + '>' + IC.up + '</button>' +
      '<button type="button" class="icon-btn" data-act="dup" title="Duplicate" aria-label="Duplicate">' + IC.copy + '</button>' +
      '<button type="button" class="icon-btn fb-del" data-act="del" title="Delete" aria-label="Delete">' + IC.trash + '</button>' +
      '</span><span class="fb-chev">' + IC.chevron + '</span></div>' +
      (isOpen ? '<div class="fb-panel">' + panelHtml(f) + '</div>' : '') +
      '</li>';
  }

  const fieldEl = (label, inner, cls, help) => '<label class="field' + (cls ? ' ' + cls : '') + '"><span>' + label + '</span>' + inner + (help ? '<small class="help">' + help + '</small>' : '') + '</label>';
  const input = (k, v, extra) => '<input class="input" data-k="' + k + '" value="' + esc(v ?? '') + '"' + (extra || '') + '>';

  function typeSelect(cur, allowed) {
    const groups = {};
    Object.entries(TYPES).forEach(([k, t]) => { if (!allowed || allowed.includes(k)) (groups[t.group] = groups[t.group] || []).push([k, t]); });
    return Object.entries(groups).map(([g, items]) => '<optgroup label="' + esc(g) + '">' +
      items.map(([k, t]) => '<option value="' + esc(k) + '"' + (k === cur ? ' selected' : '') + '>' + esc(t.label) + '</option>').join('') + '</optgroup>').join('');
  }

  function nameField(f, scope) {
    if (f._saved && !f._unlock) {
      return '<div class="field"><span>Name in templates</span><div class="fb-name-locked"><code>' + esc(f.name) + '</code>' +
        '<button type="button" class="btn btn-sm btn-ghost" data-act="unlock"' + (scope ? ' data-scope="' + scope + '"' : '') + '>' + IC.lock + ' Change</button></div>' +
        '<small class="help">Saved content uses this name, so it stays the same when you rename the label.</small></div>';
    }
    return fieldEl('Name in templates', input('name', f.name, ' spellcheck="false" autocomplete="off"' + (scope ? ' data-scope="' + scope + '"' : '')), '',
      f._saved ? '<span class="fb-warn">' + IC.alert + ' Content saved under "' + esc(f._origName || f.name) + '" will no longer show in this field.</span>' : 'Filled in from the label. Lowercase letters, numbers and underscores.');
  }

  function panelHtml(f) {
    if (f.type === 'tab') {
      return '<div class="fb-grid">' + fieldEl('Tab label', input('label', f.label), 'span-2', 'Fields below this tab, up to the next tab, are shown together under this label.') +
        fieldEl('Type', '<select class="input" data-k="type">' + typeSelect(f.type) + '</select>') + '</div>';
    }
    let h = '<div class="fb-grid">' +
      fieldEl('Label', input('label', f.label, ' autocomplete="off"')) + nameField(f) +
      fieldEl('Type', '<select class="input" data-k="type">' + typeSelect(f.type) + '</select>', '', esc(typeOf(f.type).hint)) +
      fieldEl('Width', '<select class="input" data-k="width">' + WIDTHS.map(([w, l]) => '<option value="' + w + '"' + (String(f.width || '100') === w ? ' selected' : '') + '>' + l + '</option>').join('') + '</select>') +
      '<label class="fb-switch span-2"><input type="checkbox" class="switch" data-k="required"' + (f.required ? ' checked' : '') + '><span><strong>Required</strong><small class="muted">Editors must fill this in before publishing.</small></span></label>' +
      fieldEl('Instructions <small class="muted">optional</small>', input('help', f.help, ' placeholder="Shown under the field"'), 'span-2');
    if (!NO_PLACEHOLDER.includes(f.type)) h += fieldEl('Placeholder <small class="muted">optional</small>', input('placeholder', f.placeholder));
    if (!NO_DEFAULT.includes(f.type)) h += fieldEl('Default value <small class="muted">optional</small>', input('default', f.default));
    if (CHOICES.includes(f.type)) {
      h += fieldEl('Choices', '<textarea class="input" rows="4" data-k="options" placeholder="Small\nMedium\nlarge : Large">' + esc((f.options || []).join('\n')) + '</textarea>', 'span-2',
        'One per line. To store a different value than the label, write value : Label.');
    }
    if (f.type === 'relation') {
      const chosen = String(f.post_type || '').split(',').filter(Boolean);
      h += '<div class="field span-2"><span>Can link to</span><div class="cm-loc">' +
        Object.entries(cfg.postTypes).map(([k, t]) => '<label class="cm-toggle-chip"><input type="checkbox" data-k="post_type" value="' + esc(k) + '"' + (chosen.includes(k) ? ' checked' : '') + '>' + t.icon + esc(t.label) + '</label>').join('') +
        '</div><small class="help">Leave all off to allow any content.</small></div>';
    }
    if (f.type === 'taxonomy') {
      h += fieldEl('Taxonomy', '<select class="input" data-k="taxonomy"><option value="">Choose a taxonomy</option>' +
        Object.entries(cfg.taxonomies).map(([k, t]) => '<option value="' + esc(k) + '"' + (f.taxonomy === k ? ' selected' : '') + '>' + esc(t.label) + '</option>').join('') + '</select>');
    }
    h += '</div>';
    if (f.type === 'repeater') h += subsHtml(f);
    if (f.name) h += '<p class="fb-code muted small">In a template: <code>' + esc(f.type === 'repeater' ? "foreach (field('" + f.name + "') as $row)" : "field('" + f.name + "')") + '</code></p>';
    return h;
  }

  function subsHtml(f) {
    const subs = f.sub_fields = f.sub_fields || [];
    return '<div class="fb-subs"><div class="fb-subs-head"><strong>Fields in each row</strong><small class="muted">Editors add as many rows as they need, each with these fields.</small></div>' +
      (subs.length ? '<ol class="fb-sub-list">' + subs.map((s, si) =>
        '<li class="fb-srow" data-si="' + si + '">' +
        '<span class="fb-sub-move"><button type="button" class="icon-btn fb-up" data-act="sub-up" data-si="' + si + '" title="Move up" aria-label="Move up"' + (si === 0 ? ' disabled' : '') + '>' + IC.up + '</button>' +
        '<button type="button" class="icon-btn" data-act="sub-down" data-si="' + si + '" title="Move down" aria-label="Move down"' + (si === subs.length - 1 ? ' disabled' : '') + '>' + IC.up + '</button></span>' +
        '<label class="fb-sub-cell"><span>Label</span><input class="input input-sm" data-sk="label" data-si="' + si + '" value="' + esc(s.label) + '" autocomplete="off"></label>' +
        '<label class="fb-sub-cell"><span>Type</span><select class="input input-sm" data-sk="type" data-si="' + si + '">' + typeSelect(s.type, cfg.subTypes) + '</select></label>' +
        (s._saved && !s._unlock
          ? '<span class="fb-sub-cell"><span>Name</span><span class="fb-name-locked is-sm"><code>' + esc(s.name) + '</code><button type="button" class="icon-btn" data-act="sub-unlock" data-si="' + si + '" title="Change the name" aria-label="Change the name">' + IC.lock + '</button></span></span>'
          : '<label class="fb-sub-cell"><span>Name</span><input class="input input-sm" data-sk="name" data-si="' + si + '" value="' + esc(s.name) + '" spellcheck="false" autocomplete="off"></label>') +
        '<label class="fb-sub-cell"><span>Width</span><select class="input input-sm" data-sk="row_width" data-si="' + si + '">' +
          [['auto', 'Auto']].concat(WIDTHS.map(([w, l]) => [w, w === '100' ? 'Full row' : l])).map(([w, l]) => '<option value="' + w + '"' + (String(s.row_width || 'auto') === w ? ' selected' : '') + '>' + l + '</option>').join('') + '</select></label>' +
        '<button type="button" class="icon-btn fb-del" data-act="sub-del" data-si="' + si + '" title="Remove" aria-label="Remove">' + IC.trash + '</button>' +
        (s.type === 'select' ? '<label class="fb-sub-cell fb-sub-wide"><span>Choices, one per line</span><textarea class="input input-sm" rows="3" data-sk="options" data-si="' + si + '">' + esc((s.options || []).join('\n')) + '</textarea></label>' : '') +
        '</li>').join('') + '</ol>' : '<p class="muted small fb-subs-empty">No fields in the rows yet.</p>') +
      '<div class="fb-sub-add"><select class="input input-sm" data-sub-type aria-label="Type of the new field">' + typeSelect('text', cfg.subTypes) + '</select>' +
      '<button type="button" class="btn btn-sm" data-act="sub-add">' + IC.plus + ' Add to each row</button></div></div>';
  }

  function render() {
    list.innerHTML = group.fields.map(itemHtml).join('');
    $('[data-fields-empty]', form).hidden = group.fields.length > 0;
    $('[data-fb-count]', form).textContent = group.fields.filter((f) => f.type !== 'tab').length;
    renderPreview();
  }
  function refreshRow(li, f) {
    const t = $('.fb-title', li);
    t.querySelector('strong').textContent = f.label || 'Untitled';
    t.querySelector('small').innerHTML = summary(f);
  }

  // ---- where it shows ----
  function renderWhere() {
    const loc = { post_types: [], taxonomies: [] };
    $$('[data-loc]:checked', form).forEach((cb) => loc[cb.dataset.loc].push(cb.value));
    const names = loc.post_types.map((k) => (cfg.postTypes[k] || {}).label || k);
    const taxes = loc.taxonomies.map((k) => (cfg.taxonomies[k] || {}).label || k);
    const join = (a) => a.length < 2 ? a.join('') : a.slice(0, -1).join(', ') + ' and ' + a[a.length - 1];
    const el = $('[data-fb-where]', form);
    if (!names.length && !taxes.length) {
      el.innerHTML = IC.alert + ' Not shown anywhere yet. Pick at least one place above.';
      el.classList.add('is-warn');
    } else {
      el.textContent = 'Shows when editing ' + [names.length ? join(names) : '', taxes.length ? join(taxes) + ' terms' : ''].filter(Boolean).join(', and on ') + '.';
      el.classList.remove('is-warn');
    }
    const pw = $('[data-fb-preview-where]', form);
    if (pw) pw.textContent = names[0] ? 'Editing one of ' + names[0] : '';
    return loc;
  }

  // ---- preview: a small version of the box in the editor ----
  function mockInput(f) {
    const ph = esc(f.placeholder || f.default || '');
    const opts = (f.options || []).slice(0, 4).map((o) => o.includes(':') ? o.split(':').slice(1).join(':').trim() : o);
    switch (f.type) {
      case 'textarea': case 'code': return '<span class="fbp-input is-area">' + ph + '</span>';
      case 'richtext': return '<span class="fbp-input is-rich"><i></i><i></i><i class="short"></i></span>';
      case 'select': case 'form': case 'taxonomy': return '<span class="fbp-input is-select">' + esc(opts[0] || 'Select') + IC.up + '</span>';
      case 'radio': case 'checkbox': return '<span class="fbp-choices">' + (opts.length ? opts : ['Option']).map((o, i) => '<span><b class="' + (f.type === 'radio' ? 'is-round' : '') + (i === 0 ? ' is-on' : '') + '"></b>' + esc(o) + '</span>').join('') + '</span>';
      case 'toggle': return '<span class="fbp-toggle"><b></b>' + esc(f.label) + '</span>';
      case 'image': case 'file': return '<span class="fbp-media">' + typeOf(f.type).icon + ' Choose</span>';
      case 'gallery': return '<span class="fbp-gallery"><b></b><b></b><b></b></span>';
      case 'color': return '<span class="fbp-input"><b class="fbp-swatch" style="background:' + (/^#[0-9a-f]{3,8}$/i.test(f.default || '') ? f.default : '#F26B2D') + '"></b></span>';
      case 'relation': return '<span class="fbp-input">Search to add</span>';
      case 'repeater': return '<span class="fbp-repeater">' + ((f.sub_fields || []).length ? '<span class="fbp-row">' + f.sub_fields.slice(0, 3).map((s) => '<span><small>' + esc(s.label) + '</small><span class="fbp-input"></span></span>').join('') + '</span>' : '<small class="muted">No fields in the rows yet</small>') + '<span class="fbp-add">' + IC.plus + ' Add row</span></span>';
      default: return '<span class="fbp-input">' + ph + '</span>';
    }
  }
  function renderPreview() {
    const box = $('[data-fb-preview]', form);
    const pos = ($('[data-g="position"]:checked', form) || {}).value || 'main';
    box.classList.toggle('is-side', pos === 'side');
    if (!group.fields.length) { box.innerHTML = '<p class="muted small">Add fields to see how the box looks to editors.</p>'; return; }
    const tabs = group.fields.filter((f) => f.type === 'tab').map((f) => f.label || 'Tab');
    const fieldHtml = (f) => {
      const w = pos === 'side' ? 100 : (+f.width || 100);
      return '<div class="fbp-field" style="--w:' + w + '%">' + (f.type === 'toggle' ? '' : '<span class="fbp-label">' + esc(f.label || 'Untitled') + (f.required ? ' <em>*</em>' : '') + '</span>') +
        mockInput(f) + (f.help ? '<small>' + esc(f.help) + '</small>' : '') + '</div>';
    };
    // Like the editor: fields before the first tab are always visible, then the tab bar with the first tab open.
    let before = '', first = '', inTab = 0;
    group.fields.forEach((f) => {
      if (f.type === 'tab') { inTab++; return; }
      if (inTab === 0) before += fieldHtml(f);
      else if (inTab === 1) first += fieldHtml(f);
    });
    box.innerHTML = '<div class="fbp-card"><div class="fbp-head">' + esc(titleInput.value || 'Field group') + '</div>' +
      (before ? '<div class="fbp-body">' + before + '</div>' : '') +
      (tabs.length ? '<div class="fbp-tabs">' + tabs.map((t, i) => '<span class="' + (i === 0 ? 'is-on' : '') + '">' + esc(t) + '</span>').join('') + '</div>' +
        '<div class="fbp-body">' + (first || '<p class="muted small">This tab is empty.</p>') + '</div>' : '') + '</div>';
  }

  // ---- editing ----
  list.addEventListener('input', (e) => {
    const el = e.target;
    const li = el.closest('.fb-field');
    if (!li) return;
    const f = group.fields[+li.dataset.i];
    if (el.dataset.sk !== undefined) {
      const s = f.sub_fields[+el.dataset.si];
      const k = el.dataset.sk;
      if (k === 'options') s.options = el.value.split('\n').map((x) => x.trim()).filter(Boolean);
      else if (k === 'name') { s.name = el.value.toLowerCase().replace(/[^a-z0-9_]/g, ''); s._touched = true; }
      else s[k] = el.value;
      if (k === 'label' && !s._saved && !s._touched) {
        s.name = uniqueName(toName(el.value), f.sub_fields, s);
        const ni = li.querySelector('[data-sk="name"][data-si="' + el.dataset.si + '"]');
        if (ni) ni.value = s.name;
      }
    } else if (el.dataset.k) {
      const k = el.dataset.k;
      if (k === 'required') f.required = el.checked;
      else if (k === 'post_type') f.post_type = $$('[data-k="post_type"]:checked', li).map((c) => c.value).join(',');
      else if (k === 'options') f.options = el.value.split('\n').map((x) => x.trim()).filter(Boolean);
      else if (k === 'name') { f.name = el.value.toLowerCase().replace(/[^a-z0-9_]/g, ''); f._touched = true; }
      else if (k !== 'type') f[k] = el.value;
      if (k === 'label' && !f._saved && !f._touched) {
        f.name = uniqueName(f.type === 'tab' ? 'tab_' + toName(el.value) : toName(el.value), group.fields, f);
        const ni = li.querySelector('[data-k="name"]');
        if (ni) ni.value = f.name;
      }
      refreshRow(li, f);
    }
    renderPreview();
    changed();
  });
  list.addEventListener('change', (e) => {
    const el = e.target;
    const li = el.closest('.fb-field');
    if (!li) return;
    const f = group.fields[+li.dataset.i];
    if (el.dataset.k === 'type') {
      f.type = el.value;
      if (f.type === 'repeater') f.sub_fields = f.sub_fields || [];
      render();
      changed();
    } else if (el.dataset.sk === 'type') {
      f.sub_fields[+el.dataset.si].type = el.value;
      render();
      changed();
    } else if (el.dataset.k === 'name' || el.dataset.sk === 'name') {
      // Never leave a name empty or duplicated: fall back to the label.
      const isSub = el.dataset.sk === 'name';
      const obj = isSub ? f.sub_fields[+el.dataset.si] : f;
      const sibs = isSub ? f.sub_fields : group.fields;
      const fixed = uniqueName(obj.name || toName(obj.label), sibs, obj);
      if (fixed !== obj.name) { obj.name = fixed; el.value = fixed; toast('Names must be unique, so it became "' + fixed + '".'); }
      if (!isSub) refreshRow(li, f);
    }
  });

  function move(arr, from, to) {
    if (to < 0 || to >= arr.length || from === to) return;
    const [x] = arr.splice(from, 1);
    arr.splice(to, 0, x);
  }

  list.addEventListener('click', (e) => {
    const li = e.target.closest('.fb-field');
    if (!li) return;
    const i = +li.dataset.i;
    const f = group.fields[i];
    const btn = e.target.closest('[data-act], [data-toggle]');
    if (!btn) return;
    if (btn.matches('[data-toggle]')) { open = open === f ? null : f; render(); return; }
    const act = btn.dataset.act;
    const si = +btn.dataset.si;
    if (act === 'up' || act === 'down') {
      move(group.fields, i, act === 'up' ? i - 1 : i + 1);
      render();
      const again = $$('.fb-field', list)[act === 'up' ? i - 1 : i + 1];
      again?.querySelector('[data-act="' + act + '"]:not(:disabled)')?.focus();
    } else if (act === 'dup') {
      const c = JSON.parse(JSON.stringify(f, (k, v) => (k === '_saved' || k === '_unlock' || k === '_touched' || k === '_origName') ? undefined : v));
      c.label = (f.label || 'Field') + ' copy';
      c.name = uniqueName(f.type === 'tab' ? f.name : toName(c.label), group.fields, c);
      group.fields.splice(i + 1, 0, c);
      open = c;
      render();
      toast('Field duplicated.');
    } else if (act === 'del') {
      if (f._saved && !confirm('Delete the field "' + (f.label || f.name) + '"? Values already saved stay on the content, but are no longer shown.')) return;
      group.fields.splice(i, 1);
      if (open === f) open = null;
      render();
    } else if (act === 'unlock') {
      f._unlock = true;
      f._origName = f.name;
      render();
      $('.fb-field.is-open [data-k="name"]', list)?.focus();
    } else if (act === 'sub-add') {
      const type = $('[data-sub-type]', li).value;
      const s = { label: typeOf(type).label, name: '', type, required: false };
      s.name = uniqueName(toName(s.label), f.sub_fields, s);
      f.sub_fields.push(s);
      render();
      const inp = $$('.fb-field.is-open [data-sk="label"]', list).pop();
      if (inp) { inp.focus(); inp.select(); }
    } else if (act === 'sub-del') {
      const s = f.sub_fields[si];
      if (s._saved && !confirm('Remove "' + (s.label || s.name) + '" from the rows? Values already saved stay, but are no longer shown.')) return;
      f.sub_fields.splice(si, 1);
      render();
    } else if (act === 'sub-up' || act === 'sub-down') {
      move(f.sub_fields, si, act === 'sub-up' ? si - 1 : si + 1);
      render();
    } else if (act === 'sub-unlock') {
      f.sub_fields[si]._unlock = true;
      f.sub_fields[si]._origName = f.sub_fields[si].name;
      render();
      $('.fb-field.is-open [data-sk="name"][data-si="' + si + '"]', list)?.focus();
    } else return;
    changed();
  });

  // ---- drag and drop ----
  let dragFrom = null;
  const clearMarks = () => $$('.drop-before, .drop-after', list).forEach((el) => el.classList.remove('drop-before', 'drop-after'));
  list.addEventListener('dragstart', (e) => {
    const row = e.target.closest && e.target.closest('.fb-row');
    if (!row) return;
    const li = row.closest('.fb-field');
    dragFrom = +li.dataset.i;
    li.classList.add('is-dragging');
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', String(dragFrom)); } catch (err) { /* some browsers */ }
  });
  list.addEventListener('dragover', (e) => {
    if (dragFrom === null) return;
    const row = e.target.closest('.fb-row');
    if (!row) return;
    e.preventDefault();
    clearMarks();
    const r = row.getBoundingClientRect();
    row.classList.add(e.clientY < r.top + r.height / 2 ? 'drop-before' : 'drop-after');
  });
  list.addEventListener('drop', (e) => {
    if (dragFrom === null) return;
    e.preventDefault();
    const row = e.target.closest('.fb-row');
    if (row) {
      const to = +row.closest('.fb-field').dataset.i;
      const after = row.classList.contains('drop-after');
      let dest = to + (after ? 1 : 0);
      if (dragFrom < dest) dest--;
      move(group.fields, dragFrom, dest);
      changed();
    }
    dragFrom = null;
    render();
  });
  list.addEventListener('dragend', () => { dragFrom = null; clearMarks(); $$('.is-dragging', list).forEach((x) => x.classList.remove('is-dragging')); });

  // ---- group settings ----
  form.addEventListener('click', (e) => {
    const b = e.target.closest('[data-add-type]');
    if (b) addField(b.dataset.addType);
    if (e.target.closest('[data-fb-jump]')) {
      const pal = $('#fb-palette');
      pal.scrollIntoView({ behavior: 'smooth', block: 'start' });
      pal.querySelector('[data-add-type]')?.focus({ preventScroll: true });
    }
  });
  titleInput.addEventListener('input', () => {
    const h = $('[data-fb-title]', form);
    h.textContent = titleInput.value.trim() || h.dataset.new;
    titleInput.classList.remove('is-invalid');
    renderPreview();
    changed();
  });
  form.addEventListener('change', (e) => {
    if (e.target.matches('[data-loc]')) { renderWhere(); changed(); }
    if (e.target.matches('[data-g="position"]')) { renderPreview(); changed(); }
  });

  // ---- save ----
  const clean = (k, v) => (typeof k === 'string' && k.startsWith('_')) ? undefined : v;
  form.addEventListener('submit', (e) => {
    if (e.submitter && e.submitter.getAttribute('formaction')) return;
    group.title = titleInput.value.trim();
    group.position = ($('[data-g="position"]:checked', form) || {}).value || 'main';
    group.location = renderWhere();
    const fail = (msg, f) => {
      e.preventDefault();
      toast(msg, 'error');
      if (f) { open = f; render(); $('.fb-field.is-open', list)?.scrollIntoView({ block: 'center' }); }
    };
    if (!group.title) { fail('Give the field group a name.'); titleInput.classList.add('is-invalid'); titleInput.focus(); return; }
    const seen = new Set();
    for (const f of group.fields) {
      if (f.type === 'tab') continue;
      if (!f.name || seen.has(f.name)) return fail('Every field needs its own name. Check "' + (f.label || 'Untitled') + '".', f);
      seen.add(f.name);
      if (f.type === 'repeater') {
        const subSeen = new Set();
        for (const s of f.sub_fields || []) {
          if (!s.name || subSeen.has(s.name)) return fail('The fields in "' + f.label + '" need their own names.', f);
          subSeen.add(s.name);
        }
      }
    }
    $('[data-group-json]', form).value = JSON.stringify(group, clean);
  });

  // ---- start ----
  titleInput.value = group.title || '';
  const pos = $('[data-g="position"][value="' + (group.position === 'side' ? 'side' : 'main') + '"]', form);
  if (pos) pos.checked = true;
  $$('[data-loc]', form).forEach((cb) => { cb.checked = (group.location[cb.dataset.loc] || []).includes(cb.value); });
  const taxMore = $('[data-fb-tax-more]', form);
  if (taxMore && (group.location.taxonomies || []).length) taxMore.open = true;
  renderPalette();
  renderWhere();
  render();
  if (!group.title) titleInput.focus();
  ready = true;
})();
