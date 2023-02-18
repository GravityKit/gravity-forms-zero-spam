<?php

if ( ! class_exists( 'GFForms' ) || ! is_callable( array( 'GFForms', 'include_addon_framework' ) ) ) {
	return;
}

GFForms::include_addon_framework();

/**
 * @since 1.2
 */
class GF_Zero_Spam_AddOn extends GFAddOn {

	protected $_slug = 'gf-zero-spam';
	protected $_path = GF_ZERO_SPAM_BASENAME;
	protected $_full_path = __FILE__;
	protected $_title = 'Gravity Forms Zero Spam';
	protected $_short_title = 'Zero Spam';

	public function init() {
		parent::init();

		add_filter( 'gform_form_settings_fields', array( $this, 'add_settings_field' ), 10, 2 );
		add_filter( 'gform_tooltips', array( $this, 'add_tooltip' ) );

		// Adding at 20 priority so anyone filtering the default priority (10) will define the default, but the
		// per-form setting may still _override_ the default.
		add_filter( 'gf_zero_spam_check_key_field', array( $this, 'filter_gf_zero_spam_check_key_field' ), 20, 2 );
	}

	/**
	 * Use per-form settings to determine whether to check for spam.
	 *
	 * @param bool $check_key_field
	 * @param array $form
	 *
	 * @return array|mixed
	 */
	public function filter_gf_zero_spam_check_key_field( $check_key_field = true, $form = array() ) {

		// The setting has been set, but it's not enabled.
		if ( isset( $form['enableGFZeroSpam'] ) && empty( $form['enableGFZeroSpam'] ) ) {
			return false;
		}

		return $check_key_field;
	}

	/**
	 * Include custom tooltip text for the Zero Spam setting in the Form Settings page
	 *
	 * @param array $tooltips Key/Value pair of tooltip/tooltip text
	 *
	 * @return array
	 */
	public function add_tooltip( $tooltips ) {

		$tooltips['enableGFZeroSpam'] = esc_html__( 'Enable to fight spam using a simple, effective method that is more effective than the built-in anti-spam honeypot.', 'gf-zero-spam' );

		return $tooltips;
	}

	/**
	 * Adds the Zero Spam field to the "Form Options" settings group in GF 2.5+
	 *
	 * @see https://docs.gravityforms.com/gform_form_settings_fields/
	 *
	 * @param array $fields Form Settings fields.
	 * @param array $form The current form
	 *
	 * @return array
	 */
	function add_settings_field( $fields, $form = array() ) {

		$fields['form_options']['fields'][] = array(
              'name' => 'enableGFZeroSpam',
              'type' => 'toggle',
              'label' => esc_html__( 'Prevent spam using Gravity Forms Zero Spam', 'gf-zero-spam' ),
              'tooltip' => gform_tooltip( 'enableGFZeroSpam', '', true ),
              'default_value' => apply_filters( 'gf_zero_spam_check_key_field', true, $form ),
		);

		return $fields;
	}

	/**
	 * Logging is not currently supported.
	 *
	 * @param array $plugins An array of plugins that support logging.
	 *
	 * @return array
	 */
	public function set_logging_supported( $plugins ) {

		return $plugins;
	}

}

new GF_Zero_Spam_AddOn();