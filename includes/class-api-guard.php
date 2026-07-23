<?php
/**
 * API cost/abuse guard: server-side geocoding proxy, caching,
 * per-IP rate limiting, and hard monthly usage caps.
 *
 * @package ProductStoreLocator
 */

namespace ProductStoreLocator;

defined( 'ABSPATH' ) || exit;

/**
 * Class ApiGuard
 *
 * All billable Google traffic that this plugin can control is funneled
 * through here so it can be cached, rate limited, and capped.
 */
final class ApiGuard {

	/**
	 * REST namespace.
	 */
	public const REST_NS = 'psl/v1';

	/**
	 * Option key for the current month's usage counters.
	 */
	private const USAGE_OPTION = 'psl_usage';

	/**
	 * Cache lifetime for a geocode result (30 days — postal codes are stable).
	 */
	private const CACHE_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_post_psl_reset_usage', array( $this, 'handle_reset_usage' ) );
	}

	/**
	 * Register the REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NS,
			'/geocode',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_geocode' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'query' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Geocoding proxy
	 * ------------------------------------------------------------------ */

	/**
	 * Handle a geocode request from the frontend search box.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_geocode( \WP_REST_Request $request ): \WP_REST_Response {
		$query = trim( (string) $request->get_param( 'query' ) );

		// Basic input validation — reject empty or absurdly long input.
		if ( '' === $query || strlen( $query ) > 100 ) {
			return $this->response( array( 'error' => 'invalid' ), 400 );
		}

		// 1) Serve from cache when possible (free, and immune to caps/limits).
		$cache_key = 'psl_geo_' . md5( strtolower( preg_replace( '/\s+/', ' ', $query ) ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['lat'], $cached['lng'] ) ) {
			return $this->response(
				array(
					'lat'    => (float) $cached['lat'],
					'lng'    => (float) $cached['lng'],
					'cached' => true,
				),
				200
			);
		}

		// 2) Per-IP rate limit.
		if ( ! $this->check_rate_limit() ) {
			return $this->response( array( 'error' => 'rate_limited' ), 429 );
		}

		// 3) Hard monthly cap.
		$cap = (int) Settings::get( 'psl_geocode_monthly_cap' );
		if ( $cap > 0 && $this->usage_get( 'geocode' ) >= $cap ) {
			return $this->response( array( 'error' => 'cap_reached' ), 503 );
		}

		// 4) Call Google server-side with the (hidden) geocoding key.
		$api_key = (string) Settings::get( 'psl_geocode_server_key' );
		if ( '' === $api_key ) {
			// Fall back to the main key (works only if it is not referrer-restricted).
			$api_key = (string) Settings::get( 'psl_maps_api_key' );
		}
		if ( '' === $api_key ) {
			return $this->response( array( 'error' => 'not_configured' ), 500 );
		}

		$result = $this->remote_geocode( $query, $api_key );
		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			$http = ( 'zero_results' === $code ) ? 404 : 502;
			return $this->response( array( 'error' => $code ), $http );
		}

		// Count the billed call and cache the result.
		$this->usage_inc( 'geocode' );
		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $this->response(
			array(
				'lat' => $result['lat'],
				'lng' => $result['lng'],
			),
			200
		);
	}

	/**
	 * Perform the actual server-side Geocoding API request.
	 *
	 * @param string $query   Address / ZIP query.
	 * @param string $api_key Geocoding API key.
	 * @return array{lat:float,lng:float}|\WP_Error
	 */
	private function remote_geocode( string $query, string $api_key ) {
		$url = add_query_arg(
			array(
				'address' => rawurlencode( $query ),
				'key'     => rawurlencode( $api_key ),
			),
			'https://maps.googleapis.com/maps/api/geocode/json'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 8,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'request_failed', 'Geocoding request failed.' );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['status'] ) ) {
			return new \WP_Error( 'bad_response', 'Unexpected geocoding response.' );
		}

		if ( 'ZERO_RESULTS' === $body['status'] ) {
			return new \WP_Error( 'zero_results', 'No results.' );
		}

		if ( 'OK' !== $body['status'] || empty( $body['results'][0]['geometry']['location'] ) ) {
			return new \WP_Error( 'geocode_error', 'Geocoding error: ' . $body['status'] );
		}

		$loc = $body['results'][0]['geometry']['location'];

		return array(
			'lat' => (float) $loc['lat'],
			'lng' => (float) $loc['lng'],
		);
	}

	/* ---------------------------------------------------------------------
	 * Rate limiting
	 * ------------------------------------------------------------------ */

	/**
	 * Enforce a per-IP, per-minute rate limit.
	 *
	 * @return bool True if the request is allowed.
	 */
	private function check_rate_limit(): bool {
		$limit = (int) Settings::get( 'psl_rate_limit_per_min' );
		if ( $limit <= 0 ) {
			return true;
		}

		$key   = 'psl_rl_' . md5( $this->client_ip() );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Best-effort client IP. Uses REMOTE_ADDR (not spoofable headers) by default.
	 *
	 * @return string
	 */
	private function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';

		// When explicitly told the site is behind Cloudflare, prefer the real
		// visitor IP from CF-Connecting-IP. Only trust this if the origin is
		// locked to Cloudflare IPs (otherwise the header is spoofable).
		if ( 1 === (int) Settings::get( 'psl_behind_cloudflare' ) && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$cf = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				$ip = $cf;
			}
		}

		/**
		 * Filter the detected client IP (e.g. to read a trusted proxy header).
		 *
		 * @param string $ip Detected IP.
		 */
		return (string) apply_filters( 'psl_client_ip', $ip );
	}

	/* ---------------------------------------------------------------------
	 * Usage counters (monthly, hard caps)
	 * ------------------------------------------------------------------ */

	/**
	 * Get the current month's usage array, resetting when the month rolls over.
	 *
	 * @return array{month:string,geocode:int,maploads:int}
	 */
	private function usage(): array {
		$usage = get_option( self::USAGE_OPTION, array() );
		$month = gmdate( 'Y-m' );

		if ( ! is_array( $usage ) || empty( $usage['month'] ) || $usage['month'] !== $month ) {
			$usage = array(
				'month'    => $month,
				'geocode'  => 0,
				'maploads' => 0,
			);
			update_option( self::USAGE_OPTION, $usage, false );
		}

		return $usage;
	}

	/**
	 * Read a single counter.
	 *
	 * @param string $key Counter key.
	 * @return int
	 */
	private function usage_get( string $key ): int {
		$usage = $this->usage();
		return (int) ( $usage[ $key ] ?? 0 );
	}

	/**
	 * Increment a single counter.
	 *
	 * @param string $key Counter key.
	 * @return void
	 */
	private function usage_inc( string $key ): void {
		$usage         = $this->usage();
		$usage[ $key ] = (int) ( $usage[ $key ] ?? 0 ) + 1;
		update_option( self::USAGE_OPTION, $usage, false );
	}

	/* ---------------------------------------------------------------------
	 * Map-load cap (static helpers used by the shortcode)
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the monthly map-load cap has been reached.
	 *
	 * @return bool
	 */
	public static function map_cap_reached(): bool {
		$cap = (int) Settings::get( 'psl_map_monthly_cap' );
		if ( $cap <= 0 ) {
			return false;
		}
		$guard = new self();
		return $guard->usage_get( 'maploads' ) >= $cap;
	}

	/**
	 * Record a map load (called when the Maps script is actually emitted).
	 *
	 * @return void
	 */
	public static function record_map_load(): void {
		$guard = new self();
		$guard->usage_inc( 'maploads' );
	}

	/**
	 * Snapshot of the current month's usage for admin display.
	 *
	 * @return array{month:string,geocode:int,maploads:int}
	 */
	public static function usage_snapshot(): array {
		$guard = new self();
		return $guard->usage();
	}

	/* ---------------------------------------------------------------------
	 * Admin: reset usage
	 * ------------------------------------------------------------------ */

	/**
	 * Handle the "reset usage counters" admin action.
	 *
	 * @return void
	 */
	public function handle_reset_usage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'product-store-locator' ) );
		}
		check_admin_referer( 'psl_reset_usage' );

		delete_option( self::USAGE_OPTION );

		wp_safe_redirect(
			add_query_arg(
				array( 'psl_usage_reset' => '1' ),
				Settings::settings_url()
			)
		);
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Build a REST response with a status code.
	 *
	 * @param array<string, mixed> $data   Payload.
	 * @param int                  $status HTTP status.
	 * @return \WP_REST_Response
	 */
	private function response( array $data, int $status ): \WP_REST_Response {
		return new \WP_REST_Response( $data, $status );
	}
}
