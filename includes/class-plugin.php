<?php
/**
 * Main plugin orchestrator.
 *
 * @package ProductStoreLocator
 */

namespace ProductStoreLocator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 *
 * Wires together the individual components and loads the text domain.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor is private to enforce the singleton.
	 */
	private function __construct() {}

	/**
	 * Initialise all components and hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( CPT::class, 'register_post_type' ) );
		add_action( 'init', array( CPT::class, 'register_meta' ) );

		( new Settings() )->hooks();
		( new Metabox() )->hooks();
		( new Shortcode() )->hooks();
		( new Block() )->hooks();
		( new ApiGuard() )->hooks();
		( new ImportExport() )->hooks();
		( new Updater() )->hooks();

		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load the plugin translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'product-store-locator',
			false,
			dirname( PSL_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Version string for an asset, based on its file modification time.
	 *
	 * This guarantees browsers (and CDNs like Cloudflare) fetch the newest
	 * file whenever it changes, instead of serving a stale cached copy.
	 *
	 * @param string $relative_path Path relative to the plugin root, e.g. 'assets/js/psl-admin.js'.
	 * @return string
	 */
	public static function asset_version( string $relative_path ): string {
		$file = PSL_PLUGIN_DIR . ltrim( $relative_path, '/\\' );
		if ( file_exists( $file ) ) {
			return (string) filemtime( $file );
		}
		return PSL_VERSION;
	}

	/**
	 * Sideload a local temp file into the media library.
	 *
	 * Shared by the Google-photo auto-import (Metabox) and the JSON
	 * import/export feature (ImportExport) so both go through one path.
	 *
	 * @param string $tmp_path Path to a temp file (e.g. from download_url() or wp_tempnam()).
	 * @param string $filename Desired filename, including extension.
	 * @param int    $post_id  Post to attach the media to.
	 * @param string $title    Attachment title.
	 * @return int|\WP_Error Attachment ID, or WP_Error on failure.
	 */
	public static function sideload_attachment( string $tmp_path, string $filename, int $post_id, string $title = '' ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp_path,
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id, $title );

		if ( is_wp_error( $attachment_id ) && file_exists( $tmp_path ) ) {
			wp_delete_file( $tmp_path );
		}

		return $attachment_id;
	}

	/**
	 * Decode HTML entities to real UTF-8 characters for safe use as plain text.
	 *
	 * The frontend inserts these strings via JS textContent (never innerHTML),
	 * so decoding is safe: "America&#8217;s" becomes "America’s" instead of
	 * displaying the raw entity. Idempotent — plain strings pass through.
	 *
	 * @param string $value Possibly entity-encoded string.
	 * @return string
	 */
	public static function plain_text( string $value ): string {
		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Build the array of store data used by the frontend map.
	 *
	 * Only published stores are included. Fields hidden by the per-store
	 * visibility toggles are stripped so no private data reaches the browser.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_stores_for_frontend(): array {
		$query = new \WP_Query(
			array(
				'post_type'      => CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'no_found_rows'  => true,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$stores = array();

		foreach ( $query->posts as $post ) {
			$lat = (float) get_post_meta( $post->ID, 'store_lat', true );
			$lng = (float) get_post_meta( $post->ID, 'store_lng', true );

			// A store with no coordinates cannot be placed on the map.
			if ( 0.0 === $lat && 0.0 === $lng ) {
				continue;
			}

			$show_phone = (bool) get_post_meta( $post->ID, 'store_show_phone', true );
			$show_hours = (bool) get_post_meta( $post->ID, 'store_show_hours', true );
			$show_about = (bool) get_post_meta( $post->ID, 'store_show_about', true );

			// Structured hours + timezone for live open/closed status.
			$hours_periods = array();
			if ( $show_hours ) {
				$raw = (string) get_post_meta( $post->ID, 'store_hours_json', true );
				if ( '' !== $raw ) {
					$decoded = json_decode( $raw, true );
					if ( is_array( $decoded ) ) {
						$hours_periods = $decoded;
					}
				}
			}

			$photo   = (string) get_the_post_thumbnail_url( $post->ID, 'medium_large' );
			$logo_id = (int) get_post_meta( $post->ID, 'store_logo_id', true );
			$logo    = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '';

			$stores[] = array(
				'id'           => $post->ID,
				// Decode HTML entities (e.g. &#8217; &#8211;) so the map's JS
				// textContent shows real characters, not the entity text.
				'name'         => self::plain_text( get_the_title( $post ) ),
				'address'      => self::plain_text( (string) get_post_meta( $post->ID, 'store_address', true ) ),
				'lat'          => $lat,
				'lng'          => $lng,
				'photo'        => $photo ? $photo : '',
				'logo'         => $logo ? $logo : '',
				'phone'        => $show_phone ? self::plain_text( (string) get_post_meta( $post->ID, 'store_phone', true ) ) : '',
				'hours'        => $show_hours ? self::plain_text( (string) get_post_meta( $post->ID, 'store_hours', true ) ) : '',
				'hoursPeriods' => $hours_periods,
				'utcOffset'    => $show_hours ? (float) get_post_meta( $post->ID, 'store_utc_offset', true ) : 0,
				'about'        => $show_about ? self::plain_text( (string) get_post_meta( $post->ID, 'store_about', true ) ) : '',
				'show_phone'   => $show_phone,
				'show_hours'   => $show_hours,
				'show_about'   => $show_about,
			);
		}

		wp_reset_postdata();

		return $stores;
	}
}
