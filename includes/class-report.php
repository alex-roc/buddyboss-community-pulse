<?php
namespace AlexROC\CommunityPulse;
defined('ABSPATH') || exit;

/** Monthly, fixed-window aggregate queries. No personal rows leave the database. */
final class Report {
    const THRESHOLD = 5;

    /** Permit only one of the latest 12 calendar months, using UTC throughout. */
    public static function window(string $month, ?\DateTimeImmutable $now = null): array {
        $now = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
        $current = $now->modify('first day of this month')->setTime(0, 0);
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $month, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m') !== $month || $date > $current || $date < $current->modify('-11 months')) {
            throw new \InvalidArgumentException('Select one of the latest 12 UTC calendar months.');
        }
        return [$date->format('Y-m-d H:i:s'), $date->modify('+1 month')->format('Y-m-d H:i:s')];
    }

    /** Normalise DB counts and suppress the entire small-cohort row. */
    public static function protect(array $row): array {
        $contributors = max(0, (int) ($row['contributors'] ?? 0));
        if ($contributors < self::THRESHOLD) {
            return ['suppressed' => true, 'contributors' => null, 'contributions' => null, 'returning' => null];
        }
        return ['suppressed' => false, 'contributors' => $contributors,
            'contributions' => max(0, (int) ($row['contributions'] ?? 0)),
            // A sub-cohort also needs threshold protection, including its complement.
            'returning' => self::safe_subcount((int) ($row['returning'] ?? 0), $contributors)];
    }

    /** Avoid revealing small sub-cohorts or their small complements. */
    public static function safe_subcount(int $value, int $total): ?int {
        if ($value < self::THRESHOLD || $value > $total || ($value !== $total && $total - $value < self::THRESHOLD)) {
            return null;
        }
        return $value;
    }

    /** Prevent spreadsheet formula interpretation of labels in CSV exports. */
    public static function csv_label(string $label): string {
        return preg_match('/^[\s]*[=+@\-]/u', $label) ? "'" . $label : $label;
    }

    /** Query a BuddyBoss/BuddyPress activity table and public groups only. */
    public static function fetch(string $month): array {
        global $wpdb;
        [$start, $end] = self::window($month);
        if (!function_exists('bp_is_active') || !bp_is_active('activity') || !bp_is_active('groups')) {
            throw new \RuntimeException('Activate BuddyBoss Platform or BuddyPress with Activity and Groups.');
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
        // Comments are intentionally excluded: parent visibility can differ from the comment row.
        $where = "a.date_recorded >= %s AND a.date_recorded < %s AND a.is_spam = 0
            AND a.hide_sitewide = 0 AND a.user_id > 0 AND a.type = 'activity_update'
            AND (a.component = 'activity' OR (a.component = 'groups' AND g.status = 'public'))" . $privacy;
        // Aggregate per user *inside SQL*, return only cohort counts to PHP.
        $inner = "SELECT a.user_id, COUNT(*) AS n, COUNT(DISTINCT DATE(a.date_recorded)) AS days
            FROM `{$activity}` a LEFT JOIN `{$groups}` g ON a.component = 'groups' AND a.item_id = g.id
            WHERE {$where} GROUP BY a.user_id";
        $summary = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS contributors, COALESCE(SUM(n),0) AS contributions,
            COALESCE(SUM(days >= 2),0) AS `returning` FROM ({$inner}) p", $start, $end), ARRAY_A);
        if ($wpdb->last_error || !is_array($summary)) {
            throw new \RuntimeException('Could not query activity. Check platform compatibility.');
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT g.name, COUNT(DISTINCT a.user_id) AS contributors, COUNT(*) AS contributions
            FROM `{$activity}` a JOIN `{$groups}` g ON a.component = 'groups' AND a.item_id = g.id
            WHERE {$where} AND g.status = 'public'
            GROUP BY g.id, g.name HAVING COUNT(DISTINCT a.user_id) >= %d ORDER BY g.id ASC LIMIT 101",
            $start, $end, self::THRESHOLD), ARRAY_A);
        if ($wpdb->last_error || !is_array($rows)) {
            throw new \RuntimeException('Could not query public groups.');
        }
        return ['month' => $month, 'summary' => self::protect($summary),
            'groups' => array_slice($rows, 0, 100), 'truncated' => count($rows) > 100,
            'platform' => $buddyboss ? 'BuddyBoss Platform' : 'BuddyPress'];
    }
}
