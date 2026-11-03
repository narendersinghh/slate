/* Slate media library + picker. */
(function () {
  'use strict';
  const { api, toast, modal, esc, $, $$, debounce } = window.Slate;
  const S = window.SLATE;

  function uploadFile(file, onProgress) {
    return new Promise((resolve) => {
      const fd = new FormData();
      fd.append('file', file);
      const xhr = new XMLHttpRequest();
      xhr.open('POST', S.api + '/media');
      xhr.setRequestHeader('X-CSRF-Token', S.csrf);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.onprogress = (e) => { if (e.lengthComputable) onProgress?.(Math.round(e.loaded / e.total * 100)); };
      xhr.onload = () => { let d; try { d = JSON.parse(xhr.responseText); } catch (e) { d = { ok: false, error: 'Upload failed (' + xhr.status + ')' }; } resolve(d); };
      xhr.onerror = () => resolve({ ok: false, error: 'Network error' });
      xhr.send(fd);
    });
  }

  class MediaApp {
    constructor(root, opts = {}) {
      this.root = root;
      this.opts = Object.assign({ mode: 'library', multiple: false, kind: '' }, opts);
      this.items = [];
      this.page = 1;
      this.pages = 1;
      this.selected = new Map();
      this.current = null;
      this.q = '';
      this.filter = new URLSearchParams(location.search).get('filter') || this.opts.kind || '';
      this.render();
      this.load(true);
    }
    render() {
      this.root.innerHTML =
        '<div class="ml-toolbar">' +
        '<div class="search-inline"><input class="input" type="search" placeholder="Search media…" data-q></div>' +
        '<select class="input" style="width:auto" data-filter><option value="">All files</option><option value="image">Images</option><option value="video">Videos</option><option value="document">Documents</option><option value="noalt">Images missing alt text</option></select>' +
        '<span class="muted" data-total></span>' +
        '<label class="btn btn-sm" style="margin-left:auto">Upload<input type="file" multiple hidden data-file></label></div>' +
        '<div class="ml-drop" data-drop>Drop files here to upload — images are optimized automatically.</div>' +
        '<div class="ml-progress" data-progress></div>' +
        '<div class="ml-layout no-detail" data-layout><div><div class="ml-grid" data-grid></div><div class="ml-more" data-more hidden><button type="button" class="btn">Load more</button></div></div><aside class="ml-detail card" data-detail hidden></aside></div>';
      $('[data-filter]', this.root).value = this.filter;
      $('[data-q]', this.root).addEventListener('input', debounce((e) => { this.q = e.target.value; this.load(true); }, 300));
      $('[data-filter]', this.root).addEventListener('change', (e) => { this.filter = e.target.value; this.load(true); });
      $('[data-file]', this.root).addEventListener('change', (e) => { this.upload(Array.from(e.target.files)); e.target.value = ''; });
      $('[data-more] button', this.root).addEventListener('click', () => { this.page++; this.load(false); });
      const drop = $('[data-drop]', this.root);
      const target = this.opts.mode === 'library' ? document.body : this.root;
      ['dragenter', 'dragover'].forEach((ev) => target.addEventListener(ev, (e) => { if (e.dataTransfer?.types?.includes('Files')) { e.preventDefault(); drop.classList.add('over'); } }));
      ['dragleave', 'drop'].forEach((ev) => target.addEventListener(ev, (e) => { if (ev === 'dragleave' && e.relatedTarget && target.contains(e.relatedTarget)) return; drop.classList.remove('over'); }));
      target.addEventListener('drop', (e) => { if (e.dataTransfer?.files?.length) { e.preventDefault(); this.upload(Array.from(e.dataTransfer.files)); } });
      $('[data-grid]', this.root).addEventListener('click', (e) => {
        const btn = e.target.closest('.ml-item');
        if (btn) this.select(+btn.dataset.id, e.shiftKey || e.metaKey || e.ctrlKey);
      });
    }
    async load(reset) {
      if (reset) { this.page = 1; this.items = []; }
      const kind = this.filter;
      const r = await api('/media?page=' + this.page + '&q=' + encodeURIComponent(this.q) + '&filter=' + encodeURIComponent(kind));
      if (!r.ok) { toast(r.error || 'Could not load media', 'error'); return; }
      this.items = this.items.concat(r.items);
      this.pages = r.pages;
      $('[data-total]', this.root).textContent = r.total + ' files';
      this.drawGrid();
    }
    drawGrid() {
      const grid = $('[data-grid]', this.root);
      grid.innerHTML = this.items.map((m) => '<button type="button" class="ml-item' + (this.selected.has(m.id) ? ' selected' : '') + '" data-id="' + m.id + '" title="' + esc(m.title) + '">' +
        (m.is_image ? '<img src="' + esc(m.thumb) + '" alt="' + esc(m.alt) + '" loading="lazy">' : '<span class="ml-ext">' + esc(m.ext) + '</span>') +
        (m.is_image && !m.alt ? '<span class="ml-badge" title="Missing alt text">no alt</span>' : '') +
        '<span class="ml-check">✓</span></button>').join('') || '<p class="muted">No files yet. Drop some here.</p>';
      $('[data-more]', this.root).hidden = this.page >= this.pages;
    }
    select(id, additive) {
      const m = this.items.find((x) => x.id === id);
      if (!m) return;
      if (this.opts.mode === 'picker') {
        if (!this.opts.multiple) this.selected.clear();
        if (this.selected.has(id) && (this.opts.multiple || additive)) this.selected.delete(id); else this.selected.set(id, m);
      } else {
        this.selected.clear();
        this.selected.set(id, m);
      }
      this.current = m;
      this.drawGrid();
      this.drawDetail();
      this.opts.onChange?.(Array.from(this.selected.values()));
    }
    drawDetail() {
      const d = $('[data-detail]', this.root);
      const m = this.current;
      $('[data-layout]', this.root).classList.toggle('no-detail', !m);
      d.hidden = !m;
      if (!m) return;
      d.innerHTML = '<div class="card-pad stack-sm">' +
        '<div class="preview">' + (m.is_image ? '<img src="' + esc(m.url) + '" alt="">' : '<span class="ml-ext" style="padding:40px">' + esc(m.ext) + '</span>') + '</div>' +
        '<div class="ml-meta"><b>File</b><span>' + esc(m.filename + '.' + m.ext) + '</span>' +
        '<b>Size</b><span>' + esc(m.size_h) + (m.saved > 0 ? ' <span class="ml-saved">−' + m.saved + '%</span> <small>(was ' + esc(m.original_size_h) + ')</small>' : '') + '</span>' +
        (m.width ? '<b>Dimensions</b><span>' + m.width + ' × ' + m.height + '</span>' : '') +
        '<b>Type</b><span>' + esc(m.mime) + '</span><b>Uploaded</b><span>' + esc(m.date) + '</span></div>' +
        (m.is_image ? '<label class="field"><span>Alt text ' + (S.ai ? '<button type="button" class="link" data-ai-alt>✨ Generate</button>' : '') + '</span><textarea class="input" rows="2" data-m="alt" placeholder="Describe the image for screen readers & SEO">' + esc(m.alt) + '</textarea></label>' : '') +
        '<label class="field"><span>Title</span><input class="input" data-m="title" value="' + esc(m.title) + '"></label>' +
        '<label class="field"><span>Caption</span><textarea class="input" rows="2" data-m="caption">' + esc(m.caption || '') + '</textarea></label>' +
        '<label class="field"><span>File name (URL slug)</span><span class="input-group"><input class="input" data-m="filename" value="' + esc(m.filename) + '"><code>.' + esc(m.ext) + '</code></span><small class="help">Renaming updates links inside your content automatically.</small></label>' +
        '<label class="field"><span>File URL</span><span class="input-group"><input class="input input-sm" readonly value="' + esc(m.absolute_url) + '"><button type="button" class="btn btn-sm" data-copy="' + esc(m.absolute_url) + '">Copy</button></span></label>' +
        '<div class="row-between"><button type="button" class="btn btn-primary btn-sm" data-save>Save</button><button type="button" class="btn btn-ghost btn-danger btn-sm" data-del>Delete</button></div></div>';
      $('[data-save]', d).addEventListener('click', async (e) => {
        const b = e.currentTarget;
        const body = {};
        $$('[data-m]', d).forEach((i) => { body[i.dataset.m] = i.value; });
        b.classList.add('is-loading');
        const r = await api('/media/' + m.id, { body });
        b.classList.remove('is-loading');
        if (r.ok) { Object.assign(m, r.item); this.drawGrid(); this.drawDetail(); toast('Media updated'); } else toast(r.error, 'error');
      });
      $('[data-del]', d).addEventListener('click', async () => {
        if (!confirm('Delete this file permanently? Content that uses it will show a broken image.')) return;
        const r = await api('/media/' + m.id + '/delete', { body: {} });
        if (r.ok) { this.items = this.items.filter((x) => x.id !== m.id); this.selected.delete(m.id); this.current = null; this.drawGrid(); this.drawDetail(); toast('Deleted'); }
      });
      $('[data-ai-alt]', d)?.addEventListener('click', async (e) => {
        const b = e.currentTarget;
        b.textContent = '…';
        const r = await api('/ai', { body: { action: 'alt', title: m.title + ' (' + m.filename + ')', input: m.caption || document.title } });
        b.textContent = '✨ Generate';
        if (r.ok) $('[data-m="alt"]', d).value = r.text.replace(/^"|"$/g, ''); else toast(r.error, 'error');
      });
    }
    async upload(files) {
      const prog = $('[data-progress]', this.root);
      const queue = files.slice();
      const worker = async () => {
        while (queue.length) {
          const f = queue.shift();
          const row = document.createElement('div');
          row.innerHTML = '<span>' + esc(f.name) + '</span><span>0%</span>';
          prog.appendChild(row);
          const r = await uploadFile(f, (p) => { row.lastChild.textContent = p < 100 ? p + '%' : 'Optimizing…'; });
          if (r.ok) {
            const m = r.items[0];
            row.lastChild.innerHTML = m.saved > 0 ? '<span class="ml-saved">✓ −' + m.saved + '%</span>' : '✓';
            this.items.unshift(m);
            this.drawGrid();
            if (this.opts.mode === 'picker') this.select(m.id, this.opts.multiple);
            setTimeout(() => row.remove(), 3000);
          } else {
            row.lastChild.textContent = 'Failed';
            row.style.color = 'var(--danger)';
            toast(r.error || 'Upload failed', 'error', 7000);
            setTimeout(() => row.remove(), 8000);
          }
        }
      };
      await Promise.all([worker(), worker()]);
    }
  }

  /** Open the media picker. Resolves with an array of selected items (or null). */
  window.Slate.pickMedia = function ({ multiple = false, kind = '' } = {}) {
    return new Promise((resolve) => {
      let picked = [];
      const foot = document.createElement('div');
      foot.style.cssText = 'display:flex;gap:8px;align-items:center';
      foot.innerHTML = '<span class="muted" data-count></span><button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-use disabled>Use selected</button>';
      const body = document.createElement('div');
      const m = modal({ title: multiple ? 'Choose files' : 'Choose a file', body, foot });
      new MediaApp(body, { mode: 'picker', multiple, kind, onChange: (items) => {
        picked = items;
        $('[data-use]', m).disabled = !items.length;
        $('[data-count]', m).textContent = items.length ? items.length + ' selected' : '';
      } });
      $('[data-use]', m).addEventListener('click', () => { m.close(); resolve(picked); });
      $$('[data-close]', m).forEach((b) => b.addEventListener('click', () => resolve(null)));
    });
  };

  // Library page
  const lib = $('[data-media-app]');
  if (lib) {
    const app = new MediaApp(lib, { mode: 'library' });
    $('[data-upload-input]')?.addEventListener('change', (e) => { app.upload(Array.from(e.target.files)); e.target.value = ''; });
    if (location.hash === '#upload') $('[data-upload-input]')?.click();
  }

  // Media fields (featured image, settings images, custom fields)
  function initMediaField(root) {
    const hidden = $('input[type=hidden]', root);
    const preview = $('.media-field-preview', root);
    const remove = $('[data-media-remove]', root);
    const multiple = root.hasAttribute('data-multiple');
    const set = (items, append) => {
      const ids = append ? hidden.value.split(',').filter(Boolean) : [];
      if (!append) preview.innerHTML = '';
      items.forEach((it) => {
        if (ids.includes(String(it.id))) return;
        ids.push(String(it.id));
        preview.insertAdjacentHTML('beforeend', '<figure data-id="' + it.id + '">' + (it.is_image ? '<img src="' + esc(root.classList.contains('media-field-lg') ? it.url : it.thumb) + '" alt="">' : '<span class="file-chip">' + esc(it.filename + '.' + it.ext) + '</span>') + '</figure>');
      });
      hidden.value = multiple ? ids.join(',') : (ids[0] || '');
      remove.hidden = !hidden.value;
      hidden.dispatchEvent(new Event('change', { bubbles: true }));
    };
    $('[data-media-choose]', root).addEventListener('click', async () => {
      const items = await window.Slate.pickMedia({ multiple, kind: root.dataset.kind || '' });
      if (items && items.length) set(items, multiple);
    });
    remove.addEventListener('click', () => { hidden.value = ''; preview.innerHTML = ''; remove.hidden = true; hidden.dispatchEvent(new Event('change', { bubbles: true })); });
    if (multiple) {
      preview.addEventListener('click', (e) => {
        const fig = e.target.closest('figure');
        if (!fig || !confirm('Remove this image from the gallery?')) return;
        fig.remove();
        hidden.value = $$('figure', preview).map((f) => f.dataset.id).join(',');
        remove.hidden = !hidden.value;
      });
    }
  }
  $$('[data-media-field]').forEach(initMediaField);
  window.Slate.MediaApp = MediaApp;
  window.Slate.uploadFile = uploadFile;
})();
