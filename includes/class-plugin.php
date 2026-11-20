<?php
namespace AlexROC\CommunityPulse;
defined('ABSPATH') || exit;

/** Restricted WordPress Tools screen and nonce-protected export. */
final class Plugin {
    const SLUG = 'community-pulse';
    const EXPORT_ACTION = 'community_pulse_export';

    public static function boot(): void {
        add_action('init', [self::class, 'load_textdomain']);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_' . self::EXPORT_ACTION, [self::class, 'export']);
        add_filter('plugin_action_links_' . plugin_basename(COMMUNITY_PULSE_FILE), [self::class, 'action_links']);
    }

    /**
     * Load bundled translations. Any Spanish locale (es_MX, es_AR, es_BO…) falls
     * back to the bundled es_ES catalogue when no exact match exists.
     */
    public static function load_textdomain(): void {
        $dir = dirname(COMMUNITY_PULSE_FILE) . '/languages';
        $locale = determine_locale();
        $file = "{$dir}/community-pulse-{$locale}.mo";
        if (!is_readable($file) && !is_readable(substr($file, 0, -3) . '.l10n.php') && str_starts_with($locale, 'es_')) {
            $file = "{$dir}/community-pulse-es_ES.mo";
        }
        load_textdomain('community-pulse', $file, $locale);
    }

    /** Capability required to view and export. Filterable for custom reporting roles. */
    public static function capability(): string {
        $cap = apply_filters('community_pulse_capability', 'manage_options');
        return is_string($cap) && $cap !== '' ? $cap : 'manage_options';
    }

    public static function menu(): void {
        add_management_page('Community Pulse', 'Community Pulse', self::capability(), self::SLUG, [self::class, 'page']);
    }

    public static function action_links(array $links): array {
        if (current_user_can(self::capability())) {
            $url = admin_url('tools.php?page=' . self::SLUG);
            $links = ['report' => '<a href="' . esc_url($url) . '">' . esc_html__('View report', 'community-pulse') . '</a>'] + $links;
        }
        return $links;
    }

    public static function authorize(): void {
        if (!current_user_can(self::capability())) {
            wp_die(esc_html__('You cannot access this report.', 'community-pulse'), '', ['response' => 403]);
        }
    }

    private static function month(): string {
        $value = $_GET['month'] ?? gmdate('Y-m');
        return is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
    }

    private static function number(int $value): string {
        return number_format_i18n($value);
    }

    public static function page(): void {
        self::authorize();
        $month = self::month();
        echo '<div class="wrap"><h1>Community Pulse</h1>';
        echo '<p>' . esc_html__('Monthly public participation. Months are UTC calendar months. Only aggregate counts are shown.', 'community-pulse') . '</p>';
        echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '"><label for="cp-month">' . esc_html__('Month', 'community-pulse') . '</label> <select id="cp-month" name="month">';
        $now = new \DateTimeImmutable('first day of this month', new \DateTimeZone('UTC'));
        for ($i = 0; $i < 12; $i++) {
            $option = $now->modify("-{$i} months")->format('Y-m');
            echo '<option value="' . esc_attr($option) . '" ' . selected($month, $option, false) . '>' . esc_html($option) . '</option>';
        }
        echo '</select> ';
        submit_button(__('View report', 'community-pulse'), 'secondary', '', false);
        echo '</form>';
        try {
            $report = Report::fetch($month);
        } catch (\Throwable $e) {
            echo '<div class="notice notice-error inline"><p>' . esc_html(self::error_message($e)) . '</p></div></div>';
            return;
        }
        if ($report['partial']) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('This month is still in progress: counts are partial and will grow until the month ends (UTC).', 'community-pulse') . '</p></div>';
        }

        $summary = $report['summary'];
        echo '<h2>' . esc_html__('Participation', 'community-pulse') . '</h2>';
        if ($summary['suppressed']) {
            echo '<p>' . esc_html__('Summary hidden: fewer than 5 people posted public updates this month.', 'community-pulse') . '</p>';
        } else {
            echo '<table class="widefat striped" style="max-width:40em"><tbody>';
            $rows = [
                'contributors' => [__('Unique contributors', 'community-pulse'), __('People who posted at least one public update.', 'community-pulse')],
                'contributions' => [__('Public updates', 'community-pulse'), __('Public activity updates posted (comments not included).', 'community-pulse')],
                'returning' => [__('Contributors on 2+ days', 'community-pulse'), __('People who posted on at least two different UTC dates. Hidden if this count, or the people outside it, is under 5.', 'community-pulse')],
            ];
            foreach ($rows as $key => [$label, $help]) {
                $value = $summary[$key] === null ? __('Hidden', 'community-pulse') : self::number($summary[$key]);
                echo '<tr><th scope="row">' . esc_html($label) . '<br><span class="description">' . esc_html($help) . '</span></th><td><strong>' . esc_html($value) . '</strong></td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h2>' . esc_html__('Public groups with 5+ contributors', 'community-pulse') . '</h2>';
        if (!$report['groups']) {
            echo '<p>' . esc_html__('No public group reached 5 contributors this month.', 'community-pulse') . '</p>';
        } else {
            echo '<table class="widefat striped" style="max-width:40em"><thead><tr><th scope="col">' . esc_html__('Group', 'community-pulse') . '</th><th scope="col">' . esc_html__('Contributors', 'community-pulse') . '</th><th scope="col">' . esc_html__('Updates', 'community-pulse') . '</th></tr></thead><tbody>';
            foreach ($report['groups'] as $row) {
                echo '<tr><td>' . esc_html($row['name']) . '</td><td>' . esc_html(self::number($row['contributors'])) . '</td><td>' . esc_html(self::number($row['contributions'])) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '<p class="description">' . esc_html__('Group rows overlap: one person can post in several groups, so do not add them up to get unique contributors.', 'community-pulse') . '</p>';
        }
        if ($report['truncated']) {
            echo '<p><strong>' . esc_html__('Only the first 100 qualifying groups are shown.', 'community-pulse') . '</strong></p>';
        }

        $url = wp_nonce_url(add_query_arg(['action' => self::EXPORT_ACTION, 'month' => $report['month']], admin_url('admin-post.php')), self::EXPORT_ACTION);
        echo '<p><a class="button button-primary" href="' . esc_url($url) . '">' . esc_html__('Download aggregate CSV', 'community-pulse') . '</a></p>';
        echo '<h2>' . esc_html__('What is counted', 'community-pulse') . '</h2><ul style="list-style:disc;padding-left:2em">';
        foreach ([
            __('Counted: public activity updates on profiles and in public groups.', 'community-pulse'),
            __('Not counted: comments, private or hidden groups, hidden or spam activity, scheduled or non-public posts, messages, reads and logins.', 'community-pulse'),
            __('Any count based on fewer than 5 people is hidden. This reduces exposure but is not an anonymity guarantee.', 'community-pulse'),
            __('Data is read on demand: nothing is stored and nothing is sent to external services.', 'community-pulse'),
            __('These counts describe public posting, not readers, impact or quality of participation.', 'community-pulse'),
        ] as $line) {
            echo '<li>' . esc_html($line) . '</li>';
        }
        echo '</ul><p class="description">' . esc_html(sprintf(
            /* translators: 1: platform name, 2: window start, 3: window end. */
            __('Source: %1$s · window %2$s to %3$s UTC (end excluded).', 'community-pulse'),
            $report['platform'], $report['start'], $report['end']
        )) . '</p></div>';
    }

    private static function error_message(\Throwable $e): string {
        if ($e instanceof \InvalidArgumentException) {
            return __('Select one of the latest 12 UTC calendar months.', 'community-pulse');
        }
        if ($e instanceof PlatformMissing) {
            return __('Activate BuddyBoss Platform or BuddyPress with the Activity and Groups components.', 'community-pulse');
        }
        return __('Could not read community activity. Check that BuddyBoss Platform or BuddyPress is up to date.', 'community-pulse');
    }

    public static function export(): void {
        self::authorize();
        check_admin_referer(self::EXPORT_ACTION);
        try {
            $report = Report::fetch(self::month());
        } catch (\Throwable $e) {
            wp_die(esc_html(self::error_message($e)), '', ['response' => 400]);
        }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="community-pulse-' . $report['month'] . '.csv"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        self::write_csv($out, $report);
        fclose($out);
        exit;
    }

    /**
     * Export only protected aggregate values, never user identifiers.
     * Starts with a UTF-8 byte order mark so spreadsheet apps keep accents.
     */
    public static function write_csv($out, array $report): void {
        fwrite($out, "\xEF\xBB\xBF");
        $partial = !empty($report['partial']) ? 'yes' : 'no';
        $put = static fn(array $row) => fputcsv($out, $row, ',', '"', '');
        $put(['month_utc', 'partial_month', 'scope', 'group_name', 'contributors', 'public_updates', 'contributors_2plus_days']);
        $s = $report['summary'];
        $put([$report['month'], $partial, 'overall', '', $s['contributors'] ?? 'suppressed', $s['contributions'] ?? 'suppressed', $s['returning'] ?? 'suppressed']);
        foreach ($report['groups'] as $row) {
            $put([$report['month'], $partial, 'group', Report::csv_label($row['name']), (int) $row['contributors'], (int) $row['contributions'], 'not_calculated']);
        }
        if ($report['truncated']) {
            $put([$report['month'], $partial, 'note', 'report_truncated_at_100_groups', '', '', '']);
        }
    }
}
