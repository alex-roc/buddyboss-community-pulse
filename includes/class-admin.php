<?php
namespace AlexROC\CommunityPulse;
defined('ABSPATH') || exit;

/** Tools → Community Pulse: monthly report, 12-month trend and public reports. */
final class Admin {
    const EXPORT_ACTION = 'community_pulse_export';
    const PUBLISH_ACTION = 'community_pulse_publish';

    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_' . self::EXPORT_ACTION, [self::class, 'export']);
        add_action('admin_post_' . self::PUBLISH_ACTION, [self::class, 'publish']);
    }

    public static function menu(): void {
        add_management_page('Community Pulse', 'Community Pulse', Plugin::capability(), Plugin::SLUG, [self::class, 'page']);
    }

    public static function url(array $args = []): string {
        return add_query_arg(array_merge(['page' => Plugin::SLUG], $args), admin_url('tools.php'));
    }

    private static function month(string $source = 'GET'): string {
        $input = $source === 'POST' ? $_POST : $_GET;
        $value = $input['month'] ?? gmdate('Y-m');
        return is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
    }

    private static function tab(): string {
        $tab = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'report';
        return in_array($tab, ['report', 'trend', 'public'], true) ? $tab : 'report';
    }

    private static function number(?int $value): string {
        return $value === null ? __('Hidden', 'community-pulse') : number_format_i18n($value);
    }

    public static function error_message(\Throwable $e): string {
        if ($e instanceof \InvalidArgumentException) {
            return __('Select one of the latest 12 UTC calendar months.', 'community-pulse');
        }
        if ($e instanceof PlatformMissing) {
            return __('Activate BuddyBoss Platform or BuddyPress with the Activity and Groups components.', 'community-pulse');
        }
        return __('Could not read community activity. Check that BuddyBoss Platform or BuddyPress is up to date.', 'community-pulse');
    }

    /** Indicator label and help text, shared by the report and trend views. */
    public static function indicators(): array {
        return [
            'contributors' => [__('Unique contributors', 'community-pulse'), __('People who posted at least one public update.', 'community-pulse')],
            'contributions' => [__('Public updates', 'community-pulse'), __('Public activity updates posted (comments not included).', 'community-pulse')],
            'returning' => [__('Contributors on 2+ days', 'community-pulse'), __('People who posted on at least two different UTC dates.', 'community-pulse')],
            'retained' => [__('Retained from previous month', 'community-pulse'), __('People who posted this month and also the month before.', 'community-pulse')],
            'new' => [__('First-time contributors', 'community-pulse'), __('People whose first public update in the community was this month.', 'community-pulse')],
        ];
    }

    public static function page(): void {
        Plugin::authorize();
        $tab = self::tab();
        echo '<div class="wrap community-pulse-admin"><h1>Community Pulse</h1>';
        self::print_styles();
        echo '<nav class="nav-tab-wrapper cp-noprint">';
        foreach (['report' => __('Monthly report', 'community-pulse'), 'trend' => __('12-month trend', 'community-pulse'), 'public' => __('Public reports', 'community-pulse')] as $key => $label) {
            echo '<a href="' . esc_url(self::url(['tab' => $key])) . '" class="nav-tab' . ($tab === $key ? ' nav-tab-active' : '') . '"' . ($tab === $key ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        self::notice();
        match ($tab) {
            'trend' => self::trend_tab(),
            'public' => self::public_tab(),
            default => self::report_tab(),
        };
        echo '</div>';
    }

    private static function print_styles(): void {
        echo '<style>
            .community-pulse-admin .cp-table{max-width:46em}
            .community-pulse-admin .cp-table td{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
            .community-pulse-admin .cp-trend{max-width:60em}
            .community-pulse-admin .cp-trend th[scope=row]{white-space:nowrap}
            .community-pulse-admin .cp-chart{max-width:46em;margin:1em 0}
            .community-pulse-admin .cp-chart svg{width:100%;height:auto}
            .community-pulse-admin .cp-actions{display:flex;gap:.5em;flex-wrap:wrap;margin:1em 0}
            .community-pulse-admin .cp-shortcode{user-select:all}
            .community-pulse-admin .cp-publish{max-width:70em}
            .community-pulse-admin .cp-publish th[scope=row]{white-space:nowrap;width:6em}
            .community-pulse-admin .cp-publish td{vertical-align:middle}
            .community-pulse-admin .cp-publish form{display:flex;gap:1em;align-items:center;flex-wrap:wrap;margin:0}
            @media print{#adminmenumain,#wpadminbar,#wpfooter,.cp-noprint,.notice:not(.inline),.updated,.error:not(.inline),.update-nag{display:none!important}
              #wpcontent,#wpbody-content{margin:0!important;padding:0!important}html.wp-toolbar{padding:0!important}
              html,body,#wpwrap,#wpcontent{background:#fff!important}}
        </style>';
    }

    private static function notice(): void {
        $code = isset($_GET['cp_notice']) && is_string($_GET['cp_notice']) ? sanitize_key(wp_unslash($_GET['cp_notice'])) : '';
        $messages = [
            'published' => ['success', __('Report published. Pages using the shortcode now show it.', 'community-pulse')],
            'unpublished' => ['success', __('Report unpublished. It no longer appears on public pages.', 'community-pulse')],
            'error' => ['error', __('The report could not be published. Only finished months can be published.', 'community-pulse')],
        ];
        if (isset($messages[$code])) {
            echo '<div class="notice notice-' . esc_attr($messages[$code][0]) . ' is-dismissible"><p>' . esc_html($messages[$code][1]) . '</p></div>';
        }
    }

    private static function month_form(string $month): void {
        echo '<form method="get" class="cp-noprint"><input type="hidden" name="page" value="' . esc_attr(Plugin::SLUG) . '"><label for="cp-month">' . esc_html__('Month', 'community-pulse') . '</label> <select id="cp-month" name="month">';
        foreach (Report::selectable_months() as $option) {
            echo '<option value="' . esc_attr($option) . '" ' . selected($month, $option, false) . '>' . esc_html($option) . '</option>';
        }
        echo '</select> ';
        submit_button(__('View report', 'community-pulse'), 'secondary', '', false);
        echo '</form>';
    }

    private static function report_tab(): void {
        $month = self::month();
        echo '<p>' . esc_html__('Monthly public participation. Months are UTC calendar months. Only aggregate counts are shown.', 'community-pulse') . '</p>';
        self::month_form($month);
        try {
            $report = Report::fetch($month);
        } catch (\Throwable $e) {
            echo '<div class="notice notice-error inline"><p>' . esc_html(self::error_message($e)) . '</p></div>';
            return;
        }
        if ($report['partial']) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('This month is still in progress: counts are partial and will grow until the month ends (UTC).', 'community-pulse') . '</p></div>';
        }
        $summary = $report['summary'];
        echo '<h2>' . esc_html(sprintf(
            /* translators: %s: month, YYYY-MM. */
            __('Participation in %s', 'community-pulse'), $report['month'])) . '</h2>';
        if ($summary['suppressed']) {
            echo '<p>' . esc_html(sprintf(
                /* translators: %d: privacy threshold. */
                __('Summary hidden: fewer than %d people posted public updates this month.', 'community-pulse'), $report['threshold'])) . '</p>';
        } else {
            echo '<table class="widefat striped cp-table"><tbody>';
            foreach (self::indicators() as $key => [$label, $help]) {
                echo '<tr><th scope="row">' . esc_html($label) . '<br><span class="description">' . esc_html($help) . '</span></th><td><strong>' . esc_html(self::number($summary[$key])) . '</strong></td></tr>';
            }
            echo '</tbody></table>';
            echo '<p class="description">' . esc_html(sprintf(
                /* translators: %d: privacy threshold. */
                __('"Hidden" means the figure, or the people it leaves out, is under %d.', 'community-pulse'), $report['threshold'])) . '</p>';
        }

        echo '<h2>' . esc_html(sprintf(
            /* translators: %d: privacy threshold. */
            __('Public groups with %d+ contributors', 'community-pulse'), $report['threshold'])) . '</h2>';
        if (!$report['groups']) {
            echo '<p>' . esc_html__('No public group reached the threshold this month.', 'community-pulse') . '</p>';
        } else {
            echo '<table class="widefat striped cp-table"><thead><tr><th scope="col">' . esc_html__('Group', 'community-pulse') . '</th><th scope="col">' . esc_html__('Contributors', 'community-pulse') . '</th><th scope="col">' . esc_html__('Updates', 'community-pulse') . '</th></tr></thead><tbody>';
            foreach ($report['groups'] as $row) {
                echo '<tr><th scope="row">' . esc_html($row['name']) . '</th><td>' . esc_html(number_format_i18n($row['contributors'])) . '</td><td>' . esc_html(number_format_i18n($row['contributions'])) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '<p class="description">' . esc_html__('Group rows overlap: one person can post in several groups, so do not add them up to get unique contributors.', 'community-pulse') . '</p>';
        }
        if ($report['truncated']) {
            echo '<p><strong>' . esc_html__('Only the first 100 qualifying groups are shown.', 'community-pulse') . '</strong></p>';
        }

        $csv = wp_nonce_url(add_query_arg(['action' => self::EXPORT_ACTION, 'month' => $report['month']], admin_url('admin-post.php')), self::EXPORT_ACTION);
        echo '<div class="cp-actions cp-noprint"><a class="button button-primary" href="' . esc_url($csv) . '">' . esc_html__('Download aggregate CSV', 'community-pulse') . '</a>';
        echo '<button type="button" class="button" onclick="window.print()">' . esc_html__('Print or save as PDF', 'community-pulse') . '</button>';
        if (!$report['partial'] && current_user_can(Plugin::publish_capability())) {
            echo '<a class="button" href="' . esc_url(self::url(['tab' => 'public'])) . '">' . esc_html__('Publish this month…', 'community-pulse') . '</a>';
        }
        echo '</div>';
        self::counting_rules($report['threshold']);
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: 1: platform name, 2: window start, 3: window end. */
            __('Source: %1$s · window %2$s to %3$s UTC (end excluded).', 'community-pulse'),
            $report['platform'], $report['start'], $report['end']
        )) . '</p>';
    }

    private static function counting_rules(int $k): void {
        echo '<h2>' . esc_html__('What is counted', 'community-pulse') . '</h2><ul style="list-style:disc;padding-left:2em">';
        foreach ([
            __('Counted: public activity updates on profiles and in public groups.', 'community-pulse'),
            __('Not counted: comments, private or hidden groups, hidden or spam activity, scheduled or non-public posts, messages, reads and logins.', 'community-pulse'),
            /* translators: %1$d: privacy threshold. */
            sprintf(__('Any figure based on fewer than %1$d people, or that would reveal fewer than %1$d people by subtraction, is hidden. This reduces exposure but is not an anonymity guarantee.', 'community-pulse'), $k),
            __('Data is read on demand: nothing is stored unless you publish a report, and nothing is sent to external services.', 'community-pulse'),
            __('These counts describe public posting, not readers, impact or quality of participation.', 'community-pulse'),
        ] as $line) {
            echo '<li>' . esc_html($line) . '</li>';
        }
        echo '</ul>';
    }

    private static function trend_tab(): void {
        echo '<p>' . esc_html__('The last 12 UTC calendar months, oldest first. The current month is still in progress.', 'community-pulse') . '</p>';
        try {
            $trend = Report::trend();
        } catch (\Throwable $e) {
            echo '<div class="notice notice-error inline"><p>' . esc_html(self::error_message($e)) . '</p></div>';
            return;
        }
        echo '<h2>' . esc_html__('Unique contributors per month', 'community-pulse') . '</h2>';
        echo '<div class="cp-chart">' . self::chart($trend['months']) . '</div>';
        $indicators = self::indicators();
        echo '<table class="widefat striped cp-table cp-trend"><thead><tr><th scope="col">' . esc_html__('Month', 'community-pulse') . '</th>';
        foreach ($indicators as [$label]) {
            echo '<th scope="col">' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($trend['months'] as $m) {
            echo '<tr><th scope="row">' . esc_html($m['month']) . ($m['partial'] ? ' <span class="description">(' . esc_html__('in progress', 'community-pulse') . ')</span>' : '') . '</th>';
            foreach (array_keys($indicators) as $key) {
                echo '<td>' . esc_html(self::number($m[$key])) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: %d: privacy threshold. */
            __('"Hidden" means the figure, or the people it leaves out, is under %d.', 'community-pulse'), $trend['threshold'])) . '</p>';
        $csv = wp_nonce_url(add_query_arg(['action' => self::EXPORT_ACTION, 'scope' => 'trend'], admin_url('admin-post.php')), self::EXPORT_ACTION);
        echo '<div class="cp-actions cp-noprint"><a class="button button-primary" href="' . esc_url($csv) . '">' . esc_html__('Download 12-month CSV', 'community-pulse') . '</a>';
        echo '<button type="button" class="button" onclick="window.print()">' . esc_html__('Print or save as PDF', 'community-pulse') . '</button></div>';
    }

    /** Accessible inline SVG bar chart; the table below carries the same data. */
    public static function chart(array $months): string {
        $w = 720; $h = 220; $top = 24; $bottom = 32; $left = 8;
        $values = array_map(static fn($m) => $m['contributors'] ?? 0, $months);
        $max = max(1, ...$values);
        $slot = ($w - $left * 2) / max(1, count($months));
        $bar = $slot * 0.62;
        $plot = $h - $top - $bottom;
        $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-labelledby="cp-chart-title"><title id="cp-chart-title">' . esc_html__('Unique contributors per month; the table below has the exact figures.', 'community-pulse') . '</title>';
        $svg .= '<line x1="0" x2="' . $w . '" y1="' . ($h - $bottom) . '" y2="' . ($h - $bottom) . '" stroke="#c3c4c7"/>';
        foreach ($months as $i => $m) {
            $x = $left + $i * $slot + ($slot - $bar) / 2;
            $cx = $x + $bar / 2;
            $svg .= '<text x="' . round($cx, 1) . '" y="' . ($h - 12) . '" text-anchor="middle" font-size="11" fill="#50575e">' . esc_html(substr($m['month'], 2)) . '</text>';
            if ($m['contributors'] === null) {
                $svg .= '<text x="' . round($cx, 1) . '" y="' . ($h - $bottom - 6) . '" text-anchor="middle" font-size="11" fill="#8c8f94">' . esc_html__('hidden', 'community-pulse') . '</text>';
                continue;
            }
            $bh = max(2, $plot * $m['contributors'] / $max);
            $y = $h - $bottom - $bh;
            $fill = $m['partial'] ? '#9ec2e6' : '#2271b1';
            $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bar, 1) . '" height="' . round($bh, 1) . '" rx="2" fill="' . $fill . '"/>';
            $svg .= '<text x="' . round($cx, 1) . '" y="' . round($y - 6, 1) . '" text-anchor="middle" font-size="11" font-weight="600" fill="#1d2327">' . esc_html(number_format_i18n($m['contributors'])) . '</text>';
        }
        return $svg . '</svg>';
    }

    private static function public_tab(): void {
        $canPublish = current_user_can(Plugin::publish_capability());
        echo '<p>' . esc_html__('Publish a finished month to show it on any page with a shortcode, for example in an annual report or a page for funders. Published reports are frozen copies of the protected figures: visitors never query live activity, and the numbers do not change until you update or unpublish them.', 'community-pulse') . '</p>';
        $published = Snapshots::all();
        $current = gmdate('Y-m');
        echo '<table class="widefat striped cp-publish"><thead><tr><th scope="col">' . esc_html__('Month', 'community-pulse') . '</th><th scope="col">' . esc_html__('Status', 'community-pulse') . '</th><th scope="col">' . esc_html__('Actions', 'community-pulse') . '</th></tr></thead><tbody>';
        foreach (Report::selectable_months() as $month) {
            if ($month === $current) {
                continue;
            }
            $snap = $published[$month] ?? null;
            echo '<tr><th scope="row">' . esc_html($month) . '</th><td>';
            if ($snap) {
                echo esc_html(sprintf(
                    /* translators: %s: date and time in UTC. */
                    __('Published %s UTC', 'community-pulse'), $snap['published_at']));
                $flags = [];
                if ($snap['rounded']) { $flags[] = __('rounded to 5', 'community-pulse'); }
                if ($snap['groups_included']) { $flags[] = __('with groups', 'community-pulse'); }
                if ($flags) { echo '<br><span class="description">' . esc_html(implode(' · ', $flags)) . '</span>'; }
            } else {
                echo '<span class="description">' . esc_html__('Not published', 'community-pulse') . '</span>';
            }
            echo '</td><td>';
            if ($canPublish) {
                self::publish_form($month, $snap);
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        $older = array_diff_key($published, array_flip(Report::selectable_months()));
        if ($older) {
            echo '<p class="description">' . esc_html(sprintf(
                /* translators: %s: list of months. */
                __('Older published months still shown publicly: %s', 'community-pulse'), implode(', ', array_keys($older)))) . '</p>';
        }

        echo '<h2>' . esc_html__('Show it on a page', 'community-pulse') . '</h2><p>' . esc_html__('Add a Shortcode block (or paste into the classic editor):', 'community-pulse') . '</p><table class="widefat cp-table"><tbody>';
        foreach ([
            '[community_pulse]' => __('Latest published month', 'community-pulse'),
            '[community_pulse month="2026-10"]' => __('A specific published month', 'community-pulse'),
            '[community_pulse view="history"]' => __('Table of every published month', 'community-pulse'),
            '[community_pulse groups="no"]' => __('Hide the group table', 'community-pulse'),
        ] as $code => $label) {
            echo '<tr><th scope="row"><code class="cp-shortcode">' . esc_html($code) . '</code></th><td style="text-align:left">' . esc_html($label) . '</td></tr>';
        }
        echo '</tbody></table>';
        $latest = Snapshots::latest();
        if ($latest) {
            wp_enqueue_style('community-pulse-public', plugins_url('assets/public.css', COMMUNITY_PULSE_FILE), [], COMMUNITY_PULSE_VERSION);
            echo '<h2>' . esc_html__('Preview of the latest published report', 'community-pulse') . '</h2><div class="card" style="max-width:46em">' . PublicReport::summary($latest, true) . '</div>';
        }
    }

    private static function publish_form(string $month, ?array $snap): void {
        $url = admin_url('admin-post.php');
        echo '<form method="post" action="' . esc_url($url) . '">';
        wp_nonce_field(self::PUBLISH_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::PUBLISH_ACTION) . '"><input type="hidden" name="month" value="' . esc_attr($month) . '">';
        echo '<label><input type="checkbox" name="round" value="1" ' . checked($snap ? $snap['rounded'] : true, true, false) . '> ' . esc_html__('Round to nearest 5', 'community-pulse') . '</label>';
        echo '<label><input type="checkbox" name="groups" value="1" ' . checked($snap ? $snap['groups_included'] : false, true, false) . '> ' . esc_html__('Include public groups', 'community-pulse') . '</label>';
        echo '<button class="button button-primary" name="op" value="publish">' . esc_html($snap ? __('Update', 'community-pulse') : __('Publish', 'community-pulse')) . '</button>';
        if ($snap) {
            echo '<button class="button" name="op" value="unpublish">' . esc_html__('Unpublish', 'community-pulse') . '</button>';
        }
        echo '</form>';
    }

    public static function publish(): void {
        if (!current_user_can(Plugin::publish_capability())) {
            wp_die(esc_html__('You cannot publish community reports.', 'community-pulse'), '', ['response' => 403]);
        }
        check_admin_referer(self::PUBLISH_ACTION);
        $month = self::month('POST');
        $op = isset($_POST['op']) && $_POST['op'] === 'unpublish' ? 'unpublish' : 'publish';
        $notice = 'error';
        try {
            if ($op === 'unpublish') {
                Snapshots::unpublish($month);
                $notice = 'unpublished';
            } else {
                Report::window($month);
                Snapshots::publish($month, !empty($_POST['groups']), !empty($_POST['round']));
                $notice = 'published';
            }
        } catch (\Throwable $e) {
            $notice = 'error';
        }
        wp_safe_redirect(self::url(['tab' => 'public', 'cp_notice' => $notice]));
        exit;
    }

    public static function export(): void {
        Plugin::authorize();
        check_admin_referer(self::EXPORT_ACTION);
        $trend = isset($_GET['scope']) && $_GET['scope'] === 'trend';
        try {
            $data = $trend ? Report::trend() : Report::fetch(self::month());
        } catch (\Throwable $e) {
            wp_die(esc_html(self::error_message($e)), '', ['response' => 400]);
        }
        $name = $trend ? 'community-pulse-12-months-' . gmdate('Y-m') : 'community-pulse-' . $data['month'];
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '.csv"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        $trend ? Csv::trend($out, $data) : Csv::monthly($out, $data);
        fclose($out);
        exit;
    }
}
