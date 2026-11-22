<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add custom rewrite rules.
 * NOTE: flush_rewrite_rules() is NOT called here.
 */
function xsmg_add_rewrite_rules() {
    add_rewrite_rule(
        '^news-sitemap([0-9]*)\.xml$',
        'index.php?xsmg_news_sitemap=$matches[1]',
        'top'
    );
}
add_action('init', 'xsmg_add_rewrite_rules', 1);

/**
 * Template redirect to serve sitemap.
 */
function xsmg_template_redirect() {
    // Use sanitize_text_field for REQUEST_URI (path + query).
    $request_uri = isset($_SERVER['REQUEST_URI'])
        ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']))
        : '';

    $permalink_structure = get_option('permalink_structure');
    $is_pretty_enabled   = !empty($permalink_structure);

    $current_sitemap_url = $is_pretty_enabled
        ? home_url('/news-sitemap.xml')
        : home_url('/?xsmg_news_sitemap=1');

    $sitemap = get_query_var('xsmg_news_sitemap');

    // Redirect old sitemap URL to the correct one.
    if (
        ($sitemap !== '' && $is_pretty_enabled && strpos($request_uri, '/news-sitemap.xml') === false) ||
        (!$is_pretty_enabled && strpos($request_uri, '/news-sitemap.xml') !== false)
    ) {
        wp_redirect($current_sitemap_url, 301);
        exit;
    }

    if (!is_main_query()) {
        return;
    }

    // Normalize: /news-sitemap.xml → index 1.
    if ($sitemap === '' && strpos($request_uri, '/news-sitemap.xml') !== false) {
        $sitemap = 1;
    } elseif ($sitemap === '') {
        return;
    }

    // Tell WordPress this is NOT a 404 - CRITICAL FOR GSC.
    global $wp_query;
    $wp_query->is_404 = false;
    status_header(200);

    xsmg_generate_news_sitemap($sitemap);
    exit;
}
add_action('template_redirect', 'xsmg_template_redirect', 0);

/**
 * Generate sitemap XML.
 *
 * @param string|int $sitemap_index Sitemap page index.
 */
function xsmg_generate_news_sitemap($sitemap_index = '') {
    $cache_key     = 'xsmg_sitemap_cache_' . $sitemap_index;
    $cached_output = get_transient($cache_key);

    if ($cached_output !== false) {
        if (is_array($cached_output) && isset($cached_output['xml'], $cached_output['generated_time'])) {
            $sitemap_output = $cached_output['xml'];
            $generated_time = $cached_output['generated_time'];
        } else {
            $sitemap_output = $cached_output;
            $generated_time = time();
        }

        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $generated_time) . ' GMT');
        header('Cache-Control: public, max-age=' . (30 * 60));
        if (!headers_sent()) {
            header('Content-Type: application/xml; charset=utf-8');
        }
        echo $sitemap_output;
        flush();
        exit;
    }

    // Clean output buffers.
    while (ob_get_level()) {
        @ob_end_clean();
    }

    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', 1);
    }
    @ini_set('zlib.output_compression', 'Off');

    // Remove only specific headers instead of ALL.
    if (!headers_sent()) {
        header_remove('Content-Type');
        header_remove('Cache-Control');
    }
    header('Content-Type: application/xml; charset=utf-8');

    try {
        $limit  = 1000; // Google News sitemap limit: 1000 URLs per file.
        $offset = 0;

        // Use absint for safe integer conversion.
        $sitemap_index = absint($sitemap_index);
        if ($sitemap_index > 1) {
            $offset = ($sitemap_index - 1) * $limit;
        }

        $args = [
            'post_type'      => ['post'],
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'date_query'     => [
                ['after' => '48 hours ago'],
            ],
            'fields'         => 'ids',
        ];

        // Only filter by Yoast schema type if Yoast is active.
        if (defined('WPSEO_VERSION')) {
            $args['meta_query'] = [
                [
                    'key'   => '_yoast_wpseo_schema_article_type',
                    'value' => 'NewsArticle',
                ],
            ];
        }

        $posts = get_posts($args);

        // Determine publication language per Google News spec.
        $lang = strtolower(get_bloginfo('language'));
        if (strpos($lang, 'zh-cn') === 0) {
            $lang = 'zh-cn';
        } elseif (strpos($lang, 'zh-tw') === 0) {
            $lang = 'zh-tw';
        } else {
            $lang = explode('-', $lang)[0];
        }

        $site_name = get_bloginfo('name');

        ob_start();
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">
<?php
        foreach ($posts as $post_id) {
            $title    = wp_strip_all_tags(get_the_title($post_id));
            $pub_date = get_the_date('c', $post_id);
            $link     = get_permalink($post_id);
            ?>
    <url>
        <loc><?php echo esc_url($link); ?></loc>
        <news:news>
            <news:publication>
                <news:name><?php echo esc_html($site_name); ?></news:name>
                <news:language><?php echo esc_html($lang); ?></news:language>
            </news:publication>
            <news:publication_date><?php echo esc_html($pub_date); ?></news:publication_date>
            <news:title><?php echo esc_html($title); ?></news:title>
        </news:news>
    </url>
<?php } ?>
</urlset>
<?php
        $sitemap_output = ob_get_clean();
        $generated_time = time();

        $cache_data = [
            'xml'            => $sitemap_output,
            'generated_time' => $generated_time,
            'human_time'     => current_time('mysql'),
        ];

        set_transient($cache_key, $cache_data, 30 * MINUTE_IN_SECONDS);

        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $generated_time) . ' GMT');
        header('Cache-Control: public, max-age=' . (30 * 60));

        echo $sitemap_output;
        flush();
        exit;

    } catch (\Throwable $e) {
        error_log('[XSMG] Sitemap generation error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        status_header(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Sitemap generation error. Please try again later.';
        exit;
    }
}

/**
 * Clear all sitemap cache transients.
 * Deletes both _transient_ and _transient_timeout_ records.
 */
function xsmg_clear_all_cache() {
    global $wpdb;

    // Get all cache keys (without timeout prefix).
    $keys = $wpdb->get_col(
        "SELECT option_name FROM $wpdb->options
         WHERE option_name LIKE '_transient_xsmg_sitemap_cache_%'
         AND option_name NOT LIKE '_transient_timeout_%'"
    );

    foreach ($keys as $key) {
        $transient_name = str_replace('_transient_', '', $key);
        delete_transient($transient_name);
    }

    // Clean up any orphaned timeout entries.
    $wpdb->query(
        "DELETE FROM $wpdb->options
         WHERE option_name LIKE '_transient_timeout_xsmg_sitemap_cache_%'"
    );

    update_option('xsmg_last_cache_clear', current_time('mysql'));
}

/**
 * Clear sitemap cache if affected post is changed.
 * Includes capability check to prevent unauthorized cache clearing.
 *
 * @param int $post_id Post ID.
 */
function maybe_clear_sitemap_cache($post_id) {
    // Only proceed if user has permission to edit posts.
    if (!current_user_can('edit_posts')) {
        return;
    }

    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'post') {
        return;
    }

    if (strtotime($post->post_date) < strtotime('-48 hours')) {
        return;
    }

    xsmg_clear_all_cache();
}
add_action('save_post', 'maybe_clear_sitemap_cache');
add_action('before_delete_post', 'maybe_clear_sitemap_cache');
add_action('trashed_post', 'maybe_clear_sitemap_cache');

/**
 * Get sitemap cache information.
 *
 * @return array
 */
function xsmg_get_cache_info() {
    global $wpdb;

    $info = [
        'total_cache_files' => 0,
        'oldest_cache'      => null,
        'newest_cache'      => null,
        'cache_size'        => 0,
        'last_clear'        => get_option('xsmg_last_cache_clear', 'Never'),
        'last_cron_run'     => get_option('xsmg_last_cron_run', 'Never'),
    ];

    $keys = $wpdb->get_results(
        "SELECT option_name, option_value
         FROM $wpdb->options
         WHERE option_name LIKE '_transient_xsmg_sitemap_cache_%'
         AND option_name NOT LIKE '_transient_timeout_%'"
    );

    if ($keys) {
        $cache_count = 0;
        $min_time    = PHP_INT_MAX;
        $max_time    = 0;

        foreach ($keys as $key) {
            $cache_data = maybe_unserialize($key->option_value);

            if (is_array($cache_data) && isset($cache_data['generated_time'])) {
                $cache_count++;
                $info['cache_size'] += strlen(serialize($cache_data));

                if ($cache_data['generated_time'] < $min_time) {
                    $min_time = $cache_data['generated_time'];
                }
                if ($cache_data['generated_time'] > $max_time) {
                    $max_time = $cache_data['generated_time'];
                }
            }
        }

        $info['total_cache_files'] = $cache_count;
        $info['oldest_cache']      = $min_time !== PHP_INT_MAX ? date('Y-m-d H:i:s', $min_time) : null;
        $info['newest_cache']      = $max_time ? date('Y-m-d H:i:s', $max_time) : null;
        $info['cache_size']        = size_format($info['cache_size'], 2);
    }

    return $info;
}

/**
 * Clear old cache (older than specified hours).
 *
 * @param int $hours Hours threshold.
 * @return int Number of cleared transients.
 */
function xsmg_clear_old_cache($hours = 48) {
    global $wpdb;

    $keys = $wpdb->get_col(
        "SELECT option_name
         FROM $wpdb->options
         WHERE option_name LIKE '_transient_xsmg_sitemap_cache_%'
         AND option_name NOT LIKE '_transient_timeout_%'"
    );

    $cleared     = 0;
    $cutoff_time = time() - ($hours * 3600);

    foreach ($keys as $key) {
        $transient_name = str_replace('_transient_', '', $key);
        $cache_data     = get_transient($transient_name);

        if (is_array($cache_data) && isset($cache_data['generated_time'])) {
            if ($cache_data['generated_time'] < $cutoff_time) {
                delete_transient($transient_name);
                $cleared++;
            }
        }
    }

    return $cleared;
}