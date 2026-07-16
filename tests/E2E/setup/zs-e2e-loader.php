<?php
/**
 * Plugin Name: Zero Spam E2E Loader
 * Description: Tiny stub mu-plugin that loads the actual E2E mu-plugins from a directory mount. Lives at a stable path so the wp-env file-level bind mount survives editor rewrites of the underlying files.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$zs_e2e_dir = WPMU_PLUGIN_DIR . '/zs-e2e';

if ( is_dir( $zs_e2e_dir ) ) {
	foreach ( [ 'zs-e2e-mailcatch.php', 'zs-e2e-helpers.php', 'zs-e2e-shield.php', 'zs-e2e-check-order.php' ] as $file ) {
		$zs_e2e_path = $zs_e2e_dir . '/' . $file;

		if ( file_exists( $zs_e2e_path ) ) {
			require_once $zs_e2e_path;
		}
	}
}
