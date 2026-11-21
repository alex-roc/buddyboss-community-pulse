# Validation record — 0.3.0

Date: 2026-11-20. Synthetic fixtures only. No client or production data was used.

| Component | Tested version |
|---|---|
| WordPress | 7.1.5 |
| BuddyBoss Platform | 3.8.0, official release ZIP |
| BuddyPress | 14.5.6, from wordpress.org |
| PHP | 8.3.36 |
| MariaDB | 11.4.13, private Unix socket, networking disabled |
| WP-CLI | 2.12.0 |

The environment was built from scratch with `scripts/dev-env.sh up`.

## Executed locally

- PHP syntax check on every PHP file: passed.
- `php tests/unit.php`: **52 checks passed**.
- `tests/integration.php` on **BuddyBoss Platform 3.8.0**: **37 checks passed**.
- The same test on **BuddyPress 14.5.6**, from the symlinked source and again with the plugin installed from the **packaged ZIP**: **37 checks passed** both times.
- **Uninstall:** `wp plugin uninstall` removed the `community_pulse_snapshots` option.
- **Fresh install:** after the dev environment ran BuddyBoss's installer, running `admin_init` and `bp_admin_init` no longer switched the Groups component off.
- **Browser**, logged in as administrator on the PHP built-in server with a synthetic 12-month community (691 people, 4,434 updates, 5 groups):
  - the monthly report, trend chart and table, and public-reports tab rendered;
  - the print stylesheet hid the admin chrome;
  - a page with `[community_pulse]` and `[community_pulse view="history"]` showed the published snapshots, rounded, on Twenty Twenty-Five.

  The screenshots in `docs/screenshots/` come from this session.
- **Translation:** every screen rendered in Spanish with the admin user locale set to `es_ES`. In 0.2.0, the `es_MX` and `es_BO` fallback was checked too.
- **Load:** on 600,000 synthetic activity rows (20,000 users, 300 groups, about 2 years), `Report::fetch()` for one month took **0.97 s** and `Report::trend()` for 12 months took **1.36 s**.

### What the integration test covers

- **Every indicator:** distinct contributors, updates, 2+ days, retained from the previous month, and first-time contributors. A spam post in a newcomer's history does not count as prior participation.
- **Exclusions:** spam, hidden activity, private groups, private and scheduled posts, and other months.
- **Real posting APIs:** `bp_activity_post_update` and `groups_post_update`.
- **Trend:** the 12-month view agrees with the monthly report.
- **Threshold filter:** raising it suppresses, and values under 5 are ignored.
- **Output content:** the CSV and screens hold no post content, emails or private group names, and formula-like names are escaped.
- **Admin screens:** the report and trend tabs render, and the export link carries a nonce.
- **Public reports:**
  - partial months are rejected;
  - snapshots stay frozen when new activity arrives;
  - the shortcode renders summary, history and rounded variants, and renders nothing for unpublished months;
  - unpublishing deletes the option.
- **Permissions:** subscribers are denied both the report and publishing, and the capability filter grants a custom role.

### What the unit tests cover

- the month window and the selectable months;
- suppression, including both retention complements, first-time complements and raised thresholds;
- formula escaping and group-label normalisation;
- the monthly and 12-month CSV writers;
- snapshot building, rounding to 5, and suppressed months publishing nothing;
- that the version header matches `COMMUNITY_PULSE_VERSION`.

## Limits

These have not been validated:
- BuddyBoss Theme and BuddyBoss Pro;
- multisite;
- real production traffic;
- BuddyBoss versions other than 3.8.0;
- MySQL locally. CI covers MySQL 8.4.

Suppression, rounding and frozen snapshots reduce exposure. They do not guarantee anonymity: see [docs/privacy-model.md](docs/privacy-model.md).

CI runs syntax and unit checks on PHP 8.1, 8.3 and 8.4. It also runs the integration test for BuddyBoss 3.8.0 and current BuddyPress on PHP 8.3 with MariaDB 11.4, plus BuddyBoss on MySQL 8.4, installing the packaged ZIP. A configured workflow is not itself a passing run: see the repository's Actions results.
