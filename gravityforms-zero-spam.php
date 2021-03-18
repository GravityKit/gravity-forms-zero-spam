<?php
/**
 * Plugin Name:       Gravity Forms Zero Spam
 * Plugin URI:        https://gravityview.co?utm_source=plugin&utm_campaign=zero-spam&utm_content=pluginuri
 * Description:       Enhance Gravity Forms to include effective anti-spam measures—without using a CAPTCHA.
 * Version:           1.0.7
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
		add_action( 'wp_print_footer_scripts', array( $this, 'add_key_field' ), 9999 );
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
	 * Adds inject the hidden field and key into the form at submission
	 *
	 * @return void
	 */
	public function add_key_field() {
		?>
        <script type='text/javascript'>
	        if ( window.jQuery ) {
		        jQuery( document ).ready( function ( $ ) {
			        var gforms = '.gform_wrapper form';
			        $( document ).on( 'submit', gforms, function () {
				        $( '<input>' ).attr( 'type', 'hidden' )
					        .attr( 'name', 'gf_zero_spam_key' )
					        .attr( 'value', '<?php echo esc_js( $this->get_key() ); ?>' )
					        .appendTo( gforms );
				        return true;
			        } );
		        } );
	        }
        </script>
		<?php
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

		if ( ! isset( $_POST['gf_zero_spam_key'] ) ) {
			return true;
		}

		if ( $_POST['gf_zero_spam_key'] !== $this->get_key() ) {
			return true;
		}

		return $is_spam;
	}
}
