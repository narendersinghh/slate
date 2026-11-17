/* Slate menu builder (Settings → Appearance → Menus): search to add, drag and drop to order and nest. */
(function () {
  'use strict';
  const { esc, $, $$, debounce } = window.Slate;
  const root = $('[data-menu-builder]');
  if (!root) return;
  const cfg = JSON.parse($('[data-menu-config]').textContent || '{}');
  const menus = cfg.menus || [];
  const locations = cfg.locations || {};
  const refs = cfg.refs || { post: {}, term: {} };
  const out = $('[data-menus-json]');
  const MAX_DEPTH = 3;
  let cur = 0;
  let open = null; // id of the item whose details are open
  const uid = () => Math.random().toString(36).slice(2, 10);
  if (!menus.length) menus.push({ id: 'main', name: 'Main menu', location: Object.keys(locations)[0] || '', items: [] });

  const svg = (d, s = 16) => '<svg class="ic" width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + d + '</svg>';
  const IC = {
    grip: svg('<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>'),
    up: svg('<path d="m18 15-6-6-6 6"/>'), down: svg('<path d="m6 9 6 6 6-6"/>'),
    indent: svg('<path d="M3 6h18M11 12h10M11 18h10M3 10l4 3-4 3"/>'), outdent: svg('<path d="M3 6h18M11 12h10M11 18h10M7 10l-4 3 4 3"/>'),
    trash: svg('<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>'), chevron: svg('<path d="m9 18 6-6-6-6"/>'),
    plus: svg('<path d="M12 5v14M5 12h14"/>'), search: svg('<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>', 15),
    link: svg('<path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>', 14),
    check: svg('<path d="M20 6 9 17l-5-5"/>', 14), warn: svg('<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>', 14),
  };

  const sync = () => { out.value = JSON.stringify(menus); const f = root.closest('form'); if (f) f.dispatchEvent(new Event('input', { bubbles: true })); };
  const ref = (it) => (it.type === 'post' || it.type === 'term') ? (refs[it.type][it.object_id] || null) : null;
  const titleOf = (it) => it.label || (ref(it) || {}).label || it.url || 'Untitled';
  const kindOf = (it) => it.type === 'custom' ? 'Custom link' : ((ref(it) || {}).group || (it.type === 'term' ? 'Term' : 'Content'));

  // ---- tree helpers (path = array of indexes) ----
  const listAt = (path) => { let arr = menus[cur].items; for (const i of path) arr = (arr[i].children = arr[i].children || []); return arr; };
  const get = (path) => listAt(path.slice(0, -1))[path[path.length - 1]];
  const take = (path) => listAt(path.slice(0, -1)).splice(path[path.length - 1], 1)[0];
  const depthOf = (it) => 1 + Math.max(0, ...(it.children || []).map(depthOf));
  const findPath = (id, items = menus[cur].items, base = []) => {
    for (let i = 0; i < items.length; i++) {
      if (items[i].id === id) return base.concat(i);
      const p = findPath(id, items[i].children || [], base.concat(i));
      if (p) return p;
    }
    return null;
  };

  // ---- render ----
  function itemHtml(it, level) {
    const r = ref(it);
    const isOpen = open === it.id;
    return '<li class="mb-item' + (isOpen ? ' is-open' : '') + '" data-id="' + esc(it.id) + '">' +
      '<div class="mb-row" draggable="true">' +
      '<span class="mb-grip" title="Drag to move">' + IC.grip + '</span>' +
      '<button type="button" class="mb-title" data-toggle aria-expanded="' + isOpen + '"><strong>' + esc(titleOf(it)) + '</strong>' +
      '<small>' + esc(kindOf(it)) + (r && r.missing ? ' · <span class="mb-warn">' + IC.warn + ' in trash</span>' : '') + (it.target ? ' · new tab' : '') + '</small></button>' +
      '<span class="mb-tools">' +
      '<button type="button" class="icon-btn" data-act="up" title="Move up" aria-label="Move up">' + IC.up + '</button>' +
      '<button type="button" class="icon-btn" data-act="down" title="Move down" aria-label="Move down">' + IC.down + '</button>' +
      '<button type="button" class="icon-btn" data-act="outdent" title="Move out of the sub-menu" aria-label="Move out"' + (level === 0 ? ' disabled' : '') + '>' + IC.outdent + '</button>' +
      '<button type="button" class="icon-btn" data-act="indent" title="Make it a sub-item of the item above" aria-label="Make sub-item">' + IC.indent + '</button>' +
      '<button type="button" class="icon-btn mb-del" data-act="del" title="Remove" aria-label="Remove">' + IC.trash + '</button>' +
      '</span><span class="mb-chev">' + IC.chevron + '</span></div>' +
      (isOpen ? '<div class="mb-details">' +
        '<label class="field"><span>Label</span><input class="input" data-k="label" value="' + esc(it.label) + '" placeholder="' + esc((r || {}).label || '') + '"></label>' +
        (it.type === 'custom' ? '<label class="field"><span>Link</span><input class="input" data-k="url" value="' + esc(it.url || '') + '" placeholder="https://… or /page/"></label>'
          : '<p class="muted small">Links to the ' + esc(kindOf(it).toLowerCase()) + ' “' + esc((r || {}).label || '?') + '”. The link follows it if its address changes.</p>') +
        '<label class="mb-check"><input type="checkbox" data-k="target"' + (it.target ? ' checked' : '') + '> Open in a new tab</label></div>' : '') +
      ((it.children || []).length ? '<ul class="mb-list">' + it.children.map((c) => itemHtml(c, level + 1)).join('') + '</ul>' : '') +
      '</li>';
  }

  function headHtml() {
    const m = menus[cur];
    const locOpts = '<option value="">Not shown yet</option>' + Object.entries(locations).map(([k, l]) => '<option value="' + esc(k) + '"' + (m.location === k ? ' selected' : '') + '>' + esc(l) + '</option>').join('');
    return '<div class="mb-head card">' +
      '<label class="field mb-pick"><span>Menu</span><select class="input" data-menu-switch>' + menus.map((x, i) => '<option value="' + i + '"' + (i === cur ? ' selected' : '') + '>' + esc(x.name || 'Untitled') + '</option>').join('') + '</select></label>' +
      '<label class="field"><span>Name</span><input class="input" data-menu-name value="' + esc(m.name) + '"></label>' +
      '<label class="field"><span>Shown in</span><select class="input" data-menu-loc>' + locOpts + '</select></label>' +
      '<div class="mb-head-actions"><button type="button" class="btn btn-sm" data-new-menu>' + IC.plus + ' New menu</button>' +
      (menus.length > 1 ? '<button type="button" class="btn btn-sm btn-ghost btn-danger" data-del-menu>Delete menu</button>' : '') + '</div>' +
      '</div>';
  }

  function treeHtml() {
    const m = menus[cur];
    return '<section class="mb-tree card">' +
      '<div class="mb-tree-head"><strong>' + esc(m.name || 'Menu') + '</strong><span class="muted small">Drag items to reorder. Drop onto the middle of an item to make a sub-item.</span></div>' +
      (m.items.length ? '<ul class="mb-list mb-root">' + m.items.map((it) => itemHtml(it, 0)).join('') + '</ul>'
        : '<div class="mb-empty">' + IC.link + '<strong>This menu is empty</strong><span class="muted small">Search for pages or terms and add them.</span></div>') +
      '</section>';
  }

  // The add panel is built once, so searching is not reset when the menu changes.
  function render() {
    if (!$('.mb-add', root)) {
      root.innerHTML = headHtml() +
        '<div class="mb-layout">' +
          '<aside class="mb-add card">' +
            '<div class="mb-tabs" role="tablist"><button type="button" class="is-on" data-tab="post">Content</button><button type="button" data-tab="term">Terms</button><button type="button" data-tab="custom">Custom link</button></div>' +
            '<div class="mb-pane" data-pane="post">' + searchUi('post', cfg.types) + '</div>' +
            '<div class="mb-pane" data-pane="term" hidden>' + searchUi('term', cfg.taxes) + '</div>' +
            '<div class="mb-pane" data-pane="custom" hidden><div class="stack-sm">' +
              '<label class="field"><span>Label</span><input class="input" data-custom-label placeholder="e.g. Shop"></label>' +
              '<label class="field"><span>Link</span><input class="input" data-custom-url placeholder="https://… or /page/"></label>' +
              '<button type="button" class="btn btn-primary btn-sm" data-add-custom>' + IC.plus + ' Add to menu</button></div></div>' +
          '</aside>' + treeHtml() +
        '</div>';
      runSearch('post');
    } else {
      $('.mb-head', root).outerHTML = headHtml();
      $('.mb-tree', root).outerHTML = treeHtml();
    }
    sync();
  }

  function searchUi(kind, filters) {
    const opts = Object.entries(filters || {}).map(([k, l]) => '<option value="' + esc(k) + '">' + esc(l) + '</option>').join('');
    return '<div class="mb-search"><label class="mb-q">' + IC.search + '<input class="input" type="search" data-q="' + kind + '" placeholder="Search ' + (kind === 'post' ? 'pages, posts…' : 'categories, tags…') + '" autocomplete="off"></label>' +
      '<select class="input" data-filter="' + kind + '"><option value="">All types</option>' + opts + '</select></div>' +
      '<div class="mb-results" data-results="' + kind + '"><p class="muted small">Loading…</p></div>' +
      '<div class="mb-results-foot"><button type="button" class="btn btn-sm btn-primary" data-add-selected="' + kind + '" disabled>' + IC.plus + ' Add selected</button><small class="muted" data-sel-count="' + kind + '"></small></div>';
  }

  // ---- search ----
  const found = { post: [], term: [] };
  const runSearch = debounce(async (kind) => {
    const box = $('[data-results="' + kind + '"]', root);
    if (!box) return;
    const q = ($('[data-q="' + kind + '"]', root) || {}).value || '';
    const type = ($('[data-filter="' + kind + '"]', root) || {}).value || '';
    try {
      const r = await fetch(cfg.search + '?kind=' + kind + '&q=' + encodeURIComponent(q) + '&type=' + encodeURIComponent(type), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const d = await r.json();
      found[kind] = d.items || [];
      box.innerHTML = found[kind].length ? (q ? '' : '<p class="muted small">' + (kind === 'post' ? 'Recently updated' : 'Most used') + '</p>') + found[kind].map((x, i) =>
        '<label class="mb-result"><input type="checkbox" value="' + i + '"><span><strong>' + esc(x.label) + '</strong><small>' + esc(x.group) + '</small></span>' +
        '<button type="button" class="icon-btn" data-add-one="' + kind + ':' + i + '" title="Add to menu" aria-label="Add ' + esc(x.label) + '">' + IC.plus + '</button></label>').join('')
        : '<p class="muted small">Nothing found' + (q ? ' for “' + esc(q) + '”' : '') + '.</p>';
      selCount(kind);
    } catch (e) { box.innerHTML = '<p class="muted small">Search failed. Try again.</p>'; }
  }, 200);
  const selCount = (kind) => {
    const n = $$('[data-results="' + kind + '"] input:checked', root).length;
    const b = $('[data-add-selected="' + kind + '"]', root);
    if (b) b.disabled = !n;
    const c = $('[data-sel-count="' + kind + '"]', root);
    if (c) c.textContent = n ? n + ' selected' : '';
  };
  const addRefs = (kind, list) => {
    list.forEach((x) => {
      refs[kind][x.id] = { label: x.label, group: x.group };
      menus[cur].items.push({ id: uid(), label: '', type: kind, object_id: x.id, target: '', children: [] });
    });
    $$('[data-results="' + kind + '"] input:checked', root).forEach((c) => { c.checked = false; });
    selCount(kind);
    render();
    window.Slate.toast(list.length === 1 ? '“' + list[0].label + '” added' : list.length + ' items added');
  };

  // ---- events ----
  root.addEventListener('input', (e) => {
    const el = e.target;
    if (el.matches('[data-menu-name]')) { menus[cur].name = el.value; $('[data-menu-switch] option:checked', root).textContent = el.value || 'Untitled'; $('.mb-tree-head strong', root).textContent = el.value || 'Menu'; sync(); return; }
    if (el.matches('[data-q]')) { runSearch(el.dataset.q); return; }
    const li = el.closest('.mb-item');
    if (li && el.dataset.k) {
      const it = get(findPath(li.dataset.id));
      it[el.dataset.k] = el.type === 'checkbox' ? (el.checked ? '_blank' : '') : el.value;
      if (el.dataset.k === 'label') $('.mb-title strong', li).textContent = titleOf(it);
      sync();
    }
  });
  root.addEventListener('change', (e) => {
    const el = e.target;
    if (el.matches('[data-menu-switch]')) { cur = +el.value; open = null; render(); return; }
    if (el.matches('[data-menu-loc]')) { menus.forEach((m, i) => { if (i !== cur && m.location === el.value && el.value) m.location = ''; }); menus[cur].location = el.value; sync(); return; }
    if (el.matches('[data-filter]')) { runSearch(el.dataset.filter); return; }
    if (el.closest('[data-results]')) { selCount(el.closest('[data-results]').dataset.results); return; }
    if (el.dataset.k === 'target') { const li = el.closest('.mb-item'); get(findPath(li.dataset.id)).target = el.checked ? '_blank' : ''; sync(); render(); }
  });
  root.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && (e.target.matches('[data-custom-url],[data-custom-label],[data-q]') || e.target.closest('.mb-details'))) e.preventDefault();
  });
  root.addEventListener('click', (e) => {
    const t = e.target.closest('button');
    if (!t) return;
    if (t.dataset.tab) {
      $$('[data-tab]', root).forEach((b) => b.classList.toggle('is-on', b === t));
      $$('[data-pane]', root).forEach((p) => { p.hidden = p.dataset.pane !== t.dataset.tab; });
      if (t.dataset.tab !== 'custom') runSearch(t.dataset.tab);
      return;
    }
    if (t.dataset.addOne) { const [kind, i] = t.dataset.addOne.split(':'); e.preventDefault(); addRefs(kind, [found[kind][+i]]); return; }
    if (t.dataset.addSelected) { const kind = t.dataset.addSelected; addRefs(kind, $$('[data-results="' + kind + '"] input:checked', root).map((c) => found[kind][+c.value])); return; }
    if (t.matches('[data-add-custom]')) {
      const url = $('[data-custom-url]', root).value.trim();
      if (!url) { $('[data-custom-url]', root).focus(); return; }
      const label = $('[data-custom-label]', root).value.trim() || url;
      menus[cur].items.push({ id: uid(), label, type: 'custom', url, target: '', children: [] });
      $('[data-custom-url]', root).value = '';
      $('[data-custom-label]', root).value = '';
      render();
      window.Slate.toast('“' + label + '” added');
      return;
    }
    if (t.matches('[data-new-menu]')) { menus.push({ id: 'menu-' + uid(), name: 'New menu', location: '', items: [] }); cur = menus.length - 1; open = null; render(); $('[data-menu-name]', root).select(); return; }
    if (t.matches('[data-del-menu]')) { if (confirm('Delete the menu “' + menus[cur].name + '”? Click Save changes to make it final.')) { menus.splice(cur, 1); cur = 0; render(); } return; }
    const li = t.closest('.mb-item');
    if (!li) return;
    const path = findPath(li.dataset.id);
    if (t.hasAttribute('data-toggle')) { open = open === li.dataset.id ? null : li.dataset.id; render(); if (open) { const inp = $('.mb-item.is-open .mb-details input', root); if (inp) inp.focus(); } return; }
    const act = t.dataset.act;
    const list = listAt(path.slice(0, -1));
    const i = path[path.length - 1];
    if (act === 'del') { const it = list[i]; if ((it.children || []).length && !confirm('Remove “' + titleOf(it) + '” and its sub-items?')) return; list.splice(i, 1); }
    if (act === 'up' && i > 0) [list[i - 1], list[i]] = [list[i], list[i - 1]];
    if (act === 'down' && i < list.length - 1) [list[i + 1], list[i]] = [list[i], list[i + 1]];
    if (act === 'indent' && i > 0 && path.length + depthOf(list[i]) <= MAX_DEPTH) { const it = list.splice(i, 1)[0]; (list[i - 1].children = list[i - 1].children || []).push(it); }
    if (act === 'outdent' && path.length > 1) { const it = take(path); const parentPath = path.slice(0, -1); listAt(parentPath.slice(0, -1)).splice(parentPath[parentPath.length - 1] + 1, 0, it); }
    render();
    const again = root.querySelector('.mb-item[data-id="' + CSS.escape(li.dataset.id) + '"] [data-act="' + act + '"]');
    if (again && !again.disabled) again.focus();
  });

  // ---- drag and drop ----
  let dragId = null;
  root.addEventListener('dragstart', (e) => {
    const row = e.target.closest('.mb-row');
    if (!row) return;
    dragId = row.parentElement.dataset.id;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', dragId);
    setTimeout(() => row.parentElement.classList.add('is-dragging'), 0);
  });
  const clearMarks = () => $$('.drop-before,.drop-after,.drop-inside', root).forEach((x) => x.classList.remove('drop-before', 'drop-after', 'drop-inside'));
  const zone = (row, y) => { const r = row.getBoundingClientRect(); const f = (y - r.top) / r.height; return f < 0.28 ? 'before' : f > 0.72 ? 'after' : 'inside'; };
  root.addEventListener('dragover', (e) => {
    const row = e.target.closest('.mb-row');
    if (!dragId || !row || row.parentElement.dataset.id === dragId || row.closest('.mb-item[data-id="' + CSS.escape(dragId) + '"]')) return;
    e.preventDefault();
    clearMarks();
    row.classList.add('drop-' + zone(row, e.clientY));
  });
  root.addEventListener('dragleave', (e) => { if (!root.contains(e.relatedTarget)) clearMarks(); });
  root.addEventListener('drop', (e) => {
    const row = e.target.closest('.mb-row');
    if (!dragId || !row) return;
    e.preventDefault();
    const where = zone(row, e.clientY);
    clearMarks();
    const targetId = row.parentElement.dataset.id;
    if (targetId === dragId) return;
    const moving = get(findPath(dragId));
    const tPath = findPath(targetId);
    const newDepth = (where === 'inside' ? tPath.length + 1 : tPath.length) + depthOf(moving) - 1;
    if (newDepth > MAX_DEPTH) { window.Slate.toast('Menus go ' + MAX_DEPTH + ' levels deep at most.', 'error'); return; }
    take(findPath(dragId));
    const p = findPath(targetId);
    if (where === 'inside') { const t = get(p); (t.children = t.children || []).push(moving); }
    else { listAt(p.slice(0, -1)).splice(p[p.length - 1] + (where === 'after' ? 1 : 0), 0, moving); }
    render();
  });
  root.addEventListener('dragend', () => { dragId = null; clearMarks(); $$('.is-dragging', root).forEach((x) => x.classList.remove('is-dragging')); });

  render();
})();
