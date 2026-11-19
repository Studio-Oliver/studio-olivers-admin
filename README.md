# Studio Olivers Admin

Admin tweaks for Studio Olivers client sites. It updates itself from this repo's GitHub releases.

**This repo is public.** Never commit API keys, licence keys, passwords or private notes. Put them in a site's `wp-config.php` instead.

## Adding a feature

Put a new file in `modules/`. It loads automatically. Prefix its functions with `soa_`.

Turn a module off on one site in its `wp-config.php`:

```php
define( 'SOA_DISABLED_MODULES', array( 'quiet-admin' ) );
```

## Releasing

```bash
./release.sh 1.0.1 "What changed"
```

This bumps the version, tags it, pushes, and creates a GitHub release. Sites check about every 12 hours. To check straight away, click **Check for updates** under the plugin in the Plugins list.

## Modules

- `quiet-admin`: for clients (administrators included), hides plugin and theme nag notices and ads and trims the dashboard. Studio Olivers staff always see everything: anyone whose email is on `studio-olivers.co.uk` or `mrolivers.co.uk`. Add a domain on one site with `define( 'SOA_STUDIO_EMAIL_DOMAINS', array( 'example.co.uk' ) );` or the `soa_studio_email_domains` filter. For clients, notices from WordPress itself and from the security, backup, mail and two-factor plugins in `soa_notice_allowlist()` still show. Add more on one site with the `soa_notice_allowlist` filter. Add `?show_notices=1` to any admin URL to see everything.
- `support`: "Support" admin page, dashboard widget and footer link with Studio Olivers contact details. Change the details in `soa_contact_details()`, or override them on one site with the `soa_contact_details` filter.
- `settings`: the **Settings → Studio Olivers** screen. Modules register their own sections with the WordPress Settings API against `soa_settings_page()`, and each keeps its own option so one module's settings can be reset alone.
- `login-url`: moves the login page to `/<slug>/` and shows the site's 404 for `wp-login.php` and logged-out `wp-admin`. Set it at **Settings → Studio Olivers**. See [Login address](#login-address) below.
- `login-limit`: locks an IP address out after repeated failed logins. Set it at **Settings → Studio Olivers**. See [Failed login limit](#failed-login-limit) below.
- `login-style`: brands the login, lost-password, reset and two-factor screens (logo, colours, font; flat, no shadows). Off until a site sets `define( 'SOA_LOGIN_BRAND', array( … ) );`. The keys are listed at the top of `modules/login-style.php`.

## Login address

Moves the login page to a custom address and shows the site's own 404 page at
`wp-login.php` and at `wp-admin` for anyone not logged in. Set it at
**Settings → Studio Olivers**.

It ships switched off. Updating the plugin changes nothing until someone turns
it on for that site.

### Replacing CleanTalk's login rename

On the first admin page load after updating, the module copies CleanTalk's
renamed login slug (`spbc_settings` → `login_page_rename__name`) into its own
setting, so the address people already bookmarked carries on working once
CleanTalk is gone. Only that one option is read; none of CleanTalk's code is
used at runtime.

It deliberately does **not** switch itself on, because CleanTalk is usually
still serving that address and two features answering for one URL is how people
get locked out. The order on each site is:

1. Update the plugin from wp-admin.
2. Go to **Settings → Studio Olivers**, check the imported address, tick
   **Hide wp-login.php**, save.
3. Open the address in a private window and confirm the login form appears.
4. Only then turn CleanTalk's login rename off (or remove CleanTalk).

### Recovering from a lockout

Over SSH, with read-only WP-CLI being no use here, one command puts the login
back at `wp-login.php` immediately:

```bash
wp option delete soa_login_url
```

Or in `wp-config.php`:

```php
define( 'SOA_LOGIN_DISABLE', true );
```

The module also refuses to switch on at all if the slug is empty, reserved by
WordPress (`login`, `admin`, `dashboard`, `wp-admin`…) or already used by a
page, so a bad value leaves `wp-login.php` working rather than hiding the login
with nothing serving it.

### Why there's a file in mu-plugins

Deactivating a normal plugin would put the login back at `wp-login.php` — fine
for recovery, but it means a client clicking Deactivate silently undoes the
hardening. So when the feature is switched on, the plugin writes
`wp-content/mu-plugins/0-soa-login-url.php`, a three-line loader that includes
this module early. mu-plugins can't be deactivated from the dashboard.

The loader is written through `WP_Filesystem`, so it is owned by the app user
and not by the SSH master user. If the plugin is deleted the loader finds no
file and does nothing, which puts `wp-login.php` back instead of breaking the
site. Switching the feature off deletes the loader.

### Sites still using the old constant

Before there was a settings screen, this module was switched on with
`define( 'SOA_LOGIN_SLUG', '…' );` in `wp-config.php`. That still works and
still means "on", so updating doesn't move those sites' login back to
`wp-login.php`. The constant wins over the setting.

### What keeps working while the login is hidden

Password-protected pages (`action=postpass`), logging out, password-reset links
that carry a valid key, `admin-ajax.php`, `admin-post.php`, `wp-cron.php`,
WP-CLI, the REST API and XML-RPC. WooCommerce's My Account login is a normal
front-end page and is unaffected.

## Failed login limit

After a number of failed logins from one address within a window, that address
is refused for a while. Counts live in transients, so nothing permanent is
added to the database. Defaults: 5 failures in 15 minutes, then locked out for
15 minutes. Off until switched on at **Settings → Studio Olivers**.

The check runs on `authenticate` before WordPress tests the password, so a
locked-out attacker learns nothing about whether the credentials were right. It
covers XML-RPC and application-password logins too, because those also go
through `wp_login_failed`.

### Allowlist

One address or CIDR range per line in the settings field, IPv4 or IPv6. The
settings screen shows the address you are currently connecting from, so the
office range can be copied straight in. There is no default allowlist — an IP
guessed wrong is worse than none.

For an emergency, `wp-config.php` wins:

```php
define( 'SOA_LOGIN_ALLOWED_IPS', array( '203.0.113.4', '203.0.113.0/24' ) );
```

### How the address is decided

`REMOTE_ADDR` is used and is never overridden by a request header. A
forwarded-for header is attacker-controlled, so trusting it would let someone
both dodge the limit and lock out whoever they chose. `X-Forwarded-For` is only
consulted when `REMOTE_ADDR` is itself private or loopback, which means a proxy
really is in front; override the header with `SOA_LOGIN_IP_HEADER` if needed.

If no routable address can be established the limiter does nothing, even when
switched on, and the settings screen says so. Without a per-visitor address
every visitor would share one counter and a single attacker would lock out the
whole site. On Cloudways (nginx → Apache → PHP-FPM) `REMOTE_ADDR` is the real
client address, so this is the normal path.

### Recovering from a lockout

```bash
wp option delete soa_login_limit   # switches the limiter off entirely
wp cache flush                     # drops the counters (sites using Redis)
```

Lockouts also simply expire. Note that on sites with a persistent object cache
the counters live in Redis, so `wp cache flush` clears every lockout at once.
