<?php

use Throwable as BaseThrowable;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Registers AI Spam Review settings and settings notices.
 *
 * @since 1.9.0
 */
final class GF_Zero_Spam_AI_Review_Settings {
	/**
	 * The GF Zero Spam AddOn instance.
	 *
	 * @since 1.9.0
	 *
	 * @var GF_Zero_Spam_AddOn
	 */
	private $addon;

	/**
	 * Constructor.
	 *
	 * @since 1.9.0
	 *
	 * @param GF_Zero_Spam_AddOn $addon The AddOn instance.
	 */
	public function __construct( $addon ) {
		$this->addon = $addon;
	}

	/**
	 * Initializes settings hooks.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'gf_zero_spam_ai_enabled', [ $this, 'filter_ai_enabled' ], 20, 4 );
		add_filter( 'gform_form_settings_initial_values', [ $this, 'filter_form_settings_initial_values' ], 10, 2 );
		add_action( 'admin_footer', [ $this, 'print_form_settings_scripts' ] );
	}

	/**
	 * Prints the "Copy global instructions" click handler on the form settings page.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function print_form_settings_scripts() {
		if ( 'gf_edit_forms' !== rgget( 'page' ) || 'settings' !== rgget( 'view' ) ) {
			return;
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return;
		}
		?>
			<script>
				( function () {
					const isToggleChecked = function ( name ) {
						const input = document.getElementById( '_gform_setting_' + name ) ||
							document.getElementById( name ) ||
							document.querySelector( '[name="_gform_setting_' + name + '"]' ) ||
							document.querySelector( '[name="' + name + '"]' );

						return input ? Boolean( input.checked ) : false;
					};

					window.gfZeroSpamAISharedDependency = function () {
						return isToggleChecked( 'enableGFZeroSpam' ) &&
							( isToggleChecked( 'enableGFZeroSpamAI' ) || isToggleChecked( 'enableGFZeroSpamAIRescue' ) );
					};

					const textarea = document.getElementById( 'gfZeroSpamAIPrompt' );

					if ( ! textarea ) {
						return;
					}

					const wrap = textarea.closest( '.gform-settings-field' ) || textarea.parentNode;

					if ( wrap.querySelector( '.gfzs-copy-global-prompt' ) ) {
						return;
					}

					const paragraph = document.createElement( 'p' );
					const button    = document.createElement( 'button' );

					button.type      = 'button';
					button.className = 'button gfzs-copy-global-prompt';
					button.textContent = <?php echo wp_json_encode( __( 'Copy global instructions', 'gravity-forms-zero-spam' ) ); ?>;
					button.title = <?php echo wp_json_encode( __( 'Copies the global AI instructions into this field so you can edit them for this form.', 'gravity-forms-zero-spam' ) ); ?>;

					button.addEventListener( 'click', function () {
						textarea.value = <?php echo wp_json_encode( $this->get_effective_global_prompt() ); ?>;
						textarea.focus();
					} );

					paragraph.appendChild( button );
					wrap.appendChild( paragraph );
				} )();
			</script>
		<?php
	}

	/**
	 * Prefills the per-form AI fields with their effective defaults when no value is saved.
	 *
	 * Gravity Forms returns the saved value over a field's default_value whenever the key
	 * exists (even when empty), so an already-saved blank field would otherwise render empty.
	 * Coercing the render-time initial values keeps the saved data untouched while showing the
	 * editable effective prompt and the 0 (no limit) default.
	 *
	 * @since 1.9.0
	 *
	 * @param array $values The initial values for the form settings renderer.
	 * @param array $form   The form being edited.
	 *
	 * @return array The adjusted initial values.
	 */
	public function filter_form_settings_initial_values( $values, $form ) {
		// Only adjust forms that actually use AI, so other forms are never polluted on save.
		if ( ! $this->filter_ai_enabled( false, GF_Zero_Spam_AI_Review::CONTEXT_REVIEW, $form ) && ! $this->filter_ai_enabled( false, GF_Zero_Spam_AI_Review::CONTEXT_RESCUE, $form ) ) {
			return $values;
		}

		// Show 0 (no limit) instead of a blank rate-cap field on already-saved forms.
		if ( rgblank( rgar( $values, 'gfZeroSpamAIMaxCallsPerHour' ) ) ) {
			$values['gfZeroSpamAIMaxCallsPerHour'] = 0;
		}

		return $values;
	}

	/**
	 * Gets the effective global classification prompt (saved global, else the default).
	 *
	 * @since 1.9.0
	 *
	 * @return string The effective global prompt.
	 */
	private function get_effective_global_prompt() {
		$global_prompt = $this->addon->get_plugin_setting( 'gf_zero_spam_ai_default_prompt' );

		return ( is_string( $global_prompt ) && '' !== trim( $global_prompt ) ) ? $global_prompt : self::get_default_prompt();
	}

	/**
	 * Uses global and per-form settings to determine whether AI is enabled for a context.
	 *
	 * @since 1.9.0
	 *
	 * @param bool   $enabled Whether AI is enabled.
	 * @param string $context Either "review" or "rescue".
	 * @param array  $form    The form object.
	 * @param array  $entry   The entry object.
	 *
	 * @return bool Whether AI is enabled.
	 */
	public function filter_ai_enabled( $enabled = false, $context = GF_Zero_Spam_AI_Review::CONTEXT_REVIEW, $form = [], $entry = [] ) {
		unset( $enabled, $entry );

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}

		if ( GF_Zero_Spam_AI_Review::CONTEXT_REVIEW === $context ) {
			return $this->filter_ai_review_enabled( $form );
		}

		if ( GF_Zero_Spam_AI_Review::CONTEXT_RESCUE === $context ) {
			return $this->filter_ai_rescue_enabled( $form );
		}

		return false;
	}

	/**
	 * Uses global and per-form settings to determine whether AI rescue is enabled.
	 *
	 * @since 1.9.0
	 *
	 * @param array $form The form object.
	 *
	 * @return bool Whether AI rescue is enabled.
	 */
	private function filter_ai_rescue_enabled( $form = [] ) {
		if ( ! $this->is_base_zero_spam_enabled( $form ) ) {
			return false;
		}

		if ( isset( $form['enableGFZeroSpamAIRescue'] ) ) {
			return ! empty( $form['enableGFZeroSpamAIRescue'] );
		}

		return ! empty( $this->addon->get_plugin_setting( 'gf_zero_spam_ai_rescue_enabled' ) );
	}

	/**
	 * Uses global and per-form settings to determine whether AI review is enabled.
	 *
	 * @since 1.9.0
	 *
	 * @param array $form The form object.
	 *
	 * @return bool Whether AI review is enabled.
	 */
	private function filter_ai_review_enabled( $form = [] ) {
		if ( isset( $form['enableGFZeroSpamAI'] ) ) {
			return ! empty( $form['enableGFZeroSpamAI'] );
		}

		return ! empty( $this->addon->get_plugin_setting( 'gf_zero_spam_ai_review_enabled' ) );
	}

	/**
	 * Adds the AI Spam Review global settings section.
	 *
	 * @since 1.9.0
	 *
	 * @param array $sections Existing settings sections.
	 *
	 * @return array Settings sections.
	 */
	public function add_settings_section( $sections ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return $sections;
		}

		// The whole section only renders on WP 7.0+ (guarded above), so the copy need not mention the version.
		// translators: Do not translate {{link}} and {{/link}}.
		$section_description = strtr(
			esc_html__( 'Zero Spam blocks most spam with an invisible token check before AI is involved. Turn on the tools below to let AI handle the edge cases — catching spam that slips past, or rescuing real submissions blocked by mistake. Both use the same instructions and the same {{link}}AI service{{/link}}.', 'gravity-forms-zero-spam' ),
			[
				'{{link}}'  => '<a href="' . esc_url( $this->get_connectors_settings_url() ) . '">',
				'{{/link}}' => '</a>',
			]
		);

		$ai_enabled_dependency = [
			'live'     => true,
			'operator' => 'ANY',
			'fields'   => [
				[
					'field' => 'gf_zero_spam_ai_review_enabled',
				],
				[
					'field' => 'gf_zero_spam_ai_rescue_enabled',
				],
			],
		];
		$review_dependency     = [
			'live'   => true,
			'fields' => [
				[
					'field' => 'gf_zero_spam_ai_review_enabled',
				],
			],
		];
		$rescue_dependency     = [
			'live'   => true,
			'fields' => [
				[
					'field' => 'gf_zero_spam_ai_rescue_enabled',
				],
			],
		];

		$configured_connectors = $this->get_configured_ai_provider_connectors();
		$fields                = [];

		if ( $this->should_show_connector_notice( $configured_connectors ) ) {
			$fields[] = [
				'name' => 'gf_zero_spam_ai_connector_notice',
				'type' => 'html',
				'html' => $this->get_connector_notice_html( $configured_connectors ),
			];
		}

		$fields[] = [
			'name'          => 'gf_zero_spam_ai_review_enabled',
			'label'         => esc_html__( 'Catch spam the token check misses', 'gravity-forms-zero-spam' ),
			'tooltip'       => esc_html__( 'Reviews submissions that were allowed through — a second opinion to catch bots that defeated the token check.', 'gravity-forms-zero-spam' ),
			'description'   => esc_html__( 'After a submission passes the normal spam checks, AI reads it and flags it as spam if it looks suspicious.', 'gravity-forms-zero-spam' ),
			'type'          => 'toggle',
			'default_value' => false,
		];

		$fields[] = [
			'name'          => 'gf_zero_spam_ai_rescue_enabled',
			'label'         => esc_html__( 'Rescue good submissions blocked by mistake', 'gravity-forms-zero-spam' ),
			'tooltip'       => esc_html__( 'Reviews submissions blocked by Zero Spam\'s own token check (not Akismet, the honeypot, or email rules). Always runs before notifications are sent.', 'gravity-forms-zero-spam' ),
			'description'   => esc_html__( 'If the token check blocks a submission but the content looks genuine, AI can let it through. Only submissions blocked by Zero Spam\'s token check are eligible; during a token-failure flood this can increase AI usage.', 'gravity-forms-zero-spam' ),
			'type'          => 'toggle',
			'default_value' => false,
		];

		$fields[] = [
			'name'          => 'gf_zero_spam_ai_default_prompt',
			'label'         => esc_html__( 'AI instructions', 'gravity-forms-zero-spam' ),
			'tooltip'       => esc_html__( 'Tell the AI how to evaluate submissions — what counts as spam for your site and what doesn\'t.', 'gravity-forms-zero-spam' ),
			'description'   => esc_html__( 'Tell the AI what counts as spam on your site. These instructions are used by both tools above; the default works well for most sites.', 'gravity-forms-zero-spam' ),
			'type'          => 'textarea',
			'default_value' => self::get_default_prompt(),
			'class'         => 'large',
			'dependency'    => $ai_enabled_dependency,
		];

		$sections[] = [
			'title'       => esc_html__( 'AI spam protection', 'gravity-forms-zero-spam' ),
			'description' => $section_description,
			'fields'      => $fields,
		];

		$advanced_fields = [];

		if ( count( $configured_connectors ) >= 2 || '' !== $this->get_selected_provider_id() ) {
			$advanced_fields[] = [
				'name'          => 'gf_zero_spam_ai_provider',
				'label'         => esc_html__( 'AI service', 'gravity-forms-zero-spam' ),
				'tooltip'       => esc_html__( 'Chooses which configured AI service Zero Spam uses to review submissions.', 'gravity-forms-zero-spam' ),
				'description'   => esc_html__( 'Automatic uses the site default AI service.', 'gravity-forms-zero-spam' ),
				'type'          => 'select',
				'default_value' => '',
				'choices'       => $this->get_ai_provider_choices( $configured_connectors ),
				'dependency'    => $ai_enabled_dependency,
			];
		}

		$advanced_fields[] = [
			'name'                => 'gf_zero_spam_ai_confidence_threshold',
			'label'               => esc_html__( 'How sure must AI be to flag spam?', 'gravity-forms-zero-spam' ),
			'tooltip'             => esc_html__( 'Sets how confident AI must be before Zero Spam marks a submission as spam.', 'gravity-forms-zero-spam' ),
			'description'         => esc_html__( 'Stricter = fewer real submissions flagged, but more spam may slip through.', 'gravity-forms-zero-spam' ),
			'type'                => 'select',
			'default_value'       => GF_Zero_Spam_AI_Review::DEFAULT_CONFIDENCE_THRESHOLD,
			'choices'             => [
				[
					'label' => esc_html__( 'Balanced — flag when fairly sure (recommended)', 'gravity-forms-zero-spam' ),
					'value' => (string) GF_Zero_Spam_AI_Review::DEFAULT_CONFIDENCE_THRESHOLD,
				],
				[
					'label' => esc_html__( 'Strict — only flag when very sure', 'gravity-forms-zero-spam' ),
					'value' => '0.95',
				],
			],
			'validation_callback' => [ $this, 'validate_confidence_threshold' ],
			'dependency'          => $review_dependency,
		];

		$advanced_fields[] = [
			'name'                => 'gf_zero_spam_ai_rescue_confidence_threshold',
			'label'               => esc_html__( 'How sure must AI be to rescue a blocked submission?', 'gravity-forms-zero-spam' ),
			'tooltip'             => esc_html__( 'Sets how confident AI must be before Zero Spam restores a submission blocked by the token check.', 'gravity-forms-zero-spam' ),
			'description'         => esc_html__( 'Balanced works well for most sites; a restore cannot be undone, so more cautious options are available.', 'gravity-forms-zero-spam' ),
			'type'                => 'select',
			'default_value'       => GF_Zero_Spam_AI_Review::DEFAULT_RESCUE_CONFIDENCE_THRESHOLD,
			'choices'             => [
				[
					'label' => esc_html__( 'Balanced — rescue when fairly sure (recommended)', 'gravity-forms-zero-spam' ),
					'value' => (string) GF_Zero_Spam_AI_Review::DEFAULT_RESCUE_CONFIDENCE_THRESHOLD,
				],
				[
					'label' => esc_html__( 'Cautious — rescue only when very sure', 'gravity-forms-zero-spam' ),
					'value' => '0.95',
				],
				[
					'label' => esc_html__( 'Very cautious — rescue only when almost certain', 'gravity-forms-zero-spam' ),
					'value' => '0.98',
				],
			],
			'validation_callback' => [ $this, 'validate_confidence_threshold' ],
			'dependency'          => $rescue_dependency,
		];

		$sections[] = [
			'title'        => esc_html__( 'Advanced AI settings', 'gravity-forms-zero-spam' ),
			'id'           => 'gf_zero_spam_ai_advanced',
			'collapsible'  => true,
			'is_collapsed' => true,
			'dependency'   => $ai_enabled_dependency,
			'fields'       => $advanced_fields,
		];

		return $sections;
	}

	/**
	 * Adds AI Spam Review fields to form settings.
	 *
	 * @since 1.9.0
	 *
	 * @param array $fields Form settings fields.
	 * @param array $form   The current form.
	 *
	 * @return array Form settings fields.
	 */
	public function add_form_settings_fields( $fields, $form = [] ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return $fields;
		}

		$zero_spam_dependency = [
			'live'   => true,
			'fields' => [
				[
					'field' => 'enableGFZeroSpam',
				],
			],
		];
		$review_dependency    = [
			'live'     => true,
			'operator' => 'ALL',
			'fields'   => [
				[
					'field' => 'enableGFZeroSpam',
				],
				[
					'field' => 'enableGFZeroSpamAI',
				],
			],
		];
		$shared_dependency    = [
			'live'     => true,
			'operator' => 'ALL',
			'fields'   => [
				[
					'field' => 'enableGFZeroSpam',
				],
				[
					'field' => 'enableGFZeroSpamAI',
				],
				[
					'field' => 'enableGFZeroSpamAIRescue',
				],
			],
			'callback' => [
				'php' => static function ( $settings ) {
					return ! empty( $settings->get_value( 'enableGFZeroSpam' ) )
						&& ( ! empty( $settings->get_value( 'enableGFZeroSpamAI' ) ) || ! empty( $settings->get_value( 'enableGFZeroSpamAIRescue' ) ) );
				},
				'js'  => 'gfZeroSpamAISharedDependency',
			],
		];
		$ai_fields            = [
			[
				'name'          => 'enableGFZeroSpamAI',
				'type'          => 'toggle',
				'label'         => esc_html__( 'Catch spam the token check misses', 'gravity-forms-zero-spam' ),
				'tooltip'       => esc_html__( 'When enabled, the AI reads each submission to this form and flags it as spam if the content looks suspicious.', 'gravity-forms-zero-spam' ),
				'default_value' => apply_filters( 'gf_zero_spam_ai_enabled', false, GF_Zero_Spam_AI_Review::CONTEXT_REVIEW, $form ),
				'dependency'    => $zero_spam_dependency,
			],
			[
				'name'          => 'enableGFZeroSpamAIRescue',
				'type'          => 'toggle',
				'label'         => esc_html__( 'Rescue good submissions blocked by mistake', 'gravity-forms-zero-spam' ),
				'tooltip'       => esc_html__( 'When enabled, AI can restore this form\'s submissions that the Zero Spam token check flagged but the content appears legitimate.', 'gravity-forms-zero-spam' ),
				'description'   => esc_html__( 'Rescue always runs during submission, before notifications are sent.', 'gravity-forms-zero-spam' ),
				'default_value' => apply_filters( 'gf_zero_spam_ai_enabled', false, GF_Zero_Spam_AI_Review::CONTEXT_RESCUE, $form ),
				'dependency'    => $zero_spam_dependency,
			],
			[
				'name'        => 'gfZeroSpamAIPrompt',
				'type'        => 'textarea',
				'label'       => esc_html__( 'AI instructions for this form', 'gravity-forms-zero-spam' ),
				'tooltip'     => esc_html__( 'Tell the AI how to evaluate this form\'s submissions.', 'gravity-forms-zero-spam' ),
				'description' => esc_html__( 'Leave blank to use the global instructions.', 'gravity-forms-zero-spam' ),
				'placeholder' => $this->get_effective_global_prompt(),
				'class'       => 'large',
				'dependency'  => $shared_dependency,
			],
			[
				'name'        => 'gfZeroSpamAIExcludedFields',
				'type'        => 'field_select',
				'label'       => esc_html__( 'Skip these fields', 'gravity-forms-zero-spam' ),
				'tooltip'     => esc_html__( 'Selected fields are left out of the content the AI reviews — use this for fields with sensitive data.', 'gravity-forms-zero-spam' ),
				'description' => esc_html__( 'Left out of what AI reads — use for sensitive data.', 'gravity-forms-zero-spam' ),
				'multiple'    => true,
				'enhanced_ui' => true,
				'args'        => [
					'disable_first_choice' => true,
				],
				'dependency'  => $shared_dependency,
			],
			[
				'name'          => 'gfZeroSpamAIMaxCallsPerHour',
				'type'          => 'text',
				'input_type'    => 'number',
				'label'         => esc_html__( 'Limit AI checks per hour', 'gravity-forms-zero-spam' ),
				'tooltip'       => esc_html__( 'Limits how many of this form\'s submissions are sent to the AI each hour, to control usage.', 'gravity-forms-zero-spam' ),
				'description'   => esc_html__( '0 = no limit.', 'gravity-forms-zero-spam' ),
				'default_value' => 0,
				'min'           => 0,
				'step'          => 1,
				'dependency'    => $shared_dependency,
			],
		];

		if ( isset( $fields['spam'] ) ) {
			$fields['spam']['fields'] = array_merge( $fields['spam']['fields'], $ai_fields );
		} else {
			$fields['form_options']['fields'] = array_merge( $fields['form_options']['fields'], $ai_fields );
		}

		return $fields;
	}

	/**
	 * Validates the confidence threshold setting.
	 *
	 * @since 1.9.0
	 *
	 * @param object $field The GF Settings Field object with a set_error() method.
	 * @param string $value The submitted threshold.
	 *
	 * @return void
	 */
	public function validate_confidence_threshold( $field, $value ) {
		if ( is_numeric( $value ) && (float) $value >= 0.5 && (float) $value <= 1 ) {
			return;
		}

		$field->set_error( esc_html__( 'Minimum confidence must be a number between 0.5 and 1.', 'gravity-forms-zero-spam' ) );
	}

	/**
	 * Checks whether the base Zero Spam token check is enabled for a form.
	 *
	 * @since 1.9.0
	 *
	 * @param array $form The form object.
	 *
	 * @return bool Whether the base Zero Spam token check is enabled.
	 */
	private function is_base_zero_spam_enabled( $form ) {
		$form_id = (int) rgar( $form, 'id' );
		$enabled = true;

		if ( $form_id > 0 ) {
			/** This filter is documented in includes/class-gf-zero-spam.php. */
			$enabled = gf_apply_filters( 'gf_zero_spam_check_key_field', $form_id, $enabled, $form, [] );
		} else {
			$enabled = apply_filters( 'gf_zero_spam_check_key_field', $enabled, $form );
		}

		return false !== $enabled;
	}

	/**
	 * Gets configured AI provider connectors without triggering live model discovery.
	 *
	 * @since 1.9.0
	 *
	 * @return array<string, array{id: string, name: string}> Configured provider connector data.
	 */
	private function get_configured_ai_provider_connectors() {
		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return [];
		}

		$connectors = wp_get_connectors();

		if ( ! is_array( $connectors ) ) {
			return [];
		}

		$providers = [];

		foreach ( $connectors as $connector_id => $connector ) {
			if ( ! is_array( $connector ) || 'ai_provider' !== rgar( $connector, 'type' ) ) {
				continue;
			}

			if ( ! $this->is_connector_plugin_active( $connector ) ) {
				continue;
			}

			if ( ! $this->connector_has_credentials( $connector ) ) {
				continue;
			}

			$connector_id = (string) $connector_id;

			$providers[ $connector_id ] = [
				'id'   => $connector_id,
				'name' => $this->get_connector_name( $connector, $connector_id ),
			];
		}

		return $providers;
	}

	/**
	 * Checks whether a connector's provider plugin is active.
	 *
	 * @since 1.9.0
	 *
	 * @param array $connector Connector data.
	 *
	 * @return bool Whether the connector plugin is active.
	 */
	private function is_connector_plugin_active( $connector ) {
		$plugin    = isset( $connector['plugin'] ) ? $connector['plugin'] : [];
		$is_active = is_array( $plugin ) ? rgar( $plugin, 'is_active' ) : null;

		if ( ! is_callable( $is_active ) ) {
			return true;
		}

		try {
			return (bool) call_user_func( $is_active );
		} catch ( BaseThrowable $e ) {
			return false;
		}
	}

	/**
	 * Checks whether a connector has enough credentials configured to be selectable.
	 *
	 * @since 1.9.0
	 *
	 * @param array $connector Connector data.
	 *
	 * @return bool Whether the connector has configured credentials.
	 */
	private function connector_has_credentials( $connector ) {
		$auth = isset( $connector['authentication'] ) ? $connector['authentication'] : [];

		if ( ! is_array( $auth ) ) {
			return false;
		}

		if ( 'none' === rgar( $auth, 'method' ) ) {
			return true;
		}

		if ( 'api_key' !== rgar( $auth, 'method' ) ) {
			return false;
		}

		$env_var_name = (string) rgar( $auth, 'env_var_name', '' );

		if ( '' !== $env_var_name ) {
			$env_value = getenv( $env_var_name );

			if ( false !== $env_value && '' !== $env_value ) {
				return true;
			}
		}

		$constant_name = (string) rgar( $auth, 'constant_name', '' );

		if ( '' !== $constant_name && defined( $constant_name ) ) {
			$constant_value = constant( $constant_name );

			if ( is_string( $constant_value ) && '' !== $constant_value ) {
				return true;
			}
		}

		$setting_name = (string) rgar( $auth, 'setting_name', '' );

		if ( '' === $setting_name ) {
			return false;
		}

		$option_value = get_option( $setting_name, '' );

		return is_string( $option_value ) && '' !== $option_value;
	}

	/**
	 * Gets a connector display name.
	 *
	 * @since 1.9.0
	 *
	 * @param array  $connector    Connector data.
	 * @param string $connector_id Connector ID.
	 *
	 * @return string Connector display name.
	 */
	private function get_connector_name( $connector, $connector_id ) {
		$name = rgar( $connector, 'name' );

		if ( is_string( $name ) && '' !== $name ) {
			return $name;
		}

		return ucwords( str_replace( [ '-', '_' ], ' ', $connector_id ) );
	}

	/**
	 * Gets AI provider select choices.
	 *
	 * @since 1.9.0
	 *
	 * @param array $configured_connectors Configured provider connector data.
	 *
	 * @return array Select field choices.
	 */
	private function get_ai_provider_choices( $configured_connectors ) {
		$choices = [
			[
				'label' => esc_html__( 'Automatic (site default)', 'gravity-forms-zero-spam' ),
				'value' => '',
			],
		];

		foreach ( $configured_connectors as $connector_id => $connector ) {
			$choices[] = [
				'label' => esc_html( rgar( $connector, 'name', (string) $connector_id ) ),
				'value' => (string) $connector_id,
			];
		}

		return $choices;
	}

	/**
	 * Gets the selected AI provider ID.
	 *
	 * @since 1.9.0
	 *
	 * @return string Selected provider ID, or empty string for automatic selection.
	 */
	private function get_selected_provider_id() {
		$provider_id = $this->addon->get_plugin_setting( 'gf_zero_spam_ai_provider' );

		return is_string( $provider_id ) ? trim( $provider_id ) : '';
	}

	/**
	 * Gets the connector notice HTML.
	 *
	 * @since 1.9.0
	 *
	 * @param array $configured_connectors Configured provider connector data.
	 *
	 * @return string Connector notice HTML.
	 */
	private function get_connector_notice_html( $configured_connectors ) {
		$url         = $this->get_connectors_settings_url();
		$provider_id = $this->get_selected_provider_id();

		$replace = [
			'[link]'  => '',
			'[/link]' => '',
		];

		if ( '' !== $url ) {
			$replace['[link]']  = '<a href="' . esc_url( $url ) . '">';
			$replace['[/link]'] = '</a>';
		}

		if ( '' !== $provider_id && ! isset( $configured_connectors[ $provider_id ] ) ) {
			/* translators: Do not translate [link] and [/link]. */
			$message = strtr(
				esc_html__( 'The selected AI service is no longer available; switch to Automatic, choose another service, or update connectors in [link]Settings > Connectors[/link].', 'gravity-forms-zero-spam' ),
				$replace
			);
		} else {
			/* translators: Do not translate [link] and [/link]. */
			$message = strtr(
				esc_html__( 'AI review or rescue is enabled, but no AI service is configured for text generation. Set one up in [link]Settings > Connectors[/link].', 'gravity-forms-zero-spam' ),
				$replace
			);
		}

		return sprintf(
			'<div class="alert gforms_note_warning" role="alert">%s</div>',
			wp_kses(
				$message,
				[
					'a' => [
						'href' => [],
					],
				]
			)
		);
	}

	/**
	 * Checks whether the connector notice should render in settings.
	 *
	 * @since 1.9.0
	 *
	 * @param array $configured_connectors Configured provider connector data.
	 *
	 * @return bool Whether the connector notice should render.
	 */
	private function should_show_connector_notice( $configured_connectors ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}

		if ( ! $this->is_global_ai_review_enabled() ) {
			return false;
		}

		$provider_id = $this->get_selected_provider_id();

		if ( '' !== $provider_id ) {
			return ! isset( $configured_connectors[ $provider_id ] );
		}

		return empty( $configured_connectors );
	}

	/**
	 * Gets the Connectors settings URL.
	 *
	 * @since 1.9.0
	 *
	 * @return string Connectors settings URL.
	 */
	private function get_connectors_settings_url() {
		return admin_url( 'options-connectors.php' );
	}

	/**
	 * Checks if the global AI review setting is enabled.
	 *
	 * @since 1.9.0
	 *
	 * @return bool Whether global AI review is enabled.
	 */
	private function is_global_ai_review_enabled() {
		return ! empty( $this->addon->get_plugin_setting( 'gf_zero_spam_ai_review_enabled' ) )
			|| ! empty( $this->addon->get_plugin_setting( 'gf_zero_spam_ai_rescue_enabled' ) );
	}

	/**
	 * Gets the default AI classification prompt.
	 *
	 * @since 1.9.0
	 *
	 * @return string Default prompt.
	 */
	public static function get_default_prompt() {
		$sentences = [
			__( 'Review this Gravity Forms submission and decide whether it is spam.', 'gravity-forms-zero-spam' ),
			__( 'Classify it as spam only when it is clearly unsolicited, deceptive, malicious, automated, or sent in bulk.', 'gravity-forms-zero-spam' ),
			__( 'Treat ordinary customer messages — questions, support requests, quote requests, bookings, job applications, and other coherent human inquiries — as legitimate, even when they are brief or short on detail.', 'gravity-forms-zero-spam' ),
			__( 'The confidence value reports how certain you are of the classification you return, whether spam or legitimate. It is not a measure of how spam-like the message is.', 'gravity-forms-zero-spam' ),
			__( 'When the classification is clear, report high confidence: use 0.98 to 1.00 for obvious spam, and equally for an ordinary, coherent human submission that shows no spam signals.', 'gravity-forms-zero-spam' ),
			__( 'Use 0.85 to 0.97 when your classification is probable but some context is missing or mixed.', 'gravity-forms-zero-spam' ),
			__( 'Use a value below 0.85 only when the submission is genuinely ambiguous or too sparse to judge.', 'gravity-forms-zero-spam' ),
			__( 'It is better to miss a piece of spam than to block a legitimate submission.', 'gravity-forms-zero-spam' ),
			__( 'Use only the form title, source path, field labels, field types, and submitted values provided, and do not guess about missing context.', 'gravity-forms-zero-spam' ),
			__( 'Return JSON matching the supplied schema.', 'gravity-forms-zero-spam' ),
			__( 'The reason must be concise and must not quote personal data such as names or email addresses.', 'gravity-forms-zero-spam' ),
		];

		return implode( ' ', $sentences );
	}
}
