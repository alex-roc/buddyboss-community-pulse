# Community Pulse for BuddyBoss

A small WordPress extension by **Alex Ojeda Copa** for community managers who need a monthly view of public participation without exporting member profiles or post content.

**Status: 0.1.0 · technical prototype, not a production-certified analytics suite.** Created in November 2026. This is a new work sample, not evidence of earlier BuddyBoss engagements. No affiliation with BuddyBoss.

## Qué resuelve

En **Herramientas → Community Pulse**, un administrador puede elegir uno de los últimos 12 meses UTC y consultar:

- Participantes únicos que publicaron actualizaciones públicas.
- Cantidad de actualizaciones públicas.
- Participantes que contribuyeron en dos o más días UTC distintos.
- Grupos públicos con al menos cinco participantes que publicaron.
- Un CSV con los mismos valores agregados y etiquetas protegidas contra fórmulas de hoja de cálculo.

La lectura ayuda a identificar dónde sostener la facilitación, qué comunidades contribuyen y si existe participación recurrente. Estos indicadores **no equivalen a usuarios activos por lectura, impacto social, causalidad ni calidad de participación**.

## Installation

1. Install WordPress 6.6+ and BuddyBoss Platform with **Activity** and **Groups** enabled. PHP 8.1+ is required.
2. Upload the release ZIP through **Plugins → Add new → Upload plugin**, then activate.
3. Open **Tools → Community Pulse** as an administrator.

BuddyPress's public activity schema is also targeted, but has not been integration-tested; see the test matrix below. It does not require BuddyBoss Pro, a theme, external API keys, a SaaS account, JavaScript build tooling or extra database tables.

## Privacy and security boundaries

- Only users with `manage_options` can view or export. The CSV endpoint additionally requires a WordPress nonce.
- Queries select **aggregate counts**; user IDs participate inside SQL only. No names, emails, profile attributes, post text, messages or IPs are returned to PHP or exported. Public group labels are returned.
- Excludes private/hidden groups, hidden activity, spam, anonymous/deleted-user activity, comments and all activity types other than `activity_update`. BuddyBoss additionally requires `privacy=public` and `status=published`.
- Comments are excluded because their parent visibility requires separate handling. No private community insights are inferred.
- Fewer than five contributors suppresses the entire summary row; small groups are omitted. Recurrence is suppressed when either the recurring subset or its complement is smaller than five.
- Counts are read on demand. No analytics data is stored, no cron is registered, and nothing is sent to external services.
- **Thresholding is risk reduction, not an anonymity guarantee.** An administrator may already know contributors; comparing reports over time can reveal changes. Restrict exports and share them under your organisation's data policy.
- Fixed monthly UTC windows, at most 12 months of history, at most 100 qualifying groups per report. Current month is explicitly incomplete. Reports query the existing activity table; they do not add indexes. Test query cost on large communities before rollout.
- No login/read tracking, registered-user denominator, countries, gender inference, cookies or individual rankings. Such indicators need a separate, consent-aware design.

## Definitions

| Metric | Definition |
|---|---|
| Contributor | Distinct nonzero `user_id` with an eligible `activity_update` in the selected UTC month |
| Contribution | One eligible `activity_update`; not a comment, registration or group join |
| Recurring contributor | Eligible updates on at least two distinct UTC calendar dates |
| Group | Public group with at least five distinct eligible contributors |

Overall includes public profile activity and eligible public-group updates. Group rows are a subset and must not be summed to derive unique contributors. Recurrence is calculated overall only.

## Development and tests

```sh
php tests/unit.php
# Disposable WordPress database only; requires wp-cli and the active plugin.
wp eval-file /absolute/path/buddyboss-community-pulse/tests/integration.php
python3 scripts/package.py /path/to/output
```

The integration fixture creates synthetic users, groups and posts through the platform API and cleans them in `finally`. **Never run it on a production site.** It assumes an otherwise empty activity table for the current month.

Source-level checks cover month bounds, suppression (including complements), CSV formula defence, real platform queries, visibility, spam, scheduled/private posts, capability denial and screen rendering. GitHub Actions runs PHP syntax/unit checks and a real WordPress/MariaDB/BuddyBoss integration job.

### Verified locally

See [VALIDATION.md](VALIDATION.md) for exact tested versions and results. BuddyBoss Theme, BuddyBoss Pro, multisite and production load are not certified. No compatibility claims beyond the recorded test matrix.

## Architecture and source references

`Report` obtains table names from the public `buddypress()` component object; queries use WordPress `$wpdb->prepare` for all varying values and validate platform-supplied identifiers. SQL aggregation avoids loading the content and member metadata returned by general activity object hydration.

- [BuddyBoss Platform source](https://github.com/buddyboss/buddyboss-platform): `bp-activity/classes/class-bp-activity-activity.php` documents `hide_sitewide`, `privacy`, `status`, `date_recorded` and `user_id`; `bp-groups` defines public group status.
- [BuddyBoss hook/reference guidance](https://buddyboss.com/docs/finding-hooks/).
- [WordPress capability checks](https://developer.wordpress.org/reference/functions/current_user_can/).
- [WordPress nonces](https://developer.wordpress.org/apis/security/nonces/): nonce validation supplements, and never replaces, permission checks.
- [WordPress database prepare](https://developer.wordpress.org/reference/classes/wpdb/prepare/).

## Roadmap

Consent-aware adoption metrics; configurable reporting roles; accessible visual trends; performance evaluation on large datasets; translation catalogue. These are future possibilities, not delivered features.

## License

GPL-2.0-or-later. Copyright 2026 Alex Ojeda Copa. No warranty; review and validate on staging before deployment.
