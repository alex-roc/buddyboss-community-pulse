<?php
namespace AlexROC\CommunityPulse;
defined('ABSPATH') || exit;

/** Bootstrap, permissions and translations. */
final class Plugin {
    const SLUG = 'community-pulse';

    public static function boot(): void {
        add_action('init', [self::class, 'load_textdomain']);
        add_filter('plugin_action_links_' . plugin_basename(COMMUNITY_PULSE_FILE), [self::class, 'action_links']);
        Admin::boot();
        PublicReport::boot();
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

    private static function filtered_cap(string $filter): string {
        $cap = apply_filters($filter, 'manage_options');
        return is_string($cap) && $cap !== '' ? $cap : 'manage_options';
    }

    /** Capability required to view and export. Filterable for custom reporting roles. */
    public static function capability(): string {
        return self::filtered_cap('community_pulse_capability');
    }

    /** Capability required to publish or unpublish public reports. */
    public static function publish_capability(): string {
        return self::filtered_cap('community_pulse_publish_capability');
    }

    public static function action_links(array $links): array {
        if (current_user_can(self::capability())) {
            $links = ['report' => '<a href="' . esc_url(Admin::url()) . '">' . esc_html__('View report', 'community-pulse') . '</a>'] + $links;
        }
        return $links;
    }

    public static function authorize(): void {
        if (!current_user_can(self::capability())) {
            wp_die(esc_html__('You cannot access this report.', 'community-pulse'), '', ['response' => 403]);
        }
    }
}
