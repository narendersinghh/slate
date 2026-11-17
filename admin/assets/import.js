/* Import pages (admin/views/import.php): files grouped by type with remove buttons and drag and drop, automatic
   linking hints (which page uses which stylesheet or script), import by link, and the design previews. */
(() => {
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
  const S = window.SLATE || {};
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const icons = {};
  $$('template[data-import-icon]').forEach((t) => { icons[t.dataset.importIcon] = t.innerHTML; });

  const GROUPS = [
    ['page', 'Pages', /\.(html?)$/i, 'file'],
    ['css', 'Stylesheets', /\.css$/i, 'palette'],
    ['js', 'Scripts', /\.(m?js)$/i, 'code'],
    ['img', 'Images and media', /\.(png|jpe?g|gif|webp|avif|svg|ico|bmp|mp4|webm|ogg|mp3|wav|pdf)$/i, 'image'],
    ['font', 'Fonts', /\.(woff2?|ttf|otf|eot)$/i, 'type'],
    ['zip', 'Archives (unpacked on upload)', /\.zip$/i, 'box'],
    ['other', 'Other files', /./, 'file'],
  ];
  const groupOf = (path) => GROUPS.find((g) => g[2].test(path));
  const size = (n) => (n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB');
  const base = (p) => p.split('/').pop().toLowerCase();
  const resolve = (from, ref) => {
    const parts = (ref.startsWith('/') ? [] : from.split('/').slice(0, -1));
    ref.replace(/^\//, '').split('/').forEach((seg) => { if (seg === '..') parts.pop(); else if (seg !== '.' && seg !== '') parts.push(seg); });
    return parts.join('/');
  };
  const refsIn = (text) => {
    const out = [];
    const re = /\s(?:src|href|poster|data-src)\s*=\s*["']([^"']+)["']|url\(\s*['"]?([^'")]+)/gi;
    let m;
    while ((m = re.exec(text))) out.push((m[1] || m[2] || '').trim());
    return out.filter((u) => u && !/^([a-z][a-z0-9+.-]*:|\/\/|#|\{\{)/i.test(u)).map((u) => decodeURIComponent(u.replace(/[?#].*$/, '')));
  };

  // ---------- Upload: files, folder, zip ----------
  $$('[data-site-import]').forEach((form) => {
    const input = $('[data-import-files]', form);
    const more = $('[data-import-more]', form);
    const folder = $('[data-import-folder]', form);
    const drop = $('[data-import-drop]', form);
    const list = $('[data-import-list]', form);
    const picked = $('[data-import-picked]', form);
    const msg = $('[data-import-msg]', form);
    let chosen = [];
    const uses = new Map(); // page path => [asked paths]

    const readPages = async () => {
      for (const c of chosen) {
        if (!/\.html?$/i.test(c.path) || uses.has(c.path) || c.file.size > 3 * 1048576) continue;
        try { uses.set(c.path, refsIn(await c.file.text()).map((r) => resolve(c.path, r))); } catch (e) { uses.set(c.path, []); }
      }
    };
    // Which pages ask for a file: by its path, or by its name when files were picked without their folders.
    const usedBy = (c) => {
      const out = [];
      uses.forEach((asked, page) => {
        if (asked.some((a) => a === c.path || base(a) === base(c.path))) out.push(page.split('/').pop());
      });
      return out;
    };
    const render = () => {
      const bytes = chosen.reduce((n, c) => n + c.file.size, 0);
      picked.textContent = chosen.length ? chosen.length + ' file' + (chosen.length === 1 ? '' : 's') + ' ready (' + size(bytes) + ')' : '';
      list.hidden = !chosen.length;
      if (!chosen.length) { list.innerHTML = ''; return; }
      const pages = chosen.filter((c) => /\.html?$/i.test(c.path)).length;
      let html = '<div class="import-files-head"><strong>' + (pages === 1 && !chosen.some((c) => /\.zip$/i.test(c.path)) ? 'One page with its files' : 'Files to import')
        + '</strong><button type="button" class="link" data-import-clear>Remove all</button></div>';
      GROUPS.forEach(([key, label, , icon]) => {
        const items = chosen.map((c, i) => [c, i]).filter(([c]) => groupOf(c.path)[0] === key);
        if (!items.length) return;
        html += '<div class="import-group" data-import-group="' + key + '"><div class="import-group-head">' + (icons[icon] || '') + ' ' + esc(label) + ' <span class="pill">' + items.length + '</span></div><ul>';
        items.forEach(([c, i]) => {
          let note = '';
          if (key === 'css' || key === 'js') {
            const by = usedBy(c);
            note = by.length ? '<span class="pill pill-success">Linked to ' + esc(by.slice(0, 3).join(', ')) + (by.length > 3 ? ' +' + (by.length - 3) : '') + '</span>'
              : (pages ? '<span class="pill pill-warn" title="You can attach it to the page in the next step">Not linked yet: attach it in the next step</span>' : '');
          } else if (key === 'page') {
            const asked = uses.get(c.path) || [];
            const have = new Set(chosen.map((x) => base(x.path)));
            const found = asked.filter((a) => /\.(css|m?js)$/i.test(a) && have.has(base(a)));
            if (found.length) note = '<span class="pill pill-soft">Uses ' + esc([...new Set(found.map(base))].slice(0, 4).join(', ')) + '</span>';
          }
          html += '<li data-import-item="' + esc(c.path) + '"><span class="import-file-name" title="' + esc(c.path) + '">' + esc(c.path) + '</span>' + note
            + '<span class="muted small">' + size(c.file.size) + '</span>'
            + '<button type="button" class="icon-btn icon-btn-sm" data-import-remove="' + i + '" aria-label="Remove ' + esc(c.path) + '">' + (icons.x || 'Remove') + '</button></li>';
        });
        html += '</ul></div>';
      });
      list.innerHTML = html;
    };
    const add = async (files) => {
      const known = new Set(chosen.map((c) => c.path));
      Array.from(files).forEach((f) => {
        const path = (f.webkitRelativePath || f._path || f.name).replace(/^\/+/, '');
        if (known.has(path)) chosen = chosen.filter((c) => c.path !== path); // a newer file with the same name replaces the old one
        chosen.push({ file: f, path });
        known.add(path);
        uses.delete(path);
      });
      msg.hidden = true;
      render();
      await readPages();
      render();
    };
    [input, more, folder].forEach((el) => el && el.addEventListener('change', async () => { await add(el.files); el.value = ''; }));
    list.addEventListener('click', (e) => {
      const rm = e.target.closest('[data-import-remove]');
      if (rm) { const c = chosen[+rm.dataset.importRemove]; chosen.splice(+rm.dataset.importRemove, 1); if (c) uses.delete(c.path); render(); }
      if (e.target.closest('[data-import-clear]')) { chosen = []; uses.clear(); render(); }
    });
    ['dragover', 'dragenter'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.remove('is-over')));
    drop.addEventListener('drop', async (e) => {
      e.preventDefault();
      const items = Array.from(e.dataTransfer.items || []).map((i) => i.webkitGetAsEntry && i.webkitGetAsEntry()).filter(Boolean);
      if (!items.length) { add(e.dataTransfer.files); return; }
      const out = [];
      const walk = (entry, prefix) => new Promise((done) => {
        if (entry.isFile) entry.file((f) => { f._path = prefix + f.name; out.push(f); done(); }, () => done());
        else {
          const reader = entry.createReader();
          const all = [];
          const read = () => reader.readEntries(async (batch) => {
            if (!batch.length) { for (const c of all) await walk(c, prefix + entry.name + '/'); done(); return; }
            all.push(...batch); read();
          }, () => done());
          read();
        }
      });
      for (const it of items) await walk(it, '');
      add(out);
    });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!chosen.length) { msg.hidden = false; msg.className = 'alert alert-error'; msg.textContent = 'Choose a .zip, the files of a page or a folder first.'; return; }
      const fd = new FormData();
      fd.append('_csrf', S.csrf);
      chosen.forEach((c) => fd.append('files[]', c.file, c.path));
      const btn = $('[data-import-go]', form);
      btn.disabled = true;
      msg.hidden = false; msg.className = 'alert'; msg.textContent = 'Uploading ' + chosen.length + ' file' + (chosen.length === 1 ? '' : 's') + '...';
      try {
        const r = await fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': S.csrf } });
        const d = await r.json().catch(() => ({ ok: false, error: 'Upload failed (' + r.status + '). The files may be larger than the server allows.' }));
        if (!d.ok) throw new Error(d.error || 'Upload failed.');
        location.href = d.next;
      } catch (err) { msg.className = 'alert alert-error'; msg.textContent = err.message; btn.disabled = false; }
    });
  });

  // ---------- From a link ----------
  $$('[data-url-import]').forEach((form) => {
    form.addEventListener('submit', () => {
      const btn = $('[data-import-fetch]', form);
      const msg = $('[data-import-msg]', form);
      setTimeout(() => { btn.disabled = true; btn.classList.add('is-loading'); }, 0);
      msg.hidden = false; msg.className = 'alert';
      msg.textContent = 'Fetching the page' + ($('[data-import-follow]', form).checked ? 's' : '') + ' and every file they use. This can take a minute...';
    });
  });

  // ---------- Review: select all, previews per page ----------
  // Thumbnails show the page at desktop width (1280px), scaled to the frame.
  const fit = (box) => {
    const frame = $('iframe', box);
    if (!frame || !box.clientWidth) return;
    const k = box.clientWidth / 1280;
    frame.style.width = '1280px';
    frame.style.height = Math.ceil(box.clientHeight / k) + 'px';
    frame.style.transform = 'scale(' + k + ')';
  };
  if (window.ResizeObserver) {
    const ro = new ResizeObserver((entries) => entries.forEach((en) => fit(en.target)));
    $$('.import-thumb').forEach((box) => { ro.observe(box); fit(box); });
  }
  $$('[data-import-review]').forEach((form) => {
    const all = $('[data-import-all]', form);
    if (all) all.addEventListener('change', () => $$('input[name="paths[]"]', form).forEach((c) => { c.checked = all.checked; }));
    const pick = $('[data-import-preview-page]', form);
    const parts = () => $$('[data-import-part]:checked', form).map((c) => c.value);
    // Previews follow the page picked and the ticked parts ("Use my site's styles for...").
    const refresh = (modes) => {
      const path = pick ? pick.value : form.dataset.first;
      modes.forEach((mode) => {
        const url = form.dataset.preview + '?path=' + encodeURIComponent(path) + '&design=' + mode + (mode === 'partial' ? '&parts=' + parts().join(',') : '');
        const frame = $('[data-import-thumb="' + mode + '"]', form);
        const link = $('[data-import-open="' + mode + '"]', form);
        if (frame && frame.getAttribute('src') !== url) frame.src = url;
        if (link) link.href = url;
      });
    };
    if (pick) pick.addEventListener('change', () => refresh(['keep', 'partial', 'site']));
    let timer;
    $$('[data-import-part]', form).forEach((c) => c.addEventListener('change', () => {
      const radio = $('input[name=design][value=partial]', form);
      if (c.checked && radio) radio.checked = true;
      clearTimeout(timer);
      timer = setTimeout(() => refresh(['partial']), 150);
    }));
    form.addEventListener('submit', (e) => {
      const radio = $('input[name=design][value=partial]', form);
      if (radio && radio.checked && !parts().length) {
        e.preventDefault();
        alert('Tick at least one part to use your site\'s styles for, or choose another design.');
      }
    });
  });
})();
