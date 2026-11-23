<?php
/**
 * GitHub updater for XML Sitemap Generator (xsmg).
 *
 * GENERATED FILE — do not edit by hand.
 * Source: tools/shared/updater/class-github-updater.php
 * Regenerate: node tools/scripts/sync-updater.mjs news-sitemap-generator
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin updates through the native WordPress mechanism; source — GitHub
 * Releases and tags of the repository constant.
 *
 * The source of truth is the highest version of the GitHub Release and the
 * newest vX.Y.Z tag: WordPress sees the new version, shows it under Plugins
 * and updates from the package of the winning source.
 * No third-party libraries and no write requests to the repository.
 */
final class XSMG_GitHub_Updater {

	private const TRANSIENT       = 'xsmg_github_release';
	private const ERROR_TRANSIENT = 'xsmg_github_release_error';

	private static ?array $header = null;

	/**
	 * Explicit user action, not a scheduled check: must not be answered from
	 * the error backoff, which otherwise hides the fact that no request was
	 * made at all.
	 */
	private static bool $force = false;

	/**
	 * Failure of the request in flight: ['code' => int, 'reason' => string,
	 * 'at' => int]. The reason vocabulary lives in error_reason().
	 *
	 * @var array{code:int,reason:string,at:int}|null
	 */
	private ?array $last_error = null;

	public function register_hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 10, 3 );
		add_filter( 'upgrader_source_selection',             [ $this, 'rename_source' ], 10, 4 );
		add_action( 'upgrader_process_complete',             [ $this, 'after_upgrade' ], 10, 2 );
		add_action( 'load-plugins.php',                      [ $this, 'maybe_force_check' ] );
		add_action( 'admin_notices',                         [ $this, 'admin_notices' ] );
		add_filter( 'plugin_action_links_' . self::basename(), [ $this, 'action_links' ] );
	}

	/**
	 * Basename of the installed main plugin file, e.g.
	 * "news-sitemap-generator/news-sitemap-generator.php".
	 *
	 * Computed at runtime, never taken from a constant: the folder is
	 * whatever the site has. A GitHub zipball unpacks into
	 * Gnoztis-news-sitemap-generator-<sha>, a manual upload can use any name.
	 * WordPress keys update_plugins->response by this basename and counts
	 * every entry for the admin badge, but lists only entries whose key
	 * matches a file from get_plugins() — so a hard-coded key shows an
	 * "update available" badge with an empty update list.
	 */
	public static function basename(): string {
		$basename = plugin_basename( XSMG_PLUGIN_FILE );

		if ( $basename === '' ) {
			// plugin_basename() returns the path relative to the plugin
			// directory; an empty result means the main file was not inside
			// it, so fall back to the documented layout.
			$basename = 'news-sitemap-generator' . '/' . wp_basename( XSMG_PLUGIN_FILE );
		}

		return $basename;
	}

	/* ============================================================
	 *  Updates
	 * ============================================================ */

	/**
	 * Adds our entry to the update_plugins transient when GitHub has a
	 * release newer than the installed version.
	 *
	 * The comparison is made against both the version constant and the
	 * header of the file on disk: inside the request that replaces the
	 * files, core re-writes this transient while the constant still holds
	 * the version loaded at the start of the request. Comparing only with
	 * the constant would re-inject "2.5.0 -> 2.5.0" right after a
	 * successful update, and the phantom entry would live up to 12 hours.
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}

		$basename  = self::basename();
		$installed = $this->installed_version();

		// Runs before the early returns: a dangling entry is a leftover of
		// the previous key and must disappear even when GitHub is
		// unreachable right now.
		$this->purge_stale_entries( $transient, $basename );

		// The entry offers what is already on disk: drop it, so a phantom
		// left by an earlier run disappears on this one.
		$this->purge_installed_entry( $transient, $basename, $installed );

		$release = $this->get_release();
		if ( $release === null ) {
			return $transient;
		}

		// Newer than the running code (constant) and than the files on
		// disk (header), otherwise there is nothing to install.
		if ( version_compare( $release['version'], XSMG_VERSION, '<=' )
			|| version_compare( $release['version'], $installed, '<=' ) ) {
			return $transient;
		}

		$transient->response[ $basename ] = (object) [
			'id'            => $basename,
			'slug'          => 'news-sitemap-generator',
			'plugin'        => $basename,
			'new_version'   => $release['version'],
			'url'           => 'https://github.com/' . 'Gnoztis/news-sitemap-generator',
			'package'       => $release['package'],
			'tested'        => $release['tested'],
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'icons'         => [],
			'banners'       => [],
			'compatibility' => new stdClass(),
		];

		if ( isset( $transient->no_update ) && is_array( $transient->no_update ) ) {
			unset( $transient->no_update[ $basename ] );
		}

		return $transient;
	}

	/**
	 * Version of the main plugin file as it is on disk.
	 *
	 * Deliberately not cached in a static: the whole point is to see the
	 * files after Plugin_Upgrader replaced them within the current request.
	 * get_file_data() reads the first 8 KB of one file, so the cost is
	 * negligible next to the transient write it guards.
	 */
	private function installed_version(): string {
		$data = function_exists( 'get_file_data' )
			? get_file_data( XSMG_PLUGIN_FILE, [ 'Version' => 'Version' ], 'plugin' )
			: [];

		$version = isset( $data['Version'] ) ? trim( (string) $data['Version'] ) : '';

		return $version !== '' ? $version : XSMG_VERSION;
	}

	/**
	 * Removes our own update entry when it offers what is already installed.
	 *
	 * Core compares the entry's new_version with the version from
	 * get_plugins() and shows the row as an update anyway until the
	 * transient is rebuilt, so the entry must not stay in response.
	 *
	 * @param mixed  $transient
	 * @param string $basename Our key in the transient.
	 * @param string $installed Version from the file on disk.
	 */
	private function purge_installed_entry( $transient, string $basename, string $installed ): void {
		if ( ! isset( $transient->response[ $basename ] ) || ! is_object( $transient->response[ $basename ] ) ) {
			return;
		}

		$entry   = $transient->response[ $basename ];
		$offered = isset( $entry->new_version ) ? trim( (string) $entry->new_version ) : '';

		if ( $offered === '' || version_compare( $offered, $installed, '<=' ) ) {
			unset( $transient->response[ $basename ] );
			return;
		}

		// Not newer than the constant either — the same phantom, seen from
		// the other side.
		if ( version_compare( $offered, XSMG_VERSION, '<=' ) ) {
			unset( $transient->response[ $basename ] );
		}
	}

	/**
	 * Drops update entries that point at our main file under a foreign key.
	 *
	 * Such entries are leftovers of the hard-coded basename this class used
	 * before, or of a folder that was renamed. They make the admin badge
	 * count an update that get_plugin_updates() cannot list, because the
	 * key does not match any installed plugin file.
	 *
	 * @param mixed  $transient
	 * @param string $basename Our real basename, kept as is.
	 */
	private function purge_stale_entries( $transient, string $basename ): void {
		$main = wp_basename( XSMG_PLUGIN_FILE );

		foreach ( [ 'response', 'no_update' ] as $bucket ) {
			if ( ! isset( $transient->$bucket ) || ! is_array( $transient->$bucket ) ) {
				continue;
			}

			foreach ( $transient->$bucket as $key => $entry ) {
				if ( (string) $key === $basename || ! is_object( $entry ) ) {
					continue;
				}
				if ( empty( $entry->plugin ) || wp_basename( (string) $entry->plugin ) !== $main ) {
					continue;
				}

				unset( $transient->$bucket[ $key ] );
			}
		}
	}

	/**
	 * Data for the View plugin details modal.
	 *
	 * @param mixed $result
	 * @param string $action
	 * @param mixed $args
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) ) {
			return $result;
		}
		if ( 'news-sitemap-generator' !== (string) $args->slug ) {
			return $result;
		}

		$release = $this->get_release();
		if ( $release === null ) {
			return $result;
		}

		$header = $this->header_data();

		return (object) [
			'name'          => 'XML Sitemap Generator (xsmg)',
			'slug'          => 'news-sitemap-generator',
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/Gnoztis">Gnoztis</a>',
			'homepage'      => 'https://github.com/' . 'Gnoztis/news-sitemap-generator',
			'requires'      => $header['requires'],
			'tested'        => $this->tested_version( $header['tested'] ),
			'requires_php'  => $header['requires_php'],
			'download_link' => $release['package'],
			'last_updated'  => $release['published_at'],
			'sections'      => [
				'description' => __( 'Serves a Google News sitemap with the newest posts published within the last 48 hours (up to 1000 URLs), cached for 30 minutes.', 'news-sitemap-generator' ),
				'changelog'   => $release['body'] !== '' ? wpautop( $release['body'] ) : __( 'Описание изменений в релизе не заполнено.', 'news-sitemap-generator' ),
			],
		];
	}

	/**
	 * GitHub archives unpack into a directory like
	 * Gnoztis-news-sitemap-generator-1a2b3c4 — rename it to the directory the site
	 * actually uses (the folder of the installed main file), otherwise
	 * WordPress updates the wrong directory or adds a second copy.
	 *
	 * @param string $source
	 * @param string $remote_source
	 * @param mixed  $upgrader
	 * @param array  $hook_extra
	 * @return string
	 */
	public function rename_source( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		if ( ! is_string( $source ) || ! is_string( $remote_source ) ) {
			return $source;
		}
		if ( ! is_array( $hook_extra ) ) {
			return $source;
		}

		// A single update carries `plugin`, a bulk update carries `plugins`.
		// Checking only the first form left bulk installs with the folder name
		// taken from the archive, which produces a second copy of the plugin.
		$ours   = self::basename();
		$target = '';

		if ( ! empty( $hook_extra['plugin'] ) ) {
			$target = (string) $hook_extra['plugin'];
		} elseif ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			foreach ( $hook_extra['plugins'] as $candidate ) {
				if ( $ours === (string) $candidate ) {
					$target = $ours;
					break;
				}
			}
		}

		if ( '' === $target || $ours !== $target ) {
			return $source;
		}
		if ( ! is_dir( $source ) ) {
			return $source;
		}
		if ( ! is_file( trailingslashit( $source ) . wp_basename( XSMG_PLUGIN_FILE ) ) ) {
			return $source;
		}

		// Rename the unpacked directory to the folder the site actually
		// uses, not to the slug: the update has to replace the installed
		// directory, otherwise WordPress ends up with two copies of the
		// plugin (one of them inactive).
		$folder = dirname( self::basename() );
		if ( $folder === '.' || $folder === '' ) {
			// The main file sits directly in the plugins directory, so
			// there is no directory to rename.
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $folder;
		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( ! $wp_filesystem ) {
			return $source;
		}

		if ( $wp_filesystem->move( $source, $desired, true ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}

	/**
	 * Reset the update-check cache after an upgrade so we do not keep
	 * advertising an update for the version just installed.
	 */
	public function after_upgrade( $upgrader = null, $hook_extra = [] ): void {
		if ( is_array( $hook_extra ) ) {
			$ours = self::basename();
			$hit  = false;

			if ( ! empty( $hook_extra['plugin'] ) ) {
				$hit = $ours === (string) $hook_extra['plugin'];
			} elseif ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
				$hit = in_array( $ours, $hook_extra['plugins'], true );
			}

			// Another plugin was updated: leave our cached release and backoff
			// alone, otherwise every unrelated update costs a GitHub request.
			if ( ! $hit ) {
				return;
			}
		}

		$this->flush();
	}

	/**
	 * Update state for the /me diagnostics.
	 *
	 * Answers "why does the admin show an update badge with nothing to
	 * update?" in one request: the real basename, and the entry the
	 * update_plugins transient holds for our main file — including the key
	 * it sits under, which is where a mismatch shows up.
	 *
	 * @return array{plugin_basename:string,transient_entry:bool,transient_key:string,new_version:string,package:string,last_error:array{code:int,reason:string,at:int}|null,next_retry_in:int,token_configured:bool}
	 */
	public static function diagnostic(): array {
		$basename = self::basename();

		$out = [
			'plugin_basename'  => $basename,
			'transient_entry'  => false,
			'transient_key'    => '',
			'new_version'      => '',
			'package'          => '',
			'last_error'       => self::saved_error(),
			'next_retry_in'    => self::retry_in(),
			'token_configured' => '' !== self::token(),
		];

		$transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) || ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			return $out;
		}

		$entry = null;
		$key   = '';

		if ( isset( $transient->response[ $basename ] ) && is_object( $transient->response[ $basename ] ) ) {
			$entry = $transient->response[ $basename ];
			$key   = $basename;
		} else {
			// No entry under our key: report a dangling one, if any, so the
			// badge/list mismatch is visible directly.
			$main = wp_basename( XSMG_PLUGIN_FILE );
			foreach ( $transient->response as $candidate_key => $candidate ) {
				if ( ! is_object( $candidate ) || empty( $candidate->plugin ) ) {
					continue;
				}
				if ( wp_basename( (string) $candidate->plugin ) !== $main ) {
					continue;
				}
				$entry = $candidate;
				$key   = (string) $candidate_key;
				break;
			}
		}

		if ( ! is_object( $entry ) ) {
			return $out;
		}

		$out['transient_entry'] = true;
		$out['transient_key']   = $key;
		$out['new_version']     = isset( $entry->new_version ) ? (string) $entry->new_version : '';
		$out['package']         = isset( $entry->package ) ? (string) $entry->package : '';

		return $out;
	}

	/**
	 * Check for updates link in the plugin row.
	 *
	 * @param string[] $links
	 * @return string[]
	 */
	public function action_links( $links ): array {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return (array) $links;
		}

		$url = wp_nonce_url(
			add_query_arg( 'xsmg-force-check', '1', admin_url( 'plugins.php' ) ),
			'xsmg_force_check'
		);

		$links = (array) $links;

		// The last check failed: say so, otherwise "no update" and "the check
		// crashed" look exactly the same in the plugin row.
		$error = self::saved_error();
		if ( null !== $error ) {
			$links['xsmg_state'] = '<span class="xsmg-check-failed" style="color:#b32d2e">'
				. esc_html( self::error_label( $error ) )
				. '</span>';
		}

		$links['xsmg_check'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Проверить обновления', 'news-sitemap-generator' ) . '</a>';

		return $links;
	}

	/**
	 * Explains a failed check on the Plugins screen.
	 *
	 * Without it the failure is invisible: WordPress only shows "no updates",
	 * and an exhausted GitHub quota looks like a plugin without releases.
	 */
	public function admin_notices(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}

		$error = self::saved_error();
		if ( null === $error ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s %s</p></div>',
			esc_html__( 'XML Sitemap Generator (xsmg): проверка обновлений не удалась.', 'news-sitemap-generator' ),
			esc_html( self::error_label( $error ) . '.' ),
			esc_html( self::error_hint( $error ) )
		);
	}

	/**
	 * Handles the Check for updates click on plugins.php.
	 */
	public function maybe_force_check(): void {
		if ( ! isset( $_GET['xsmg-force-check'] ) ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		check_admin_referer( 'xsmg_force_check' );

		// The click is an explicit request for a fresh check: ignore and drop
		// the error backoff, then flush the caches and ask GitHub again.
		self::$force = true;

		$this->flush();

		require_once ABSPATH . 'wp-admin/includes/update.php';
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Drops every cached value of this class.
	 *
	 * Called after an upgrade, so the next run does not reuse the release
	 * data (and the header values) read before the files were replaced.
	 */
	private function flush(): void {
		delete_transient( self::TRANSIENT );
		delete_transient( self::ERROR_TRANSIENT );
		delete_site_transient( 'update_plugins' );

		self::$header = null;
	}

	/* ============================================================
	 *  GitHub API
	 * ============================================================ */

	/**
	 * Latest version data (6 hour cache, 15 minute error cache).
	 */
	private function get_release(): ?array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['version'], $cached['package'] ) ) {
			return $cached;
		}

		// An explicit force-check is not answered from the backoff: the user
		// asked for a request, and a cached failure must not fake an answer.
		if ( ! self::$force && null !== self::saved_error() ) {
			return null;
		}

		$this->last_error = null;
		$release          = $this->fetch_release();

		if ( $release === null ) {
			$error = $this->last_error ?? [
				'code'   => 404,
				'reason' => 'not_found',
				'at'     => time(),
			];

			set_transient( self::ERROR_TRANSIENT, $error, 900 );

			return null;
		}

		// A success clears the backoff: the next scheduled check may run again.
		delete_transient( self::ERROR_TRANSIENT );
		set_transient( self::TRANSIENT, $release, 21600 );

		return $release;
	}

	/**
	 * Latest version data: the highest version of both sources.
	 *
	 * Both sources are always queried (the winner is cached as a whole).
	 * Taking the release and using the tag only when there is no release
	 * hides a fresh tag behind an older GitHub Release: the repository
	 * keeps release 1.6.3, the owner pushes tag 1.7.0, and the update is
	 * never offered until a release is created by hand.
	 *
	 * Equal versions: the release wins, it carries the changelog.
	 */
	private function fetch_release(): ?array {
		$release = $this->fetch_latest_release();
		$tag     = $this->fetch_latest_tag();

		if ( $release === null && $tag === null ) {
			return null;
		}
		if ( $release === null ) {
			return $tag;
		}
		if ( $tag === null ) {
			return $release;
		}

		return version_compare( $tag['version'], $release['version'], '>' ) ? $tag : $release;
	}

	private function fetch_latest_release(): ?array {
		$body = $this->api_get( sprintf( 'https://api.github.com/repos/%s/releases/latest', 'Gnoztis/news-sitemap-generator' ) );
		if ( $body === null ) {
			return null;
		}

		$version = $this->normalize_version( isset( $body['tag_name'] ) ? (string) $body['tag_name'] : '' );
		if ( $version === '' ) {
			return null;
		}

		$package = $this->find_package( $body );
		if ( $package === '' ) {
			return null;
		}

		return $this->build_release(
			$version,
			$package,
			isset( $body['body'] ) ? (string) $body['body'] : '',
			isset( $body['published_at'] ) ? (string) $body['published_at'] : ''
		);
	}

	/**
	 * Highest semver tag of the repository. Competes with the release in
	 * fetch_release(); a tag without a GitHub Release is a normal case.
	 */
	private function fetch_latest_tag(): ?array {
		$tags = $this->api_get(
			sprintf( 'https://api.github.com/repos/%s/tags?per_page=30', 'Gnoztis/news-sitemap-generator' )
		);

		if ( ! is_array( $tags ) ) {
			return null;
		}

		$best_version = '';
		$best_tag     = '';

		foreach ( $tags as $tag ) {
			if ( ! is_array( $tag ) || empty( $tag['name'] ) ) {
				continue;
			}
			$version = $this->normalize_version( (string) $tag['name'] );
			if ( $version === '' ) {
				continue;
			}
			if ( $best_version === '' || version_compare( $version, $best_version, '>' ) ) {
				$best_version = $version;
				$best_tag     = (string) $tag['name'];
			}
		}

		if ( $best_version === '' || $best_tag === '' ) {
			return null;
		}

		$package = $this->safe_package_url(
			sprintf(
				'https://api.github.com/repos/%s/zipball/%s',
				'Gnoztis/news-sitemap-generator',
				rawurlencode( $best_tag )
			)
		);

		if ( $package === '' ) {
			return null;
		}

		return $this->build_release( $best_version, $package, '', '' );
	}

	/**
	 * @param string $version
	 * @param string $package
	 * @param string $body
	 * @param string $published_at
	 */
	private function build_release( string $version, string $package, string $body, string $published_at ): array {
		$header = $this->header_data();

		return [
			'version'      => $version,
			'package'      => $package,
			'body'         => $body,
			'published_at' => $published_at,
			'tested'       => $this->tested_version( $header['tested'] ),
			'requires'     => $header['requires'],
			'requires_php' => $header['requires_php'],
		];
	}

	/**
	 * "Tested up to" turned into the value WordPress compares with.
	 *
	 * The header holds a branch claim ("7.1"), while the update screen
	 * compares the stored value with the full running version ("7.1.5"):
	 * version_compare( $update->tested, $cur_wp_version, '>=' ). A branch
	 * claim is always lower than a patch release, so a bare "7.1" still
	 * renders "Compatibility with WordPress 7.1.5: Not tested".
	 *
	 * api.wordpress.org resolves this by serving the patch release of the
	 * declared branch: readmes say "Tested up to: 7.1" and the API answers
	 * "7.1.5". The same expansion is done here, so the claim covers the
	 * running patch release of the branch the author declared.
	 *
	 * @param string $tested Header value, e.g. "7.1"; empty when absent.
	 * @return string Empty when the header is absent, otherwise a version.
	 */
	private function tested_version( string $tested ): string {
		$tested = trim( $tested );
		if ( '' === $tested ) {
			return '';
		}

		$running = function_exists( 'wp_get_wp_version' ) ? (string) wp_get_wp_version() : '';
		if ( '' === $running && isset( $GLOBALS['wp_version'] ) ) {
			$running = (string) $GLOBALS['wp_version'];
		}
		$running = trim( (string) preg_replace( '/-.*/', '', $running ) );
		if ( '' === $running ) {
			return $tested;
		}

		$tested_parts  = explode( '.', $tested );
		$running_parts = explode( '.', $running );

		// Already as specific as the running version, or more so.
		if ( count( $tested_parts ) >= count( $running_parts ) ) {
			return $tested;
		}

		// Same branch: the claim covers the running patch release as well.
		if ( array_slice( $running_parts, 0, count( $tested_parts ) ) === $tested_parts ) {
			return $running;
		}

		return $tested;
	}

	/**
	 * GET to the GitHub API with our User-Agent and an optional token.
	 *
	 * Failures are recorded in $last_error instead of being flattened to null:
	 * a 403 rate limit, a 404 tag and a dead network need different advice.
	 * A 404 is deliberately not recorded — for releases/latest "no release"
	 * is a normal answer and the tag list may still provide the update.
	 *
	 * @return array<string,mixed>|null
	 */
	private function api_get( string $url ): ?array {
		// The token is attached only to GitHub hosts: the URL comes from the
		// repository constant, never from user input.
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$args  = [
			'timeout'     => 5,
			'redirection' => 2,
			'headers'     => [
				'Accept'       => 'application/vnd.github+json',
				'User-Agent'   => 'XML-Sitemap-Generator-(xsmg)/' . XSMG_VERSION,
				'Content-Type' => 'application/json',
			],
		];

		$token = self::token();
		if ( '' !== $token && ( 'api.github.com' === $host || 'github.com' === $host ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		// The URL is built from the repository constant, not from user
		// input; scheme and host of the answer are validated separately.
		$response = wp_remote_get( $url, $args ); // phpcs:ignore -- URL built from the repository constant; scheme and host are validated below.

		if ( is_wp_error( $response ) ) {
			$this->last_error = [
				'code'   => 0,
				'reason' => 'network',
				'at'     => time(),
			];
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			if ( 404 !== $code ) {
				$this->last_error = [
					'code'   => $code,
					'reason' => self::error_reason( $code, $response ),
					'at'     => time(),
				];
			}
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			$this->last_error = [
				'code'   => $code,
				'reason' => 'invalid',
				'at'     => time(),
			];
			return null;
		}

		return $body;
	}

	/**
	 * Why the request failed, in the vocabulary used by the admin notice.
	 *
	 * @param int              $code     HTTP status.
	 * @param array<string,mixed>|WP_Error $response Raw response.
	 */
	private static function error_reason( int $code, $response ): string {
		if ( 429 === $code ) {
			return 'rate_limit';
		}

		if ( 403 === $code ) {
			// GitHub reports an exhausted quota as a 403 with a zero
			// remaining counter; a plain 403 means the token lacks access.
			$remaining = '';
			if ( ! is_wp_error( $response ) ) {
				$remaining = (string) wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
			}

			return '' === trim( $remaining ) || '0' === trim( $remaining ) ? 'rate_limit' : 'unauthorized';
		}

		if ( 404 === $code ) {
			return 'not_found';
		}

		if ( 401 === $code ) {
			return 'unauthorized';
		}

		return 'invalid';
	}

	/**
	 * Saved failure of the last check, or null when the last check succeeded.
	 *
	 * Before 2.6.2 this transient held a bare `1`, so a rate limit, a missing
	 * tag and a dead network were indistinguishable. An old-style value may
	 * still be in the cache right after the upgrade; it is reported as
	 * "unknown" rather than ignored.
	 *
	 * @return array{code:int,reason:string,at:int}|null
	 */
	private static function saved_error(): ?array {
		$error = get_transient( self::ERROR_TRANSIENT );

		if ( is_array( $error ) && isset( $error['reason'] ) ) {
			return [
				'code'   => (int) ( $error['code'] ?? 0 ),
				'reason' => (string) $error['reason'],
				'at'     => (int) ( $error['at'] ?? 0 ),
			];
		}

		if ( $error ) {
			return [
				'code'   => 0,
				'reason' => 'unknown',
				'at'     => 0,
			];
		}

		return null;
	}

	/**
	 * Seconds until the failed check may be retried; 0 when there is none.
	 */
	private static function retry_in(): int {
		$error = self::saved_error();

		if ( null === $error || $error['at'] <= 0 ) {
			return 0;
		}

		return max( 0, $error['at'] + (int) 900 - time() );
	}

	/**
	 * Human readable form of a failure, e.g. "проверка не удалась: 403
	 * (лимит GitHub API)".
	 *
	 * @param array{code:int,reason:string,at:int} $error
	 */
	private static function error_label( array $error ): string {
		$reasons = [
			'rate_limit'   => __( 'лимит GitHub API', 'news-sitemap-generator' ),
			'not_found'    => __( 'репозиторий или тег не найдены', 'news-sitemap-generator' ),
			'unauthorized' => __( 'токен отклонён', 'news-sitemap-generator' ),
			'network'      => __( 'нет связи с GitHub', 'news-sitemap-generator' ),
			'invalid'      => __( 'неожиданный ответ GitHub', 'news-sitemap-generator' ),
			'unknown'      => __( 'неизвестная ошибка', 'news-sitemap-generator' ),
		];

		$reason = $reasons[ $error['reason'] ] ?? $reasons['unknown'];
		$code   = $error['code'] > 0 ? (string) $error['code'] : '—';

		return sprintf(
			/* translators: 1: HTTP status or a dash, 2: failure reason */
			__( 'проверка не удалась: %1$s (%2$s)', 'news-sitemap-generator' ),
			$code,
			$reason
		);
	}

	/**
	 * What to do about a failure.
	 *
	 * @param array{code:int,reason:string,at:int} $error
	 */
	private static function error_hint( array $error ): string {
		switch ( $error['reason'] ) {
			case 'rate_limit':
				return __( 'Задайте XSMG_GITHUB_TOKEN в wp-config.php: без токена GitHub разрешает 60 запросов в час на IP, с токеном — 5000.', 'news-sitemap-generator' );
			case 'not_found':
				return __( 'Проверьте, что тег вида vX.Y.Z существует в репозитории и репозиторий доступен без авторизации.', 'news-sitemap-generator' );
			case 'unauthorized':
				return __( 'Проверьте XSMG_GITHUB_TOKEN: токен должен иметь доступ на чтение публичного репозитория.', 'news-sitemap-generator' );
			case 'network':
				return __( 'Хостинг не смог открыть api.github.com: проверьте исходящие запросы и DNS.', 'news-sitemap-generator' );
			default:
				return __( 'Повторите проверку позже; если ошибка повторяется, посмотрите ответ api.github.com.', 'news-sitemap-generator' );
		}
	}

	/**
	 * Archive URL: the release zip asset first, then the GitHub zipball.
	 *
	 * @param array $body
	 */
	private function find_package( array $body ): string {
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) ) {
					continue;
				}
				$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
				if ( ! preg_match( '/\.zip$/i', $name ) ) {
					continue;
				}
				$url = $this->safe_package_url( (string) $asset['browser_download_url'] );
				if ( $url !== '' ) {
					return $url;
				}
			}
		}

		if ( ! empty( $body['zipball_url'] ) ) {
			return $this->safe_package_url( (string) $body['zipball_url'] );
		}

		return '';
	}

	/**
	 * HTTPS only and GitHub hosts only — the package must not come
	 * from a field a third-party service could tamper with.
	 */
	private function safe_package_url( string $url ): string {
		$url = esc_url_raw( trim( $url ) );
		if ( $url === '' ) {
			return '';
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		if ( 'https' !== strtolower( (string) $scheme ) ) {
			return '';
		}

		$allowed = [ 'api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com' ];
		if ( ! in_array( strtolower( (string) $host ), $allowed, true ) ) {
			return '';
		}

		return $url;
	}

	private function normalize_version( string $tag ): string {
		$tag = trim( $tag );
		if ( ! preg_match( '/^v?(\d+\.\d+(?:\.\d+)?)$/', $tag, $m ) ) {
			return '';
		}
		return $m[1];
	}

	/**
	 * Personal access token for the GitHub API, if the site provides one.
	 *
	 * Optional: a public repository needs no token, but the anonymous quota is
	 * 60 requests per hour per IP, and several plugins behind one hosting IP
	 * exhaust it. The value is never printed — diagnostic() only reports
	 * whether it is configured.
	 */
	private static function token(): string {
		$token = defined( 'XSMG_GITHUB_TOKEN' ) ? (string) XSMG_GITHUB_TOKEN : '';
		$token = (string) apply_filters( 'xsmg_github_token', $token );
		return trim( $token );
	}

	/**
	 * Version headers of the plugin file.
	 *
	 * `Tested up to` and `Requires at least` are different things: the
	 * first is the newest WordPress the plugin was checked against, the
	 * second is the oldest it runs on. Feeding the minimum into `tested`
	 * makes the update screen show "compatibility: unknown", so they are
	 * read separately and `tested` stays empty when the header is absent.
	 *
	 * @return array{requires:string,requires_php:string,tested:string}
	 */
	private function header_data(): array {
		if ( self::$header === null ) {
			$data = function_exists( 'get_file_data' )
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
				'requires'     => isset( $data['Requires at least'] ) ? (string) $data['Requires at least'] : '',
				'requires_php' => isset( $data['Requires PHP'] ) ? (string) $data['Requires PHP'] : '',
				'tested'       => isset( $data['Tested up to'] ) ? (string) $data['Tested up to'] : '',
			];
		}

		return self::$header;
	}
}
