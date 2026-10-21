<?php
/**
 * PHPUnit bootstrap for gkunit: loads the WordPress test suite, then Gravity Forms and Zero Spam on
 * `muplugins_loaded`, as a real site loads them. No test reaches the network.
 */

$zero_spam_tests_wp_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/tmp/wordpress-tests-lib';
$zero_spam_tests_gf_dir = getenv( 'GF_PLUGIN_DIR' ) ? getenv( 'GF_PLUGIN_DIR' ) : '/tmp/gravityforms';
$zero_spam_plugin_dir   = dirname( __DIR__ );

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $zero_spam_plugin_dir . '/vendor/yoast/phpunit-polyfills' );
}

require_once $zero_spam_tests_wp_dir . '/includes/functions.php';

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

tests_add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		/**
		 * Lets a test answer an outbound request. Anything unanswered fails, so no test reaches the network.
		 *
		 * @param false|array $response Response array, or false when no test answered.
		 * @param array       $args     Request arguments.
		 * @param string      $url      Request URL.
		 */
		$response = apply_filters( 'gf_zero_spam_tests_http', false, $args, $url );

		return false === $response ? new WP_Error( 'http_blocked_in_tests', $url ) : $response;
	},
	10,
	3
);

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $zero_spam_tests_gf_dir, $zero_spam_plugin_dir ) {
		require_once $zero_spam_tests_gf_dir . '/gravityforms.php';
		require_once GFCommon::get_base_path() . '/form_display.php';

		// Gravity Forms' output buffer would swallow PHPUnit's progress output.
		remove_action( 'init', [ 'GFForms', 'init_buffer' ] );

		require_once $zero_spam_plugin_dir . '/gravityforms-zero-spam.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		if ( function_exists( 'gf_upgrade' ) ) {
			gf_upgrade()->maybe_upgrade();
		}
	}
);

require_once $zero_spam_tests_wp_dir . '/includes/bootstrap.php';
