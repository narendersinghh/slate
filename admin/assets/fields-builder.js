/* Slate custom field group builder. */
(function () {
  'use strict';
  const { esc, $, $$, slugify } = window.Slate;
  const form = $('[data-field-builder]');
  if (!form) return;
  const group = JSON.parse($('[data-group]', form).textContent);
  const types = JSON.parse($('[data-field-types]', form).textContent);
  const postTypes = JSON.parse($('[data-post-types]', form).textContent);
  group.fields = group.fields || [];
  group.location = group.location || { post_types: [], taxonomies: [] };

  $('[data-g="title"]', form).value = group.title || '';
  $('[data-g="position"]', form).value = group.position || 'main';
  $$('[data-loc]', form).forEach((cb) => { cb.checked = (group.location[cb.dataset.loc] || []).includes(cb.value); });

  const list = $('[data-fields]', form);
  let open = -1;
  const typeOptions = (sel) => Object.entries(types).map(([k, l]) => '<option value="' + k + '"' + (k === sel ? ' selected' : '') + '>' + esc(l) + '</option>').join('');
  const needsOptions = (t) => ['select', 'radio', 'checkbox'].includes(t);

  function fieldBody(f, path) {
    const opt = (name, label, html) => '<label class="field"><span>' + label + '</span>' + html + '</label>';
    let h = opt('label', 'Label', '<input class="input" data-p="' + path + '" data-k="label" value="' + esc(f.label) + '">') +
      opt('name', 'Name (key)', '<input class="input" data-p="' + path + '" data-k="name" value="' + esc(f.name) + '" pattern="[a-z0-9_]+">') +
      opt('type', 'Type', '<select class="input" data-p="' + path + '" data-k="type">' + typeOptions(f.type) + '</select>') +
      opt('width', 'Width', '<select class="input" data-p="' + path + '" data-k="width">' + ['100', '75', '66', '50', '33', '25'].map((w) => '<option value="' + w + '"' + (String(f.width || '100') === w ? ' selected' : '') + '>' + w + '%</option>').join('') + '</select>') +
      opt('help', 'Instructions', '<input class="input" data-p="' + path + '" data-k="help" value="' + esc(f.help || '') + '">') +
      opt('placeholder', 'Placeholder', '<input class="input" data-p="' + path + '" data-k="placeholder" value="' + esc(f.placeholder || '') + '">') +
      opt('default', 'Default value', '<input class="input" data-p="' + path + '" data-k="default" value="' + esc(f.default || '') + '">') +
      '<label class="field"><span>&nbsp;</span><label class="switch-row"><input type="checkbox" class="switch" data-p="' + path + '" data-k="required"' + (f.required ? ' checked' : '') + '><span>Required</span></label></label>';
    if (needsOptions(f.type)) {
      h += '<label class="field span-2"><span>Choices <small class="muted">one per line, optionally value : Label</small></span><textarea class="input" rows="4" data-p="' + path + '" data-k="options">' + esc((f.options || []).join('\n')) + '</textarea></label>';
    }
    if (f.type === 'relation') {
      h += opt('post_type', 'Limit to post type', '<select class="input" data-p="' + path + '" data-k="post_type"><option value="">Any</option>' + Object.entries(postTypes).map(([k, l]) => '<option value="' + k + '"' + (f.post_type === k ? ' selected' : '') + '>' + esc(l) + '</option>').join('') + '</select>');
    }
    if (f.type === 'repeater' && !path.includes('.')) {
      f.sub_fields = f.sub_fields || [];
      h += '<div class="fb-sub"><strong class="small-label">Sub fields</strong>' + f.sub_fields.map((sf, si) =>
        '<div class="grid-form" style="margin:8px 0"><input class="input input-sm" placeholder="Label" data-p="' + path + '.' + si + '" data-k="label" value="' + esc(sf.label) + '">' +
        '<span class="input-group"><select class="input input-sm" data-p="' + path + '.' + si + '" data-k="type">' + typeOptions(sf.type).replace(/<option value="(repeater|gallery|relation|richtext)"[^<]*<\/option>/g, '') + '</select>' +
        '<button type="button" class="icon-btn" data-del-sub="' + path + '.' + si + '">✕</button></span></div>').join('') +
        '<button type="button" class="btn btn-sm" data-add-sub="' + path + '">+ Add sub field</button></div>';
    }
    return h;
  }

  function render() {
    list.innerHTML = group.fields.map((f, i) =>
      '<div class="fb-item" draggable="true" data-i="' + i + '">' +
      '<div class="fb-head" data-toggle="' + i + '"><span class="grip">⋮⋮</span><strong>' + esc(f.label || '(no label)') + '</strong><code>' + esc(f.name) + '</code><span class="pill pill-soft">' + esc(types[f.type] || f.type) + '</span>' +
      '<button type="button" class="icon-btn" data-dup="' + i + '" title="Duplicate">⧉</button><button type="button" class="icon-btn" data-del="' + i + '" title="Delete">✕</button></div>' +
      (open === i ? '<div class="fb-body">' + fieldBody(f, String(i)) + '</div>' : '') + '</div>').join('');
    $('[data-fields-empty]', form).hidden = group.fields.length > 0;
  }
  const resolve = (path) => {
    const parts = path.split('.').map(Number);
    return parts.length === 1 ? group.fields[parts[0]] : group.fields[parts[0]].sub_fields[parts[1]];
  };

  list.addEventListener('input', (e) => {
    const el = e.target;
    if (!el.dataset.p) return;
    const f = resolve(el.dataset.p);
    const k = el.dataset.k;
    if (k === 'required') f.required = el.checked;
    else if (k === 'options') f.options = el.value.split('\n').map((s) => s.trim()).filter(Boolean);
    else f[k] = el.value;
    if (k === 'label' && (!f._nameTouched)) {
      f.name = slugify(el.value).replace(/-/g, '_');
      const nameInput = el.closest('.fb-body, .grid-form')?.querySelector('[data-k="name"][data-p="' + el.dataset.p + '"]');
      if (nameInput) nameInput.value = f.name;
    }
    if (k === 'name') f._nameTouched = true;
    if (!el.dataset.p.includes('.')) {
      const head = el.closest('.fb-item').querySelector('.fb-head');
      head.querySelector('strong').textContent = f.label || '(no label)';
      head.querySelector('code').textContent = f.name;
    }
  });
  list.addEventListener('change', (e) => { if (e.target.dataset.k === 'type') render(); });
  list.addEventListener('click', (e) => {
    const t = e.target;
    if (t.closest('[data-del]')) { e.stopPropagation(); if (confirm('Delete this field? Stored values stay on existing content.')) { group.fields.splice(+t.closest('[data-del]').dataset.del, 1); open = -1; render(); } return; }
    if (t.closest('[data-dup]')) { e.stopPropagation(); const i = +t.closest('[data-dup]').dataset.dup; const c = JSON.parse(JSON.stringify(group.fields[i])); c.name += '_copy'; c.label += ' (copy)'; group.fields.splice(i + 1, 0, c); render(); return; }
    if (t.closest('[data-add-sub]')) { const f = resolve(t.closest('[data-add-sub]').dataset.addSub); f.sub_fields.push({ label: 'Sub field', name: 'sub_' + (f.sub_fields.length + 1), type: 'text' }); render(); return; }
    if (t.closest('[data-del-sub]')) { const [a, b] = t.closest('[data-del-sub]').dataset.delSub.split('.').map(Number); group.fields[a].sub_fields.splice(b, 1); render(); return; }
    const head = t.closest('[data-toggle]');
    if (head && !t.closest('button')) { open = open === +head.dataset.toggle ? -1 : +head.dataset.toggle; render(); }
  });
  // Sub-field label → name
  list.addEventListener('input', (e) => {
    const el = e.target;
    if (el.dataset.p && el.dataset.p.includes('.') && el.dataset.k === 'label') resolve(el.dataset.p).name = slugify(el.value).replace(/-/g, '_');
  });

  // Drag to reorder
  let dragIndex = null;
  list.addEventListener('dragstart', (e) => { const it = e.target.closest('.fb-item'); if (!it) return; dragIndex = +it.dataset.i; it.classList.add('dragging'); });
  list.addEventListener('dragend', () => { dragIndex = null; render(); });
  list.addEventListener('dragover', (e) => {
    e.preventDefault();
    const over = e.target.closest('.fb-item');
    if (!over || dragIndex === null) return;
    const to = +over.dataset.i;
    if (to === dragIndex) return;
    const [moved] = group.fields.splice(dragIndex, 1);
    group.fields.splice(to, 0, moved);
    dragIndex = to;
    open = -1;
    render();
    $$('.fb-item', list)[to]?.classList.add('dragging');
  });

  $('[data-add-field]', form).addEventListener('click', () => {
    const n = group.fields.length + 1;
    group.fields.push({ label: 'Field ' + n, name: 'field_' + n, type: 'text', width: '100' });
    open = group.fields.length - 1;
    render();
    const inp = list.querySelector('.fb-body [data-k="label"]');
    inp?.focus();
    inp?.select();
  });

  form.addEventListener('submit', (e) => {
    if (e.submitter && e.submitter.getAttribute('formaction')) return;
    group.title = $('[data-g="title"]', form).value;
    group.position = $('[data-g="position"]', form).value;
    group.location = { post_types: [], taxonomies: [] };
    $$('[data-loc]:checked', form).forEach((cb) => group.location[cb.dataset.loc].push(cb.value));
    const names = group.fields.map((f) => f.name);
    if (names.some((n, i) => !n || names.indexOf(n) !== i)) { e.preventDefault(); alert('Every field needs a unique name.'); return; }
    $('[data-group-json]', form).value = JSON.stringify(group);
  });
  render();
})();
