# Changelog

## 0.3.0 — 2026-11-20

### Added
- **Retention and first-time contributors.** The report shows people retained from the previous month and people posting for the first time ever. Both are computed in SQL (a per-user month bitmask and the first eligible date), so user IDs never reach PHP.
- **12-month trend** tab: an accessible SVG bar chart, a table with every indicator, and a 12-month CSV.
- **Public reports:** publish a finished month as a frozen, protected snapshot, optionally rounded to the nearest 5 and optionally with groups, and show it with the `[community_pulse]` shortcode (`month`, `view="history"`, `groups`, `title`). Public pages never query live data.
- **Print or save as PDF** button and print stylesheet for the admin report.
- `community_pulse_threshold` filter: raise the minimum cohort size. It can never go below 5.
- `community_pulse_publish_capability` filter.
- `uninstall.php` removes the published snapshots, the only stored data.
- `scripts/dev-env.sh`: a one-command disposable WordPress with BuddyBoss and BuddyPress for development and tests.
- Docs: user guide, privacy model and developer guide, with screenshots of every screen.

### Changed
- "Retained" is protected against small complements in **both** months, and "first-time" against this month.
- The monthly CSV gains `retained_from_previous_month` and `first_time_contributors` columns.
- The code is split into `Report`, `Snapshots`, `Csv`, `Admin`, `PublicReport` and `Plugin`. `Plugin::write_csv()` is now `Csv::monthly()`.
- CI runs BuddyBoss's installer explicitly, so components are not reset on the first admin visit.

## 0.2.0 — 2026-11-20

### Fixed
- Group names with quotes, `&` or HTML entities showed stray backslashes and entities in the screen and CSV, for example `Tom \"&amp;\" Jerry`. They are now normalised the way the platform displays them.
- CSV formula escaping now also covers cells starting with a tab or carriage return.

### Changed
- **CSV format:** new `partial_month` and `group_name` columns. `scope` is now `overall`, `group` or `note`, so a group named "overall" can no longer be confused with the total. The file starts with a UTF-8 BOM so spreadsheets keep accents.
- The admin screen explains each indicator, flags the current month as partial, says what is and is not counted, shows the source window and platform, and formats numbers for the site locale.
- Error messages are translatable and no longer expose internal exception text.

### Added
- Spanish translation, used by any `es_*` locale, and a `.pot` template.
- A **View report** link in the plugins list.
- The `community_pulse_capability` filter to let a trusted non-admin role view the report.
- BuddyPress 14.5.6 integration-tested, locally and in CI.
- The integration test now also posts through the real posting APIs (`bp_activity_post_update`, `groups_post_update`).
- CI installs the packaged ZIP instead of the source tree. The packager reads the version from the plugin header.
- A Spanish README, a screenshot, CSV column docs, troubleshooting and performance notes.

## 0.1.0 — 2026-11-18

- First technical prototype: monthly public-participation summary, public groups with 5+ contributors, aggregate CSV, small-cell suppression.
