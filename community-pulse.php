<?php
/**
 * Plugin Name: Community Pulse for BuddyBoss
 * Description: Privacy-conscious monthly participation reports for public community activity.
 * Version: 0.3.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Alex Ojeda Copa
 * Author URI: https://github.com/alex-roc
 * License: GPL-2.0-or-later
 * Text Domain: community-pulse
 * Domain Path: /languages
 */
namespace AlexROC\CommunityPulse;
defined('ABSPATH') || exit;
const COMMUNITY_PULSE_FILE = __FILE__;
const COMMUNITY_PULSE_VERSION = '0.3.0';
foreach (['report', 'snapshots', 'csv', 'public', 'admin', 'plugin'] as $cp_file) {
    require_once __DIR__ . "/includes/class-{$cp_file}.php";
}
unset($cp_file);
Plugin::boot();
