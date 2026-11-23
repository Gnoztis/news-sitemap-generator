<?php
/**
 * Plugin Name: XML Sitemap Generator (xsmg)
 * Description: Serves a Google News sitemap with the newest posts published within the last 48 hours (up to 1000 URLs), cached for 30 minutes.
 * Version: 1.5.3
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 8.4
 * Author: Gnostis
 * Author URI: https://github.com/Gnoztis
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: news-sitemap-generator
 * Update URI: https://github.com/Gnoztis/news-sitemap-generator
 */

if (!defined('ABSPATH')) {
    exit;
}

define('XSMG_VERSION', '1.5.3');
define('XSMG_PLUGIN_FILE', __FILE__);
define('XSMG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('XSMG_PLUGIN_URL', plugin_dir_url(__FILE__));

// Sitemap shape. Constants keep the query, the cache key, the served path and
// the README in step: one document with the newest 1000 published posts of the
// last 48 hours, cached as a single transient for 30 minutes.
define('XSMG_WINDOW_HOURS', 48);
define('XSMG_MAX_URLS', 1000);
define('XSMG_SITEMAP_PATH', '/news-sitemap.xml');
define('XSMG_CACHE_KEY', 'xsmg_sitemap_cache');
define('XSMG_CACHE_TTL', 30 * MINUTE_IN_SECONDS);

// Diagnostic flag for the admin notice: "1" when the Yoast schema filter hides
// every post in the window, "0" when it does not. Never a user setting.
define('XSMG_YOAST_EMPTY_KEY', 'xsmg_yoast_empty');

require_once XSMG_PLUGIN_DIR . 'includes/sitemap-generator.php';
require_once XSMG_PLUGIN_DIR . 'includes/class-xsmg-github-updater.php';

// The filter and action arrays hold [ $this, 'method' ] callables, so the
// instance stays alive for the whole request.
( new XSMG_GitHub_Updater() )->register_hooks();

/**
 * Activation hook — flush rewrite rules ONCE on activation.
 *
 * The plugin serves its path from template_redirect and registers no rule of
 * its own, but the flush is kept so a rule cached by 1.4 (and by the query
 * var that version used) is dropped from the rewrite cache.
 */
function xsmg_activate_plugin() {
    flush_rewrite_rules();
    update_option('xsmg_last_permalink_structure', get_option('permalink_structure'));

    xsmg_schedule_cache_cleanup();
}
register_activation_hook(__FILE__, 'xsmg_activate_plugin');

/**
 * Unix timestamp of the next 03:00 in the site timezone.
 *
 * PHP runs in UTC inside WordPress, so a bare strtotime('tomorrow 03:00')
 * means 03:00 UTC — a different local hour on every site. WP-Cron stores
 * timestamps in UTC, so the site offset is applied explicitly.
 *
 * @return int
 */
function xsmg_next_cleanup_time() {
    $local_next = strtotime('tomorrow 03:00', current_time('timestamp'));
    $offset     = (float) get_option('gmt_offset') * HOUR_IN_SECONDS;

    return (int) ($local_next - $offset);
}

/**
 * Ensure WP-Cron event is scheduled (self-healing on every request).
 * Runs on 'wp' hook so it fires once per front-end/admin request.
 */
function xsmg_schedule_cache_cleanup() {
    if (!wp_next_scheduled('xsmg_daily_cache_cleanup')) {
        wp_schedule_event(xsmg_next_cleanup_time(), 'daily', 'xsmg_daily_cache_cleanup');
    }
}
add_action('wp', 'xsmg_schedule_cache_cleanup');

/**
 * Execute daily cache cleanup via WP-Cron.
 */
function xsmg_execute_daily_cache_cleanup() {
    if (function_exists('xsmg_clear_old_cache')) {
        xsmg_clear_old_cache(XSMG_WINDOW_HOURS);
    }
    update_option('xsmg_last_cron_run', current_time('mysql'));
}
add_action('xsmg_daily_cache_cleanup', 'xsmg_execute_daily_cache_cleanup');

/**
 * Detect permalink changes and flush if needed.
 * Only runs in admin to avoid front-end overhead.
 */
function xsmg_check_and_auto_flush_rewrite() {
    if (!is_admin()) {
        return;
    }

    $current_permalink    = get_option('permalink_structure');
    $last_saved_permalink = get_option('xsmg_last_permalink_structure');

    if ($current_permalink !== $last_saved_permalink) {
        // Drop any rule cached by an older version together with the stale
        // rewrite cache; the plugin itself registers no rule.
        flush_rewrite_rules();
        update_option('xsmg_last_permalink_structure', $current_permalink);
    }
}
add_action('admin_init', 'xsmg_check_and_auto_flush_rewrite');

/**
 * Deactivation hook.
 */
function xsmg_deactivate_plugin() {
    // Remove every occurrence, not just the next one, so a duplicate event
    // left by an older version cannot survive deactivation.
    wp_clear_scheduled_hook('xsmg_daily_cache_cleanup');

    if (function_exists('xsmg_clear_all_cache')) {
        xsmg_clear_all_cache();
    }
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'xsmg_deactivate_plugin');

/**
 * Uninstall hook.
 */
function xsmg_uninstall_plugin() {
    wp_clear_scheduled_hook('xsmg_daily_cache_cleanup');

    // The cache is cleared before the options are deleted: clearing it
    // writes xsmg_last_cache_clear, which would otherwise be recreated
    // during uninstall and stay behind.
    if (function_exists('xsmg_clear_all_cache')) {
        xsmg_clear_all_cache();
    }

    // The generated updater keeps its two transients outside the sitemap
    // cache; the names are its TRANSIENT / ERROR_TRANSIENT constants, kept
    // from the previous updater so an in-place update keeps its cache.
    delete_transient('xsmg_github_release');
    delete_transient('xsmg_github_release_error');

    delete_option('xsmg_last_permalink_structure');
    delete_option('xsmg_last_cache_clear');
    delete_option('xsmg_last_cron_run');

    flush_rewrite_rules();
}
register_uninstall_hook(__FILE__, 'xsmg_uninstall_plugin');

/**
 * Add plugin action links.
 */
function xsmg_add_action_links($links) {
    $custom_link = '<a href="' . esc_url(home_url(XSMG_SITEMAP_PATH)) . '" target="_blank" rel="noopener noreferrer">'
        . esc_html__('View News Sitemap', 'news-sitemap-generator')
        . '</a>';
    array_unshift($links, $custom_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'xsmg_add_action_links');