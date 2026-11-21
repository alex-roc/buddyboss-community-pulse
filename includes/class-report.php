<?php
namespace AlexROC\CommunityPulse;
defined('ABSPATH') || exit;

/** Raised when BuddyBoss Platform/BuddyPress or a required component is inactive. */
final class PlatformMissing extends \RuntimeException {}

/** Monthly, fixed-window aggregate queries. No personal rows leave the database. */
final class Report {
    /** The smallest cohort ever shown. The threshold can be raised, never lowered. */
    const MIN_THRESHOLD = 5;
    /** Kept for backwards compatibility; use threshold(). */
    const THRESHOLD = self::MIN_THRESHOLD;

    /** Active threshold: the community_pulse_threshold filter, floored at MIN_THRESHOLD. */
    public static function threshold(): int {
        $k = function_exists('apply_filters') ? apply_filters('community_pulse_threshold', self::MIN_THRESHOLD) : self::MIN_THRESHOLD;
        return max(self::MIN_THRESHOLD, is_numeric($k) ? (int) $k : self::MIN_THRESHOLD);
    }

    private static function utc(?\DateTimeImmutable $now): \DateTimeImmutable {
        return ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
    }

    /** The selectable months, newest first: the current UTC month and the 11 before it. */
    public static function selectable_months(?\DateTimeImmutable $now = null): array {
        $current = self::utc($now)->modify('first day of this month')->setTime(0, 0);
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[] = $current->modify("-{$i} months")->format('Y-m');
        }
        return $months;
    }

    /** Permit only one of the latest 12 calendar months, using UTC throughout. */
    public static function window(string $month, ?\DateTimeImmutable $now = null): array {
        $current = self::utc($now)->modify('first day of this month')->setTime(0, 0);
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $month, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m') !== $month || $date > $current || $date < $current->modify('-11 months')) {
            throw new \InvalidArgumentException('Select one of the latest 12 UTC calendar months.');
        }
        return [$date->format('Y-m-d H:i:s'), $date->modify('+1 month')->format('Y-m-d H:i:s')];
    }

    /**
     * Normalise DB counts and suppress small cohorts.
     *
     * $row: contributors, contributions, returning, retained, new (raw counts).
     * $previous: raw contributor count of the month before, needed to protect
     * "retained" against its complement in that month (people who stopped).
     */
    public static function protect(array $row, ?int $previous = null, ?int $k = null): array {
        $k ??= self::threshold();
        $contributors = max(0, (int) ($row['contributors'] ?? 0));
        if ($contributors < $k) {
            return ['suppressed' => true, 'contributors' => null, 'contributions' => null,
                'returning' => null, 'retained' => null, 'new' => null];
        }
        $retained = null;
        if ($previous !== null && array_key_exists('retained', $row)) {
            $value = (int) $row['retained'];
            // Retained is a subset of this month AND of last month: protect both complements.
            if (self::safe_subcount($value, $contributors, $k) !== null && self::safe_subcount($value, $previous, $k) !== null) {
                $retained = $value;
            }
        }
        return ['suppressed' => false, 'contributors' => $contributors,
            'contributions' => max(0, (int) ($row['contributions'] ?? 0)),
            // A sub-cohort also needs threshold protection, including its complement.
            'returning' => self::safe_subcount((int) ($row['returning'] ?? 0), $contributors, $k),
            'retained' => $retained,
            'new' => array_key_exists('new', $row) ? self::safe_subcount((int) $row['new'], $contributors, $k) : null];
    }

    /** Avoid revealing small sub-cohorts or their small complements. */
    public static function safe_subcount(int $value, int $total, ?int $k = null): ?int {
        $k ??= self::threshold();
        if ($value < $k || $value > $total || ($value !== $total && $total - $value < $k)) {
            return null;
        }
        return $value;
    }

    /**
     * Turn a stored group name into plain text, the way the platform displays it.
     * BuddyBoss/BuddyPress store names slashed and entity-encoded and apply
     * stripslashes and html_entity_decode on output (bp_get_group_name).
     */
    public static function group_label(string $raw): string {
        $plain = html_entity_decode(strip_tags(stripslashes($raw)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
    }

    /** True when the month has not finished yet (UTC), so its counts are partial. */
    public static function is_partial(string $month, ?\DateTimeImmutable $now = null): bool {
        return $month === self::utc($now)->format('Y-m');
    }

    /** Prevent spreadsheet formula interpretation of labels in CSV exports. */
    public static function csv_label(string $label): string {
        // OWASP CSV injection: leading = + - @, or a leading tab/carriage return.
        return preg_match('/^(?:[\t\r]|\s*[=+@\-])/u', $label) ? "'" . $label : $label;
    }

    /** Platform tables and the shared eligibility clause (two %s placeholders: start, end). */
    private static function source(): array {
        if (!function_exists('bp_is_active') || !bp_is_active('activity') || !bp_is_active('groups')) {
            throw new PlatformMissing('Activate BuddyBoss Platform or BuddyPress with Activity and Groups.');
        }
        $bp = buddypress();
        $activity = $bp->activity->table_name;
        $groups = $bp->groups->table_name;
        // Identifiers are supplied by the platform, never from request parameters.
        foreach ([$activity, $groups] as $table) {
            if (!is_string($table) || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                throw new \RuntimeException('Unsupported database table identifier.');
            }
        }
        $buddyboss = defined('BP_PLATFORM_VERSION');
        $privacy = $buddyboss ? " AND a.privacy = 'public' AND a.status = 'published'" : '';
        $from = "`{$activity}` a LEFT JOIN `{$groups}` g ON a.component = 'groups' AND a.item_id = g.id";
        // Comments are intentionally excluded: parent visibility can differ from the comment row.
        $eligible = "a.is_spam = 0 AND a.hide_sitewide = 0 AND a.user_id > 0 AND a.type = 'activity_update'
            AND (a.component = 'activity' OR (a.component = 'groups' AND g.status = 'public'))" . $privacy;
        return [$from, $eligible, $activity, $groups, $buddyboss ? 'BuddyBoss Platform' : 'BuddyPress'];
    }

    /**
     * Raw monthly counts for every month in [$from, $to] (both 'Y-m', validated by the caller).
     * Two aggregate queries; user IDs stay inside SQL. Returns month => counts, plus the
     * raw contributor count of the month before each one (for complement protection).
     */
    public static function months(string $from, string $to): array {
        global $wpdb;
        [$fromSql, $eligible] = self::source();
        $utc = new \DateTimeZone('UTC');
        $first = \DateTimeImmutable::createFromFormat('!Y-m', $from, $utc);
        $last = \DateTimeImmutable::createFromFormat('!Y-m', $to, $utc);
        $base = $first->modify('-1 month'); // bit 0 = month before $from
        $end = $last->modify('+1 month');
        $count = (int) (($last->format('Y') - $base->format('Y')) * 12 + $last->format('n') - $base->format('n')) + 1;
        if ($count < 2 || $count > 13) {
            throw new \InvalidArgumentException('Invalid month range.');
        }

        // 1. Per-month contributors, updates and 2+ day contributors.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m, COUNT(*) AS contributors, COALESCE(SUM(n),0) AS contributions, COALESCE(SUM(days >= 2),0) AS `returning`
            FROM (SELECT a.user_id, LEFT(a.date_recorded, 7) AS m, COUNT(*) AS n, COUNT(DISTINCT DATE(a.date_recorded)) AS days
                FROM {$fromSql} WHERE a.date_recorded >= %s AND a.date_recorded < %s AND {$eligible}
                GROUP BY a.user_id, m) p GROUP BY m",
            $first->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')), ARRAY_A);
        if ($wpdb->last_error || !is_array($rows)) {
            throw new \RuntimeException('Could not query activity. Check platform compatibility.');
        }

        // 2. Retention and first-time contributors: one bitmask of active months per user
        //    and the date of their first eligible update ever, aggregated per month in SQL.
        $cols = [];
        $selectArgs = [];
        for ($i = 0; $i < $count; $i++) {
            $cols[] = "COALESCE(SUM((mask >> {$i}) & 1),0) AS c{$i}";
            if ($i > 0) {
                $prev = $i - 1;
                $cols[] = "COALESCE(SUM(((mask >> {$i}) & 1) AND ((mask >> {$prev}) & 1)),0) AS r{$i}";
                $cols[] = "COALESCE(SUM(((mask >> {$i}) & 1) AND first_seen >= %s),0) AS n{$i}";
                $selectArgs[] = $base->modify("+{$i} months")->format('Y-m-d H:i:s');
            }
        }
        $sql = "SELECT " . implode(', ', $cols) . " FROM (
                SELECT a.user_id, MIN(a.date_recorded) AS first_seen,
                    BIT_OR(IF(a.date_recorded >= %s, 1 << PERIOD_DIFF(EXTRACT(YEAR_MONTH FROM a.date_recorded), %d), 0)) AS mask
                FROM {$fromSql} WHERE a.date_recorded < %s AND {$eligible} GROUP BY a.user_id) u";
        $ordered = array_merge($selectArgs, [$base->format('Y-m-d H:i:s'), (int) $base->format('Ym'), $end->format('Y-m-d H:i:s')]);
        $cohort = $wpdb->get_row($wpdb->prepare($sql, $ordered), ARRAY_A);
        if ($wpdb->last_error || !is_array($cohort)) {
            throw new \RuntimeException('Could not query activity cohorts.');
        }

        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[$row['m']] = $row;
        }
        $result = [];
        for ($i = 1; $i < $count; $i++) {
            $month = $base->modify("+{$i} months")->format('Y-m');
            $row = $byMonth[$month] ?? ['contributors' => 0, 'contributions' => 0, 'returning' => 0];
            $result[$month] = [
                'contributors' => (int) $row['contributors'],
                'contributions' => (int) $row['contributions'],
                'returning' => (int) $row['returning'],
                'retained' => (int) $cohort["r{$i}"],
                'new' => (int) $cohort["n{$i}"],
                'previous' => (int) $cohort['c' . ($i - 1)],
            ];
        }
        return $result;
    }

    /** One month: protected summary plus public groups with enough contributors. */
    public static function fetch(string $month): array {
        global $wpdb;
        [$start, $end] = self::window($month);
        [$fromSql, $eligible, $activity, $groups, $platform] = self::source();
        $k = self::threshold();
        $raw = self::months($month, $month)[$month];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT g.name, COUNT(DISTINCT a.user_id) AS contributors, COUNT(*) AS contributions
            FROM `{$activity}` a JOIN `{$groups}` g ON a.component = 'groups' AND a.item_id = g.id
            WHERE a.date_recorded >= %s AND a.date_recorded < %s AND {$eligible} AND g.status = 'public'
            GROUP BY g.id, g.name HAVING COUNT(DISTINCT a.user_id) >= %d ORDER BY g.id ASC LIMIT 101",
            $start, $end, $k), ARRAY_A);
        if ($wpdb->last_error || !is_array($rows)) {
            throw new \RuntimeException('Could not query public groups.');
        }
        $groupRows = array_map(static fn(array $row): array => [
            'name' => self::group_label((string) $row['name']),
            'contributors' => (int) $row['contributors'],
            'contributions' => (int) $row['contributions'],
        ], array_slice($rows, 0, 100));
        return ['month' => $month, 'start' => $start, 'end' => $end, 'partial' => self::is_partial($month),
            'summary' => self::protect($raw, $raw['previous'], $k), 'groups' => $groupRows,
            'truncated' => count($rows) > 100, 'platform' => $platform, 'threshold' => $k];
    }

    /** The 12 selectable months, oldest first, each protected. */
    public static function trend(?\DateTimeImmutable $now = null): array {
        $months = array_reverse(self::selectable_months($now));
        $k = self::threshold();
        $raw = self::months($months[0], end($months));
        $out = [];
        foreach ($raw as $month => $row) {
            $out[] = ['month' => $month, 'partial' => self::is_partial($month, $now)] + self::protect($row, $row['previous'], $k);
        }
        return ['months' => $out, 'threshold' => $k, 'platform' => self::source()[4]];
    }
}
