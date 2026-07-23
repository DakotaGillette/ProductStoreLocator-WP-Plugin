<?php
/**
 * Custom post type registration for stores.
 *
 * @package ProductStoreLocator
 */

namespace ProductStoreLocator;

defined( 'ABSPATH' ) || exit;

/**
 * Class CPT
 *
 * Registers the `store_location` custom post type and its meta fields.
 */
final class CPT {

	/**
	 * Post type key.
	 */
	public const POST_TYPE = 'store_location';

	/**
	 * Top-level admin menu slug shared with the settings page.
	 *
	 * Points directly at the store list so the parent menu link opens the
	 * "All Stores" screen instead of an empty page.
	 */
	public const MENU_SLUG = 'edit.php?post_type=store_location';

	/**
	 * Meta keys and their sanitization callbacks.
	 *
	 * @return array<string, array{type:string, sanitize:callable}>
	 */
	public static function meta_fields(): array {
		return array(
			'store_address'    => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_text_field',
			),
			'store_lat'        => array(
				'type'     => 'number',
				'sanitize' => array( self::class, 'sanitize_float' ),
			),
			'store_lng'        => array(
				'type'     => 'number',
				'sanitize' => array( self::class, 'sanitize_float' ),
			),
			'store_place_id'   => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_text_field',
			),
			'store_logo_id'    => array(
				'type'     => 'integer',
				'sanitize' => 'absint',
			),
			'store_phone'      => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_text_field',
			),
			'store_hours'      => array(
				'type'     => 'string',
				'sanitize' => array( self::class, 'sanitize_multiline' ),
			),
			'store_hours_json' => array(
				'type'     => 'string',
				'sanitize' => array( self::class, 'sanitize_json' ),
			),
			'store_utc_offset' => array(
				'type'     => 'number',
				'sanitize' => array( self::class, 'sanitize_float' ),
			),
			'store_about'      => array(
				'type'     => 'string',
				'sanitize' => array( self::class, 'sanitize_multiline' ),
			),
			'store_show_phone' => array(
				'type'     => 'boolean',
				'sanitize' => array( self::class, 'sanitize_bool' ),
			),
			'store_show_hours' => array(
				'type'     => 'boolean',
				'sanitize' => array( self::class, 'sanitize_bool' ),
			),
			'store_show_about' => array(
				'type'     => 'boolean',
				'sanitize' => array( self::class, 'sanitize_bool' ),
			),
		);
	}

	/**
	 * Register the custom post type.
	 *
	 * @return void
	 */
	public static function register_post_type(): void {
		$labels = array(
			'name'                  => _x( 'Stores', 'Post type general name', 'product-store-locator' ),
			'singular_name'         => _x( 'Store', 'Post type singular name', 'product-store-locator' ),
			'menu_name'             => _x( 'Store Locator', 'Admin Menu text', 'product-store-locator' ),
			'name_admin_bar'        => _x( 'Store', 'Add New on Toolbar', 'product-store-locator' ),
			'add_new'               => __( 'Add New', 'product-store-locator' ),
			'add_new_item'          => __( 'Add New Store', 'product-store-locator' ),
			'new_item'              => __( 'New Store', 'product-store-locator' ),
			'edit_item'             => __( 'Edit Store', 'product-store-locator' ),
			'view_item'             => __( 'View Store', 'product-store-locator' ),
			'all_items'             => __( 'All Stores', 'product-store-locator' ),
			'search_items'          => __( 'Search Stores', 'product-store-locator' ),
			'not_found'             => __( 'No stores found.', 'product-store-locator' ),
			'not_found_in_trash'    => __( 'No stores found in Trash.', 'product-store-locator' ),
			'featured_image'        => __( 'Store Image', 'product-store-locator' ),
			'set_featured_image'    => __( 'Set store image', 'product-store-locator' ),
			'remove_featured_image' => __( 'Remove store image', 'product-store-locator' ),
			'items_list'            => __( 'Stores list', 'product-store-locator' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			// Let the CPT create its own top-level "Store Locator" menu.
			// The Settings page is attached to it as a submenu (see Settings::add_menu()).
			'show_in_menu'        => true,
			'menu_position'       => 26,
			'menu_icon'           => 'dashicons-location-alt',
			'show_in_rest'        => true,
			'hierarchical'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'capability_type'     => 'post',
			// No 'editor' — the store form (metabox) is the whole edit screen.
			'supports'            => array( 'title', 'thumbnail' ),
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register post meta so it is sanitized and (optionally) exposed to REST.
	 *
	 * @return void
	 */
	public static function register_meta(): void {
		foreach ( self::meta_fields() as $key => $config ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'              => $config['type'],
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => $config['sanitize'],
					'auth_callback'     => static function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	/**
	 * Sanitize a floating point coordinate.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	public static function sanitize_float( $value ): float {
		return (float) $value;
	}

	/**
	 * Sanitize a boolean-ish checkbox value.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function sanitize_bool( $value ): bool {
		return (bool) $value;
	}

	/**
	 * Sanitize a multiline text field while preserving line breaks.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_multiline( $value ): string {
		return sanitize_textarea_field( (string) $value );
	}

	/**
	 * Sanitize a JSON string by validating it decodes; re-encode to normalize.
	 *
	 * @param mixed $value Raw value.
	 * @return string Valid JSON, or empty string.
	 */
	public static function sanitize_json( $value ): string {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		$decoded = json_decode( $value, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return '';
		}
		return (string) wp_json_encode( $decoded );
	}
}
