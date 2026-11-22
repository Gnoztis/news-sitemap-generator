<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin updates through the native WordPress mechanism; source — GitHub
 * releases and tags of XSMG_GitHub_Updater::REPO.
 *
 * The source of truth is the highest version across the latest GitHub release
 * and the vX.Y.Z tag list: a repository may hold an old release next to a newer
 * tag, and preferring the release would never offer the newer version. The
 * winning version is cached as a whole; WordPress shows it under Plugins and
 * updates from the package of the source that won. No third-party libraries
 * and no write requests to the repository.
 */
final class XSMG_GitHub_Updater {

    /** GitHub repository the updates come from. */
    public const REPO = 'Gnoztis/news-sitemap-generator';

    /** Plugin slug: repository name and the name of a release asset. */
    public const SLUG = 'news-sitemap-generator';

    /** Transient holding the result of the last successful check. */
    private const TRANSIENT = 'xsmg_github_release';

    /** Transient holding the failure marker of the last check. */
    private const ERROR_TRANSIENT = 'xsmg_github_release_error';

    /** A successful check is cached for six hours. */
    private const CACHE_TTL = 21600;

    /** A failed check is retried after fifteen minutes. */
    private const ERROR_TTL = 900;

    /** Version headers of the main file, read once per request. */
    private static ?array $header = null;

    /**
     * Hooks the update plumbing.
     */
    public function register_hooks(): void {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'rename_source'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'after_upgrade'], 10, 2);
        add_action('load-plugins.php', [$this, 'maybe_force_check']);
        add_filter('plugin_action_links_' . self::basename(), [$this, 'action_links']);
    }

    /**
     * Basename of the installed main plugin file, e.g.
     * "news-sitemap-generator/news-sitemap-generator.php".
     *
     * Computed at runtime, never taken from a constant: the folder is
     * whatever the site has. A GitHub zipball unpacks into
     * Gnoztis-news-sitemap-generator-<sha>, a manual upload can use any name.
     * WordPress keys update_plugins->response by this basename and counts every
     * entry for the admin badge, but lists only entries whose key matches a
     * file from get_plugins() — a hard-coded key shows an "update available"
     * badge with an empty update list.
     *
     * @return string
     */
    public static function basename(): string {
        $basename = plugin_basename(XSMG_PLUGIN_FILE);

        if ($basename === '') {
            // plugin_basename() returns the path relative to the plugins
            // directory; an empty result means the main file is not inside it,
            // so fall back to the documented layout.
            $basename = self::SLUG . '/' . wp_basename(XSMG_PLUGIN_FILE);
        }

        return $basename;
    }

    /**
     * Version of the main plugin file as it is on disk.
     *
     * Deliberately not cached in a static: inside the request that replaces
     * the files, the constant still holds the version loaded at the beginning
     * while the header is already new. get_file_data() reads the first 8 KB of
     * one file, so the cost is negligible next to the transient write it
     * guards.
     *
     * @return string
     */
    private function installed_version(): string {
        $data = function_exists('get_file_data')
            ? get_file_data(XSMG_PLUGIN_FILE, ['Version' => 'Version'], 'plugin')
            : [];

        $version = isset($data['Version']) ? trim((string) $data['Version']) : '';

        return $version !== '' ? $version : XSMG_VERSION;
    }

    /* ============================================================
     *  Updates
     * ============================================================ */

    /**
     * Adds our entry to the update_plugins transient when GitHub has a
     * release newer than the installed version.
     *
     * @param mixed $transient
     * @return mixed
     */
    public function inject_update($transient) {
        if (!is_object($transient)) {
            return $transient;
        }
        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }

        $basename  = self::basename();
        $installed = $this->installed_version();

        // Runs before the early returns: a dangling entry is a leftover of the
        // previous key and must disappear even when GitHub is unreachable now.
        $this->purge_stale_entries($transient, $basename);

        // The entry offers what is already on disk: drop it, so a phantom left
        // by an earlier run disappears on this one.
        $this->purge_installed_entry($transient, $basename, $installed);

        $release = $this->get_release();
        if ($release === null) {
            return $transient;
        }

        // Newer than the running code (constant) and than the files on disk
        // (header), otherwise there is nothing to install.
        if (
            version_compare($release['version'], XSMG_VERSION, '<=')
            || version_compare($release['version'], $installed, '<=')
        ) {
            return $transient;
        }

        $transient->response[$basename] = (object) [
            'id'            => $basename,
            'slug'          => self::SLUG,
            'plugin'        => $basename,
            'new_version'   => $release['version'],
            'url'           => 'https://github.com/' . self::REPO,
            'package'       => $release['package'],
            'tested'        => $release['tested'],
            'requires'      => $release['requires'],
            'requires_php'  => $release['requires_php'],
            'icons'         => [],
            'banners'       => [],
            'compatibility' => new stdClass(),
        ];

        if (isset($transient->no_update) && is_array($transient->no_update)) {
            unset($transient->no_update[$basename]);
        }

        return $transient;
    }

    /**
     * Removes our own update entry when it offers what is already installed.
     *
     * Core compares the entry's new_version with the version from
     * get_plugins() and shows the row as an update anyway until the transient
     * is rebuilt, so the entry must not stay in response.
     *
     * @param mixed  $transient The update_plugins transient.
     * @param string $basename  Our key in the transient.
     * @param string $installed Version from the file on disk.
     */
    private function purge_installed_entry($transient, string $basename, string $installed): void {
        if (!isset($transient->response[$basename]) || !is_object($transient->response[$basename])) {
            return;
        }

        $entry   = $transient->response[$basename];
        $offered = isset($entry->new_version) ? trim((string) $entry->new_version) : '';

        if ($offered === '' || version_compare($offered, $installed, '<=')) {
            unset($transient->response[$basename]);
            return;
        }

        // Not newer than the constant either — the same phantom seen from the
        // other side.
        if (version_compare($offered, XSMG_VERSION, '<=')) {
            unset($transient->response[$basename]);
        }
    }

    /**
     * Drops update entries that point at our main file under a foreign key.
     *
     * Such entries are leftovers of a hard-coded basename or of a folder that
     * was renamed. They make the admin badge count an update that
     * get_plugin_updates() cannot list, because the key does not match any
     * installed plugin file.
     *
     * @param mixed  $transient The update_plugins transient.
     * @param string $basename  Our real basename, kept as is.
     */
    private function purge_stale_entries($transient, string $basename): void {
        $main = wp_basename(XSMG_PLUGIN_FILE);

        foreach (['response', 'no_update'] as $bucket) {
            if (!isset($transient->$bucket) || !is_array($transient->$bucket)) {
                continue;
            }

            foreach ($transient->$bucket as $key => $entry) {
                if ((string) $key === $basename || !is_object($entry)) {
                    continue;
                }

                // Older entries may carry the main file only in `plugin`, and
                // some carry it in `id`; both are checked.
                $plugin = !empty($entry->plugin) ? wp_basename((string) $entry->plugin) : '';
                $id     = !empty($entry->id) ? wp_basename((string) $entry->id) : '';

                if ($main !== $plugin && $main !== $id) {
                    continue;
                }

                unset($transient->$bucket[$key]);
            }
        }
    }

    /**
     * Data for the View plugin details modal.
     *
     * @param mixed  $result
     * @param string $action
     * @param mixed  $args
     * @return mixed
     */
    public function plugin_info($result, $action, $args) {
        if ('plugin_information' !== $action || !is_object($args) || empty($args->slug)) {
            return $result;
        }
        if (self::SLUG !== (string) $args->slug) {
            return $result;
        }

        $release = $this->get_release();
        if ($release === null) {
            return $result;
        }

        $header = $this->header_data();

        return (object) [
            'name'          => 'XML Sitemap Generator (xsmg)',
            'slug'          => self::SLUG,
            'version'       => $release['version'],
            'author'        => '<a href="https://github.com/Gnoztis">Gnostis</a>',
            'homepage'      => 'https://github.com/' . self::REPO,
            'requires'      => $header['requires'],
            'tested'        => $release['tested'],
            'requires_php'  => $header['requires_php'],
            'download_link' => $release['package'],
            'last_updated'  => $release['published_at'],
            'sections'      => [
                'description' => __(
                    'Google News sitemap with the newest posts published within the last 48 hours, served from a 30-minute cache.',
                    'news-sitemap-generator'
                ),
                'changelog'   => $release['body'] !== ''
                    ? wpautop($release['body'])
                    : __('The release description is empty.', 'news-sitemap-generator'),
            ],
        ];
    }

    /**
     * GitHub archives unpack into a directory like
     * Gnoztis-news-sitemap-generator-1a2b3c4 — rename it to the directory the
     * site actually uses (the folder of the installed main file), otherwise
     * WordPress updates the wrong directory or adds a second copy.
     *
     * @param string $source
     * @param string $remote_source
     * @param mixed  $upgrader
     * @param array  $hook_extra
     * @return string
     */
    public function rename_source($source, $remote_source, $upgrader, $hook_extra = []) {
        if (!is_string($source) || !is_string($remote_source)) {
            return $source;
        }
        if (!is_array($hook_extra) || empty($hook_extra['plugin'])) {
            return $source;
        }
        if (self::basename() !== (string) $hook_extra['plugin']) {
            return $source;
        }
        if (!is_dir($source)) {
            return $source;
        }
        if (!is_file(trailingslashit($source) . wp_basename(XSMG_PLUGIN_FILE))) {
            return $source;
        }

        // Rename the unpacked directory to the folder the site actually uses,
        // not to the slug: the update has to replace the installed directory,
        // otherwise WordPress ends up with two copies of the plugin (one of
        // them inactive).
        $folder = dirname(self::basename());
        if ($folder === '.' || $folder === '') {
            // The main file sits directly in the plugins directory, so there
            // is no directory to rename.
            return $source;
        }

        $desired = trailingslashit($remote_source) . $folder;
        if (untrailingslashit($source) === untrailingslashit($desired)) {
            return $source;
        }

        global $wp_filesystem;
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if (!$wp_filesystem) {
            return $source;
        }

        if ($wp_filesystem->move($source, $desired, true)) {
            return trailingslashit($desired);
        }

        return $source;
    }

    /**
     * Reset the update-check cache after an upgrade so the site does not keep
     * advertising the version it has just installed.
     *
     * @param mixed $upgrader
     * @param array $hook_extra
     */
    public function after_upgrade($upgrader = null, $hook_extra = []): void {
        $basename = self::basename();
        $ours     = true;

        if (is_array($hook_extra)) {
            if (!empty($hook_extra['plugins']) && is_array($hook_extra['plugins'])) {
                $ours = in_array($basename, $hook_extra['plugins'], true);
            } elseif (!empty($hook_extra['plugin'])) {
                $ours = $basename === (string) $hook_extra['plugin'];
            }
        }

        if (!$ours) {
            return;
        }

        $this->flush();
    }

    /**
     * Check for updates link in the plugin row.
     *
     * @param string[] $links
     * @return string[]
     */
    public function action_links($links): array {
        if (!current_user_can('update_plugins')) {
            return (array) $links;
        }

        $url = wp_nonce_url(
            add_query_arg('xsmg-force-check', '1', admin_url('plugins.php')),
            'xsmg_force_check'
        );

        $links            = (array) $links;
        $links['xsmg_check'] = '<a href="' . esc_url($url) . '">'
            . esc_html__('Check for updates', 'news-sitemap-generator')
            . '</a>';

        return $links;
    }

    /**
     * Handles the Check for updates click on plugins.php.
     */
    public function maybe_force_check(): void {
        if (!isset($_GET['xsmg-force-check'])) {
            return;
        }
        if (!current_user_can('update_plugins')) {
            return;
        }
        check_admin_referer('xsmg_force_check');

        $this->flush();

        require_once ABSPATH . 'wp-admin/includes/update.php';
        if (function_exists('wp_update_plugins')) {
            wp_update_plugins();
        }

        wp_safe_redirect(admin_url('plugins.php'));
        exit;
    }

    /**
     * Drops every cached value of this class so the next check reads GitHub
     * again instead of serving data read before the files were replaced.
     *
     * Also used by uninstall: the two transients must not outlive the plugin.
     */
    public static function clear_cache(): void {
        delete_transient(self::TRANSIENT);
        delete_transient(self::ERROR_TRANSIENT);
    }

    /**
     * Drops the cached check and the core update transient.
     */
    private function flush(): void {
        self::clear_cache();
        delete_site_transient('update_plugins');

        self::$header = null;
    }

    /* ============================================================
     *  GitHub API
     * ============================================================ */

    /**
     * Latest version data (six hour cache, fifteen minute error cache).
     *
     * @return array<string,mixed>|null
     */
    private function get_release(): ?array {
        $cached = get_transient(self::TRANSIENT);
        if (is_array($cached) && isset($cached['version'], $cached['package'])) {
            return $cached;
        }

        if (get_transient(self::ERROR_TRANSIENT)) {
            return null;
        }

        $release = $this->fetch_release();

        if ($release === null) {
            set_transient(self::ERROR_TRANSIENT, 1, self::ERROR_TTL);
            return null;
        }

        set_transient(self::TRANSIENT, $release, self::CACHE_TTL);

        return $release;
    }

    /**
     * Latest version data: the highest version of both sources.
     *
     * Both sources are always queried (the winner is cached as a whole).
     * Taking the release and using a tag only when there is no release hides a
     * fresh tag behind an older GitHub release: the repository keeps release
     * 1.4.0, the owner pushes tag 1.5.0, and the update is never offered until
     * a release is created by hand.
     *
     * Equal versions: the release wins, it carries the changelog.
     *
     * @return array<string,mixed>|null
     */
    private function fetch_release(): ?array {
        $release = $this->fetch_latest_release();
        $tag     = $this->fetch_latest_tag();

        if ($release === null && $tag === null) {
            return null;
        }
        if ($release === null) {
            return $tag;
        }
        if ($tag === null) {
            return $release;
        }

        return version_compare($tag['version'], $release['version'], '>') ? $tag : $release;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetch_latest_release(): ?array {
        $body = $this->api_get(sprintf('https://api.github.com/repos/%s/releases/latest', self::REPO));
        if ($body === null) {
            return null;
        }

        $version = $this->normalize_version(isset($body['tag_name']) ? (string) $body['tag_name'] : '');
        if ($version === '') {
            return null;
        }

        $package = $this->find_package($body);
        if ($package === '') {
            return null;
        }

        return $this->build_release(
            $version,
            $package,
            isset($body['body']) ? (string) $body['body'] : '',
            isset($body['published_at']) ? (string) $body['published_at'] : ''
        );
    }

    /**
     * Highest semver tag of the repository. Competes with the release in
     * fetch_release(); a tag without a GitHub release is a normal case.
     *
     * @return array<string,mixed>|null
     */
    private function fetch_latest_tag(): ?array {
        $tags = $this->api_get(
            sprintf('https://api.github.com/repos/%s/tags?per_page=30', self::REPO)
        );

        if (!is_array($tags)) {
            return null;
        }

        $best_version = '';
        $best_tag     = '';

        foreach ($tags as $tag) {
            if (!is_array($tag) || empty($tag['name'])) {
                continue;
            }
            $version = $this->normalize_version((string) $tag['name']);
            if ($version === '') {
                continue;
            }
            if ($best_version === '' || version_compare($version, $best_version, '>')) {
                $best_version = $version;
                $best_tag     = (string) $tag['name'];
            }
        }

        if ($best_version === '' || $best_tag === '') {
            return null;
        }

        $package = $this->safe_package_url(
            sprintf(
                'https://api.github.com/repos/%s/zipball/%s',
                self::REPO,
                rawurlencode($best_tag)
            )
        );

        if ($package === '') {
            return null;
        }

        return $this->build_release($best_version, $package, '', '');
    }

    /**
     * @param string $version
     * @param string $package
     * @param string $body
     * @param string $published_at
     * @return array<string,mixed>
     */
    private function build_release(string $version, string $package, string $body, string $published_at): array {
        $header = $this->header_data();

        return [
            'version'      => $version,
            'package'      => $package,
            'body'         => $body,
            'published_at' => $published_at,
            'tested'       => $this->tested_version($header['tested']),
            'requires'     => $header['requires'],
            'requires_php' => $header['requires_php'],
        ];
    }

    /**
     * "Tested up to" turned into the value WordPress compares with.
     *
     * The header holds a branch claim ("7.1"), while the update screen compares
     * the stored value with the full running version ("7.1.5"):
     * version_compare( $update->tested, $cur_wp_version, '>=' ). A branch claim
     * is always lower than a patch release, so a bare "7.1" still renders
     * "Compatibility with WordPress 7.1.5: Not tested".
     *
     * api.wordpress.org resolves this by serving the patch release of the
     * declared branch. The same expansion is done here, so the claim covers the
     * running patch release of the branch the author declared.
     *
     * @param string $tested Header value, e.g. "7.1"; empty when absent.
     * @return string
     */
    private function tested_version(string $tested): string {
        $tested = trim($tested);
        if ($tested === '') {
            return '';
        }

        $running = function_exists('wp_get_wp_version') ? (string) wp_get_wp_version() : '';
        if ($running === '' && isset($GLOBALS['wp_version'])) {
            $running = (string) $GLOBALS['wp_version'];
        }
        $running = trim((string) preg_replace('/-.*/', '', $running));
        if ($running === '') {
            return $tested;
        }

        $tested_parts  = explode('.', $tested);
        $running_parts = explode('.', $running);

        // Already as specific as the running version, or more so.
        if (count($tested_parts) >= count($running_parts)) {
            return $tested;
        }

        // Same branch: the claim covers the running patch release as well.
        if (array_slice($running_parts, 0, count($tested_parts)) === $tested_parts) {
            return $running;
        }

        return $tested;
    }

    /**
     * GET to the GitHub API with our User-Agent and an optional token.
     *
     * @return array<string,mixed>|null
     */
    private function api_get(string $url): ?array {
        $args = [
            'timeout'     => 5,
            'redirection' => 2,
            'headers'     => [
                'Accept'       => 'application/vnd.github+json',
                'User-Agent'   => 'news-sitemap-generator/' . XSMG_VERSION,
                'Content-Type' => 'application/json',
            ],
        ];

        $token = $this->token();
        if ($token !== '') {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }

        // The URL is built from the repository constant, not from user input;
        // scheme and host of the answer are validated separately.
        $response = wp_remote_get($url, $args); // phpcs:ignore -- URL built from a repository constant; scheme and host are validated below.
        if (is_wp_error($response)) {
            return null;
        }
        if (200 !== (int) wp_remote_retrieve_response_code($response)) {
            return null;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($body) ? $body : null;
    }

    /**
     * Archive URL: the release zip asset first, then the GitHub zipball.
     *
     * @param array $body
     */
    private function find_package(array $body): string {
        if (!empty($body['assets']) && is_array($body['assets'])) {
            foreach ($body['assets'] as $asset) {
                if (!is_array($asset) || empty($asset['browser_download_url'])) {
                    continue;
                }
                $name = isset($asset['name']) ? (string) $asset['name'] : '';
                if (!preg_match('/\.zip$/i', $name)) {
                    continue;
                }
                $url = $this->safe_package_url((string) $asset['browser_download_url']);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        if (!empty($body['zipball_url'])) {
            return $this->safe_package_url((string) $body['zipball_url']);
        }

        return '';
    }

    /**
     * HTTPS only and GitHub hosts only: the package must not come from a field
     * a third-party service could tamper with.
     */
    private function safe_package_url(string $url): string {
        $url = esc_url_raw(trim($url));
        if ($url === '') {
            return '';
        }

        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        $host   = wp_parse_url($url, PHP_URL_HOST);

        if ('https' !== strtolower((string) $scheme)) {
            return '';
        }

        $allowed = ['api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com'];
        if (!in_array(strtolower((string) $host), $allowed, true)) {
            return '';
        }

        return $url;
    }

    /**
     * Turns a vX.Y.Z (or X.Y.Z) tag into a version and rejects anything else.
     */
    private function normalize_version(string $tag): string {
        $tag = trim($tag);
        if (!preg_match('/^v?(\d+\.\d+(?:\.\d+)?)$/', $tag, $m)) {
            return '';
        }
        return $m[1];
    }

    /**
     * Optional read token for the GitHub API; never hard-coded.
     */
    private function token(): string {
        $token = defined('XSMG_GITHUB_TOKEN') ? (string) XSMG_GITHUB_TOKEN : '';
        $token = (string) apply_filters('xsmg_github_token', $token);

        return trim($token);
    }

    /**
     * Requires at least / Tested up to / Requires PHP from the plugin header.
     *
     * `tested` and `requires` come from different headers on purpose:
     * WordPress reads Requires at least for the minimum version, while the
     * Plugins screen shows Tested up to in the compatibility column. Filling
     * `tested` with the minimum version makes WordPress report the plugin as
     * "not tested" with the current release.
     *
     * @return array{tested:string,requires:string,requires_php:string}
     */
    private function header_data(): array {
        if (self::$header === null) {
            $data = function_exists('get_file_data')
                ? get_file_data(
                    XSMG_PLUGIN_FILE,
                    [
                        'Requires at least' => 'Requires at least',
                        'Requires PHP'      => 'Requires PHP',
                        'Tested up to'      => 'Tested up to',
                    ],
                    'plugin'
                )
                : [];

            self::$header = [
                'tested'       => isset($data['Tested up to']) ? (string) $data['Tested up to'] : '',
                'requires'     => isset($data['Requires at least']) ? (string) $data['Requires at least'] : '',
                'requires_php' => isset($data['Requires PHP']) ? (string) $data['Requires PHP'] : '',
            ];
        }

        return self::$header;
    }
}
