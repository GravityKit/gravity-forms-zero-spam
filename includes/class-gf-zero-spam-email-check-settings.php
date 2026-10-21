<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Adds the Email Check section to the Zero Spam settings page and its toggle to each form's settings.
 *
 * @since TBD
 */
class GF_Zero_Spam_Email_Check_Settings {

	/**
	 * Adds the form setting.
	 *
	 * @since TBD
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'gform_form_settings_fields', [ $this, 'add_form_settings_field' ], 11, 2 );
	}

	/**
	 * Returns the reasons offered on the settings page, with their labels.
	 *
	 * @since TBD
	 *
	 * @return array<string,string> Reason => label.
	 */
	public static function get_reasons() {
		$reasons = [
			'placeholder' => __( 'Placeholder addresses, such as you@company.com', 'gravity-forms-zero-spam' ),
			'disposable'  => __( 'Throwaway inboxes, such as Mailinator', 'gravity-forms-zero-spam' ),
		];

		/**
		 * Modifies the email check reasons the settings page offers an action for.
		 *
		 * @since TBD
		 *
		 * @param array<string,string> $reasons Reason => label.
		 */
		return (array) apply_filters( 'gf_zero_spam_email_check_setting_reasons', $reasons );
	}

	/**
	 * Adds the Email Check section to the global settings.
	 *
	 * @since TBD
	 *
	 * @param array $sections Existing settings sections.
	 *
	 * @return array
	 */
	public function add_settings_section( $sections ) {
		$depends_on_enabled = [
			'live'   => true,
			'fields' => [ [ 'field' => GF_Zero_Spam_Email_Check::SETTING_ENABLED ] ],
		];

		$fields = [
			[
				'name'          => GF_Zero_Spam_Email_Check::SETTING_ENABLED,
				'label'         => esc_html__( 'Check Email Addresses', 'gravity-forms-zero-spam' ),
				'type'          => 'toggle',
				'default_value' => false,
			],
		];

		foreach ( self::get_reasons() as $reason => $label ) {
			$fields[] = [
				'name'          => 'gf_zero_spam_email_check_action_' . $reason,
				'label'         => esc_html( $label ),
				'type'          => 'select',
				'default_value' => GF_Zero_Spam_Email_Check::DEFAULT_ACTION,
				'choices'       => [
					[
						'label' => esc_html__( 'Flag as spam', 'gravity-forms-zero-spam' ),
						'value' => 'flag',
					],
					[
						'label' => esc_html__( 'Block: show the visitor an error', 'gravity-forms-zero-spam' ),
						'value' => 'block',
					],
					[
						'label' => esc_html__( 'Add a note only', 'gravity-forms-zero-spam' ),
						'value' => 'log',
					],
				],
				'dependency'    => $depends_on_enabled,
			];
		}

		/**
		 * Modifies the fields in the Email Check settings section.
		 *
		 * @since TBD
		 *
		 * @param array $fields Gravity Forms settings fields.
		 */
		$fields = (array) apply_filters( 'gf_zero_spam_email_check_settings_fields', $fields );

		$sections[] = [
			'title'       => esc_html__( 'Email Check', 'gravity-forms-zero-spam' ),
			'description' => esc_html__( 'Catch email addresses that can\'t be a real contact: placeholder text like you@company.com, and throwaway inboxes. These checks run on your own server. Each email field on your forms is checked when the form is submitted.', 'gravity-forms-zero-spam' ),
			'fields'      => $fields,
		];

		return $sections;
	}

	/**
	 * Adds "Check email addresses on this form" to the form's spam settings.
	 *
	 * @since TBD
	 *
	 * @param array $fields Form settings fields, grouped by section.
	 * @param array $form   The form.
	 *
	 * @return array
	 */
	public function add_form_settings_field( $fields, $form = [] ) {
		if ( ! GF_Zero_Spam_Email_Check::is_enabled() ) {
			return $fields;
		}

		$field = [
			'name'          => GF_Zero_Spam_Email_Check::FORM_SETTING,
			'type'          => 'toggle',
			'label'         => esc_html__( 'Check email addresses on this form', 'gravity-forms-zero-spam' ),
			'default_value' => true,
		];

		if ( isset( $fields['spam'] ) ) {
			$fields['spam']['fields'][] = $field;
		} elseif ( isset( $fields['form_options'] ) ) {
			$fields['form_options']['fields'][] = $field;
		}

		return $fields;
	}
}
