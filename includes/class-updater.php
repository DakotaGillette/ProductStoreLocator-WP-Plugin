<?php
/**
 * Self-hosted plugin updater backed by a public GitHub repository.
 *
 * @package ProductStoreLocator
 */

namespace ProductStoreLocator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Updater
 *
 * Lets WordPress's native "Update available" / "Update Now" flow work
 * against this plugin's public GitHub repo, with no separate update server.
 * The check compares the `Version:` header on the repo's `main` branch
 * against the installed PSL_VERSION.
 */
final class Updater {

	/**
	 * GitHub repo coordinates.
	 */
	private const GITHUB_OWNER  = 'DakotaGillette';
	private const GITHUB_REPO   = 'ProductStoreLocator-WP-Plugin';
	private const GITHUB_BRANCH = 'main';

	/**
	 * Transient key caching the remote version check.
	 */
	private const CACHE_KEY = 'psl_update_check';

	/**
	 * How long a successful check is cached before re-hitting GitHub.
	 */
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_folder' ), 10, 4 );
		add_filter( 'plugin_action_links_' . PSL_PLUGIN_BASENAME, array( $this, 'action_links' ) );
		add_action( 'admin_init', array( $this, 'maybe_force_check' ) );
		add_action( 'admin_notices', array( $this, 'render_check_notice' ) );
	}

	/**
	 * The plugin's folder-name slug, e.g. "product-store-locator".
	 *
	 * @return string
	 */
	private function slug(): string {
		return dirname( PSL_PLUGIN_BASENAME );
	}

	/**
	 * Public repo URL.
	 *
	 * @return string
	 */
	private function repo_url(): string {
		return 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO;
	}

	/**
	 * Direct, unauthenticated zip download of the branch — this is what
	 * WordPress's upgrader fetches when "Update Now" is clicked.
	 *
	 * @return string
	 */
	private function package_url(): string {
		return $this->repo_url() . '/archive/refs/heads/' . self::GITHUB_BRANCH . '.zip';
	}

	/**
	 * Fetch (and cache) the version currently committed on GitHub's main branch.
	 *
	 * @return array{version:string}|false
	 */
	private function remote_info() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$url = 'https://raw.githubusercontent.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO . '/' . self::GITHUB_BRANCH . '/product-store-locator.php';

		$response = wp_remote_get( $url, array( 'timeout' => 8 ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache the failure briefly so a transient outage doesn't hammer GitHub.
			set_transient( self::CACHE_KEY, false, 15 * MINUTE_IN_SECONDS );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! preg_match( '/Version:\s*([0-9][0-9.]*)/', $body, $matches ) ) {
			set_transient( self::CACHE_KEY, false, 15 * MINUTE_IN_SECONDS );
			return false;
		}

		$info = array( 'version' => trim( $matches[1] ) );
		set_transient( self::CACHE_KEY, $info, self::CACHE_TTL );

		return $info;
	}

	/**
	 * Inject an update entry into the update_plugins transient when GitHub's
	 * main branch is ahead of the installed version.
	 *
	 * @param object $transient The update_plugins site transient.
	 * @return object
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote = $this->remote_info();
		if ( ! $remote ) {
			return $transient;
		}

		if ( version_compare( $remote['version'], PSL_VERSION, '>' ) ) {
			$transient->response[ PSL_PLUGIN_BASENAME ] = (object) array(
				'slug'         => $this->slug(),
				'plugin'       => PSL_PLUGIN_BASENAME,
				'new_version'  => $remote['version'],
				'url'          => $this->repo_url(),
				'package'      => $this->package_url(),
				'tested'       => get_bloginfo( 'version' ),
				'requires'     => '6.0',
				'requires_php' => '8.0',
			);
			unset( $transient->no_update[ PSL_PLUGIN_BASENAME ] );
		} else {
			unset( $transient->response[ PSL_PLUGIN_BASENAME ] );
			$transient->no_update[ PSL_PLUGIN_BASENAME ] = (object) array(
				'slug'        => $this->slug(),
				'plugin'      => PSL_PLUGIN_BASENAME,
				'new_version' => PSL_VERSION,
				'url'         => $this->repo_url(),
				'package'     => '',
			);
		}

		return $transient;
	}

	/**
	 * Supply the "View details" popup content on the Plugins screen.
	 *
	 * @param false|object|array $result The result object/array, or false.
	 * @param string              $action The type of information being requested.
	 * @param object              $args   Plugin API arguments.
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug() ) {
			return $result;
		}

		$remote = $this->remote_info();
		if ( ! $remote ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Product Store Locator',
			'slug'          => $this->slug(),
			'version'       => $remote['version'],
			'author'        => '<a href="https://github.com/' . self::GITHUB_OWNER . '">' . esc_html( self::GITHUB_OWNER ) . '</a>',
			'homepage'      => $this->repo_url(),
			'requires'      => '6.0',
			'requires_php'  => '8.0',
			'download_link' => $this->package_url(),
			'sections'      => array(
				'description' => __( 'Google Maps store locator. Updates are published directly to GitHub; this shows whatever is currently on the main branch.', 'product-store-locator' ),
			),
		);
	}

	/**
	 * GitHub's branch zip extracts to a "{repo}-{branch}" folder; rename it
	 * to match this plugin's installed folder name so WordPress's upgrader
	 * replaces the right directory instead of installing a duplicate.
	 *
	 * @param string      $source        Path to the extracted (mismatched) folder.
	 * @param string      $remote_source Path to the parent temp directory.
	 * @param \WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Extra arguments, including the plugin being updated.
	 * @return string|\WP_Error
	 */
	public function fix_source_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== PSL_PLUGIN_BASENAME ) {
			return $source;
		}

		$desired = trailingslashit( (string) $remote_source ) . $this->slug() . '/';

		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		if ( $wp_filesystem && $wp_filesystem->move( $source, $desired, true ) ) {
			return $desired;
		}

		return $source;
	}

	/**
	 * Add a "Check for updates" link on the Plugins list row.
	 *
	 * @param array<int, string> $links Existing action links.
	 * @return array<int, string>
	 */
	public function action_links( array $links ): array {
		$url = wp_nonce_url(
			add_query_arg( 'psl_check_update', '1', admin_url( 'plugins.php' ) ),
			'psl_check_update'
		);

		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'product-store-locator' ) . '</a>';

		return $links;
	}

	/**
	 * Handle a manual "Check for updates" click: bypass all caches and
	 * force WordPress to re-check immediately.
	 *
	 * @return void
	 */
	public function maybe_force_check(): void {
		if ( empty( $_GET['psl_check_update'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		check_admin_referer( 'psl_check_update' );

		delete_transient( self::CACHE_KEY );
		delete_site_transient( 'update_plugins' );

		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		$clean_url = remove_query_arg( array( 'psl_check_update', '_wpnonce' ) );
		wp_safe_redirect( add_query_arg( 'psl_checked', '1', $clean_url ) );
		exit;
	}

	/**
	 * Show a one-time notice with the result of a manual check.
	 *
	 * @return void
	 */
	public function render_check_notice(): void {
		if ( empty( $_GET['psl_checked'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$remote = $this->remote_info();

		if ( ! $remote ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html__( 'Product Store Locator: could not reach GitHub to check for updates.', 'product-store-locator' )
			);
			return;
		}

		if ( version_compare( $remote['version'], PSL_VERSION, '>' ) ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: version available on GitHub, 2: installed version. */
						__( 'Product Store Locator: version %1$s is available (you have %2$s). See the update below.', 'product-store-locator' ),
						$remote['version'],
						PSL_VERSION
					)
				)
			);
		} else {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: installed version. */
						__( 'Product Store Locator: you are up to date (v%s).', 'product-store-locator' ),
						PSL_VERSION
					)
				)
			);
		}
	}
}
