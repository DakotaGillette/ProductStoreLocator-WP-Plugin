<?php
/**
 * Admin settings page (Settings API) and top-level menu.
 *
 * @package ProductStoreLocator
 */

namespace ProductStoreLocator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 *
 * Registers the "Store Locator" top-level menu and a Settings subpage
 * backed by the WordPress Settings API.
 */
final class Settings {

	/**
	 * Option name that stores all plugin settings as an array.
	 */
	public const OPTION_KEY = 'psl_settings';

	/**
	 * Settings API group / page slug.
	 */
	private const GROUP = 'psl_settings_group';

	/**
	 * Settings page menu slug.
	 */
	private const PAGE = 'store-locator-settings';

	/**
	 * Hook suffix of the Settings page, captured in add_menu() and used to
	 * scope asset enqueuing to just this screen.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . PSL_PLUGIN_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Enqueue the shared admin stylesheet on the Settings page only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}
		wp_enqueue_style(
			'psl-admin',
			PSL_PLUGIN_URL . 'assets/css/psl-admin.css',
			array(),
			Plugin::asset_version( 'assets/css/psl-admin.css' )
		);
	}

	/**
	 * URL of the Settings page.
	 *
	 * The page is a submenu of the CPT menu, so it is served through edit.php
	 * with the post_type and page query args.
	 *
	 * @return string
	 */
	public static function settings_url(): string {
		return admin_url( 'edit.php?post_type=' . CPT::POST_TYPE . '&page=' . self::PAGE );
	}

	/**
	 * Add a "Settings" link on the Plugins list row.
	 *
	 * @param array<int, string> $links Existing action links.
	 * @return array<int, string>
	 */
	public function action_links( array $links ): array {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::settings_url() ),
			esc_html__( 'Settings', 'product-store-locator' )
		);
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Return the default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'psl_maps_api_key'       => '',
			'psl_default_zoom'       => 4,
			'psl_map_type'           => 'roadmap',
			'psl_map_style'          => 'default',
			'psl_map_style_json'     => '',
			'psl_marker_color'       => '#d9433f',
			'psl_show_directions_link' => 1,
			// Cost / abuse controls.
			'psl_geocode_server_key'  => '',
			'psl_rate_limit_per_min'  => 10,
			'psl_geocode_monthly_cap' => 10000,
			'psl_map_monthly_cap'     => 10000,
			'psl_behind_cloudflare'   => 0,
		);
	}

	/**
	 * Get a single setting value with a default fallback.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback if not set.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$options  = get_option( self::OPTION_KEY, array() );
		$defaults = self::defaults();

		if ( isset( $options[ $key ] ) && '' !== $options[ $key ] ) {
			return $options[ $key ];
		}

		if ( null !== $default ) {
			return $default;
		}

		return $defaults[ $key ] ?? null;
	}

	/**
	 * Add the Settings subpage under the CPT's top-level "Store Locator" menu.
	 *
	 * The CPT (registered with show_in_menu => true) owns the top-level menu;
	 * this simply nests the Settings page beneath it.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$this->hook_suffix = (string) add_submenu_page(
			CPT::MENU_SLUG,
			__( 'Store Locator Settings', 'product-store-locator' ),
			__( 'Settings', 'product-store-locator' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the option, sections and fields via the Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'psl_section_api',
			__( 'API Keys', 'product-store-locator' ),
			array( $this, 'section_api_intro' ),
			self::PAGE
		);

		add_settings_field(
			'psl_maps_api_key',
			__( 'Google Maps API Key', 'product-store-locator' ),
			array( $this, 'field_api_key' ),
			self::PAGE,
			'psl_section_api',
			array(
				'key'         => 'psl_maps_api_key',
				'description' => __( 'Your Google Maps JavaScript API key. It is used on the frontend with libraries=places.', 'product-store-locator' ),
			)
		);

		add_settings_section(
			'psl_section_map',
			__( 'Map Options', 'product-store-locator' ),
			'__return_false',
			self::PAGE
		);

		add_settings_field(
			'psl_default_zoom',
			__( 'Empty-Map Zoom', 'product-store-locator' ),
			array( $this, 'field_number' ),
			self::PAGE,
			'psl_section_map',
			array(
				'key'         => 'psl_default_zoom',
				'min'         => '0',
				'max'         => '21',
				'step'        => '1',
				'description' => __( 'Zoom level used only if you have zero published stores. Once you have stores, the map always auto-fits to show every marker.', 'product-store-locator' ),
			)
		);

		add_settings_field(
			'psl_map_type',
			__( 'Map Type', 'product-store-locator' ),
			array( $this, 'field_select' ),
			self::PAGE,
			'psl_section_map',
			array(
				'key'     => 'psl_map_type',
				'options' => array(
					'roadmap'   => __( 'Roadmap', 'product-store-locator' ),
					'satellite' => __( 'Satellite', 'product-store-locator' ),
					'hybrid'    => __( 'Hybrid', 'product-store-locator' ),
					'terrain'   => __( 'Terrain', 'product-store-locator' ),
				),
			)
		);

		add_settings_field(
			'psl_map_style',
			__( 'Map Style', 'product-store-locator' ),
			array( $this, 'field_select' ),
			self::PAGE,
			'psl_section_map',
			array(
				'key'         => 'psl_map_style',
				'options'     => array(
					'default'     => __( 'Default', 'product-store-locator' ),
					'silver'      => __( 'Silver', 'product-store-locator' ),
					'retro'       => __( 'Retro', 'product-store-locator' ),
					'night'       => __( 'Night', 'product-store-locator' ),
					'custom_json' => __( 'Custom JSON', 'product-store-locator' ),
				),
				'description' => __( 'Choose a preset styling or provide your own JSON below.', 'product-store-locator' ),
			)
		);

		add_settings_field(
			'psl_map_style_json',
			__( 'Custom Style JSON', 'product-store-locator' ),
			array( $this, 'field_textarea' ),
			self::PAGE,
			'psl_section_map',
			array(
				'key'         => 'psl_map_style_json',
				'description' => __( 'Paste a Google Maps style JSON array. Only used when Map Style is set to "Custom JSON".', 'product-store-locator' ),
			)
		);

		add_settings_field(
			'psl_marker_color',
			__( 'Marker Color', 'product-store-locator' ),
			array( $this, 'field_color' ),
			self::PAGE,
			'psl_section_map',
			array( 'key' => 'psl_marker_color' )
		);

		add_settings_field(
			'psl_show_directions_link',
			__( 'Directions Link', 'product-store-locator' ),
			array( $this, 'field_checkbox' ),
			self::PAGE,
			'psl_section_map',
			array(
				'key'   => 'psl_show_directions_link',
				'label' => __( 'Show a "Get directions" link inside each info window.', 'product-store-locator' ),
			)
		);

		/*
		 * Cost / abuse controls.
		 */
		add_settings_section(
			'psl_section_limits',
			__( 'Usage & Cost Controls', 'product-store-locator' ),
			array( $this, 'section_limits_intro' ),
			self::PAGE
		);

		add_settings_field(
			'psl_geocode_server_key',
			__( 'Geocoding API Key (server-side)', 'product-store-locator' ),
			array( $this, 'field_api_key' ),
			self::PAGE,
			'psl_section_limits',
			array(
				'key'         => 'psl_geocode_server_key',
				'description' => __( 'Optional separate key used only by the server for ZIP/postcode lookups. Restrict this key by IP address (your server), NOT by HTTP referrer. If blank, the main key above is used.', 'product-store-locator' ),
			)
		);

		add_settings_field(
			'psl_rate_limit_per_min',
			__( 'Search Rate Limit (per visitor / minute)', 'product-store-locator' ),
			array( $this, 'field_number' ),
			self::PAGE,
			'psl_section_limits',
			array(
				'key'         => 'psl_rate_limit_per_min',
				'min'         => '0',
				'step'        => '1',
				'description' => __( 'Maximum ZIP/postcode searches allowed per visitor IP each minute. 0 disables the limit.', 'product-store-locator' ),
			)
		);

		add_settings_field(
			'psl_geocode_monthly_cap',
			__( 'Monthly Geocoding Cap', 'product-store-locator' ),
			array( $this, 'field_number' ),
			self::PAGE,
			'psl_section_limits',
			array(
				'key'         => 'psl_geocode_monthly_cap',
				'min'         => '0',
				'step'        => '1',
				'description' => __( 'Hard limit on geocoding API calls per month. When reached, ZIP search stops calling Google until the next month. 0 = unlimited. Cached lookups do NOT count.', 'product-store-locator' ),
			)
		);

		add_settings_field(
			'psl_map_monthly_cap',
			__( 'Monthly Map-Load Cap', 'product-store-locator' ),
			array( $this, 'field_number' ),
			self::PAGE,
			'psl_section_limits',
			array(
				'key'         => 'psl_map_monthly_cap',
				'min'         => '0',
				'step'        => '1',
				'description' => __( 'Hard limit on map loads per month. When reached, the map is hidden until the next month. 0 = unlimited.', 'product-store-locator' ),
			)
		);

		add_settings_field(
			'psl_behind_cloudflare',
			__( 'Behind Cloudflare', 'product-store-locator' ),
			array( $this, 'field_checkbox' ),
			self::PAGE,
			'psl_section_limits',
			array(
				'key'   => 'psl_behind_cloudflare',
				'label' => __( 'Use the CF-Connecting-IP header for per-visitor rate limiting. Enable ONLY if your site is served through Cloudflare and your origin server is locked to Cloudflare IPs (otherwise the header can be spoofed).', 'product-store-locator' ),
			)
		);
	}

	/**
	 * Intro + live usage display for the cost-controls section.
	 *
	 * @return void
	 */
	public function section_limits_intro(): void {
		echo '<p>' . esc_html__(
			'These settings protect you from unexpected Google charges. Most sites never need to touch them — the usage meter above already shows how you\'re tracking. ZIP/postcode geocoding runs on the server so results are cached and rate limited; map loads happen in the browser and are capped below. Set the caps at or under your Google free allotment.',
			'product-store-locator'
		) . '</p>';
	}

	/**
	 * Intro / instructions for the API section.
	 *
	 * @return void
	 */
	public function section_api_intro(): void {
		echo '<p>' . esc_html__(
			'To use this plugin you must create a Google Cloud project and enable the following APIs: Maps JavaScript API, Places API (New), and Geocoding API. Then create an API key and restrict it to your site domain.',
			'product-store-locator'
		) . '</p>';

		echo '<p><a href="https://console.cloud.google.com/google/maps-apis/overview" target="_blank" rel="noopener noreferrer">' .
			esc_html__( 'Open the Google Maps Platform console', 'product-store-locator' ) .
			'</a></p>';
	}

	/**
	 * Render a text field.
	 *
	 * @param array<string, mixed> $args Field args.
	 * @return void
	 */
	public function field_text( array $args ): void {
		$key   = $args['key'];
		$value = self::get( $key );
		$class = $args['class'] ?? 'regular-text';

		printf(
			'<input type="text" class="%1$s" id="%2$s" name="%3$s[%2$s]" value="%4$s" autocomplete="off" />',
			esc_attr( $class ),
			esc_attr( $key ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( (string) $value )
		);

		$this->description( $args );
	}

	/**
	 * Render a masked (password-style) field with a show/hide toggle, for keys.
	 *
	 * @param array<string, mixed> $args Field args.
	 * @return void
	 */
	public function field_api_key( array $args ): void {
		$key   = $args['key'];
		$value = self::get( $key );

		printf(
			'<div class="psl-key-field"><input type="password" class="regular-text" id="%1$s" name="%2$s[%1$s]" value="%3$s" autocomplete="off" /> <button type="button" class="button psl-key-toggle" data-target="%1$s">%4$s</button></div>',
			esc_attr( $key ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( (string) $value ),
			esc_html__( 'Show', 'product-store-locator' )
		);

		$this->description( $args );
	}

	/**
	 * Render a number field.
	 *
	 * @param array<string, mixed> $args Field args.
	 * @return void
	 */
	public function field_number( array $args ): void {
		$key   = $args['key'];
		$value = self::get( $key );

		printf(
			'<input type="number" id="%1$s" name="%2$s[%1$s]" value="%3$s" step="%4$s" min="%5$s" max="%6$s" class="regular-text" />',
			esc_attr( $key ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( (string) $value ),
			esc_attr( (string) ( $args['step'] ?? 'any' ) ),
			esc_attr( (string) ( $args['min'] ?? '' ) ),
			esc_attr( (string) ( $args['max'] ?? '' ) )
		);

		$this->description( $args );
	}

	/**
	 * Render a select field.
	 *
	 * @param array<string, mixed> $args Field args.
	 * @return void
	 */
	public function field_select( array $args ): void {
		$key     = $args['key'];
		$value   = self::get( $key );
		$options = $args['options'] ?? array();

		printf( '<select id="%1$s" name="%2$s[%1$s]">', esc_attr( $key ), esc_attr( self::OPTION_KEY ) );
		foreach ( $options as $option_value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $option_value ),
				selected( $value, $option_value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		$this->description( $args );
	}

	/**
	 * Render a textarea field.
	 *
	 * @param array<string, mixed> $args Field args.
	 * @return void
	 */
	public function field_textarea( array $args ): void {
		$key   = $args['key'];
		$value = self::get( $key );

		printf(
			'<textarea id="%1$s" name="%2$s[%1$s]" rows="8" cols="60" class="large-text code">%3$s</textarea>',
			esc_attr( $key ),
			esc_attr( self::OPTION_KEY ),
			esc_textarea( (string) $value )
		);

		$this->description( $args );
	}

	/**
	 * Render a color input.
	 *
	 * @param array<string, mixed> $args Field args.
	 * @return void
	 */
	public function field_color( array $args ): void {
		$key   = $args['key'];
		$value = self::get( $key );

		printf(
			'<input type="color" id="%1$s" name="%2$s[%1$s]" value="%3$s" />',
			esc_attr( $key ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( (string) $value )
		);

		$this->description( $args );
	}

	/**
	 * Render a checkbox.
	 *
	 * @param array<string, mixed> $args Field args.
	 * @return void
	 */
	public function field_checkbox( array $args ): void {
		$key   = $args['key'];
		$value = (int) self::get( $key );

		printf(
			'<label><input type="checkbox" id="%1$s" name="%2$s[%1$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( $key ),
			esc_attr( self::OPTION_KEY ),
			checked( 1, $value, false ),
			esc_html( $args['label'] ?? '' )
		);
	}

	/**
	 * Print a field description paragraph.
	 *
	 * @param array<string, mixed> $args Field args.
	 * @return void
	 */
	private function description( array $args ): void {
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Sanitize the whole settings array on save.
	 *
	 * @param mixed $input Raw submitted values.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$clean    = array();

		$clean['psl_maps_api_key']         = isset( $input['psl_maps_api_key'] ) ? sanitize_text_field( $input['psl_maps_api_key'] ) : '';
		$clean['psl_default_zoom']         = isset( $input['psl_default_zoom'] ) ? max( 0, min( 21, (int) $input['psl_default_zoom'] ) ) : $defaults['psl_default_zoom'];

		$allowed_types = array( 'roadmap', 'satellite', 'hybrid', 'terrain' );
		$clean['psl_map_type'] = ( isset( $input['psl_map_type'] ) && in_array( $input['psl_map_type'], $allowed_types, true ) )
			? $input['psl_map_type']
			: $defaults['psl_map_type'];

		$allowed_styles = array( 'default', 'silver', 'retro', 'night', 'custom_json' );
		$clean['psl_map_style'] = ( isset( $input['psl_map_style'] ) && in_array( $input['psl_map_style'], $allowed_styles, true ) )
			? $input['psl_map_style']
			: $defaults['psl_map_style'];

		// Store the raw JSON but validate it decodes; keep it as-is otherwise flag.
		$raw_json = isset( $input['psl_map_style_json'] ) ? trim( (string) $input['psl_map_style_json'] ) : '';
		if ( '' !== $raw_json ) {
			$decoded = json_decode( $raw_json, true );
			if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
				add_settings_error(
					self::OPTION_KEY,
					'psl_invalid_json',
					__( 'The custom map style JSON is not valid and was not saved.', 'product-store-locator' ),
					'error'
				);
				$raw_json = '';
			} else {
				// Re-encode to strip anything odd, preserving the array structure.
				$raw_json = wp_json_encode( $decoded );
			}
		}
		$clean['psl_map_style_json'] = $raw_json;

		$color = isset( $input['psl_marker_color'] ) ? sanitize_hex_color( $input['psl_marker_color'] ) : '';
		$clean['psl_marker_color'] = $color ? $color : $defaults['psl_marker_color'];

		$clean['psl_show_directions_link'] = ! empty( $input['psl_show_directions_link'] ) ? 1 : 0;

		// Cost / abuse controls.
		$clean['psl_geocode_server_key']  = isset( $input['psl_geocode_server_key'] ) ? sanitize_text_field( $input['psl_geocode_server_key'] ) : '';
		$clean['psl_rate_limit_per_min']  = isset( $input['psl_rate_limit_per_min'] ) ? max( 0, (int) $input['psl_rate_limit_per_min'] ) : $defaults['psl_rate_limit_per_min'];
		$clean['psl_geocode_monthly_cap'] = isset( $input['psl_geocode_monthly_cap'] ) ? max( 0, (int) $input['psl_geocode_monthly_cap'] ) : $defaults['psl_geocode_monthly_cap'];
		$clean['psl_map_monthly_cap']     = isset( $input['psl_map_monthly_cap'] ) ? max( 0, (int) $input['psl_map_monthly_cap'] ) : $defaults['psl_map_monthly_cap'];
		$clean['psl_behind_cloudflare']   = ! empty( $input['psl_behind_cloudflare'] ) ? 1 : 0;

		return $clean;
	}

	/**
	 * Render the "How to embed" box with the shortcode + copy button.
	 *
	 * @return void
	 */
	private function render_embed_box(): void {
		$shortcode = '[' . Shortcode::TAG . ']';
		?>
		<div class="psl-embed-box card" style="max-width:820px;padding:16px 20px;margin:16px 0;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Add the map to a page', 'product-store-locator' ); ?></h2>
			<p><?php esc_html_e( 'Copy this shortcode and paste it into any page or post where you want the store map to appear:', 'product-store-locator' ); ?></p>

			<p style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
				<input
					type="text"
					readonly
					id="psl-shortcode-field"
					value="<?php echo esc_attr( $shortcode ); ?>"
					onfocus="this.select();"
					style="font-family:monospace;font-size:14px;padding:8px 12px;min-width:280px;flex:0 1 320px;"
				/>
				<button
					type="button"
					class="button button-secondary"
					onclick="var b=this;var t=b.textContent;navigator.clipboard.writeText(document.getElementById('psl-shortcode-field').value).then(function(){b.textContent='<?php echo esc_js( __( 'Copied!', 'product-store-locator' ) ); ?>';setTimeout(function(){b.textContent=t;},1500);});"
				><?php esc_html_e( 'Copy shortcode', 'product-store-locator' ); ?></button>
			</p>

			<p class="description" style="margin-top:12px;">
				<strong><?php esc_html_e( 'Optional height:', 'product-store-locator' ); ?></strong>
				<code>[<?php echo esc_html( Shortcode::TAG ); ?> height="600"]</code>
				<?php esc_html_e( '— sets the map height in pixels (default 500).', 'product-store-locator' ); ?>
			</p>

			<p class="description">
				<?php esc_html_e( 'Ways to add it:', 'product-store-locator' ); ?>
			</p>
			<ul class="description" style="list-style:disc;margin-left:20px;">
				<li><?php esc_html_e( 'Block editor: add a "Shortcode" block (or the "Store Locator" block) and paste the code.', 'product-store-locator' ); ?></li>
				<li><?php esc_html_e( 'WPBakery: add a "Product Store Locator" element from the builder, or drop a "Text Block" / raw shortcode element and paste the code.', 'product-store-locator' ); ?></li>
				<li><?php esc_html_e( 'Classic editor / widgets: paste the shortcode directly.', 'product-store-locator' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render the "Usage this month" card with visual progress meters.
	 *
	 * Always visible (not tucked in the accordion) so cost tracking is the
	 * first thing an admin sees, even if they never touch the advanced caps.
	 *
	 * @return void
	 */
	private function render_usage_meter(): void {
		$usage   = ApiGuard::usage_snapshot();
		$map_cap = (int) self::get( 'psl_map_monthly_cap' );
		$geo_cap = (int) self::get( 'psl_geocode_monthly_cap' );

		$reset_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=psl_reset_usage' ),
			'psl_reset_usage'
		);
		?>
		<div class="psl-usage card" style="max-width:820px;padding:16px 20px;margin:16px 0;">
			<div class="psl-usage__head">
				<h2 style="margin:0;"><?php esc_html_e( 'Google API usage this month', 'product-store-locator' ); ?></h2>
				<span class="psl-usage__month"><?php echo esc_html( $usage['month'] ); ?></span>
			</div>

			<?php
			$this->render_meter_bar( __( 'Map loads', 'product-store-locator' ), (int) $usage['maploads'], $map_cap );
			$this->render_meter_bar( __( 'Geocoding calls', 'product-store-locator' ), (int) $usage['geocode'], $geo_cap );
			?>

			<p style="margin:14px 0 0;">
				<a href="<?php echo esc_url( $reset_url ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Reset usage counters', 'product-store-locator' ); ?>
				</a>
				<span class="description" style="margin-left:8px;">
					<?php esc_html_e( 'Adjust the caps under "Advanced: Usage & Cost Controls" below.', 'product-store-locator' ); ?>
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Render a single labeled progress bar for the usage meter.
	 *
	 * @param string $label Human-readable label.
	 * @param int    $used  Count used so far this month.
	 * @param int    $cap   Configured monthly cap (0 = unlimited).
	 * @return void
	 */
	private function render_meter_bar( string $label, int $used, int $cap ): void {
		$unlimited = $cap <= 0;
		$pct       = $unlimited ? 0 : (int) min( 100, round( ( $used / max( 1, $cap ) ) * 100 ) );
		$state     = $pct >= 90 ? 'danger' : ( $pct >= 70 ? 'warn' : 'ok' );
		$bar_width = $unlimited ? 6 : max( 2, $pct );
		?>
		<div class="psl-meter">
			<div class="psl-meter__row">
				<span class="psl-meter__label"><?php echo esc_html( $label ); ?></span>
				<span class="psl-meter__value">
					<?php if ( $unlimited ) : ?>
						<?php echo esc_html( number_format_i18n( $used ) ); ?>
						<span class="psl-meter__unlimited"><?php esc_html_e( '(unlimited)', 'product-store-locator' ); ?></span>
					<?php else : ?>
						<?php
						printf(
							/* translators: 1: calls used, 2: monthly cap. */
							esc_html__( '%1$s / %2$s', 'product-store-locator' ),
							esc_html( number_format_i18n( $used ) ),
							esc_html( number_format_i18n( $cap ) )
						);
						?>
					<?php endif; ?>
				</span>
			</div>
			<div class="psl-meter__track">
				<div
					class="psl-meter__fill psl-meter__fill--<?php echo esc_attr( $unlimited ? 'ok' : $state ); ?>"
					style="width:<?php echo esc_attr( (string) $bar_width ); ?>%;"
				></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one registered settings section (title, callback, fields) inside
	 * its own form-table. Used instead of do_settings_sections() so the
	 * "Usage & Cost Controls" section can be wrapped in a collapsed accordion.
	 *
	 * @param string $section_id Section ID passed to add_settings_section().
	 * @return void
	 */
	private function render_section( string $section_id ): void {
		global $wp_settings_sections;

		if ( empty( $wp_settings_sections[ self::PAGE ][ $section_id ] ) ) {
			return;
		}

		$section = $wp_settings_sections[ self::PAGE ][ $section_id ];

		if ( $section['title'] ) {
			echo '<h2>' . esc_html( $section['title'] ) . '</h2>';
		}
		if ( $section['callback'] ) {
			call_user_func( $section['callback'], $section );
		}

		echo '<table class="form-table" role="presentation">';
		do_settings_fields( self::PAGE, $section_id );
		echo '</table>';
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'product-store-locator' ) );
		}
		$reset = isset( $_GET['psl_usage_reset'] ) ? sanitize_text_field( wp_unslash( $_GET['psl_usage_reset'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php if ( '1' === $reset ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Usage counters have been reset.', 'product-store-locator' ); ?></p>
				</div>
			<?php endif; ?>

			<?php $this->render_embed_box(); ?>
			<?php $this->render_usage_meter(); ?>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				$this->render_section( 'psl_section_api' );
				$this->render_section( 'psl_section_map' );
				?>
				<details class="psl-accordion">
					<summary><?php esc_html_e( 'Advanced: Usage & Cost Controls', 'product-store-locator' ); ?></summary>
					<div class="psl-accordion__body">
						<?php $this->render_section( 'psl_section_limits' ); ?>
					</div>
				</details>
				<?php submit_button(); ?>
			</form>
		</div>
		<script>
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest && e.target.closest( '.psl-key-toggle' );
			if ( ! btn ) {
				return;
			}
			var input = document.getElementById( btn.getAttribute( 'data-target' ) );
			if ( ! input ) {
				return;
			}
			var showing = input.type === 'text';
			input.type = showing ? 'password' : 'text';
			btn.textContent = showing
				? '<?php echo esc_js( __( 'Show', 'product-store-locator' ) ); ?>'
				: '<?php echo esc_js( __( 'Hide', 'product-store-locator' ) ); ?>';
		} );
		</script>
		<?php
	}
}
