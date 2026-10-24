# Netivo Action Logger (MU)

Standalone must-use WordPress plugin that streams admin user actions to the
Netivo Action Logger central API. Unlike `packages/wp-module`, this package
has no dependency on `netivo/wp-core` or a Netivo theme, so it works under
any theme, and it is installed as a **must-use plugin**: it cannot be
deactivated from wp-admin like a regular plugin.

## Features
- **Theme-independent**: pure WordPress, no Netivo theme or `netivo/wp-core` required.
- **Cannot be turned off from wp-admin**: ships as a must-use plugin.
- **Zero UI disruption**: events are buffered during the request and sent once on `shutdown`, after the response has been delivered (PHP-FPM/LiteSpeed). Failed deliveries fall back to a local queue (`{prefix}nt_mu_action_log_queue`) drained by WP-Cron.
- **Granular coverage**: auth events, user management, plugin/theme/core changes, critical settings, posts/pages/media/terms, and WooCommerce orders, products, coupons and settings (when WooCommerce is active).
- **Privacy by default**: passwords, tokens, cookies and card numbers are redacted before anything leaves the site; post content and customer details are never sent.
- **Read-only configuration**: controlled only via `wp-config.php` constants (or a filter for defaults); there is no settings form.
- **Restricted admin UI**: the status page under Settings, and the plugin's entry in the "Must Use" plugins list, are only visible to users whose email is on an allowed domain (`netivo.pl` and `fehu-web.dev` by default) — a client site's own administrators keep full control over every other plugin, but this internal diagnostics screen stays out of their way.
- **Self-update from GitHub, only on request**: checks the update repository's latest release automatically (cached) when an allowed user visits Plugins or the status page, but only downloads and installs it when that user explicitly clicks "Update now" — never from a cron job. Every update attempt, success or failure, is itself logged as an audit event.

## Requirements
- PHP 8.2 – 8.5
- WordPress 6.3+
- Any theme (no `netivo/wp-core` dependency)

## Installation

`wp-content/mu-plugins/` only auto-loads `.php` files directly inside it, not
subdirectories, so the package ships two parts that are copied to different
places:

1. Copy this whole package directory to `wp-content/mu-plugins/netivo-action-logger-mu/`
   (so `src/`, `views/` and `netivo-action-logger-mu.php` end up inside it).
2. Copy `netivo-action-logger-mu-loader.php` to `wp-content/mu-plugins/` directly
   (as a sibling of the directory above, not inside it).

Resulting layout:

```
wp-content/mu-plugins/
├── netivo-action-logger-mu-loader.php      ← the one file WordPress auto-loads
└── netivo-action-logger-mu/
    ├── netivo-action-logger-mu.php         ← real bootstrap, required by the loader
    ├── src/...
    └── views/...
```

No Composer install is required on the host site: every class is loaded with
an explicit `require_once` in `netivo-action-logger-mu.php`, so this works on
plain, non-Composer-managed WordPress installs too. `src/`, `tests/` and the
dev tooling config files in this repository are only needed to develop this
package itself.

The plugin resolves its configuration on `plugins_loaded`. When it is
disabled or misconfigured it captures nothing, and the status page (for
allowed users) explains why.

## Configuration
Use the values from the dashboard's **Add site** dialog, in `wp-config.php`:

```php
define( 'NETIVO_ACTION_LOGGER_MU_API_URL', 'https://logger.netivo.pl' );
define( 'NETIVO_ACTION_LOGGER_MU_API_KEY', 'nt_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' );
```

| Setting | Constant | Default | |
|---|---|---|---|
| `api_url` | `NETIVO_ACTION_LOGGER_MU_API_URL` | — | API origin; `/api/v1` is appended when missing |
| `api_key` | `NETIVO_ACTION_LOGGER_MU_API_KEY` | — | Site token (`nt_live_` + 32 hex characters) |
| `enabled` | `NETIVO_ACTION_LOGGER_MU_ENABLED` | `true` | Kill switch |
| `delivery` | `NETIVO_ACTION_LOGGER_MU_DELIVERY` | `shutdown` | `shutdown` or `queue` (see below) |
| `timeout` | `NETIVO_ACTION_LOGGER_MU_TIMEOUT` | `3` | HTTP timeout in seconds (1–10) |
| `trusted_proxies` | `NETIVO_ACTION_LOGGER_MU_TRUSTED_PROXIES` | `[]` | Proxy IPs whose `X-Forwarded-For` is trusted for the client IP |
| `update_repo` | `NETIVO_ACTION_LOGGER_MU_UPDATE_REPO` | `netivo/wp-action-logger-plugin` | `owner/repo` the self-updater checks for new releases |
| `update_token` | `NETIVO_ACTION_LOGGER_MU_UPDATE_TOKEN` | — | Optional GitHub token, for a private update repo or a higher API rate limit |

Priority, highest first: constants, then the `netivo_action_logger_mu_config`
filter (lowest priority; useful for setting defaults from another mu-plugin).
There is no theme-config or `theme_options` source, since this package does
not assume a Netivo theme.

### Delivery modes
- **`shutdown`** (default): events are sent at the end of the request with a blocking request whose result is checked; failures are queued and retried by WP-Cron every 5 minutes. Under PHP-FPM or LiteSpeed the response is finished first, so visitors never wait.
- **`queue`**: every event is queued and only WP-Cron sends. Use this on hosts without PHP-FPM (e.g. Apache mod_php), where an unreachable API could otherwise add up to `timeout` seconds to the end of a request.

WP-Cron runs on page visits; on low-traffic sites consider triggering `wp-cron.php` from a system cron.

## Admin visibility

The status page (Settings → Action Logger (MU)) and the plugin's row in the
Plugins → "Must Use" list are only shown to users signed in with an email on
an allowed domain. The default allowlist is `netivo.pl` and `fehu-web.dev`;
override it from another mu-plugin or theme if needed:

```php
add_filter(
    'netivo_action_logger_mu_allowed_domains',
    static function ( array $domains ): array {
        $domains[] = 'example-agency.com';

        return $domains;
    }
);
```

This only affects visibility of this module's own admin UI. It does not
touch capabilities for any other plugin, page or user — a site's own
administrators keep `manage_options` and full control over everything else.

## Self-update

The plugin can update itself from [netivo/wp-action-logger-plugin](https://github.com/netivo/wp-action-logger-plugin)
(a mirror of this package, kept in sync by `.github/workflows/split-wp-mu-plugin.yml`),
whose releases each carry a zip asset laid out to match `wp-content/mu-plugins/`
exactly (loader file + plugin directory).

- **Checking** is automatic but cheap and read-only: when an allowed user (see
  above) visits Plugins or the status page, the latest release is looked up
  and the result cached for 12 hours. A banner on both screens shows when a
  newer version exists.
- **Applying** an update is never automatic. Only an explicit "Update now"
  click (status page, `manage_options` + allowed-domain gated, with a
  confirmation prompt) downloads the release zip, extracts it, and swaps it
  in for the files currently running — backing up the previous version first
  and rolling back if anything fails.
- **Every update attempt is logged**: whether it succeeds or fails, an audit
  event (`system` / `mu_plugin_updated` or `mu_plugin_update_failed`) is sent
  immediately, the same way the "Send test ping" button does, so the
  triggering admin sees right away whether it reached the API, and the event
  shows up in the dashboard like any other action.
- A "Check for updates" button on the status page forces a fresh check,
  bypassing the 12-hour cache.

## Hooks
| Filter | Purpose |
|---|---|
| `netivo_action_logger_mu_config` | Lowest-priority configuration values |
| `netivo_action_logger_mu_ignored_post_types` | Post types the content listener skips |
| `netivo_action_logger_mu_tracked_options` | Options (name => severity) the settings listener logs |
| `netivo_action_logger_mu_allowed_domains` | Email domains allowed to see this module's admin UI |

## Development
```bash
composer install
composer test           # PHPUnit + Brain Monkey
composer phpstan        # PHPStan level 7
composer lint           # WordPress VIP coding standards
composer compatibility  # PHPCompatibility 8.2 – 8.5
```

`Plugin::VERSION` (in `src/Plugin.php`, mirrored in both files' plugin
headers) must be bumped to match the tag on every release that touches this
package — the self-updater compares it against the update repository's
latest release tag to decide whether an update is available.
