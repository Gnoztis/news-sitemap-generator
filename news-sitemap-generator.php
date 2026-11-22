<?php
/**
 * Plugin Name: XML Sitemap Generator (xsmg)
 * Description: Automatically generates a real-time, Google News-compatible XML sitemap for posts published within the last 48 hours. Zero server load. Scales to thousands of URLs.
 * Version: 1.4
 * Requires at least: 6.0
 * Requires PHP: 8.4
 * Author: Your Name
 * License: GPL2+
 */

defined('ABSPATH') or die('');

define('XSMG_VERSION', '1.4');
define('XSMG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('XSMG_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once XSMG_PLUGIN_DIR . 'includes/sitemap-generator.php';

/**
 * Activation hook — flush rewrite rules ONCE on activation.
 */
function xsmg_activate_plugin() {
    if (function_exists('xsmg_add_rewrite_rules')) {
        xsmg_add_rewrite_rules();
    }
    flush_rewrite_rules();
    update_option('xsmg_last_permalink_structure', get_option('permalink_structure'));

    // Schedule daily cache cleanup if not already scheduled.
    if (!wp_next_scheduled('xsmg_daily_cache_cleanup')) {
        $timestamp = strtotime('tomorrow 03:00');
        wp_schedule_event($timestamp, 'daily', 'xsmg_daily_cache_cleanup');
    }
}
register_activation_hook(__FILE__, 'xsmg_activate_plugin');

/**
 * Ensure WP-Cron event is scheduled (self-healing on every request).
 * Runs on 'wp' hook so it fires once per front-end/admin request.
 */
function xsmg_schedule_cache_cleanup() {
    if (!wp_next_scheduled('xsmg_daily_cache_cleanup')) {
        $timestamp = strtotime('tomorrow 03:00');
        wp_schedule_event($timestamp, 'daily', 'xsmg_daily_cache_cleanup');
    }
}
add_action('wp', 'xsmg_schedule_cache_cleanup');

/**
 * Execute daily cache cleanup via WP-Cron.
 */
function xsmg_execute_daily_cache_cleanup() {
    if (function_exists('xsmg_clear_old_cache')) {
        xsmg_clear_old_cache(48);
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
        if (function_exists('xsmg_add_rewrite_rules')) {
            xsmg_add_rewrite_rules();
        }
        flush_rewrite_rules();
        update_option('xsmg_last_permalink_structure', $current_permalink);
    }
}
add_action('admin_init', 'xsmg_check_and_auto_flush_rewrite');

/**
 * Register query var for sitemap.
 */
function xsmg_add_query_vars($vars) {
    $vars[] = 'xsmg_news_sitemap';
    return $vars;
}
add_filter('query_vars', 'xsmg_add_query_vars');

/**
 * Register public query var for sitemap (CRITICAL FOR GSC).
 */
function xsmg_add_public_query_vars($vars) {
    $vars[] = 'xsmg_news_sitemap';
    return $vars;
}
add_filter('wp_public_query_vars', 'xsmg_add_public_query_vars');

/**
 * Deactivation hook.
 */
function xsmg_deactivate_plugin() {
    // Unschedule daily cache cleanup.
    $timestamp = wp_next_scheduled('xsmg_daily_cache_cleanup');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'xsmg_daily_cache_cleanup');
    }

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
    $timestamp = wp_next_scheduled('xsmg_daily_cache_cleanup');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'xsmg_daily_cache_cleanup');
    }

    delete_option('xsmg_last_permalink_structure');
    delete_option('xsmg_last_cache_clear');
    delete_option('xsmg_last_cron_run');

    if (function_exists('xsmg_clear_all_cache')) {
        xsmg_clear_all_cache();
    }
    flush_rewrite_rules();
}
register_uninstall_hook(__FILE__, 'xsmg_uninstall_plugin');

/**
 * Add plugin action links.
 */
function xsmg_add_action_links($links) {
    $permalink_structure = get_option('permalink_structure');
    if (!empty($permalink_structure)) {
        $sitemap_url = home_url('/news-sitemap.xml');
    } else {
        $sitemap_url = add_query_arg('xsmg_news_sitemap', '1', home_url('/'));
    }
    $custom_link = '<a href="' . esc_url($sitemap_url) . '" target="_blank">View News Sitemap</a>';
    array_unshift($links, $custom_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'xsmg_add_action_links');