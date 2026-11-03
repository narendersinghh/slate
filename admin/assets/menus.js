/* Slate menu builder (Settings → Appearance → Menus). */
(function () {
  'use strict';
  const { esc, $, $$ } = window.Slate;
  const root = $('[data-menu-builder]');
  if (!root) return;
  const menus = JSON.parse($('[data-menus]').textContent || '[]');
  const locations = JSON.parse($('[data-locations]').textContent || '{}');
  const sources = JSON.parse($('[data-menu-sources]').textContent || '{}');
  const out = $('[data-menus-json]');
  let cur = 0;
  const uid = () => Math.random().toString(36).slice(2, 10);
  if (!menus.length) menus.push({ id: 'main', name: 'Main menu', location: Object.keys(locations)[0] || '', items: [] });

  const optList = (items) => {
    const groups = {};
    items.forEach((i) => { (groups[i.group] = groups[i.group] || []).push(i); });
    return Object.entries(groups).map(([g, list]) => '<optgroup label="' + esc(g) + '">' + list.map((i) => '<option value="' + i.id + '">' + esc(i.label) + '</option>').join('') + '</optgroup>').join('');
  };
  const label = (it) => {
    if (it.label) return it.label;
    const src = it.type === 'post' ? sources.posts : it.type === 'term' ? sources.terms : [];
    return (src.find((s) => s.id === it.object_id) || {}).label || '';
  };
  const typeName = (it) => it.type === 'custom' ? 'Custom link' : it.type === 'post' ? ((sources.posts.find((s) => s.id === it.object_id) || {}).group || 'Content') : 'Taxonomy';

  function tree(items, path) {
    return '<ul class="mb-tree">' + items.map((it, i) => {
      const p = path.concat(i).join('.');
      return '<li class="mb-node"><div class="mb-row">' +
        '<input class="input" data-p="' + p + '" data-k="label" value="' + esc(it.label) + '" placeholder="' + esc(label(it)) + '" style="max-width:200px">' +
        (it.type === 'custom' ? '<input class="input" data-p="' + p + '" data-k="url" value="' + esc(it.url) + '" placeholder="https://… or /path/">' : '<span class="mb-type" style="flex:1">' + esc(typeName(it)) + ': ' + esc(label(it)) + '</span>') +
        '<label class="choice mb-type"><input type="checkbox" data-p="' + p + '" data-k="target"' + (it.target ? ' checked' : '') + '> New tab</label>' +
        '<button type="button" class="icon-btn" data-act="up" data-p="' + p + '" title="Move up">↑</button>' +
        '<button type="button" class="icon-btn" data-act="indent" data-p="' + p + '" title="Make sub-item">→</button>' +
        '<button type="button" class="icon-btn" data-act="outdent" data-p="' + p + '" title="Move out">←</button>' +
        '<button type="button" class="icon-btn" data-act="del" data-p="' + p + '" title="Remove">✕</button></div>' +
        (it.children && it.children.length ? tree(it.children, path.concat(i)) : '') + '</li>';
    }).join('') + '</ul>';
  }
  const parentOf = (p) => {
    const parts = p.split('.').map(Number);
    let arr = menus[cur].items;
    for (let i = 0; i < parts.length - 1; i++) arr = arr[parts[i]].children;
    return [arr, parts[parts.length - 1], parts];
  };

  function render() {
    const m = menus[cur];
    root.innerHTML =
      '<div class="mb-menus">' + menus.map((x, i) => '<button type="button" class="btn btn-sm' + (i === cur ? ' btn-primary' : '') + '" data-menu="' + i + '">' + esc(x.name) + '</button>').join('') +
      '<button type="button" class="btn btn-sm btn-ghost" data-new-menu>+ New menu</button></div>' +
      '<div class="mb-layout"><div class="mb-add">' +
      '<label class="field"><span>Menu name</span><input class="input" data-menu-name value="' + esc(m.name) + '"></label>' +
      '<label class="field"><span>Display location</span><select class="input" data-menu-loc><option value="">— None —</option>' + Object.entries(locations).map(([k, l]) => '<option value="' + k + '"' + (m.location === k ? ' selected' : '') + '>' + esc(l) + '</option>').join('') + '</select></label>' +
      '<hr><strong class="small-label">Add items</strong>' +
      '<label class="field"><span>Content</span><span class="input-group"><select class="input" data-add-post>' + optList(sources.posts || []) + '</select><button type="button" class="btn btn-sm" data-add="post">Add</button></span></label>' +
      ((sources.terms || []).length ? '<label class="field"><span>Taxonomy term</span><span class="input-group"><select class="input" data-add-term>' + optList(sources.terms) + '</select><button type="button" class="btn btn-sm" data-add="term">Add</button></span></label>' : '') +
      '<label class="field"><span>Custom link</span><input class="input" data-custom-label placeholder="Label"><span class="input-group"><input class="input" data-custom-url placeholder="https://…"><button type="button" class="btn btn-sm" data-add="custom">Add</button></span></label>' +
      (menus.length > 1 ? '<button type="button" class="link link-danger" data-del-menu>Delete this menu</button>' : '') +
      '</div><div>' + (m.items.length ? tree(m.items, []) : '<p class="muted">This menu is empty. Add items from the left.</p>') + '</div></div>';
    out.value = JSON.stringify(menus);
  }

  root.addEventListener('input', (e) => {
    const el = e.target;
    if (el.matches('[data-menu-name]')) menus[cur].name = el.value;
    if (el.dataset.p) { const [arr, idx] = parentOf(el.dataset.p); arr[idx][el.dataset.k] = el.type === 'checkbox' ? (el.checked ? '_blank' : '') : el.value; }
    out.value = JSON.stringify(menus);
  });
  root.addEventListener('change', (e) => {
    if (e.target.matches('[data-menu-loc]')) {
      const loc = e.target.value;
      menus.forEach((m, i) => { if (i !== cur && m.location === loc) m.location = ''; });
      menus[cur].location = loc;
      out.value = JSON.stringify(menus);
    }
    if (e.target.dataset.k === 'target') root.dispatchEvent(new Event('input'));
  });
  root.addEventListener('click', (e) => {
    const t = e.target.closest('button');
    if (!t) return;
    const m = menus[cur];
    if (t.dataset.menu !== undefined) { cur = +t.dataset.menu; render(); return; }
    if (t.matches('[data-new-menu]')) { menus.push({ id: 'menu-' + uid(), name: 'New menu', location: '', items: [] }); cur = menus.length - 1; render(); return; }
    if (t.matches('[data-del-menu]')) { if (confirm('Delete this menu?')) { menus.splice(cur, 1); cur = 0; render(); } return; }
    if (t.dataset.add === 'post') { const id = +$('[data-add-post]', root).value; if (id) m.items.push({ id: uid(), label: '', type: 'post', object_id: id, children: [] }); }
    if (t.dataset.add === 'term') { const id = +$('[data-add-term]', root).value; if (id) m.items.push({ id: uid(), label: '', type: 'term', object_id: id, children: [] }); }
    if (t.dataset.add === 'custom') { const url = $('[data-custom-url]', root).value.trim(); if (!url) return; m.items.push({ id: uid(), label: $('[data-custom-label]', root).value || url, type: 'custom', url, children: [] }); }
    if (t.dataset.act) {
      const [arr, idx, parts] = parentOf(t.dataset.p);
      if (t.dataset.act === 'del') arr.splice(idx, 1);
      if (t.dataset.act === 'up' && idx > 0) [arr[idx - 1], arr[idx]] = [arr[idx], arr[idx - 1]];
      if (t.dataset.act === 'indent' && idx > 0) { const [it] = arr.splice(idx, 1); (arr[idx - 1].children = arr[idx - 1].children || []).push(it); }
      if (t.dataset.act === 'outdent' && parts.length > 1) {
        const [it] = arr.splice(idx, 1);
        const [parr, pidx] = parentOf(parts.slice(0, -1).join('.'));
        parr.splice(pidx + 1, 0, it);
      }
    }
    render();
  });
  render();
})();
