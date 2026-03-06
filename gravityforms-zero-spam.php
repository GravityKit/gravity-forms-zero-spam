<?php
/**
 * Plugin Name:       Gravity Forms Zero Spam
 * Plugin URI:        https://www.gravitykit.com?utm_source=plugin&utm_campaign=zero-spam&utm_content=pluginuri
 * Description:       Enhance Gravity Forms to include effective anti-spam measures—without using a CAPTCHA.
 * Version:           1.7.1
 * Author:            GravityKit
 * Author URI:        https://www.gravitykit.com?utm_source=plugin&utm_campaign=zero-spam&utm_content=authoruri
 * Requires PHP:      7.4
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       gravity-forms-zero-spam
 */

// My mother always said to use things as they're intended or not at all.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'GF_ZERO_SPAM_BASENAME', plugin_basename( __FILE__ ) );
define( 'GF_ZERO_SPAM_FILE', __FILE__ );
define( 'GF_ZERO_SPAM_DIR', plugin_dir_path( __FILE__ ) );

require_once GF_ZERO_SPAM_DIR . 'includes/class-gf-zero-spam.php';

// Clean up after ourselves.
register_deactivation_hook( __FILE__, [ 'GF_Zero_Spam', 'deactivate' ] );

// Fire it up.
add_action( 'gform_loaded', [ 'GF_Zero_Spam', 'gform_loaded' ] );
