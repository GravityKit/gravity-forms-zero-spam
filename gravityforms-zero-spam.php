<?php
/**
 * Plugin Name:       Gravity Forms Zero Spam
 * Plugin URI:        https://gravityview.co?utm_source=plugin&utm_campaign=zero-spam&utm_content=pluginuri
 * Description:       Enhance Gravity Forms to include effective anti-spam measures—without using a CAPTCHA.
 * Version:           1.1.3
 * Author:            GravityView
 * Author URI:        https://gravityview.co?utm_source=plugin&utm_campaign=zero-spam&utm_content=authoruri
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

// my mother always said to use things as they're intended or not at all
if ( ! defined( 'WPINC' ) ) {
	die;
}

// clean up after ourselves
register_deactivation_hook( __FILE__, array( 'GF_Zero_Spam', 'deactivate' ) );

// Fire it up
add_action( 'gform_loaded', array( 'GF_Zero_Spam', 'gform_loaded' ) );

class GF_Zero_Spam {

	/**
	 * Instantiate the plugin on Gravity Forms loading
	 */
	public static function gform_loaded() {
		new self;
	}

	/**
	 * Cleans up plugin options when deactivating.
	 *
	 * @return void
	 */
	public static function deactivate() {
		delete_option( 'gf_zero_spam_key' );
	}

	public function __construct() {
		add_action( 'gform_register_init_scripts', array( $this, 'add_key_field' ), 9999 );
		add_filter( 'gform_entry_is_spam', array( $this, 'check_key_field' ), 10, 3 );
	}

	/**
	 * Retrieves the zero spam key (generating if needed)
	 *
	 * @return false|mixed|void
	 */
	public function get_key() {

		$key = get_option( 'gf_zero_spam_key' );

		if ( ! $key ) {
			$key = wp_generate_password( 64, false, false );
			update_option( 'gf_zero_spam_key', $key, false );
		}

		return $key;
	}

	/**
	 * Injects the hidden field and key into the form at submission
	 *
	 * @uses GFFormDisplay::add_init_script() to inject the code into the `gform_post_render` jQuery hook.
	 *
	 * @param array $form The Form Object
	 *
	 * @return void
	 */
	public function add_key_field( $form ) {

		$spam_key = esc_js( $this->get_key() );

		$script = <<<EOD
jQuery( document ).on( 'submit.gravityforms', '.gform_wrapper form', function( event ) {
	jQuery( '<input>' ).attr( 'type', 'hidden' )
		.attr( 'name', 'gf_zero_spam_key' )
		.attr( 'value', '{$spam_key}' )
		.appendTo( jQuery( this ) );
} );
EOD;

		GFFormDisplay::add_init_script( $form['id'], 'gf-zero-spam', GFFormDisplay::ON_PAGE_RENDER, $script );
	}

	/**
	 * Checks for our zero spam key during validation
	 *
	 * @param bool $is_spam Indicates if the submission has been flagged as spam.
	 * @param array $form The form currently being processed.
	 * @param array $entry The entry currently being processed.
	 *
	 * @return bool True: it's spam; False: it's not spam!
	 */
	public function check_key_field( $is_spam = false, $form = array(), $entry = array() ) {

	    // This was not submitted using a web form; created using API
		if ( ! did_action( 'gform_pre_submission' ) ) {
			return $is_spam;
		}

		// Created using REST API or GFAPI
		if ( isset( $entry['user_agent'] ) && 'API' === $entry['user_agent'] ) {
			return $is_spam;
		}

		if ( ! isset( $_POST['gf_zero_spam_key'] ) || html_entity_decode( $_POST['gf_zero_spam_key'] ) !== $this->get_key() ) {
			add_action( 'gform_entry_created', array( $this, 'add_entry_note' ) );

			return true;
		}

		return $is_spam;
	}

	/**
	 * Adds a note to the entry once the spam status is set (GF 2.4.18+).
	 *
	 * @since 1.1.3
	 *
	 * @param array $entry The entry data.
	 */
	public function add_entry_note( $entry ) {

		if ( 'spam' !== rgar( $entry, 'status' ) ) {
			return;
		}

		if ( ! method_exists( 'GFAPI', 'add_note' ) ) {
			return;
		}

		GFAPI::add_note( $entry['id'], 0, 'Zero Spam', __( 'This entry has been marked as spam.', 'gf-zero-spam' ), 'gf-zero-spam', 'success' );
	}

}
