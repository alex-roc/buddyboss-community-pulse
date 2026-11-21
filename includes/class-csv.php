<?php
namespace AlexROC\CommunityPulse;
defined('ABSPATH') || exit;

/**
 * Aggregate CSV writers. Only protected values are written, never user identifiers.
 * Files start with a UTF-8 byte order mark so spreadsheet apps keep accents.
 */
final class Csv {
    private static function put($out, array $row): void {
        fputcsv($out, $row, ',', '"', '');
    }

    private static function value(array $summary, string $key) {
        return $summary[$key] ?? 'suppressed';
    }

    /** One month: overall row, one row per public group, optional truncation note. */
    public static function monthly($out, array $report): void {
        fwrite($out, "\xEF\xBB\xBF");
        $partial = !empty($report['partial']) ? 'yes' : 'no';
        self::put($out, ['month_utc', 'partial_month', 'scope', 'group_name', 'contributors', 'public_updates',
            'contributors_2plus_days', 'retained_from_previous_month', 'first_time_contributors']);
        $s = $report['summary'];
        self::put($out, [$report['month'], $partial, 'overall', '', self::value($s, 'contributors'), self::value($s, 'contributions'),
            self::value($s, 'returning'), self::value($s, 'retained'), self::value($s, 'new')]);
        foreach ($report['groups'] as $row) {
            self::put($out, [$report['month'], $partial, 'group', Report::csv_label($row['name']), (int) $row['contributors'],
                (int) $row['contributions'], 'not_calculated', 'not_calculated', 'not_calculated']);
        }
        if ($report['truncated']) {
            self::put($out, [$report['month'], $partial, 'note', 'report_truncated_at_100_groups', '', '', '', '', '']);
        }
    }

    /** Twelve months, one overall row each, oldest first. */
    public static function trend($out, array $trend): void {
        fwrite($out, "\xEF\xBB\xBF");
        self::put($out, ['month_utc', 'partial_month', 'contributors', 'public_updates', 'contributors_2plus_days',
            'retained_from_previous_month', 'first_time_contributors']);
        foreach ($trend['months'] as $m) {
            self::put($out, [$m['month'], $m['partial'] ? 'yes' : 'no', self::value($m, 'contributors'), self::value($m, 'contributions'),
                self::value($m, 'returning'), self::value($m, 'retained'), self::value($m, 'new')]);
        }
    }
}
