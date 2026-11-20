# Validation record — 0.2.0

Date: 2026-11-20. Synthetic fixtures only. No client or production data was used.

| Component | Tested version |
|---|---|
| WordPress | 7.1.5 |
| BuddyBoss Platform | 3.8.0, official release ZIP |
| BuddyPress | 14.5.6, from wordpress.org |
| PHP | 8.3.36 |
| MariaDB | 11.4.13, private Unix socket, networking disabled |
| WP-CLI | 2.12.0 |

## Executed locally

- PHP syntax check on every PHP file: passed.
- `php tests/unit.php`: **32 checks passed**.
- `wp eval-file tests/integration.php` on **BuddyBoss Platform 3.8.0**: **19 checks passed**.
- The same test on **BuddyPress 14.5.6**, with the plugin installed from the **packaged ZIP**: **19 checks passed**.
- **Browser session**, logged in as administrator on the PHP built-in server: the report screen rendered, the CSV downloaded with `text/csv`, an attachment filename and a UTF-8 BOM, a tampered nonce returned **403**, and the plugins list showed the **View report** link.
- **Translation:** the screen rendered in Spanish for `es_ES` and, through the fallback, for `es_MX` and `es_BO`.
- **Load:** with 600,000 synthetic activity rows (20,000 users, 300 groups, 11 months), `Report::fetch()` for one month took **0.75 s**.

The integration test covers:
- distinct contributors and recurrence across UTC days;
- exclusion of spam, hidden activity, private groups, private and scheduled posts, and posts from other months;
- small-group suppression;
- posts made through the real posting APIs (`bp_activity_post_update`, `groups_post_update`) in public and private groups;
- group names with quotes, `&` and a formula prefix, shown as on the site and escaped in the CSV;
- the partial-month flag;
- a CSV with no post content or emails;
- the admin screen and its export nonce;
- denial of a subscriber, and the `community_pulse_capability` filter.

The unit checks cover:
- calendar range and rejection of invalid input;
- summary suppression, small recurring cohorts and their complements;
- formula escaping, including leading tab and carriage return;
- group-label normalisation and the partial-month flag;
- the CSV header, BOM, suppression values, the group/overall distinction and the truncation note.

## Finding during validation (fixed in 0.2.0)

BuddyBoss and BuddyPress store group names slashed and entity-encoded. 0.1.0 printed the raw value, so a group called `Tom "&" Jerry` appeared with backslashes in the screen and CSV. 0.2.0 normalises names the way the platform displays them.

## Limits

These have not been validated:
- BuddyBoss Theme and BuddyBoss Pro;
- multisite;
- real production traffic;
- BuddyBoss versions other than 3.8.0.

The fixed five-person threshold reduces exposure. It does not guarantee anonymity.

CI runs syntax and unit checks on PHP 8.1, 8.3 and 8.4. It also runs the integration test for BuddyBoss 3.8.0 and current BuddyPress on PHP 8.3 with MariaDB, installing the packaged ZIP. A configured workflow is not itself a passing run: see the repository's Actions results.
