<?php
/**
 * Store details metabox and meta saving.
 *
 * @package ProductStoreLocator
 */

namespace ProductStoreLocator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Metabox
 *
 * Renders the "Store Locator Details" metabox and persists its fields.
 */
final class Metabox {

	/**
	 * Nonce action.
	 */
	private const NONCE_ACTION = 'psl_save_store_meta';

	/**
	 * Nonce field name.
	 */
	private const NONCE_NAME = 'psl_store_meta_nonce';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
		add_action( 'save_post_' . CPT::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Force the classic (non-block) editor for stores so the screen is just
		// the title + our purpose-built form, not the Gutenberg canvas.
		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_block_editor' ), 10, 2 );
		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );
	}

	/**
	 * Disable the block editor for the store post type.
	 *
	 * @param bool   $use_block_editor Whether to use the block editor.
	 * @param string $post_type        Post type being edited.
	 * @return bool
	 */
	public function disable_block_editor( bool $use_block_editor, string $post_type ): bool {
		return CPT::POST_TYPE === $post_type ? false : $use_block_editor;
	}

	/**
	 * Relabel the title field placeholder on the store screen.
	 *
	 * @param string   $text Placeholder text.
	 * @param \WP_Post $post Current post.
	 * @return string
	 */
	public function title_placeholder( string $text, \WP_Post $post ): string {
		if ( CPT::POST_TYPE === $post->post_type ) {
			return __( 'Store name (e.g. Downtown Location)', 'product-store-locator' );
		}
		return $text;
	}

	/**
	 * Register the metabox on the store edit screen.
	 *
	 * @return void
	 */
	public function add_metabox(): void {
		add_meta_box(
			'psl_store_details',
			__( 'Store Locator Details', 'product-store-locator' ),
			array( $this, 'render' ),
			CPT::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Enqueue admin assets only on the store edit screen.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}

		// Media library, for the optional store-logo picker.
		wp_enqueue_media();

		wp_enqueue_style(
			'psl-admin',
			PSL_PLUGIN_URL . 'assets/css/psl-admin.css',
			array(),
			Plugin::asset_version( 'assets/css/psl-admin.css' )
		);

		wp_register_script(
			'psl-admin',
			PSL_PLUGIN_URL . 'assets/js/psl-admin.js',
			// media-editor guarantees wp.media is defined before our logo picker runs.
			array( 'media-editor' ),
			Plugin::asset_version( 'assets/js/psl-admin.js' ),
			true
		);

		wp_localize_script(
			'psl-admin',
			'PSL_ADMIN',
			array(
				'i18n' => array(
					'noResults'  => __( 'No results found.', 'product-store-locator' ),
					'searching'  => __( 'Searching…', 'product-store-locator' ),
					'apiMissing' => __( 'Places search is unavailable. Ensure your API key has the "Places API (New)" enabled in Google Cloud.', 'product-store-locator' ),
					'error'      => __( 'Search failed:', 'product-store-locator' ),
					'selectLogo' => __( 'Select store logo', 'product-store-locator' ),
					'useLogo'    => __( 'Use this logo', 'product-store-locator' ),
				),
			)
		);

		wp_enqueue_script( 'psl-admin' );

		$api_key = (string) Settings::get( 'psl_maps_api_key' );
		if ( '' !== $api_key ) {
			// Google Maps JS with Places for the admin lookup UI.
			wp_enqueue_script(
				'psl-google-maps-admin',
				add_query_arg(
					array(
						'key'       => rawurlencode( $api_key ),
						'libraries' => 'places',
						'loading'   => 'async',
						'callback'  => 'pslAdminMapsReady',
					),
					'https://maps.googleapis.com/maps/api/js'
				),
				array( 'psl-admin' ),
				null,
				array(
					'strategy'  => 'async',
					'in_footer' => true,
				)
			);
		}
	}

	/**
	 * Value for a visibility toggle: default ON until the store has been saved.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return bool
	 */
	private function toggle_value( int $post_id, string $key ): bool {
		if ( ! metadata_exists( 'post', $post_id, $key ) ) {
			return true; // New store: default to shown.
		}
		return (bool) get_post_meta( $post_id, $key, true );
	}

	/**
	 * Render the metabox UI.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$address    = (string) get_post_meta( $post->ID, 'store_address', true );
		$lat        = (string) get_post_meta( $post->ID, 'store_lat', true );
		$lng        = (string) get_post_meta( $post->ID, 'store_lng', true );
		$place_id   = (string) get_post_meta( $post->ID, 'store_place_id', true );
		$phone      = (string) get_post_meta( $post->ID, 'store_phone', true );
		$hours      = (string) get_post_meta( $post->ID, 'store_hours', true );
		$hours_json = (string) get_post_meta( $post->ID, 'store_hours_json', true );
		$utc_offset = (string) get_post_meta( $post->ID, 'store_utc_offset', true );
		$about      = (string) get_post_meta( $post->ID, 'store_about', true );

		// Visibility toggles default to ON for a brand-new store (before first save).
		$show_phone = $this->toggle_value( $post->ID, 'store_show_phone' );
		$show_hours = $this->toggle_value( $post->ID, 'store_show_hours' );
		$show_about = $this->toggle_value( $post->ID, 'store_show_about' );

		$logo_id  = (int) get_post_meta( $post->ID, 'store_logo_id', true );
		$logo_url = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '';

		// Store name mirrors the WP post title, which we hide in favor of this field.
		// Decode entities so a title stored as "America&#8217;s" shows as "America’s".
		$store_name = ( 'Auto Draft' === $post->post_title ) ? '' : Plugin::plain_text( $post->post_title );

		$has_key = '' !== (string) Settings::get( 'psl_maps_api_key' );
		?>
		<div class="psl-metabox">

			<?php if ( ! $has_key ) : ?>
				<p class="psl-notice">
					<?php
					printf(
						/* translators: %s: settings page link. */
						esc_html__( 'Add your Google Maps API key on the %s to enable Google search lookups.', 'product-store-locator' ),
						'<a href="' . esc_url( Settings::settings_url() ) . '">' . esc_html__( 'Settings page', 'product-store-locator' ) . '</a>'
					);
					?>
				</p>
			<?php endif; ?>

			<p class="psl-intro">
				<?php esc_html_e( 'Add a store in three quick steps. Search Google first — it fills in the name, address, phone, hours and even a store photo automatically. (The About text is entered manually — Google does not share business descriptions.) You can edit anything afterward.', 'product-store-locator' ); ?>
				<br>
				<?php esc_html_e( 'A photo from Google is imported as the Featured Image on save (if you haven’t set one). You can always replace it in the sidebar.', 'product-store-locator' ); ?>
			</p>

			<?php /* Step 1 — search */ ?>
			<section class="psl-card">
				<h3 class="psl-card__title"><span class="psl-step">1</span> <?php esc_html_e( 'Find the store on Google', 'product-store-locator' ); ?></h3>
				<div id="psl-combo" class="psl-combo">
					<div class="psl-search-controls">
						<input type="text" id="psl-search-input" class="psl-input psl-input--search" placeholder="<?php esc_attr_e( 'Start typing a business name…', 'product-store-locator' ); ?>" autocomplete="off" />
						<button type="button" class="button button-primary" id="psl-search-button"><?php esc_html_e( 'Search', 'product-store-locator' ); ?></button>
					</div>
					<ul id="psl-search-results" class="psl-search-results" role="listbox" aria-label="<?php esc_attr_e( 'Store search results', 'product-store-locator' ); ?>"></ul>
				</div>
				<p class="psl-help"><?php esc_html_e( 'Results appear as you type. Click one (or use ↑ ↓ and Enter) to auto-fill everything below.', 'product-store-locator' ); ?></p>
			</section>

			<?php /* Step 2 — details */ ?>
			<section class="psl-card">
				<h3 class="psl-card__title"><span class="psl-step">2</span> <?php esc_html_e( 'Confirm the details', 'product-store-locator' ); ?></h3>

				<div class="psl-field">
					<label for="psl-store-name"><?php esc_html_e( 'Store name', 'product-store-locator' ); ?></label>
					<input type="text" id="psl-store-name" class="psl-input" value="<?php echo esc_attr( $store_name ); ?>" placeholder="<?php esc_attr_e( 'Fills in from your Google search', 'product-store-locator' ); ?>" />
				</div>

				<div class="psl-field">
					<label for="psl-store-address"><?php esc_html_e( 'Address', 'product-store-locator' ); ?></label>
					<input type="text" id="psl-store-address" name="store_address" class="psl-input" value="<?php echo esc_attr( $address ); ?>" />
				</div>

				<div class="psl-field">
					<label for="psl-store-phone"><?php esc_html_e( 'Phone', 'product-store-locator' ); ?></label>
					<input type="text" id="psl-store-phone" name="store_phone" class="psl-input" value="<?php echo esc_attr( $phone ); ?>" />
				</div>

				<div class="psl-field">
					<label for="psl-store-hours"><?php esc_html_e( 'Hours', 'product-store-locator' ); ?></label>
					<textarea id="psl-store-hours" name="store_hours" class="psl-input" rows="4" placeholder="<?php esc_attr_e( 'Mon–Fri: 9am–5pm', 'product-store-locator' ); ?>"><?php echo esc_textarea( $hours ); ?></textarea>
					<p class="psl-help"><?php esc_html_e( 'Filled from Google. When picked from search, the map popup also shows a live “Open / Closed” status.', 'product-store-locator' ); ?></p>
					<input type="hidden" id="psl-store-hours-json" name="store_hours_json" value="<?php echo esc_attr( $hours_json ); ?>" />
					<input type="hidden" id="psl-store-utc-offset" name="store_utc_offset" value="<?php echo esc_attr( $utc_offset ); ?>" />
					<input type="hidden" id="psl-store-google-photo" name="store_google_photo" value="" />
				</div>

				<div class="psl-field">
					<label for="psl-store-about"><?php esc_html_e( 'About', 'product-store-locator' ); ?></label>
					<textarea id="psl-store-about" name="store_about" class="psl-input" rows="4" placeholder="<?php esc_attr_e( 'A short description of this location.', 'product-store-locator' ); ?>"><?php echo esc_textarea( $about ); ?></textarea>
				</div>

				<div class="psl-field">
					<span class="psl-label"><?php esc_html_e( 'Store logo (optional)', 'product-store-locator' ); ?></span>
					<div class="psl-logo-picker">
						<div id="psl-logo-preview" class="psl-logo-preview <?php echo $logo_url ? 'has-logo' : ''; ?>">
							<?php if ( $logo_url ) : ?>
								<img src="<?php echo esc_url( $logo_url ); ?>" alt="" />
							<?php endif; ?>
						</div>
						<div class="psl-logo-buttons">
							<button type="button" class="button" id="psl-logo-select"><?php esc_html_e( 'Select logo', 'product-store-locator' ); ?></button>
							<button type="button" class="button-link psl-logo-remove <?php echo $logo_url ? '' : 'hidden'; ?>" id="psl-logo-remove"><?php esc_html_e( 'Remove', 'product-store-locator' ); ?></button>
						</div>
					</div>
					<input type="hidden" id="psl-store-logo-id" name="store_logo_id" value="<?php echo esc_attr( (string) $logo_id ); ?>" />
					<p class="psl-help"><?php esc_html_e( 'A square logo shown as a badge on the store’s map popup. Different from the Featured Image (which is the large photo).', 'product-store-locator' ); ?></p>
				</div>

				<div class="psl-field">
					<span class="psl-label"><?php esc_html_e( 'Map preview', 'product-store-locator' ); ?></span>
					<div id="psl-admin-map" class="psl-admin-map" data-lat="<?php echo esc_attr( $lat ); ?>" data-lng="<?php echo esc_attr( $lng ); ?>">
						<span class="psl-admin-map__empty"><?php esc_html_e( 'The map appears here once a location is set.', 'product-store-locator' ); ?></span>
					</div>
				</div>

				<details class="psl-advanced"<?php echo ( '' !== $lat || '' !== $lng ) ? ' open' : ''; ?>>
					<summary><?php esc_html_e( 'Map coordinates (filled automatically)', 'product-store-locator' ); ?></summary>
					<div class="psl-advanced__body">
						<div class="psl-grid-2">
							<div class="psl-field">
								<label for="psl-store-lat"><?php esc_html_e( 'Latitude', 'product-store-locator' ); ?></label>
								<input type="text" id="psl-store-lat" name="store_lat" class="psl-input" value="<?php echo esc_attr( $lat ); ?>" />
							</div>
							<div class="psl-field">
								<label for="psl-store-lng"><?php esc_html_e( 'Longitude', 'product-store-locator' ); ?></label>
								<input type="text" id="psl-store-lng" name="store_lng" class="psl-input" value="<?php echo esc_attr( $lng ); ?>" />
							</div>
						</div>
						<div class="psl-field">
							<label for="psl-store-place-id"><?php esc_html_e( 'Google Place ID', 'product-store-locator' ); ?></label>
							<input type="text" id="psl-store-place-id" name="store_place_id" class="psl-input" value="<?php echo esc_attr( $place_id ); ?>" readonly />
						</div>
						<p class="psl-help"><?php esc_html_e( 'These come from your Google search. Only change them if you know the exact coordinates.', 'product-store-locator' ); ?></p>
					</div>
				</details>
			</section>

			<?php /* Step 3 — visibility */ ?>
			<section class="psl-card">
				<h3 class="psl-card__title"><span class="psl-step">3</span> <?php esc_html_e( 'Choose what shows on the map', 'product-store-locator' ); ?></h3>
				<p class="psl-help"><?php esc_html_e( 'These control what visitors see in the store’s info popup. Turn off anything you’d rather keep private.', 'product-store-locator' ); ?></p>
				<div class="psl-toggles">
					<label class="psl-toggle">
						<input type="checkbox" name="store_show_phone" value="1" <?php checked( $show_phone ); ?> />
						<span class="psl-toggle__text"><?php esc_html_e( 'Show phone', 'product-store-locator' ); ?></span>
					</label>
					<label class="psl-toggle">
						<input type="checkbox" name="store_show_hours" value="1" <?php checked( $show_hours ); ?> />
						<span class="psl-toggle__text"><?php esc_html_e( 'Show hours', 'product-store-locator' ); ?></span>
					</label>
					<label class="psl-toggle">
						<input type="checkbox" name="store_show_about" value="1" <?php checked( $show_about ); ?> />
						<span class="psl-toggle__text"><?php esc_html_e( 'Show about', 'product-store-locator' ); ?></span>
					</label>
				</div>
			</section>

			<div class="psl-save-row">
				<button type="button" class="button button-primary button-hero" id="psl-bottom-save">
					<?php esc_html_e( 'Save / Publish store', 'product-store-locator' ); ?>
				</button>
				<span class="psl-help"><?php esc_html_e( 'Or use the Publish/Update button in the sidebar.', 'product-store-locator' ); ?></span>
			</div>

		</div>
		<?php
	}

	/**
	 * Save the metabox fields.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function save( int $post_id, \WP_Post $post ): void {
		// Nonce check.
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		// Autosave / capability guards.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( CPT::POST_TYPE !== $post->post_type ) {
			return;
		}

		// Text-ish fields.
		$this->update_text( $post_id, 'store_address' );
		$this->update_text( $post_id, 'store_place_id' );
		$this->update_text( $post_id, 'store_phone' );

		// Optional logo attachment.
		$logo_id = isset( $_POST['store_logo_id'] ) ? absint( wp_unslash( $_POST['store_logo_id'] ) ) : 0;
		update_post_meta( $post_id, 'store_logo_id', $logo_id );

		// Import a Google photo into the media library as the featured image,
		// but only when the admin hasn't already set one.
		$photo_url = isset( $_POST['store_google_photo'] ) ? esc_url_raw( wp_unslash( $_POST['store_google_photo'] ) ) : '';
		if ( '' !== $photo_url && ! has_post_thumbnail( $post_id ) ) {
			$this->import_google_photo( $post_id, $photo_url );
		}
		$this->update_multiline( $post_id, 'store_hours' );
		$this->update_multiline( $post_id, 'store_about' );

		// Coordinates.
		$lat = isset( $_POST['store_lat'] ) ? (float) wp_unslash( $_POST['store_lat'] ) : 0.0;
		$lng = isset( $_POST['store_lng'] ) ? (float) wp_unslash( $_POST['store_lng'] ) : 0.0;
		update_post_meta( $post_id, 'store_lat', $lat );
		update_post_meta( $post_id, 'store_lng', $lng );

		// Structured hours (JSON of periods) + timezone offset, from Places lookup.
		$hours_json = isset( $_POST['store_hours_json'] ) ? wp_unslash( $_POST['store_hours_json'] ) : '';
		update_post_meta( $post_id, 'store_hours_json', CPT::sanitize_json( $hours_json ) );

		$utc_offset = isset( $_POST['store_utc_offset'] ) ? (float) wp_unslash( $_POST['store_utc_offset'] ) : 0.0;
		update_post_meta( $post_id, 'store_utc_offset', $utc_offset );

		// Booleans.
		update_post_meta( $post_id, 'store_show_phone', isset( $_POST['store_show_phone'] ) ? 1 : 0 );
		update_post_meta( $post_id, 'store_show_hours', isset( $_POST['store_show_hours'] ) ? 1 : 0 );
		update_post_meta( $post_id, 'store_show_about', isset( $_POST['store_show_about'] ) ? 1 : 0 );
	}

	/**
	 * Download a Google Places photo into the media library and set it as the
	 * store's featured image. Runs once (only when no thumbnail exists).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Google-hosted image URL.
	 * @return void
	 */
	private function import_google_photo( int $post_id, string $url ): void {
		// Only trust Google-hosted image hosts (guards against SSRF via the field).
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return;
		}
		$allowed = false;
		foreach ( array( 'googleusercontent.com', 'googleapis.com', 'ggpht.com', 'gstatic.com' ) as $suffix ) {
			if ( $host === $suffix || substr( $host, -( strlen( $suffix ) + 1 ) ) === '.' . $suffix ) {
				$allowed = true;
				break;
			}
		}
		if ( ! $allowed ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return;
		}

		// Derive a proper extension from the actual image type.
		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mime = is_array( $info ) && isset( $info['mime'] ) ? $info['mime'] : 'image/jpeg';
		$ext_map = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
		);
		$ext = $ext_map[ $mime ] ?? 'jpg';

		$attach_id = Plugin::sideload_attachment( $tmp, 'store-photo-' . $post_id . '.' . $ext, $post_id, get_the_title( $post_id ) );
		if ( is_wp_error( $attach_id ) ) {
			return;
		}

		set_post_thumbnail( $post_id, $attach_id );
	}

	/**
	 * Update a single-line text meta field.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key / POST field name.
	 * @return void
	 */
	private function update_text( int $post_id, string $key ): void {
		$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		update_post_meta( $post_id, $key, $value );
	}

	/**
	 * Update a multiline text meta field.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key / POST field name.
	 * @return void
	 */
	private function update_multiline( int $post_id, string $key ): void {
		$value = isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
		update_post_meta( $post_id, $key, $value );
	}
}
