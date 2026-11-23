<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sanitized path of the current request, relative to the site root.
 *
 * "/blog/news-sitemap.xml?x=1" on a site installed in /blog becomes
 * "/news-sitemap.xml". The query string is dropped: only the path decides
 * whether the sitemap is requested. The previous check searched the raw
 * REQUEST_URI for "/news-sitemap.xml", so it also matched any URL that
 * merely carried that string inside its query string.
 *
 * @return string Path with a leading slash; "/" for the front page.
 */
function xsmg_get_request_path() {
    $request_uri = isset($_SERVER['REQUEST_URI'])
        ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']))
        : '';

    $path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
    if ($path === '') {
        return '/';
    }

    // Strip the subdirectory prefix of a site installed below the domain.
    $home_path = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
    $home_path = '/' . trim($home_path, '/');
    if ($home_path !== '/' && ($path === $home_path || strpos($path, $home_path . '/') === 0)) {
        $path = substr($path, strlen($home_path));
    }

    return '/' . ltrim($path, '/');
}

/**
 * Serve the news sitemap.
 *
 * There is exactly one sitemap document at one URL. The sanitized request
 * path is compared with the canonical path for equality: no query
 * parameter, query var or rewrite capture selects what is served, so a
 * visitor cannot influence the sitemap content. Any other path is an
 * ordinary WordPress request and is left untouched.
 */
function xsmg_template_redirect() {
    if (xsmg_get_request_path() !== XSMG_SITEMAP_PATH) {
        return;
    }

    if (!is_main_query()) {
        return;
    }

    // The main query matched no post for this path and set 404: the sitemap
    // is a real document and has to answer 200.
    global $wp_query;
    if (is_object($wp_query)) {
        $wp_query->is_404 = false;
    }
    status_header(200);

    xsmg_generate_news_sitemap();
    exit;
}
add_action('template_redirect', 'xsmg_template_redirect', 0);

/**
 * Remove characters that are not allowed in XML 1.0 text nodes.
 *
 * A title can reach the database through the REST API with a control
 * character; one such byte makes the whole sitemap unparsable. Tab, line
 * feed and carriage return are legal and kept, the rest of the C categories
 * (including invalid UTF-8, replaced first) is dropped. Emoji are category
 * So and pass through untouched.
 *
 * @param string $text Raw text.
 * @return string Text that is safe inside an XML text node.
 */
function xsmg_clean_xml_text($text) {
    $text = (string) $text;

    if (function_exists('wp_check_invalid_utf8')) {
        $text = wp_check_invalid_utf8($text);
    }

    $clean = preg_replace('/[^\P{C}\t\n\r]/u', '', $text);

    return $clean === null ? '' : $clean;
}

/**
 * Response header values of a sitemap document.
 *
 * Built in one place so they can be asserted without a web server; the
 * cache lifetime matches the transient lifetime.
 *
 * @param int $generated_time Unix time the sitemap was generated.
 * @return array{content_type:string,last_modified:string,cache_control:string}
 */
function xsmg_sitemap_headers($generated_time) {
    return [
        'content_type'  => 'application/xml; charset=utf-8',
        'last_modified' => gmdate('D, d M Y H:i:s', (int) $generated_time) . ' GMT',
        'cache_control' => 'public, max-age=' . (int) XSMG_CACHE_TTL,
    ];
}

/**
 * Send the response headers of a sitemap document.
 *
 * A Content-Type set by an earlier hook is removed first, so the response
 * never carries two of them.
 *
 * @param int $generated_time Unix time the sitemap was generated.
 */
function xsmg_send_sitemap_headers($generated_time) {
    if (headers_sent()) {
        return;
    }

    $headers = xsmg_sitemap_headers($generated_time);

    header_remove('Content-Type');
    header_remove('Cache-Control');

    header('Content-Type: ' . (string) $headers['content_type']);
    header('Last-Modified: ' . (string) $headers['last_modified']);
    header('Cache-Control: ' . (string) $headers['cache_control']);
}

/**
 * Query args of the 48-hour window, without the sitemap-specific parts.
 *
 * Shared by the sitemap query and by the diagnostics, so the window cannot
 * drift between them.
 *
 * @return array
 */
function xsmg_window_query_args() {
    return [
        'post_type'    => ['post'],
        'post_status'  => 'publish',
        'orderby'      => 'date',
        'order'        => 'DESC',
        'date_query'   => [
            ['after' => XSMG_WINDOW_HOURS . ' hours ago'],
        ],
        'has_password' => false,
    ];
}

/**
 * Query args of the sitemap document.
 *
 * One query returns full post objects and primes the post and meta caches,
 * so the rendering loop never loads a post by ID one at a time. With only IDs
 * (`'fields' => 'ids'`) a cold generation ran up to 1000 extra get_post()
 * queries; now it is one query plus the meta cache priming.
 *
 * The Yoast condition and the NewsArticle value are kept exactly as in 1.4:
 * existing sites rely on them.
 *
 * @return array
 */
function xsmg_sitemap_query_args() {
    $args = xsmg_window_query_args();

    $args['posts_per_page']         = XSMG_MAX_URLS;
    $args['update_post_meta_cache'] = true;
    $args['update_post_term_cache'] = false;

    // Only filter by Yoast schema type if Yoast is active.
    if (defined('WPSEO_VERSION')) {
        $args['meta_query'] = [
            [
                'key'   => '_yoast_wpseo_schema_article_type',
                'value' => 'NewsArticle',
            ],
        ];
    }

    return $args;
}

/**
 * Whether the 48-hour window contains at least one published post.
 *
 * @return bool
 */
function xsmg_window_has_posts() {
    $args = xsmg_window_query_args();

    $args['posts_per_page']         = 1;
    $args['fields']                 = 'ids';
    $args['update_post_meta_cache'] = false;
    $args['update_post_term_cache'] = false;

    return (bool) get_posts($args);
}

/**
 * Whether Yoast is hiding every post that the window contains.
 *
 * True only when Yoast is active, the window has at least one published post
 * and none of those posts carries the NewsArticle schema article type — the
 * case where the sitemap is empty although the site published. The answer is
 * cached with the sitemap, so an admin page load does not repeat the queries,
 * and generation updates it as a side effect.
 *
 * @return bool
 */
function xsmg_yoast_filter_hides_posts() {
    if (!defined('WPSEO_VERSION')) {
        return false;
    }

    $cached = get_transient(XSMG_YOAST_EMPTY_KEY);
    if ($cached === '1' || $cached === '0') {
        return $cached === '1';
    }

    $hides = false;

    if (xsmg_window_has_posts()) {
        $args = xsmg_sitemap_query_args();

        $args['posts_per_page']         = 1;
        $args['fields']                 = 'ids';
        $args['update_post_meta_cache'] = false;
        $args['update_post_term_cache'] = false;

        $hides = empty(get_posts($args));
    }

    set_transient(XSMG_YOAST_EMPTY_KEY, $hides ? '1' : '0', XSMG_CACHE_TTL);

    return $hides;
}

/**
 * Warn editors when the Yoast schema filter leaves the sitemap empty.
 *
 * No setting is introduced: the filter itself is 1.4-compatible behaviour and
 * is not changed, the notice only makes the result visible.
 */
function xsmg_yoast_empty_notice() {
    if (!current_user_can('edit_posts') || !xsmg_yoast_filter_hides_posts()) {
        return;
    }

    echo '<div class="notice notice-warning is-dismissible"><p>'
        . esc_html__('XML Sitemap Generator: в окне 48 часов нет записей с типом схемы NewsArticle — проверьте тип схемы у свежих записей.', 'news-sitemap-generator')
        . '</p></div>';
}
add_action('admin_notices', 'xsmg_yoast_empty_notice');

/**
 * Build and serve the news sitemap, from cache when possible.
 *
 * The document holds the newest XSMG_MAX_URLS posts published inside the
 * XSMG_WINDOW_HOURS window. Google News sitemaps allow at most 1000 URLs
 * per file, so there is one file and no pagination: a site publishing more
 * than 1000 posts in 48 hours sees only the newest 1000.
 */
function xsmg_generate_news_sitemap() {
    $cache_key = XSMG_CACHE_KEY;
    $cached    = get_transient($cache_key);

    $cached_xml     = '';
    $generated_time = 0;

    if (is_array($cached) && isset($cached['xml'], $cached['generated_time'])) {
        $cached_xml     = (string) $cached['xml'];
        $generated_time = (int) $cached['generated_time'];
    } elseif (is_string($cached) && $cached !== '') {
        // Entries written by 1.4 and earlier hold the XML itself.
        $cached_xml     = $cached;
        $generated_time = time();
    }

    if ($cached_xml !== '') {
        xsmg_send_sitemap_headers($generated_time);
        echo $cached_xml; // phpcs:ignore -- escaped XML, cached as a whole.
        flush();
        exit;
    }

    // The sitemap replaces the response WordPress prepared: a buffer started
    // by the theme or a plugin must not prefix the XML with HTML. @ is
    // intentional — a non-removable buffer is not an error worth a warning
    // in the response.
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    // Apache may still gzip (or double-gzip) the body after our headers.
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', 1);
    }
    @ini_set('zlib.output_compression', 'Off');

    try {
        // One query returns full post objects with their caches primed; see
        // xsmg_sitemap_query_args().
        $posts = get_posts(xsmg_sitemap_query_args());

        // Yoast can filter every post out of the window. That is not the same
        // as "no news", so it is logged once per generation (a cache hit
        // returns before this point and logs nothing) and flagged for the
        // admin notice. The filter itself is not changed.
        if (defined('WPSEO_VERSION')) {
            $hides_everything = empty($posts) && xsmg_window_has_posts();

            set_transient(XSMG_YOAST_EMPTY_KEY, $hides_everything ? '1' : '0', XSMG_CACHE_TTL);

            if ($hides_everything) {
                error_log(
                    '[XSMG] Sitemap is empty: Yoast SEO is active and no post in the '
                    . XSMG_WINDOW_HOURS . '-hour window carries the NewsArticle schema '
                    . 'article type. Check the schema article type of recent posts.'
                );
            }
        }

        // Publication language per Google News spec: the two Chinese
        // locales keep their region, every other locale is reduced to its
        // primary subtag.
        $lang = strtolower((string) get_bloginfo('language'));
        if (strpos($lang, 'zh-cn') === 0) {
            $lang = 'zh-cn';
        } elseif (strpos($lang, 'zh-tw') === 0) {
            $lang = 'zh-tw';
        } else {
            $lang = explode('-', $lang)[0];
        }

        $site_name = (string) get_bloginfo('name');

        // esc_html() escapes &, <, > and the quotes, so a title can never
        // terminate the document or open a CDATA section (no CDATA is used).
        // esc_url() is the documented way to emit <loc> and keeps "&" as a
        // valid XML character reference.
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        $xml .= ' xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

        foreach ($posts as $post) {
            $link = get_permalink($post);
            if (!$link) {
                continue;
            }

            $title    = wp_strip_all_tags(get_the_title($post));
            $pub_date = get_the_date('c', $post);

            $xml .= "\t<url>\n";
            $xml .= "\t\t<loc>" . esc_url($link) . "</loc>\n";
            $xml .= "\t\t<news:news>\n";
            $xml .= "\t\t\t<news:publication>\n";
            $xml .= "\t\t\t\t<news:name>" . esc_html(xsmg_clean_xml_text($site_name)) . "</news:name>\n";
            $xml .= "\t\t\t\t<news:language>" . esc_html($lang) . "</news:language>\n";
            $xml .= "\t\t\t</news:publication>\n";
            $xml .= "\t\t\t<news:publication_date>" . esc_html((string) $pub_date) . "</news:publication_date>\n";
            $xml .= "\t\t\t<news:title>" . esc_html(xsmg_clean_xml_text($title)) . "</news:title>\n";
            $xml .= "\t\t</news:news>\n";
            $xml .= "\t</url>\n";
        }

        $xml .= "</urlset>\n";

        $generated_time = time();

        set_transient(
            $cache_key,
            [
                'xml'            => $xml,
                'generated_time' => $generated_time,
                'human_time'     => current_time('mysql'),
            ],
            XSMG_CACHE_TTL
        );

        xsmg_send_sitemap_headers($generated_time);

        echo $xml; // phpcs:ignore -- escaped element by element above.
        flush();
        exit;

    } catch (\Throwable $e) {
        // Details go to the site log only: the response must not leak paths,
        // and the detail itself is written only with WP_DEBUG (SEC-001).
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
            error_log('[XSMG] Sitemap generation error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG only.
        }

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        status_header(500);
        nocache_headers();

        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }

        echo 'Sitemap generation error. Please try again later.';
        exit;
    }
}

/**
 * Clear all sitemap cache transients.
 *
 * The sweep is by name prefix, not by the current key: versions up to 1.4
 * wrote a key per requested page (and could write a key from a raw request
 * value), so a site may still hold transients whose names are not the
 * single cache key of this version.
 */
function xsmg_clear_all_cache() {
    global $wpdb;

    $keys = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE %s
             AND option_name NOT LIKE %s",
            xsmg_cache_like('_transient_'),
            xsmg_cache_like('_transient_timeout_')
        )
    );

    foreach ((array) $keys as $key) {
        $transient_name = substr((string) $key, strlen('_transient_'));
        delete_transient($transient_name);
    }

    // Timeout rows of keys that no longer exist.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            xsmg_cache_like('_transient_timeout_')
        )
    );

    // The Yoast diagnostic is refreshed with the sitemap, so a newly fixed
    // schema type clears the admin notice on the next admin page load.
    delete_transient(XSMG_YOAST_EMPTY_KEY);

    update_option('xsmg_last_cache_clear', current_time('mysql'));
}

/**
 * LIKE pattern matching every transient of this plugin.
 *
 * esc_like() is required: "_" and "%" are LIKE wildcards, and a pattern
 * built by hand could match another plugin's transient names.
 *
 * @param string $name_prefix '_transient_' or '_transient_timeout_'.
 * @return string Pattern with a trailing "%", ready for prepare().
 */
function xsmg_cache_like($name_prefix) {
    global $wpdb;

    return $wpdb->esc_like($name_prefix . XSMG_CACHE_KEY) . '%';
}

/**
 * Clear sitemap cache if affected post is changed.
 *
 * There is deliberately no current_user_can() check: save_post also fires
 * for a scheduled post published by WP-Cron or a post imported by WP-CLI,
 * where no user is logged in, and the sitemap still changed. The capability
 * is enforced by the code that saves the post, not by cache invalidation.
 *
 * @param int $post_id Post ID.
 */
function xsmg_maybe_clear_sitemap_cache($post_id) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'post') {
        return;
    }

    // Posts older than the 48-hour window are not in the sitemap. The
    // timestamp is taken in GMT, like the window in the query, so the site
    // timezone cannot shift the boundary. An empty date (a draft) is not
    // older and clears the cache.
    $post_time = (int) get_post_time('U', true, $post);
    if ($post_time > 0 && $post_time < time() - (XSMG_WINDOW_HOURS * HOUR_IN_SECONDS)) {
        return;
    }

    xsmg_clear_all_cache();
}
add_action('save_post', 'xsmg_maybe_clear_sitemap_cache');
add_action('before_delete_post', 'xsmg_maybe_clear_sitemap_cache');
add_action('trashed_post', 'xsmg_maybe_clear_sitemap_cache');

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
        $wpdb->prepare(
            "SELECT option_name, option_value
             FROM {$wpdb->options}
             WHERE option_name LIKE %s
             AND option_name NOT LIKE %s",
            xsmg_cache_like('_transient_'),
            xsmg_cache_like('_transient_timeout_')
        )
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
 * Transients expire after 30 minutes on their own; this is the daily safety
 * net for entries whose timeout row is gone.
 *
 * @param int $hours Hours threshold.
 * @return int Number of cleared transients.
 */
function xsmg_clear_old_cache($hours = 48) {
    global $wpdb;

    $keys = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name
             FROM {$wpdb->options}
             WHERE option_name LIKE %s
             AND option_name NOT LIKE %s",
            xsmg_cache_like('_transient_'),
            xsmg_cache_like('_transient_timeout_')
        )
    );

    $cleared     = 0;
    $cutoff_time = time() - (absint($hours) * HOUR_IN_SECONDS);

    foreach ((array) $keys as $key) {
        $transient_name = substr((string) $key, strlen('_transient_'));
        $cache_data     = get_transient($transient_name);

        if (is_array($cache_data) && isset($cache_data['generated_time'])) {
            if ((int) $cache_data['generated_time'] < $cutoff_time) {
                delete_transient($transient_name);
                $cleared++;
            }
        }
    }

    return $cleared;
}
