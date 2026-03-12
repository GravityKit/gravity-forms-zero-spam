<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Registers the Email Rejection Rules settings section in the Zero Spam global settings page
 * and enqueues the Rule Builder UI.
 *
 * @since 1.5.0
 */
class GF_Zero_Spam_Email_Rejection_Settings {

	/**
	 * The GF Zero Spam AddOn instance.
	 *
	 * @since 1.5.0
	 *
	 * @var GF_Zero_Spam_AddOn
	 */
	private $addon;

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 *
	 * @param GF_Zero_Spam_AddOn $addon The AddOn instance.
	 */
	public function __construct( $addon ) {
		$this->addon = $addon;
	}

	/**
	 * Initializes hooks.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
	}

	/**
	 * Parses the rules JSON from $_POST.
	 *
	 * The hidden input name follows GF convention: _gform_setting_{field_name}.
	 *
	 * @since 1.5.0
	 *
	 * @return array|null Parsed rules array, or null if not present/invalid.
	 */
	public static function parse_rules_from_post() {
		$rules_json = rgpost( '_gform_setting_gf_zero_spam_email_rules' );

		if ( ! is_string( $rules_json ) || '' === $rules_json ) {
			return null;
		}

		$rules = json_decode( $rules_json, true );

		if ( ! is_array( $rules ) ) {
			return null;
		}

		$rules = array_map( [ __CLASS__, 'sanitize_rule' ], $rules );

		// Deduplicate by type + value (case-insensitive), keeping the first occurrence.
		$seen  = [];
		$rules = array_filter(
            $rules,
            static function ( $rule ) use ( &$seen ) {
				$key = $rule['type'] . ':' . strtolower( $rule['value'] );

				if ( isset( $seen[ $key ] ) ) {
					return false;
				}

				$seen[ $key ] = true;

				return true;
			}
        );

		return array_values( $rules );
	}

	/**
	 * Sanitizes a single rule array.
	 *
	 * Validates type and action against allowed values, sanitizes the value
	 * string, and normalizes the enabled flag.
	 *
	 * Note: Uses isset() instead of rgar() for the enabled flag because
	 * GF's rgar() treats falsy values (including boolean false) as empty
	 * when a non-null default is provided, which would flip disabled rules
	 * back to enabled.
	 *
	 * @since 1.5.0
	 *
	 * @param array $rule Raw rule data.
	 *
	 * @return array Sanitized rule.
	 */
	private static function sanitize_rule( $rule ) {
		$type   = rgar( $rule, 'type', 'domain' );
		$action = rgar( $rule, 'action', 'flag' );
		$value  = sanitize_text_field( rgar( $rule, 'value', '' ) );

		// Strip leading/trailing commas, semicolons, and dots from values.
		$value = trim( $value, ',;. ' );

		return [
			'id'      => sanitize_text_field( rgar( $rule, 'id', '' ) ),
			'type'    => in_array( $type, GF_Zero_Spam_Email_Rejection::ALLOWED_TYPES, true ) ? $type : 'domain',
			'value'   => $value,
			'action'  => in_array( $action, GF_Zero_Spam_Email_Rejection::ALLOWED_ACTIONS, true ) ? $action : 'flag',
			'enabled' => isset( $rule['enabled'] ) ? (bool) $rule['enabled'] : true,
		];
	}

	/**
	 * Gets the asset version string for cache-busting.
	 *
	 * @since 1.5.0
	 *
	 * @return string
	 */
	public static function get_asset_version() {
		$mtime = @filemtime( dirname( __DIR__ ) . '/dist/js/gf-zero-spam-admin.js' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Graceful fallback when file is missing.

		return $mtime ? (string) $mtime : '1.0.0';
	}

	/**
	 * Saves rules JSON from the hidden input during settings save.
	 *
	 * The rules field uses type 'html', which GF's AddOn framework does not
	 * automatically save. This filter reads the serialized JSON from $_POST
	 * and injects it into the settings array.
	 *
	 * @since 1.5.0
	 *
	 * @param array $settings The settings being saved.
	 *
	 * @return array
	 */
	public function save_rules_from_post( $settings ) {
		$rules = self::parse_rules_from_post();

		if ( null !== $rules ) {
			$settings['gf_zero_spam_email_rules'] = $rules;
		}

		return $settings;
	}

	/**
	 * Adds the Email Rejection Rules section to plugin settings.
	 *
	 * @since 1.5.0
	 *
	 * @param array $settings Existing settings sections.
	 *
	 * @return array
	 */
	public function add_settings_section( $settings ) {
		$description = GF_Zero_Spam_Email_Rejection::is_block_supported()
			? esc_html__( 'Block, flag, or log email submissions matching specific addresses, domains, or patterns. Rules apply to all email fields unless overridden per field.', 'gravity-forms-zero-spam' )
			: esc_html__( 'Flag or log email submissions matching specific addresses, domains, or patterns. Rules apply to all email fields unless overridden per field.', 'gravity-forms-zero-spam' );

		$settings[] = [
			'title'       => esc_html__( 'Email Rejection Rules', 'gravity-forms-zero-spam' ),
			'description' => $description,
			'fields'      => [
				[
					'name'          => 'gf_zero_spam_email_rejection_enabled',
					'label'         => esc_html__( 'Enable Email Rejection Rules', 'gravity-forms-zero-spam' ),
					'type'          => 'toggle',
					'default_value' => false,
				],
				[
					'name'       => 'gf_zero_spam_email_rules',
					'label'      => esc_html__( 'Rules', 'gravity-forms-zero-spam' ),
					'type'       => 'html',
					'html'       => '<div id="gf-zero-spam-rule-builder"></div>',
					'dependency' => [
						'live'   => true,
						'fields' => [
							[
								'field' => 'gf_zero_spam_email_rejection_enabled',
							],
						],
					],
				],
				[
					'name'          => 'gf_zero_spam_email_rejection_message',
					'label'         => esc_html__( 'Default Validation Message', 'gravity-forms-zero-spam' ),
					'type'          => 'text',
					'class'         => 'large',
					'default_value' => GF_Zero_Spam_Email_Rejection::get_default_validation_message(),
					'description'   => esc_html__( 'Shown when action is "Block". Can be overridden per email field.', 'gravity-forms-zero-spam' ),
					'dependency'    => [
						'live'   => true,
						'fields' => [
							[
								'field' => 'gf_zero_spam_email_rejection_enabled',
							],
						],
					],
				],
			],
		];

		return $settings;
	}

	/**
	 * Enqueues assets on the Zero Spam settings page.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets() {
		if ( ! $this->is_settings_page() ) {
			return;
		}

		$plugin_dir = plugin_dir_url( __DIR__ );
		$version    = self::get_asset_version();

		wp_enqueue_script(
			'gf-zero-spam',
			$plugin_dir . 'dist/js/gf-zero-spam-admin.js',
			[],
			$version,
			true
		);

		wp_enqueue_style(
			'gf-zero-spam',
			$plugin_dir . 'dist/css/gf-zero-spam.css',
			[],
			$version
		);

		$rules = $this->get_rules_for_localize();

		wp_localize_script(
            'gf-zero-spam',
            'gfZeroSpamEmailRules_global',
            [
				'targetSelector'   => '#gf-zero-spam-rule-builder',
				'context'          => 'global',
				'rules'            => $rules,
				'inputElementName' => '_gform_setting_gf_zero_spam_email_rules',
				'gfVersion'        => GFForms::$version,
				'blockSupported'   => GF_Zero_Spam_Email_Rejection::is_block_supported(),
				'translations'     => self::get_translations(),
			]
        );
	}

	/**
	 * Checks if we're on the Zero Spam settings page.
	 *
	 * @since 1.5.0
	 *
	 * @return bool
	 */
	private function is_settings_page() {
		return is_admin()
			&& rgget( 'page' ) === 'gf_settings'
			&& rgget( 'subview' ) === 'gf-zero-spam';
	}

	/**
	 * Returns the current rules for wp_localize_script.
	 *
	 * GF 2.5+ Settings renderer saves during render() (page body), but
	 * admin_enqueue_scripts fires before that. On POST, the cached settings
	 * are stale, so we read the freshly-submitted rules from $_POST instead.
	 *
	 * @since 1.5.0
	 *
	 * @return array
	 */
	private function get_rules_for_localize() {
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$rules = self::parse_rules_from_post();

			if ( null !== $rules ) {
				return $rules;
			}
		}

		$rules = $this->addon->get_plugin_setting( 'gf_zero_spam_email_rules' );

		return is_array( $rules ) ? $rules : [];
	}

	/**
	 * Gets translation strings for the UI.
	 *
	 * @since 1.5.0
	 *
	 * @return array
	 */
	public static function get_translations() {
		return [
			'addRule'                        => __( 'Add Rule', 'gravity-forms-zero-spam' ),
			'removeRule'                     => __( 'Remove', 'gravity-forms-zero-spam' ),
			'edit'                           => __( 'Edit', 'gravity-forms-zero-spam' ),
			'enable'                         => __( 'Enable', 'gravity-forms-zero-spam' ),
			'disable'                        => __( 'Disable', 'gravity-forms-zero-spam' ),
			'save'                           => __( 'Save', 'gravity-forms-zero-spam' ),
			'cancel'                         => __( 'Cancel', 'gravity-forms-zero-spam' ),
			'domain'                         => __( 'Domain', 'gravity-forms-zero-spam' ),
			'email'                          => __( 'Email', 'gravity-forms-zero-spam' ),
			'wildcard'                       => __( 'Wildcard', 'gravity-forms-zero-spam' ),
			'regex'                          => __( 'Regex', 'gravity-forms-zero-spam' ),
			'block'                          => __( 'Block', 'gravity-forms-zero-spam' ),
			'flag'                           => __( 'Flag as Spam', 'gravity-forms-zero-spam' ),
			'log'                            => __( 'Log Only', 'gravity-forms-zero-spam' ),
			'type'                           => __( 'Type', 'gravity-forms-zero-spam' ),
			'value'                          => __( 'Value', 'gravity-forms-zero-spam' ),
			'action'                         => __( 'Action', 'gravity-forms-zero-spam' ),
			'noRules'                        => __( 'No rules defined yet.', 'gravity-forms-zero-spam' ),
			'importRules'                    => __( 'Import Rules', 'gravity-forms-zero-spam' ),
			'importDescription'              => __( 'Paste values, one per line or comma-separated. Auto-detected as Domain or Email type.', 'gravity-forms-zero-spam' ),
			'import'                         => __( 'Import', 'gravity-forms-zero-spam' ),
			'confirmRemove'                  => __( 'Remove this rule?', 'gravity-forms-zero-spam' ),
			'invalidRegex'                   => __( 'Invalid regular expression.', 'gravity-forms-zero-spam' ),
			'invalidEmail'                   => __( 'Please enter a valid email address.', 'gravity-forms-zero-spam' ),
			'invalidDomain'                  => __( 'Please enter a valid domain.', 'gravity-forms-zero-spam' ),
			'importNone'                     => __( 'No valid rules found to import.', 'gravity-forms-zero-spam' ),
			'importOne'                      => __( '1 rule imported.', 'gravity-forms-zero-spam' ),
			// translators: %d is the number of rules imported.
			'importMany'                     => __( '%d rules imported.', 'gravity-forms-zero-spam' ),
			'importSkippedOne'               => __( 'Skipped 1 invalid value.', 'gravity-forms-zero-spam' ),
			// translators: %d is the number of invalid values skipped.
			'importSkippedMany'              => __( 'Skipped %d invalid values.', 'gravity-forms-zero-spam' ),
			'blockNotice'                    => __( 'Some rules use the Block action, which requires Gravity Forms 2.9.15+. These rules are inactive until you update.', 'gravity-forms-zero-spam' ),
			'blockAvailable'                 => __( 'Upgrading to Gravity Forms 2.9.15 or higher enables the ability to configure rules that block matching form submissions.', 'gravity-forms-zero-spam' ),
			'blockRequiresGF'                => __( 'Requires Gravity Forms 2.9.15+', 'gravity-forms-zero-spam' ),
			'valuePlaceholder'               => __( 'e.g., spamdomain.com', 'gravity-forms-zero-spam' ),
			'enableForField'                 => __( 'Enable rejection rules', 'gravity-forms-zero-spam' ),
			'fieldSettingsDescription'       => __( 'Add rules to block, flag, or log submissions based on the email entered in this field. Rules can extend or replace the global rejection rules.', 'gravity-forms-zero-spam' ),
			'fieldSettingsDescriptionBefore' => __( 'Add rules to block, flag, or log submissions based on the email entered in this field. Rules can extend or replace the ', 'gravity-forms-zero-spam' ),
			'fieldSettingsDescriptionLink'   => __( 'global rejection rules', 'gravity-forms-zero-spam' ),
			'fieldSettingsDescriptionAfter'  => __( '.', 'gravity-forms-zero-spam' ),
			'ruleMode'                       => __( 'Rule Mode', 'gravity-forms-zero-spam' ),
			'inheritAdd'                     => __( 'Inherit global rules + add field-specific rules', 'gravity-forms-zero-spam' ),
			'replace'                        => __( 'Use only field-specific rules (ignore global)', 'gravity-forms-zero-spam' ),
			'fieldRules'                     => __( 'Field-Specific Rules', 'gravity-forms-zero-spam' ),
			'validationMessage'              => __( 'Validation Message (optional)', 'gravity-forms-zero-spam' ),
			'leaveBlank'                     => __( 'Leave blank to use the global default message.', 'gravity-forms-zero-spam' ),
		];
	}
}
