/* Slate Forms: submit without leaving the page. */
(function () {
  'use strict';
  function init(form) {
    if (form.dataset.sfReady) return;
    form.dataset.sfReady = '1';
    var ok = form.querySelector('.slate-form__success');
    var err = form.querySelector('.slate-form__error');
    var show = function (el, on) { if (!el) return; el.hidden = !on; el.style.display = on ? 'block' : ''; if (on) el.focus({ preventScroll: true }); };
    var btn = form.querySelector('button[type="submit"]');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      form.elements._js.value = '1';
      form.elements._page.value = location.href.split('#')[0];
      form.elements._title.value = document.title;
      show(ok, false);
      show(err, false);
      form.classList.remove('form-state-success', 'form-state-error');
      form.classList.add('is-sending');
      if (btn) btn.disabled = true;
      fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .then(function (res) {
          if (res.ok) {
            if (res.redirect) { location.href = res.redirect; return; }
            form.reset();
            form.classList.remove('form-state-default');
            form.classList.add('form-state-success');
            show(ok, true);
          } else {
            form.classList.add('form-state-error');
            if (err && res.message && res.message !== 'error') {
              err.dataset.orig = err.dataset.orig || err.innerHTML;
              err.textContent = res.message;
            }
            show(err, true);
          }
        })
        .catch(function () { form.classList.add('form-state-error'); if (err && err.dataset.orig) err.innerHTML = err.dataset.orig; show(err, true); })
        .then(function () { form.classList.remove('is-sending'); if (btn) btn.disabled = false; });
    });
  }
  function boot() { document.querySelectorAll('form[data-slate-form]').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
