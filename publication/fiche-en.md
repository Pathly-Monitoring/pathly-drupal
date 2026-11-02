# Project page on drupal.org (English)

drupal.org is in English. Paste these texts into the project form
(drupal.org/project/add, then "Edit").

## Project title

Pathly

## Short name (machine name)

pathly

## Short description (one sentence, project listings)

Watches the path of your Drupal site with Pathly: Ping if it is up, Flow if a
visitor can walk it, and the Flow replayed after each update.

## Project page body

Pathly watches the path. Ping if it's up. Flow if a user can walk it. Chain if
the services behind it hold.

This module connects a Drupal 10 or 11 site to [Pathly](https://pathlyhq.com),
an external monitoring service. A Pathly account is required. The Solo plan
is free, without a card.

### Features

- **Ping**: created on the address of the site as soon as the API key is
  saved. If a Ping already watches this address, the module uses it.
- **Flow prepared by Lumi**: Lumi, the Pathly Flow assistant, recognizes Drupal
  and proposes a Flow (contact form stopped before sending, login page). Each
  proposed Flow is replayed once before it is shown. You choose it in one
  click. You can also use a Flow that already exists in your Pathly account.
- **Replay after updates**: the Flow runs again after each update of Drupal
  core, a module or a theme (update.php, drush updatedb, cache rebuild, module
  install, Automatic Updates).
- **Status in the administration**: a block and a report page show the Ping
  and Flow status, the date and screenshot of the last failure, the plan and
  its quotas.
- **Uptime badge**: a block linked to the Pathly status page. Off by default.

### Requirements

- Drupal 10.3 or later, or Drupal 11. PHP 8.1 or later.
- A Pathly account and an API key, preferably limited to one Space.

### Data and privacy

- Nothing is sent to Pathly before an administrator saves an API key.
- Then only the address of the site goes to Pathly, with the versions of
  Drupal and of the module. No customer, order or visitor data.
- The module only calls `https://api.pathlyhq.com`.
- The API key is kept in the State API, never shown again and never logged.
- The badge is an image loaded from `api.pathlyhq.com` by the browser of the
  visitors. It is off by default.
- A Flow on a shop puts a product in a test cart: abandoned cart reminders may
  count these carts.

### Similar projects

Uptime modules check that a page answers. Pathly also replays the path of a
visitor in a real browser (Flow), and replays it after each update.

## FAQ

**Is the Pathly service free?**
The Solo plan is free, without a card and without a time limit: 5 Ping and 1
Flow. Paid plans are described on https://pathlyhq.com.

**Does the module work without a Pathly account?**
No. It is a client of the Pathly API. Without a key, it sends nothing.

**Does Lumi pay or submit anything on my site?**
No. It pays nothing, sends no form and only types test values. You validate
each Flow before it is saved.

**Does Lumi know Drupal Commerce?**
When Lumi proposes a cart Flow, the module selects it first on a site with
Drupal Commerce. Otherwise it proposes the contact form and the login page.
You can also record the Flow in Pathly and use it from the module.

**What happens when I uninstall the module?**
The settings, the key and what the module kept are removed. If the module
published the status page for the badge, it unpublishes it. The Ping and the
Flow stay in your Pathly account.

## Release notes, 1.0.0 (first release)

- Settings: API key, sign-up link.
- Ping created or reused on the address of the site.
- Flow prepared by Lumi, or existing Flow.
- Replay of the Flow after updates.
- Status block and report page: Ping, Flow, last failure with screenshot,
  plan and quotas.
- Uptime badge block, off by default.
- French translation.
