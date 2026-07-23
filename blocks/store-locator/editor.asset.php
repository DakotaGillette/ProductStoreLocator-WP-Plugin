<?php
/**
 * Dependency manifest for the block editor script.
 *
 * WordPress reads this file (matching the `file:./editor.js` handle in
 * block.json) to enqueue the editor script with the correct dependencies
 * and version, ensuring the `wp.*` packages are available first.
 *
 * @package ProductStoreLocator
 */

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-element',
		'wp-block-editor',
		'wp-components',
		'wp-i18n',
	),
	'version'      => '1.0.0',
);
