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
