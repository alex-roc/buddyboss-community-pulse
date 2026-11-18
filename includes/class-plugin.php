<?php
namespace AlexROC\CommunityPulse;
defined('ABSPATH') || exit;

/** Restricted WordPress Tools screen and nonce-protected export. */
final class Plugin {
    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_community_pulse_export', [self::class, 'export']);
    }
    public static function menu(): void {
        add_management_page('Community Pulse', 'Community Pulse', 'manage_options', 'community-pulse', [self::class, 'page']);
    }
    public static function authorize(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You cannot access this report.', 'community-pulse'), '', ['response' => 403]);
        }
    }
    private static function month(): string {
        $value = $_GET['month'] ?? gmdate('Y-m');
        return is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
    }
    public static function page(): void {
        self::authorize();
        $month = self::month();
        echo '<div class="wrap"><h1>Community Pulse</h1><p>' . esc_html__('Monthly public participation · UTC · administrators only', 'community-pulse') . '</p>';
        echo '<form method="get"><input type="hidden" name="page" value="community-pulse"><label for="cp-month">' . esc_html__('Month', 'community-pulse') . '</label> <select id="cp-month" name="month">';
        $now = new \DateTimeImmutable('first day of this month', new \DateTimeZone('UTC'));
        for ($i = 0; $i < 12; $i++) {
            $option = $now->modify("-{$i} months")->format('Y-m');
            echo '<option value="' . esc_attr($option) . '" ' . selected($month, $option, false) . '>' . esc_html($option) . '</option>';
        }
        echo '</select> '; submit_button(__('View report', 'community-pulse'), 'secondary', '', false); echo '</form>';
        try { $report = Report::fetch($month); }
        catch (\Throwable $e) { echo '<div class="notice notice-warning"><p>' . esc_html($e->getMessage()) . '</p></div></div>'; return; }
        echo '<p>' . esc_html__('Only public activity updates are counted. Comments, private/hidden groups, messages, reads, logins and spam are excluded. Current month is incomplete. Counts are not proof of impact or anonymisation.', 'community-pulse') . '</p>';
        $summary = $report['summary'];
        if ($summary['suppressed']) {
            echo '<p>' . esc_html__('Summary suppressed: fewer than 5 contributors.', 'community-pulse') . '</p>';
        } else {
            echo '<h2>' . esc_html__('Participation', 'community-pulse') . '</h2><table class="widefat striped"><tbody>';
            foreach (['contributors' => __('Unique contributors', 'community-pulse'), 'contributions' => __('Public updates', 'community-pulse'), 'returning' => __('Contributors on 2+ UTC days', 'community-pulse')] as $key => $label) {
                echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($summary[$key] === null ? __('Suppressed', 'community-pulse') : (string) $summary[$key]) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<h2>' . esc_html__('Public groups with 5+ contributors', 'community-pulse') . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__('Group', 'community-pulse') . '</th><th>' . esc_html__('Contributors', 'community-pulse') . '</th><th>' . esc_html__('Updates', 'community-pulse') . '</th></tr></thead><tbody>';
        foreach ($report['groups'] as $row) {
            echo '<tr><td>' . esc_html($row['name']) . '</td><td>' . esc_html((string) $row['contributors']) . '</td><td>' . esc_html((string) $row['contributions']) . '</td></tr>';
        }
        echo '</tbody></table><p>' . esc_html__('A maximum of 100 qualifying groups is shown. Small groups are omitted. Data is read on demand, never stored or sent to external services.', 'community-pulse') . '</p>';
        if ($report['truncated']) { echo '<p><strong>' . esc_html__('Report truncated at 100 groups.', 'community-pulse') . '</strong></p>'; }
        $url = wp_nonce_url(add_query_arg(['action' => 'community_pulse_export', 'month' => $month], admin_url('admin-post.php')), 'community_pulse_export');
        echo '<a class="button button-primary" href="' . esc_url($url) . '">' . esc_html__('Download aggregate CSV', 'community-pulse') . '</a></div>';
    }
    public static function export(): void {
        self::authorize();
        check_admin_referer('community_pulse_export');
        try { $report = Report::fetch(self::month()); }
        catch (\Throwable $e) { wp_die(esc_html($e->getMessage()), '', ['response' => 400]); return; }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="community-pulse-' . $report['month'] . '.csv"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        self::write_csv($out, $report);
        fclose($out);
        exit;
    }
    /** Export only protected aggregate values, never user identifiers. */
    public static function write_csv($out, array $report): void {
        fputcsv($out, ['month_utc', 'scope', 'contributors', 'public_updates', 'contributors_2plus_days'], ',', '"', '');
        $s = $report['summary'];
        fputcsv($out, [$report['month'], 'overall', $s['contributors'] ?? 'suppressed', $s['contributions'] ?? 'suppressed', $s['returning'] ?? 'suppressed'], ',', '"', '');
        foreach ($report['groups'] as $row) {
            fputcsv($out, [$report['month'], Report::csv_label($row['name']), (int) $row['contributors'], (int) $row['contributions'], 'not_calculated'], ',', '"', '');
        }
        if ($report['truncated']) { fputcsv($out, [$report['month'], 'report_truncated_at_100_groups', '', '', ''], ',', '"', ''); }
    }
}
