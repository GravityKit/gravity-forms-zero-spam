<?php
/**
 * Plugin Name: Zero Spam E2E Mail Catcher
 * Description: Captures wp_mail() calls into an option so E2E tests can assert on outgoing email without an SMTP service.
 * Version: 1.0.0
 *
 * Active in the wp-env environment only. Tests fetch / clear via the REST routes
 * registered in zs-e2e-helpers.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ZS_E2E_MAILCATCH_OPTION = '_zs_e2e_captured_mail';

/**
 * Short-circuit wp_mail() and store the message instead of sending.
 *
 * @param null|bool $short_circuit Filtered value. Null lets wp_mail() proceed.
 * @param array     $atts          Compacted args from wp_mail().
 *
 * @return bool Always true after capturing.
 */
add_filter(
	'pre_wp_mail',
	function ( $short_circuit, $atts ) {
		$captured   = get_option( ZS_E2E_MAILCATCH_OPTION, [] );
		$captured[] = [
			'to'          => is_array( $atts['to'] ) ? $atts['to'] : [ (string) $atts['to'] ],
			'subject'     => (string) ( $atts['subject'] ?? '' ),
			'message'     => (string) ( $atts['message'] ?? '' ),
			'headers'     => is_array( $atts['headers'] ?? null ) ? $atts['headers'] : (array) ( $atts['headers'] ?? [] ),
			'attachments' => (array) ( $atts['attachments'] ?? [] ),
			'captured_at' => time(),
		];

		update_option( ZS_E2E_MAILCATCH_OPTION, $captured, false );

		return true;
	},
	10,
	2
);
