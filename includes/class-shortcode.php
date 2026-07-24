<?php
/**
 * Frontend shortcode and asset loading.
 *
 * @package ProductStoreLocator
 */

namespace ProductStoreLocator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Shortcode
 *
 * Registers the [product_store_locator] shortcode and enqueues the
 * frontend map assets only when the shortcode/block is actually rendered.
 */
final class Shortcode {

	/**
	 * Shortcode tag.
	 */
	public const TAG = 'product_store_locator';

	/**
	 * Whether the frontend assets have been enqueued this request.
	 *
	 * @var bool
	 */
	private bool $enqueued = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		// Register a native element in the WPBakery Page Builder, if present.
		add_action( 'vc_before_init', array( $this, 'register_wpbakery' ) );
	}

	/**
	 * Map the shortcode to a WPBakery Page Builder element.
	 *
	 * Safe no-op when WPBakery is not installed.
	 *
	 * @return void
	 */
	public function register_wpbakery(): void {
		if ( ! function_exists( 'vc_map' ) ) {
			return;
		}

		vc_map(
			array(
				'name'        => __( 'Product Store Locator', 'product-store-locator' ),
				'base'        => self::TAG,
				// Literal 'Content' so it nests under WPBakery's existing Content tab.
				'category'    => 'Content',
				'icon'        => 'dashicons-location-alt',
				'description' => __( 'Google Maps store locator with ZIP/postcode search.', 'product-store-locator' ),
				'params'      => array(
					array(
						'type'        => 'textfield',
						'heading'     => __( 'Map height (px)', 'product-store-locator' ),
						'param_name'  => 'height',
						'value'       => '',
						'description' => __( 'Optional. Height of the map in pixels (default 500).', 'product-store-locator' ),
					),
				),
			)
		);
	}

	/**
	 * Register (but do not enqueue) the frontend assets.
	 *
	 * @return void
	 */
	public function register_assets(): void {
		wp_register_style(
			'psl-frontend',
			PSL_PLUGIN_URL . 'assets/css/psl-frontend.css',
			array(),
			Plugin::asset_version( 'assets/css/psl-frontend.css' )
		);

		wp_register_script(
			'psl-frontend',
			PSL_PLUGIN_URL . 'assets/js/psl-frontend.js',
			array(),
			Plugin::asset_version( 'assets/js/psl-frontend.js' ),
			true
		);
	}

	/**
	 * Enqueue frontend assets and localize configuration + store data.
	 *
	 * Called lazily from the shortcode/block render so the Google Maps
	 * script only loads on pages that actually contain the map.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( $this->enqueued ) {
			return;
		}
		$this->enqueued = true;

		wp_enqueue_style( 'psl-frontend' );

		$config = array(
			// Only used when there are zero published stores; otherwise the
			// map always auto-fits to the actual store markers.
			'defaultCenter'  => array(
				'lat' => 39.8283,
				'lng' => -98.5795,
			),
			'defaultZoom'    => (int) Settings::get( 'psl_default_zoom' ),
			'searchZoom'     => 12,
			'mapType'        => (string) Settings::get( 'psl_map_type' ),
			'mapStyle'       => (string) Settings::get( 'psl_map_style' ),
			'mapStyleJson'   => (string) Settings::get( 'psl_map_style_json' ),
			'markerColor'    => (string) Settings::get( 'psl_marker_color' ),
			'showDirections' => (bool) Settings::get( 'psl_show_directions_link' ),
			'autoLocate'     => (bool) Settings::get( 'psl_auto_locate' ),
			'geolocateZoom'  => 10,
			'stores'         => Plugin::get_stores_for_frontend(),
			// Server-side geocoding proxy (cached, rate limited, capped).
			// Public/anonymous endpoint — intentionally no nonce (would break on cached pages).
			'geocodeUrl'     => rest_url( ApiGuard::REST_NS . '/geocode' ),
			'searchMinLen'   => 3,
			'weekdays'       => array(
				__( 'Sunday', 'product-store-locator' ),
				__( 'Monday', 'product-store-locator' ),
				__( 'Tuesday', 'product-store-locator' ),
				__( 'Wednesday', 'product-store-locator' ),
				__( 'Thursday', 'product-store-locator' ),
				__( 'Friday', 'product-store-locator' ),
				__( 'Saturday', 'product-store-locator' ),
			),
			'i18n'           => array(
				'directions'   => __( 'Get Directions', 'product-store-locator' ),
				'callUs'       => __( 'Call Us', 'product-store-locator' ),
				'readMore'     => __( 'Read More', 'product-store-locator' ),
				'readLess'     => __( 'Read Less', 'product-store-locator' ),
				'openNow'      => __( 'Open', 'product-store-locator' ),
				'closedNow'    => __( 'Closed', 'product-store-locator' ),
				'openUntil'    => __( 'Open until %s', 'product-store-locator' ),
				'opensAt'      => __( 'Closed · opens %s', 'product-store-locator' ),
				'open24'       => __( 'Open 24 hours', 'product-store-locator' ),
				'closedDay'    => __( 'Closed', 'product-store-locator' ),
				'today'        => __( 'Today', 'product-store-locator' ),
				'close'        => __( 'Close', 'product-store-locator' ),
				'youAreHere'   => __( 'You are here', 'product-store-locator' ),
				'geoError'     => __( 'Location not found. Please try a different ZIP or postcode.', 'product-store-locator' ),
				'rateLimited'  => __( 'Too many searches. Please wait a moment and try again.', 'product-store-locator' ),
				'capReached'   => __( 'Search is temporarily unavailable. Please try again later.', 'product-store-locator' ),
				'searchError'  => __( 'Something went wrong with the search. Please try again.', 'product-store-locator' ),
				'searching'    => __( 'Searching…', 'product-store-locator' ),
			),
		);

		wp_localize_script( 'psl-frontend', 'PSL_DATA', $config );
		wp_enqueue_script( 'psl-frontend' );

		// Only the public Maps JS is needed on the frontend (no Places library,
		// no Geocoding — geocoding runs server-side). Respect the monthly cap.
		$api_key = (string) Settings::get( 'psl_maps_api_key' );
		if ( '' !== $api_key && ! ApiGuard::map_cap_reached() ) {
			ApiGuard::record_map_load();

			wp_enqueue_script(
				'psl-google-maps',
				add_query_arg(
					array(
						'key'      => rawurlencode( $api_key ),
						'loading'  => 'async',
						'callback' => 'pslInitMap',
					),
					'https://maps.googleapis.com/maps/api/js'
				),
				array( 'psl-frontend' ),
				null,
				array(
					'strategy'  => 'async',
					'in_footer' => true,
				)
			);
		}
	}

	/**
	 * Render the shortcode output.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes.
	 * @param string|null                 $content Enclosed content (unused).
	 * @return string
	 */
	public function render( $atts = array(), $content = null ): string {
		$atts = shortcode_atts(
			array(
				'height' => '',
			),
			$atts,
			self::TAG
		);

		// Build the wrapper style: optional height override + brand accent color.
		$style_parts = array();
		if ( '' !== $atts['height'] ) {
			$height = preg_replace( '/[^0-9]/', '', (string) $atts['height'] );
			if ( '' !== $height ) {
				$style_parts[] = sprintf( '--psl-map-height:%dpx', (int) $height );
			}
		}
		$accent = sanitize_hex_color( (string) Settings::get( 'psl_marker_color' ) );
		if ( $accent ) {
			$style_parts[] = '--psl-accent:' . $accent;
		}
		$style = ! empty( $style_parts ) ? ' style="' . esc_attr( implode( ';', $style_parts ) ) . '"' : '';

		$has_key = '' !== (string) Settings::get( 'psl_maps_api_key' );
		$cap_hit = ApiGuard::map_cap_reached();

		// Only load assets (and count a map load) when we will actually show the map.
		if ( $has_key && ! $cap_hit ) {
			$this->enqueue_assets();
		}

		ob_start();
		?>
		<div class="psl-wrapper"<?php echo $style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>>
			<?php if ( ! $has_key ) : ?>
				<p class="psl-error" role="alert">
					<?php esc_html_e( 'The store locator is not yet configured. Please add a Google Maps API key.', 'product-store-locator' ); ?>
				</p>
			<?php elseif ( $cap_hit ) : ?>
				<p class="psl-error" role="alert">
					<?php esc_html_e( 'The map is temporarily unavailable. Please check back later.', 'product-store-locator' ); ?>
				</p>
			<?php else : ?>
				<form class="psl-search" role="search" onsubmit="return false;">
					<label for="psl-zip" class="psl-search__label">
						<?php esc_html_e( 'Find a store near you', 'product-store-locator' ); ?>
					</label>
					<div class="psl-search__controls">
						<input
							type="text"
							id="psl-zip"
							class="psl-search__input"
							placeholder="<?php esc_attr_e( 'ZIP or postcode', 'product-store-locator' ); ?>"
							autocomplete="postal-code"
							inputmode="text"
						/>
						<button type="button" id="psl-zip-search" class="psl-search__button">
							<?php esc_html_e( 'Search', 'product-store-locator' ); ?>
						</button>
					</div>
					<p id="psl-search-status" class="psl-search__status" role="status" aria-live="polite"></p>
				</form>

				<div class="psl-map-area">
					<div id="psl-map" class="psl-map" role="application" aria-label="<?php esc_attr_e( 'Store locator map', 'product-store-locator' ); ?>"></div>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
