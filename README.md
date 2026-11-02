# Pathly

Pathly watches the path. Ping if it's up. Flow if a user can walk it. Chain if
the services behind it hold.

This module connects a Drupal 10 or 11 site to [Pathly](https://pathlyhq.com):

- **Ping**: created automatically on the address of the site as soon as the API
  key is saved. If a Ping already watches this address, the module uses it.
- **Flow**: Lumi, the Pathly Flow assistant, recognizes Drupal and prepares a
  Flow (contact form stopped before sending, login page; the cart with Drupal
  Commerce when Lumi proposes it). Each proposed Flow is replayed once before
  it is shown. You choose it in one click. You can also use a Flow that
  already exists in your Pathly account.
- **Replay after updates**: the Flow (or the Ping, without a Flow) runs again
  after each update of Drupal core, a module or a theme.
- **Administration**: a "Pathly: Ping and Flow status" block (Ping and Flow
  status, date and screenshot of the last failure, plan and quotas) and the
  same report under Reports, Pathly.
- **Uptime badge**: a "Pathly uptime badge" block, linked to the Pathly status
  page. Off by default.

The module is a client of the public Pathly API. It only calls
`https://api.pathlyhq.com`.

## Requirements

- Drupal 10.3 or later, or Drupal 11.
- PHP 8.1 or later.
- A Pathly account. The Solo plan is free, without a card:
  [create a free account](https://pathlyhq.com/en/login?mode=signup&ref=drupal&utm_source=drupal&utm_medium=module).

## Installation

```bash
composer require drupal/pathly
drush pm:install pathly
```

Installing the module sends nothing to Pathly.

## Configuration

1. In Pathly, Settings, create an API key. Prefer a key limited to one Space,
   with these scopes: `org:read`, `scenarios:read`, `scenarios:write`,
   `runs:read`, `runs:trigger`, `assistant:run`, `assistant:save`,
   `status:read`, `status:write`.
2. In Drupal, go to Configuration, Web services, Pathly
   (`/admin/config/services/pathly`) and paste the key. The Ping is created.
3. In the Flow section, confirm that you manage the site, then click "Prepare
   the Flow with Lumi". Come back a minute later, choose the Flow and save it.
4. Place the "Pathly: Ping and Flow status" block in the administration theme,
   or in the Dashboard of Drupal CMS. It is only shown to the users with the
   "Administer Pathly" permission.
5. Optional: turn the uptime badge on, then place the "Pathly uptime badge"
   block.

A host can set the key in `settings.php` instead, for example from an
environment variable:

```php
$settings['pathly_api_key'] = getenv('PATHLY_API_KEY');
```

## How the replay after updates works

Drupal has no single hook that says "an update just finished": Composer
changes the code, then `update.php` or `drush updatedb` runs the updates and
rebuilds the caches. The module keeps a fingerprint of the installed versions
(core, modules, themes) and compares it:

- in `hook_rebuild()`, at the end of `update.php`, `drush updatedb` and
  `drush cache:rebuild`;
- in `hook_modules_installed()`, when a module is installed;
- on the `PostApplyEvent` of Package Manager (Automatic Updates, Project
  Browser);
- in `hook_cron()`, as a safety net.

The Flow runs only when the fingerprint changed, at most once every ten
minutes. Each run counts in the quotas of your Pathly plan.

## Good to know

- **Test carts.** A Flow on a shop puts a product in a cart, up to the checkout
  page. Nothing is paid and no order is placed, but abandoned cart reminders
  may count these carts. Exclude them if needed.
- **Data sent to Pathly.** Only the address of the site, and the versions of
  Drupal and of the module (in the User-Agent header). No customer, order or
  visitor data. Nothing is sent before the API key is saved.
- **The API key** is kept in the State API: it is not exported with the
  configuration. It is never shown after it is saved, and never logged.
- **The badge** is an image loaded from `api.pathlyhq.com` by the browser of
  your visitors. It is off by default.
- **Uninstall** removes the settings, the key and everything the module kept.
  If the module published the status page for the badge, it unpublishes it.
  The Ping and the Flow stay in your Pathly account.

## Development

```bash
cd web/modules/custom/pathly
# Unit tests, coverage of lines and branches (must be 100 %, Xdebug).
../../../../vendor/bin/phpunit --testsuite unit
php tests/coverage-check.php build/coverage.cov
# Kernel tests, on a real Drupal (SQLite).
../../../../vendor/bin/phpunit --testsuite kernel --no-coverage
# Coding standards (Drupal, DrupalPractice).
../../../../vendor/bin/phpcs

# Integration test on a real Drupal, with a simulated Pathly API.
docker run --rm -v "$PWD:/module:ro" drupal:11-php8.4-apache \
  bash /module/tests/integration/run.sh
```

See `tests/README.md` for the Docker setup used on a workstation.

## Maintainers

- Pathly, [pathlyhq.com](https://pathlyhq.com)

## License

GPL-2.0-or-later. See `LICENSE.txt`.
