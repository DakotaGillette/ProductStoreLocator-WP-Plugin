<?php
/**
 * Uninstall routine for Product Store Locator.
 *
 * Removes plugin options. Store posts and their meta are intentionally
 * left in place so content is not lost on an accidental uninstall; delete
 * the "Stores" manually if you want them gone.
 *
 * @package ProductStoreLocator
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'psl_settings' );
