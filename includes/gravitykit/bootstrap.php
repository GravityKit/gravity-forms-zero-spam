<?php
/**
 * Loads GravityKit Foundation and the gravitykit.com edition's features.
 *
 * Only the gravitykit.com build ships this folder and `vendor_prefixed/`. Without the bundled
 * Foundation (a development checkout), this does nothing and Zero Spam runs as the wordpress.org build.
 *
 * @since TBD
 *
 * @return bool False when Foundation says Zero Spam must not load (an old PHP version, or
 *              `?gk_disable_loading` in the URL); true otherwise.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

$gf_zero_spam_preflight = GF_ZERO_SPAM_DIR . 'vendor_prefixed/gravitykit/foundation/src/preflight_check.php';

if ( ! file_exists( $gf_zero_spam_preflight ) ) {
	return true;
}

require_once $gf_zero_spam_preflight;

if ( ! GravityKit\ZeroSpam\Foundation\should_load( GF_ZERO_SPAM_FILE ) ) {
	return false;
}

require_once GF_ZERO_SPAM_DIR . 'vendor/autoload.php';
require_once GF_ZERO_SPAM_DIR . 'vendor_prefixed/autoload.php';

GravityKit\ZeroSpam\Foundation\Core::register( GF_ZERO_SPAM_FILE );

require_once __DIR__ . '/class-gf-zero-spam-email-check-remote.php';
require_once __DIR__ . '/class-gf-zero-spam-gravitykit.php';

GF_Zero_Spam_GravityKit::get_instance()->init();

return true;
