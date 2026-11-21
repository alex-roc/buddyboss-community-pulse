<?php
namespace AlexROC\CommunityPulse;
defined('ABSPATH') || exit;

/**
 * [community_pulse] shortcode: renders published snapshots only.
 *
 *   [community_pulse]                      latest published month
 *   [community_pulse month="2026-10"]      a specific published month
 *   [community_pulse view="history"]       table of every published month
 *   groups="no"                            hide the group table
 *   title="…"                              custom heading text
 */
final class PublicReport {
    const SHORTCODE = 'community_pulse';

    public static function boot(): void {
        add_shortcode(self::SHORTCODE, [self::class, 'render']);
    }

    private static function month_label(string $month): string {
        $time = strtotime($month . '-01 00:00:00 UTC');
        return $time ? date_i18n('F Y', $time, true) : $month;
    }

    private static function number(?int $value): string {
        return $value === null ? __('Hidden', 'community-pulse') : number_format_i18n($value);
    }

    private static function styles(): void {
        wp_enqueue_style('community-pulse-public', plugins_url('assets/public.css', COMMUNITY_PULSE_FILE), [], COMMUNITY_PULSE_VERSION);
    }

    public static function render($atts = []): string {
        $atts = shortcode_atts(['month' => 'latest', 'view' => 'summary', 'groups' => 'yes', 'title' => ''], is_array($atts) ? $atts : [], self::SHORTCODE);
        self::styles();
        if ($atts['view'] === 'history') {
            return self::history(Snapshots::all(), (string) $atts['title']);
        }
        $snapshot = $atts['month'] === 'latest' ? Snapshots::latest() : Snapshots::get((string) $atts['month']);
        if (!$snapshot) {
            // Nothing published: show nothing to visitors, a hint to people who can publish.
            return current_user_can(Plugin::capability())
                ? '<p class="community-pulse-empty">' . esc_html__('Community Pulse: no published report for this month yet. Publish one under Tools → Community Pulse → Public reports.', 'community-pulse') . '</p>'
                : '';
        }
        return self::summary($snapshot, $atts['groups'] !== 'no', (string) $atts['title']);
    }

    public static function summary(array $snap, bool $showGroups, string $title = ''): string {
        $s = $snap['summary'];
        $heading = $title !== '' ? $title : sprintf(
            /* translators: %s: month and year. */
            __('Community participation, %s', 'community-pulse'), self::month_label($snap['month']));
        $html = '<section class="community-pulse" aria-label="' . esc_attr($heading) . '">';
        $html .= '<p class="community-pulse-title">' . esc_html($heading) . '</p>';
        if ($s['suppressed']) {
            $html .= '<p>' . esc_html__('Fewer than 5 people posted publicly this month, so figures are not shown.', 'community-pulse') . '</p>';
        } else {
            $html .= '<dl class="community-pulse-figures">';
            foreach ([
                'contributors' => __('people posted publicly', 'community-pulse'),
                'contributions' => __('public updates', 'community-pulse'),
                'returning' => __('posted on 2+ days', 'community-pulse'),
                'retained' => __('also posted the month before', 'community-pulse'),
                'new' => __('posted for the first time', 'community-pulse'),
            ] as $key => $label) {
                if ($s[$key] === null) {
                    continue; // Hidden values are omitted from public output.
                }
                $html .= '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html(self::number($s[$key])) . '</dd></div>';
            }
            $html .= '</dl>';
            if ($showGroups && $snap['groups']) {
                $html .= '<table class="community-pulse-groups"><caption>' . esc_html__('Public groups with 5 or more people posting', 'community-pulse') . '</caption><thead><tr><th scope="col">'
                    . esc_html__('Group', 'community-pulse') . '</th><th scope="col">' . esc_html__('People', 'community-pulse') . '</th><th scope="col">'
                    . esc_html__('Updates', 'community-pulse') . '</th></tr></thead><tbody>';
                foreach ($snap['groups'] as $row) {
                    $html .= '<tr><td>' . esc_html($row['name']) . '</td><td>' . esc_html(self::number($row['contributors'])) . '</td><td>' . esc_html(self::number($row['contributions'])) . '</td></tr>';
                }
                $html .= '</tbody></table>';
            }
        }
        $html .= '<p class="community-pulse-note">' . esc_html(self::note($snap)) . '</p></section>';
        return $html;
    }

    public static function history(array $snapshots, string $title = ''): string {
        if (!$snapshots) {
            return '';
        }
        ksort($snapshots, SORT_STRING);
        $heading = $title !== '' ? $title : __('Community participation by month', 'community-pulse');
        $html = '<section class="community-pulse" aria-label="' . esc_attr($heading) . '"><p class="community-pulse-title">' . esc_html($heading) . '</p>';
        $html .= '<table class="community-pulse-history"><thead><tr><th scope="col">' . esc_html__('Month', 'community-pulse') . '</th><th scope="col">'
            . esc_html__('People posting', 'community-pulse') . '</th><th scope="col">' . esc_html__('Public updates', 'community-pulse') . '</th><th scope="col">'
            . esc_html__('Also posted the month before', 'community-pulse') . '</th><th scope="col">' . esc_html__('First time', 'community-pulse') . '</th></tr></thead><tbody>';
        $rounded = false;
        foreach ($snapshots as $snap) {
            $s = $snap['summary'];
            $rounded = $rounded || !empty($snap['rounded']);
            $html .= '<tr><th scope="row">' . esc_html(self::month_label($snap['month'])) . '</th>';
            foreach (['contributors', 'contributions', 'retained', 'new'] as $key) {
                $html .= '<td>' . esc_html($s[$key] === null ? '—' : number_format_i18n($s[$key])) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table><p class="community-pulse-note">' . esc_html(
            __('Public posts only. “—” means fewer than 5 people, or not enough to protect who is left out, so the figure is hidden.', 'community-pulse')
            . ($rounded ? ' ' . __('Figures are rounded to the nearest 5.', 'community-pulse') : '')
        ) . '</p></section>';
        return $html;
    }

    private static function note(array $snap): string {
        $parts = [__('Counts public posts only, not readers.', 'community-pulse'),
            __('Figures based on fewer than 5 people are hidden.', 'community-pulse')];
        if (!empty($snap['rounded'])) {
            $parts[] = __('Figures are rounded to the nearest 5.', 'community-pulse');
        }
        $time = strtotime($snap['published_at'] . ' UTC');
        if ($time) {
            /* translators: %s: publication date. */
            $parts[] = sprintf(__('Published %s.', 'community-pulse'), date_i18n(get_option('date_format') ?: 'Y-m-d', $time));
        }
        return implode(' ', $parts);
    }
}
