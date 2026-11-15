/* Passkeys (WebAuthn) for the sign-in page and the profile page. No dependencies. */
(() => {
  'use strict';
  const b64u = {
    enc: (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''),
    dec: (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4)), (c) => c.charCodeAt(0)).buffer,
  };
  const supported = () => !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);
  const post = async (url, csrf, body) => {
    const r = await fetch(url, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(body) });
    return r.json().catch(() => ({ ok: false, error: 'Unexpected response (' + r.status + ').' }));
  };
  const getJson = async (url) => (await fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })).json();
  const say = (el, msg, bad) => { if (!el) return; el.textContent = msg; el.hidden = !msg; el.classList.toggle('alert-error', !!bad); };
  const friendly = (e) => e && e.name === 'NotAllowedError' ? 'Cancelled or timed out.' : (e && e.message) || String(e);

  // ---------- sign in ----------
  const loginBtn = document.querySelector('[data-passkey-login]');
  if (loginBtn) {
    const msg = document.querySelector('[data-passkey-msg]');
    if (!supported()) { loginBtn.hidden = true; }
    loginBtn.addEventListener('click', async () => {
      say(msg, '');
      loginBtn.disabled = true;
      try {
        const o = await getJson(loginBtn.dataset.options);
        o.challenge = b64u.dec(o.challenge);
        const cred = await navigator.credentials.get({ publicKey: o });
        const res = await post(loginBtn.dataset.submit, loginBtn.dataset.csrf, {
          id: cred.id, type: cred.type,
          response: {
            clientDataJSON: b64u.enc(cred.response.clientDataJSON),
            authenticatorData: b64u.enc(cred.response.authenticatorData),
            signature: b64u.enc(cred.response.signature),
            userHandle: cred.response.userHandle ? b64u.enc(cred.response.userHandle) : null,
          },
        });
        if (res.ok) { location.href = loginBtn.dataset.redirect || res.redirect; return; }
        say(msg, res.error || 'Sign-in failed.', true);
      } catch (e) { say(msg, friendly(e), true); }
      loginBtn.disabled = false;
    });
  }

  // ---------- add a passkey (profile) ----------
  const addBtn = document.querySelector('[data-passkey-add]');
  if (addBtn) {
    const msg = document.querySelector('[data-passkey-add-msg]');
    if (!supported()) { addBtn.disabled = true; say(msg, 'This browser or connection does not support passkeys (HTTPS is required).', true); }
    addBtn.addEventListener('click', async () => {
      say(msg, '');
      addBtn.disabled = true;
      try {
        const o = await getJson(addBtn.dataset.options);
        if (o.error) throw new Error(o.error);
        o.challenge = b64u.dec(o.challenge);
        o.user.id = b64u.dec(o.user.id);
        o.excludeCredentials = (o.excludeCredentials || []).map((c) => ({ ...c, id: b64u.dec(c.id) }));
        const cred = await navigator.credentials.create({ publicKey: o });
        const name = (document.querySelector('[data-passkey-name]') || {}).value || '';
        const res = await post(addBtn.dataset.submit, addBtn.dataset.csrf, {
          name, id: cred.id, type: cred.type,
          response: {
            clientDataJSON: b64u.enc(cred.response.clientDataJSON),
            attestationObject: b64u.enc(cred.response.attestationObject),
            transports: cred.response.getTransports ? cred.response.getTransports() : [],
          },
        });
        if (res.ok) { location.reload(); return; }
        say(msg, res.error || 'Could not add the passkey.', true);
      } catch (e) { say(msg, friendly(e), true); }
      addBtn.disabled = false;
    });
  }
})();
