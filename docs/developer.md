# Developer guide

## Architecture

```
community-pulse.php          header, constants, loads includes/, Plugin::boot()
uninstall.php                deletes the only stored data (published snapshots)
includes/
  class-report.php           Report: month windows, SQL aggregates, suppression rules. No output, no storage
  class-snapshots.php        Snapshots: publish/unpublish frozen, protected figures (one autoload=no option)
  class-csv.php              Csv: monthly and 12-month writers (BOM, formula escaping)
  class-admin.php            Admin: Tools screen (tabs), export and publish handlers
  class-public.php           PublicReport: [community_pulse] shortcode, renders snapshots only
  class-plugin.php           Plugin: bootstrap, capabilities, translations, plugin-list link
assets/public.css            styles for the public block (theme-inheriting)
languages/                   .pot template, Spanish .po/.mo/.l10n.php
```

Data flow: `Report` → protected arrays → `Admin`/`Csv` (live, admin only) or `Snapshots` → option → `PublicReport` (visitors).

### Queries

`Report::months($from, $to)` answers every indicator for a range of months with **two** aggregate queries:

1. **Per month:** group by user and month (`LEFT(date_recorded, 7)`), then count users, sum updates, and count users with 2+ distinct dates.
2. **Cohorts:** one row per user with `BIT_OR(1 << PERIOD_DIFF(month, base))`, a bitmask of the months they posted in, and `MIN(date_recorded)` over all history (their first eligible update). The outer query sums bit tests per month:
   - *contributors* = bit *i* set;
   - *retained* = bits *i* and *i − 1* set;
   - *first-time* = bit *i* set and first update in month *i*.

User IDs never leave SQL. Bit 0 is the month before `$from`, so the oldest month's retention can be computed and protected. Table names come from `buddypress()` and are validated, and every value goes through `$wpdb->prepare`.

Eligibility (the shared `WHERE`) is in `Report::source()`. Change it there, and both queries and the group query follow.

**Performance.** On 600,000 synthetic activity rows (20,000 users, 300 groups, 2 years) with MariaDB 11.4 on a laptop, one month takes about 1.0 s and the 12-month trend about 1.4 s. The cohort query scans eligible history up to the end of the range using the `date_recorded` index. Nothing is cached, and public pages never run these queries.

### Protection

`Report::protect($row, $previous, $k)` applies the rules described in the [privacy model](privacy-model.md). `Report::safe_subcount($value, $total, $k)` hides a sub-count when the value or its complement is under *k*. Retention calls it against this month **and** the previous month.

### Snapshot format

Option `community_pulse_snapshots` (autoload off) holds `month => snapshot`:

```php
[
  'month' => '2026-10', 'published_at' => '2026-11-20 23:58:23', // UTC
  'rounded' => true, 'threshold' => 5, 'platform' => 'BuddyBoss Platform',
  'summary' => ['suppressed' => false, 'contributors' => 165, 'contributions' => 475,
                'returning' => 100, 'retained' => 95, 'new' => 70],   // null = hidden
  'groups_included' => true, 'truncated' => false,
  'groups' => [['name' => 'Data Literacy Circle', 'contributors' => 70, 'contributions' => 95], …],
]
```

## Hooks

| Filter | Default | Purpose |
|---|---|---|
| `community_pulse_capability` | `manage_options` | Who can view the report and download CSV files |
| `community_pulse_publish_capability` | `manage_options` | Who can publish or unpublish public snapshots |
| `community_pulse_threshold` | `5` | Minimum cohort size. Values under 5 are ignored |

```php
// mu-plugins/community-pulse-config.php
add_filter('community_pulse_capability', fn() => 'moderate_comments');   // let moderators read reports
add_filter('community_pulse_threshold', fn() => 10);                      // stricter suppression
```

The shortcode attributes pass through `shortcode_atts_community_pulse`.

## Local environment

`scripts/dev-env.sh` builds a disposable WordPress with **BuddyBoss Platform 3.8.0** and a second site with **BuddyPress**, sharing a private MariaDB that listens only on a Unix socket. The plugin is symlinked, so edits are live.

```sh
scripts/dev-env.sh up                 # create in .dev-env/ (or CP_DEV_DIR=/path)
scripts/dev-env.sh test               # unit + integration on both platforms
scripts/dev-env.sh serve 8765         # http://127.0.0.1:8765/wp-admin  (admin / fixture-only)
scripts/dev-env.sh wp <wp-cli args>   # BuddyBoss site; WP_SITE=bp for BuddyPress
scripts/dev-env.sh db -e 'SELECT …'   # MariaDB client
scripts/dev-env.sh down               # stop and delete everything
```

Requirements: PHP 8.1+, MariaDB (server and client) and curl. It takes about 900 MB.

Gotchas the script already handles:
- On macOS, Unix socket paths must stay under 104 characters, so the socket lives in `$TMPDIR`.
- BuddyBoss's installer resets the component list to its defaults, without Groups, the first time it runs. The script runs it explicitly (`bp_version_updater()`) before enabling Groups. Otherwise the first wp-admin visit would switch Groups off.

## Tests

```sh
php tests/unit.php                                            # no WordPress needed
wp eval-file /abs/path/tests/integration.php                  # disposable site with the plugin active
```

- **Unit tests** cover month windows, suppression (including both retention complements and raised thresholds), CSV writers, formula escaping, label normalisation, snapshot building and rounding, and that the version header matches `COMMUNITY_PULSE_VERSION`.
- **The integration test** creates synthetic users, groups and posts, through `bp_activity_add` and the real posting functions `bp_activity_post_update` and `groups_post_update`, and deletes everything in `finally`. It checks:
  - every indicator, including retention and first-time contributors, against a spam post in history;
  - the trend and threshold filters;
  - that CSV and screens hold no content or emails;
  - that partial months cannot be published, snapshots stay frozen, shortcodes render, and unpublishing works;
  - permissions.

  It assumes no other activity exists in the current and previous month. **Never run it on a production site.**

CI (`.github/workflows/test.yml`) runs syntax and unit checks on PHP 8.1, 8.3 and 8.4. It also runs the integration test on BuddyBoss 3.8.0 (MariaDB 11.4 and MySQL 8.4) and on current BuddyPress, installing the **packaged ZIP**.

## Translations

```sh
scripts/dev-env.sh wp i18n make-pot . languages/community-pulse.pot --domain=community-pulse --exclude=tests,scripts,docs,.dev-env
scripts/dev-env.sh wp i18n update-po languages/community-pulse.pot languages/community-pulse-es_ES.po
# translate the new entries, then:
scripts/dev-env.sh wp i18n make-mo  languages/community-pulse-es_ES.po languages
scripts/dev-env.sh wp i18n make-php languages/community-pulse-es_ES.po languages
```

Any `es_*` locale falls back to the bundled `es_ES` catalogue (`Plugin::load_textdomain`).

## Releasing

1. Bump `Version:` in `community-pulse.php` and `COMMUNITY_PULSE_VERSION`. A unit test fails if they differ.
2. Update `CHANGELOG.md` and `VALIDATION.md`, regenerate translations, and refresh screenshots if the UI changed.
3. Run `scripts/dev-env.sh test`, then `python3 scripts/package.py dist`.
4. Tag `vX.Y.Z`, push, and attach `dist/buddyboss-community-pulse-X.Y.Z.zip` to a GitHub release.
