# Validation record — 0.1.0

Date: 2026-11-18. Synthetic fixtures only; no client or production data.

| Component | Tested version |
|---|---|
| WordPress | 7.1.4 |
| BuddyBoss Platform | 3.8.0, official release ZIP |
| PHP | 8.3.36 |
| MariaDB | 11.4.13 |
| WP-CLI | 2.12.0 |

## Executed locally

- PHP syntax checks on every PHP file: passed.
- `php tests/unit.php`: **22 checks passed**.
- `wp eval-file tests/integration.php`: **14 checks passed** against real WordPress and BuddyBoss tables using the platform API to create and delete fixtures.

Integration covers distinct contributors, UTC recurrence, exclusion of spam/hidden activity/private groups/private and scheduled posts/out-of-month posts, small-group suppression, CSV without content or emails, formula-safe group labels, admin screen rendering, export nonce presence and denial of a subscriber.

Unit checks cover calendar range and invalid input rejection, summary suppression, small recurring cohorts and their complements, CSV protection and exported suppression values.

The report query is read-only. Fixture tests create users, groups and activities only in a disposable database and clean up in `finally`. The local test database uses a private Unix socket with TCP networking disabled.

## Limits

BuddyBoss Theme/Pro, multisite, production load, browser interaction and cross-version compatibility have not been validated. BuddyPress is a source-compatible target but has not been integration-tested in this release. The fixed five-person threshold reduces exposure; it does not guarantee anonymity. Permission and UI output were exercised through real WordPress callbacks under WP-CLI, not an authenticated browser session.

CI repeats syntax and unit checks on PHP 8.1/8.3/8.4, and real BuddyBoss 3.8.0 integration on PHP 8.3/MariaDB. A configured workflow is not itself a passing run: see the repository's Actions results.
