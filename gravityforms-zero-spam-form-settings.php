<?php

if ( ! class_exists( 'GFForms' ) || ! is_callable( array( 'GFForms', 'include_addon_framework' ) ) ) {
	return;
}

GFForms::include_addon_framework();

/**
 * @since 1.2
 */
class GF_Zero_Spam_AddOn extends GFAddOn {

	protected $_slug        = 'gf-zero-spam';
	protected $_path        = GF_ZERO_SPAM_BASENAME;
	protected $_full_path   = __FILE__;
	protected $_title       = 'Gravity Forms Zero Spam';
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
	 * @param bool  $check_key_field
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
			'name'          => 'enableGFZeroSpam',
			'type'          => 'toggle',
			'label'         => esc_html__( 'Prevent spam using Gravity Forms Zero Spam', 'gf-zero-spam' ),
			'tooltip'       => gform_tooltip( 'enableGFZeroSpam', '', true ),
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


	/**
	 * Register addon global settings
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {
		return array(
			array(
				'title'  => esc_html__( 'Gravity Forms Zero Spam', 'gf-zero-spam' ),
				'fields' => array(
					array(
						'label'       => esc_html__( 'Email Frequency', 'gf-zero-spam' ),
						'description' => esc_html__( 'How frequently should spam report emails be sent.', 'gf-zero-spam' ),
						'type'        => 'select',
						'name'        => 'gf_zero_spam_email_frequency',
						'choices'     => array(
							array(
								'label' => __( 'Daily', 'gf-zero-spam' ),
								'value' => 'daily',
							),
							array(
								'label' => __( 'Weekly', 'gf-zero-spam' ),
								'value' => 'weekly',
							),
							array(
								'label' => __( 'Monthly', 'gf-zero-spam' ),
								'value' => 'monthly',
							),
							array(
								'label' => __( 'Entry Limit', 'gf-zero-spam' ),
								'value' => 'entry_limit',
							),
						),
						'required'    => true,
					),
					array(
						'label'               => esc_html__( 'Entry Limit', 'gf-zero-spam' ),
						'description'         => esc_html__( 'A spam report email will be sent when the number of spam messages reaches this number.', 'gf-zero-spam' ),
						'type'                => 'text',
						'input_type'          => 'number',
						'min'                 => 1,
						'value'               => 1,
						'name'                => 'gf_zero_spam_entry_limit',
						'dependency'          => array(
							'live'   => true,
							'fields' => array(
								array(
									'field'  => 'gf_zero_spam_email_frequency',
									'values' => array( 'entry_limit' ),
								),
							),
						),
						'validation_callback' => function( $field, $value ) {
							if ( (int) $value < 1 ) {
								$field->set_error( esc_html__( 'Entry limit has to be 1 or more.', 'gf-zero-spam' ) );
							}
						},
					),

					array(
						'label'               => esc_html__( 'Spam Report Email', 'gf-zero-spam' ),
						'description'         => esc_html__( 'Send spam report to this email address.', 'gf-zero-spam' ),
						'type'                => 'text',
						'input_type'          => 'email',
						'value'               => get_bloginfo( 'admin_email' ),
						'name'                => 'gf_zero_spam_report_email',
						'required'            => true,
						'validation_callback' => function( $field, $value ) {
							if ( ! is_email( $value ) ) {
								$field->set_error( esc_html__( 'Email is invalid.', 'gf-zero-spam' ) );
							}
						},

					),

				),
			),

		);

	}

}

new GF_Zero_Spam_AddOn();
