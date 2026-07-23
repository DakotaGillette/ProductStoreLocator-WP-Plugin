<?php
/**
 * Gutenberg block registration.
 *
 * @package ProductStoreLocator
 */

namespace ProductStoreLocator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Block
 *
 * Registers a dynamic "Store Locator" block that reuses the shortcode's
 * render logic so the block and shortcode stay in sync.
 */
final class Block {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register the block from its block.json metadata.
	 *
	 * @return void
	 */
	public function register(): void {
		$block_dir = PSL_PLUGIN_DIR . 'blocks/store-locator';

		if ( ! file_exists( $block_dir . '/block.json' ) ) {
			return;
		}

		register_block_type(
			$block_dir,
			array(
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * Server-side render: delegate to the shortcode renderer.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render( array $attributes = array() ): string {
		$height = isset( $attributes['height'] ) ? (int) $attributes['height'] : 0;

		$shortcode = new Shortcode();

		return $shortcode->render(
			array(
				'height' => $height > 0 ? (string) $height : '',
			)
		);
	}
}
