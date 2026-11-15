# Securing a Slate site

Slate is built so that the usual ways CMS sites get hacked are closed by default, and the rest is one page away:
**Settings → Security → Security check** lists what is protected and what still needs doing.

## What Slate does out of the box

- Passwords hashed with Argon2id; login rate limiting and lockout (also counts password-reset and API-key attempts).
- Two-factor authentication: authenticator app (TOTP) or **passkeys** (Face ID, Touch ID, Windows Hello, security keys —
  phishing-proof, user verification required); can be required for administrators.
- Email alert to administrators when they sign in from a new address.
- CSRF protection on every form; strict, regenerated sessions; secrets (API keys, SMTP, 2FA) encrypted at rest.
- No PHP execution in `content/` (uploads); PHP in `core/`, `modules/`, `themes/` can't be opened from the web.
- Blocked paths, hidden files and manifests answer **404**, so scanners can't tell what exists.
- No "powered by" headers, generator tags or CMS-named cookies, comments or ids in public pages.
- Security headers (CSP, HSTS, COOP, X-Frame-Options, Referrer-Policy, Permissions-Policy) on every page, cached or not.
- Modules are syntax-checked and their capabilities shown before activation; a crashing module is switched off.
- Daily **file integrity check**: core files are compared with the official files on GitHub for the installed commit or
  release; modules and other files with a snapshot you trusted; executables or new `.htaccess` files in data folders
  are always flagged. Administrators get an email; the dashboard shows a warning.
- Backups with retention, revisions, activity log.

## Hide the dashboard completely

1. **Custom address** — Settings → Security → Login protection → *Admin URL slug* (or `php bin/slate admin:url my-studio-x7`).
   `/admin` then returns 404.
2. **Separate admin domain** (strongest) — the dashboard only answers on a private host name; on the public domain it
   does not exist at all, not even the login page.
   - In your hosting panel, add a subdomain or extra domain (e.g. `cms-4f7q.yourdomain.com`) whose folder is the
     **same Slate folder** as the site. (Hostinger: Domains → Subdomains → *Custom folder* → the site's `public_html`.)
   - Run `php bin/slate admin:host cms-4f7q.yourdomain.com` (or add `'admin_host' => 'cms-4f7q.yourdomain.com'` to
     `config.php`). Undo with `php bin/slate admin:host off`.
   - On the admin host the public site is only shown to signed-in team members (preview and on-page editing there).
   - Use a name nobody can guess and never link to it. Certificate transparency logs list issued certificates, so prefer
     a wildcard certificate (`*.yourdomain.com`) for the admin host if your host offers one.
3. **IP allow-list** (optional) — `php bin/slate admin:ips "203.0.113.7 198.51.100.0/24"`; `off` removes it.

What keeps working with a separate admin domain: password resets and alert emails link to the admin host; previews and
"View" links stay on the admin host, where the whole site is browsable with on-page editing; passkeys are bound to the
parent domain (`example.com` for `cms.example.com`), so they survive host changes (override with `'passkey_rp_id'`);
the dashboard's own scripts and styles (`admin/assets`) are hidden on the public host automatically (Apache/LiteSpeed —
on nginx add `location ^~ /admin/ { if ($host != "cms.example.com") { return 404; } }`). The REST API and webhooks keep
working on the public domain.

Locked out? All three settings can be changed from SSH (`bin/slate`) or by editing `config.php` — never only in the database.

## Also recommended

- Turn on HSTS once the whole site works over HTTPS (Settings → Security → HTTP security headers).
- Put Cloudflare (or your host's CDN/WAF) in front of the public site; enable "behind a proxy" only if your server
  sees the proxy's IP instead of the visitor's.
- Set `config.php` permissions to 600.
- Add a server cron (`php bin/slate cron`) so the integrity check and backups run on time.
- Configure SMTP so alerts and password resets are delivered.
- Keep the REST API off unless you use it; give apps read-only keys where possible.
