# Privacy model

Community Pulse answers "how is participation going?" without telling anyone **who** participates. This page describes what it protects against, how, and what it does not cover. It is written for the person who decides whether the plugin is acceptable under their organisation's data policy.

## What the plugin reads and returns

| | |
|---|---|
| Reads | The platform's activity table (user ID, date, type, component, item ID, visibility flags) and the groups table (ID, name, status) |
| Never reads | Post text, names, emails, profile fields, messages, IP addresses, logins or page views |
| Returns to PHP | Counts only. User IDs are grouped and counted **inside SQL**, and no per-person row ever reaches PHP |
| Stores | Nothing, unless an administrator publishes a month. A published month stores only its already-protected counts and public group names |
| Sends | Nothing to any external service |

## Who can see what

| Audience | Sees | Default capability | Filter |
|---|---|---|---|
| Report viewers | Live monthly report, 12-month trend, CSV exports | `manage_options` | `community_pulse_capability` |
| Report publishers | Publish or unpublish public snapshots | `manage_options` | `community_pulse_publish_capability` |
| Site visitors | Only published snapshots, through the `[community_pulse]` shortcode | none | none |

Administrators can already browse the activity stream, so the main purpose of the protections is **what leaves the admin screen**: CSV files, printed or PDF reports, and public pages.

## Rules

1. **Eligibility.** Only public updates count: `activity_update` rows that are not spam, not hidden, by a real user, on a profile or in a public group, and on BuddyBoss also `privacy = public` and `status = published`. Comments, private and hidden groups, and non-public posts are excluded, so private behaviour is never inferred.

2. **Threshold (k).** Every figure describes at least *k* people, with *k* = 5 by default. If fewer than *k* people posted in a month, the whole month is hidden. Groups with fewer than *k* contributors are not listed. *k* can be raised with the `community_pulse_threshold` filter. It can never go below 5, and values under 5 are ignored.

3. **Complement protection.** A sub-count is hidden when it, or the people it leaves out, is under *k*. Otherwise "contributors on 2+ days = 18 out of 20" would reveal that exactly 2 people posted on a single day.
   - *Contributors on 2+ days* and *first-time contributors* are checked against this month's contributors.
   - *Retained from previous month* is checked against **both** months, because "48 retained" with 50 last month reveals that 2 people stopped posting.

4. **Frozen public snapshots.** Public pages never query live data. An administrator publishes a **finished** month, and the plugin stores its protected figures at that moment. This prevents three things:
   - **Watching a month grow.** If the current month were public, comparing it day by day would reveal individual posts. Partial months cannot be published.
   - **Re-querying after deletions.** If someone deletes their posts, a live figure would drop by one and expose them. A snapshot does not change.
   - **Probing.** Visitors cannot choose parameters, so they cannot run many slightly different queries to subtract one from another.

5. **Rounding for publication.** When publishing, figures can be rounded to the nearest multiple of 5. This is on by default. Rounding blurs differences of up to ±2 between published numbers, for example the overall count against a group, or one month against the next. A protected value is always at least 5, so it never rounds to 0.

6. **CSV safety.** Exported cells cannot run as spreadsheet formulas. A leading `= + - @`, tab or carriage return is escaped.

## Residual risks

Small-cell suppression reduces disclosure. It is **not** anonymisation in a formal sense, and some risks remain:

- **Insiders.** People who already know the community can combine the numbers with what they know ("only Ana posts in this group").
- **Differencing between unrounded figures.** In the live admin report and in unrounded snapshots, overall *public updates* minus the sum of group updates gives the number of profile posts, which may come from few people. Published figures are rounded by default for this reason. Publish groups only when they add value.
- **Re-publishing.** If you update a published month after the data changed, someone who saved the old figure can compare it with the new one. Re-publish only to correct mistakes.
- **Retention across many months.** Each month is protected on its own. A long series of public snapshots can still show slow trends of small sub-populations. Prefer publishing overall figures.
- **Not a measure of quality or impact.** The figures count posting, not reading, wellbeing or outcomes. Do not use them to judge individuals or small teams.

## Recommendations

- Keep the default threshold, or raise it, for communities where membership itself is sensitive (health, politics, minorities).
- Publish with rounding on and groups off, unless the groups are large and their names are not sensitive.
- Share CSV files under your organisation's data policy. They contain no personal data, but they describe your community.
- Mention on the public page that figures are aggregated and protected. The shortcode adds a short note automatically.

## Related practice

The same pattern, minimum group sizes with frozen and rounded publication, is common in official statistics (small-cell suppression and base-5 rounding in census tables) and in workplace analytics. Microsoft Viva Insights, for example, uses a minimum group size of 5 that cannot be lowered. Community Pulse applies it to community platforms.
