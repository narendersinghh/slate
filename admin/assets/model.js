/* Slate content model: post type and taxonomy editors (names, key and address filled in for you, icon picker, live preview). */
(function () {
  'use strict';
  const { esc, $, $$ } = window.Slate;
  const form = $('[data-cm-edit]');
  if (!form) return;
  const mode = form.dataset.cmEdit; // type | tax
  const cfg = JSON.parse(($('[data-cm-config]', form) || {}).textContent || '{}');
  const slugify = window.Slate.slugify;

  const singular = $('[data-cm-singular]', form);
  const plural = $('[data-cm-plural]', form);
  const key = $('[data-cm-key]', form);
  const rewrite = $('[data-cm-rewrite]', form);
  const title = $('[data-cm-title]', form);
  const pub = $('[data-cm-public]', form);
  const archive = $('[data-cm-archive]', form);
  const advanced = $('[data-cm-advanced]', form);
  const touched = { plural: !!(plural.value && !cfg.isNew), key: !cfg.isNew, rewrite: !cfg.isNew };

  // ---- names: plural, key and address follow the singular name until edited by hand ----
  const IRREGULAR = { person: 'people', child: 'children', man: 'men', woman: 'women', staff: 'staff', news: 'news', media: 'media', series: 'series', faq: 'FAQs' };
  function pluralize(s) {
    const m = s.match(/^(.*?)([A-Za-z]+)(\s*)$/);
    if (!m) return s;
    const [, head, word, tail] = m;
    const low = word.toLowerCase();
    let out;
    if (IRREGULAR[low]) out = IRREGULAR[low] === 'FAQs' ? 'FAQs' : (word[0] === word[0].toUpperCase() ? IRREGULAR[low][0].toUpperCase() + IRREGULAR[low].slice(1) : IRREGULAR[low]);
    else if (/[^aeiou]y$/i.test(word)) out = word.slice(0, -1) + 'ies';
    else if (/(s|x|z|ch|sh)$/i.test(word)) out = word + 'es';
    else out = word + 's';
    return head + out + tail;
  }
  const toKey = (s) => slugify(s).replace(/-/g, '_').replace(/^[^a-z]+/, '').slice(0, 39);

  singular.addEventListener('input', () => {
    const v = singular.value.trim();
    if (!touched.plural) plural.value = v ? pluralize(v) : '';
    if (key && !key.disabled && !touched.key) key.value = toKey(v);
    if (rewrite && !touched.rewrite) rewrite.value = slugify(mode === 'tax' ? v : (plural.value || v));
    update();
  });
  plural.addEventListener('input', (e) => {
    if (e.isTrusted) touched.plural = plural.value.trim() !== '';
    if (mode === 'type' && rewrite && !touched.rewrite) rewrite.value = slugify(plural.value);
    update();
  });
  if (key) key.addEventListener('input', () => { touched.key = true; update(); });
  if (rewrite) {
    rewrite.addEventListener('input', () => { touched.rewrite = true; update(); });
    rewrite.addEventListener('blur', () => {
      rewrite.value = rewrite.value.toLowerCase().split('/').map((seg) => /^%[a-z0-9_-]+%$/.test(seg) ? seg : slugify(seg)).filter(Boolean).join('/');
      update();
    });
  }

  // ---- key check: same rules as the server, shown before saving ----
  function keyError() {
    if (!key || key.disabled) return '';
    const v = key.value.trim();
    if (!v) return 'Give it a key.';
    if (!/^[a-z][a-z0-9_-]{1,38}$/.test(v)) return 'Use 2 to 39 lowercase letters, numbers, dashes or underscores, starting with a letter.';
    if ((cfg.reserved || []).includes(v)) return '"' + v + '" is used by Slate itself. Pick another key.';
    if ((cfg.existing || []).includes(v)) return '"' + v + '" is already taken. Pick another key.';
    return '';
  }
  form.addEventListener('invalid', (e) => { if (advanced && advanced.contains(e.target)) advanced.open = true; }, true);
  form.addEventListener('submit', (e) => {
    if (e.submitter && e.submitter.getAttribute('formaction')) return;
    const err = keyError();
    if (err) {
      e.preventDefault();
      if (advanced) advanced.open = true;
      key.focus();
    }
  });

  // ---- icon picker ----
  const picker = $('[data-cm-iconpick]', form);
  if (picker) {
    const toggle = $('[data-cm-icon-toggle]', picker);
    const panel = $('[data-cm-icon-panel]', picker);
    const search = $('[data-cm-icon-search]', picker);
    toggle.addEventListener('click', () => {
      panel.hidden = !panel.hidden;
      toggle.setAttribute('aria-expanded', String(!panel.hidden));
      if (!panel.hidden) search.focus();
    });
    search.addEventListener('input', () => {
      const q = search.value.trim().toLowerCase();
      $$('.cm-icon-grid > label', picker).forEach((l) => { l.hidden = q !== '' && !l.dataset.name.includes(q); });
    });
    search.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
    picker.addEventListener('change', (e) => {
      if (e.target.name !== 'icon') return;
      const svg = e.target.nextElementSibling.innerHTML;
      $('[data-cm-icon-current]', picker).innerHTML = svg;
      $('[data-cm-icon-name]', picker).textContent = e.target.value;
      $$('[data-cm-icon-preview], [data-cm-icon-mini]', form).forEach((el) => { el.innerHTML = svg; });
    });
  }

  // ---- live preview ----
  const urlLi = (label, url) => '<li><small>' + esc(label) + '</small><code>' + esc(url) + '</code></li>';
  const tail = (p) => p + (cfg.trailing ? '/' : '');
  const checked = (sel) => $$(sel, form).filter((c) => c.checked).map((c) => c.value);

  function update() {
    const pl = plural.value.trim();
    const sg = singular.value.trim();
    title.textContent = pl || title.dataset.new;
    const addHint = $('[data-cm-add-hint]', form);
    if (addHint && sg) addHint.textContent = sg.toLowerCase();
    const err = keyError();
    const errEl = $('[data-cm-key-error]', form);
    if (errEl) { errEl.textContent = err; errEl.hidden = !err || !touched.key && !key.value; }
    if (key && !key.disabled) key.setCustomValidity(err);
    const isPublic = !pub || pub.checked;
    $$('[data-cm-needs-public]', form).forEach((el) => el.classList.toggle('is-off', !isPublic));
    const base = rewrite ? rewrite.value.replace(/^\/+|\/+$/g, '') : '';
    const urls = $('[data-cm-urls]', form);
    let html = '';

    if (mode === 'type') {
      const hint = $('[data-cm-archive-hint]', form);
      if (hint) hint.textContent = base && urls.dataset.mode === 'base' ? ' at /' + base + '/' : '';
      const m = $('[data-cm-plural-mock]', form);
      if (m) m.textContent = pl || 'Projects';
      $('[data-cm-menu-mock]', form).classList.toggle('is-off', !$('[data-cm-menu]', form).checked);
      const item = (sg || 'item').toLowerCase().replace(/\s+/g, '-');
      if (!isPublic) html = '<li class="muted small">Not on the website. Items can still be used in templates and the API.</li>';
      else if (urls.dataset.mode === 'root') html = urlLi('A page', tail(cfg.site + '/about'));
      else if (urls.dataset.mode === 'post') html = urlLi('A post', tail(cfg.site + '/my-first-post')) + '<li class="muted small">Set in Settings, Permalinks.</li>';
      else {
        html = urlLi('One ' + (sg || 'item').toLowerCase(), tail(cfg.site + '/' + (base ? base + '/' : '') + 'my-' + slugify(item)));
        if (archive && archive.checked && base && !base.includes('%')) html += urlLi('List of ' + (pl || 'items').toLowerCase(), tail(cfg.site + '/' + base));
      }
      const feats = checked('[name="supports[]"]');
      const mock = $('[data-cm-editor-mock]', form);
      mock.innerHTML =
        (feats.includes('title') ? '<div class="cm-mock-title">' + esc(sg ? 'New ' + sg.toLowerCase() : 'Title') + '</div>' : '') +
        '<div class="cm-mock-cols"><div class="cm-mock-main">' +
        (feats.includes('editor') ? '<div class="cm-mock-box is-tall"><i></i><i></i><i class="short"></i></div>' : '') +
        (feats.includes('excerpt') ? '<div class="cm-mock-box"><i class="short"></i></div>' : '') +
        (feats.includes('seo') ? '<div class="cm-mock-label">SEO</div>' : '') +
        (!feats.includes('editor') && !feats.includes('excerpt') ? '<div class="cm-mock-empty">Custom fields only</div>' : '') +
        '</div><div class="cm-mock-side"><div class="cm-mock-label">Publish</div>' +
        (feats.includes('featured_image') ? '<div class="cm-mock-img"></div>' : '') +
        (feats.includes('author') ? '<div class="cm-mock-label">Author</div>' : '') +
        (feats.includes('page_attributes') || $('[name="hierarchical"]', form).checked ? '<div class="cm-mock-label">Parent</div>' : '') +
        '</div></div>';
    } else {
      const hier = ($('[data-cm-hier]:checked', form) || {}).value === '1';
      $('[data-cm-tax-icon]', form).innerHTML = $('.cm-kind-opt input:checked + .cm-feature-icon', form).innerHTML;
      $$('[data-cm-needs-hier]', form).forEach((el) => { el.hidden = !hier; });
      const mini = $('[data-cm-rw-mini]', form);
      if (mini) mini.textContent = base || 'term';
      const t = (sg || 'term').toLowerCase();
      const nested = ($('input[type=checkbox][name="nested"]', form) || {}).checked;
      if (!isPublic) html = '<li class="muted small">No term pages. Terms still group content in the dashboard and in templates.</li>';
      else {
        html = urlLi('One ' + t, tail(cfg.site + '/' + (base ? base + '/' : '') + 'my-' + slugify(t)));
        if (hier) html += urlLi('A sub-' + t, tail(cfg.site + '/' + (base ? base + '/' : '') + (nested ? 'parent/' : '') + 'child'));
      }
      const used = $$('[data-cm-pt]:checked', form).map((c) => c.dataset.label);
      const label = esc(pl || 'Terms');
      $('[data-cm-tax-mock]', form).innerHTML = '<div class="cm-mock-card"><strong>' + label + '</strong>' +
        (hier ? '<span class="cm-mock-check is-on">Design</span><span class="cm-mock-check is-sub">Branding</span><span class="cm-mock-check">Development</span>'
          : '<span class="cm-mock-tags"><span>design</span><span>react</span></span><span class="cm-mock-input">Add ' + esc(t) + '</span>') + '</div>' +
        '<small class="muted">' + (used.length ? 'Shown when editing ' + esc(used.join(', ')) + '.' : 'Not used by any post type yet.') + '</small>';
    }
    urls.innerHTML = html;
  }

  form.addEventListener('change', update);
  form.addEventListener('input', () => { const bar = $('.cm-save-bar', form); if (bar) bar.classList.add('is-dirty'); });
  update();
})();
