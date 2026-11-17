// SEO screens: schema type switcher and fixed values (Schema by type), "Request indexing" in the editor.
(() => {
  const schema = document.querySelector('[data-sd-schema]');
  if (schema) {
    schema.addEventListener('change', () => {
      const u = new URL(location.href);
      u.searchParams.set('as', schema.value);
      location.href = u.toString();
    });
  }
  document.querySelectorAll('[data-sd-source]').forEach((sel) => {
    const fixed = sel.parentElement.querySelector('[data-sd-fixed]');
    if (!fixed) return;
    const sync = () => { fixed.hidden = sel.value !== '='; if (!fixed.hidden && !fixed.value) fixed.focus(); };
    sel.addEventListener('change', sync);
  });
  document.querySelectorAll('[data-seo-request]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const out = btn.parentElement.querySelector('[data-seo-request-out]');
      const token = (btn.closest('form') || document).querySelector('input[name="_csrf"]')?.value || '';
      const body = new FormData();
      body.append('id', btn.dataset.id);
      body.append('_csrf', token);
      btn.disabled = true;
      out.className = 'small muted';
      out.textContent = 'Sending…';
      try {
        const r = await fetch(btn.dataset.url, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json', 'X-CSRF-Token': token } });
        const j = await r.json();
        out.textContent = j.message || j.error || 'Done.';
        out.className = 'small ' + (j.ok ? 'is-ok' : 'is-error');
      } catch (e) {
        out.textContent = 'Could not reach the server. Try again.';
        out.className = 'small is-error';
      } finally {
        btn.disabled = false;
      }
    });
  });
})();
