<?php
namespace AlexROC\CommunityPulse;
defined('ABSPATH') || exit;

/**
 * Published reports: frozen, already-protected aggregates that an administrator chose
 * to make public. Public pages read only these, never the live activity table, so a
 * visitor cannot re-query a month, watch numbers change, or difference two readings.
 */
final class Snapshots {
    const OPTION = 'community_pulse_snapshots';
    const ROUND_BASE = 5;

    /** All published snapshots, newest month first. */
    public static function all(): array {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        krsort($stored, SORT_STRING);
        return $stored;
    }

    public static function get(string $month): ?array {
        return self::all()[$month] ?? null;
    }

    public static function latest(): ?array {
        $all = self::all();
        return $all ? reset($all) : null;
    }

    /** Only finished months can be published: a partial month would leak daily changes. */
    public static function publish(string $month, bool $groups, bool $round): array {
        if (Report::is_partial($month)) {
            throw new \InvalidArgumentException('Only finished months can be published.');
        }
        $snapshot = self::build(Report::fetch($month), $groups, $round, gmdate('Y-m-d H:i:s'));
        $all = self::all();
        $all[$month] = $snapshot;
        update_option(self::OPTION, $all, false);
        return $snapshot;
    }

    public static function unpublish(string $month): void {
        $all = self::all();
        unset($all[$month]);
        if ($all) {
            update_option(self::OPTION, $all, false);
        } else {
            delete_option(self::OPTION);
        }
    }

    /** Pure transformation from a protected report to a publishable snapshot. */
    public static function build(array $report, bool $groups, bool $round, string $publishedAt): array {
        $r = static fn($v) => $round ? self::round_base($v) : $v;
        $s = $report['summary'];
        $summary = ['suppressed' => (bool) $s['suppressed']];
        foreach (['contributors', 'contributions', 'returning', 'retained', 'new'] as $key) {
            $summary[$key] = isset($s[$key]) ? $r((int) $s[$key]) : null;
        }
        $snapshot = [
            'month' => $report['month'],
            'published_at' => $publishedAt,
            'rounded' => $round,
            'threshold' => (int) ($report['threshold'] ?? Report::MIN_THRESHOLD),
            'platform' => (string) ($report['platform'] ?? ''),
            'summary' => $summary,
            'groups' => [],
            'groups_included' => $groups,
            'truncated' => false,
        ];
        if ($groups && !$summary['suppressed']) {
            foreach ($report['groups'] as $row) {
                $snapshot['groups'][] = ['name' => (string) $row['name'],
                    'contributors' => $r((int) $row['contributors']), 'contributions' => $r((int) $row['contributions'])];
            }
            $snapshot['truncated'] = (bool) $report['truncated'];
        }
        return $snapshot;
    }

    /**
     * Round to the nearest multiple of 5 (ties up). A protected value is already ≥ 5,
     * so it never rounds to 0. Rounding blurs differences between published figures
     * (overall vs. groups, one month vs. the next) by up to ±2.
     */
    public static function round_base(?int $value): ?int {
        if ($value === null) {
            return null;
        }
        return max(self::ROUND_BASE, (int) (floor(($value + self::ROUND_BASE / 2) / self::ROUND_BASE) * self::ROUND_BASE));
    }
}
