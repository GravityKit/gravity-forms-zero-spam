<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Shield silentCAPTCHA integration for Gravity Forms Zero Spam.
 *
 * @since 1.10.0
 */
class GF_Zero_Spam_Shield_Silent_Captcha {

	const SETTING_KEY      = 'shield_silent_captcha';
	const PERSIST_KEY      = 'shield_silent_captcha_persist';
	const STATUS_FIELD_KEY = 'shield_silent_captcha_status';
	const HELP_URL         = 'https://www.gravitykit.com/docs/gravity-forms-zero-spam/shield-silentcaptcha/?utm_source=plugin&utm_campaign=zero-spam&utm_content=shield-learn-more';
	const SPAM_FILTER_NAME = 'Shield silentCAPTCHA';

	/**
	 * Add-on instance.
	 *
	 * @since 1.10.0
	 *
	 * @var GF_Zero_Spam_AddOn
	 */
	private $addon;

	/**
	 * Cached Shield availability after plugins_loaded.
	 *
	 * @since 1.10.0
	 *
	 * @var bool|null
	 */
	private $shield_available;

	/**
	 * Cached threshold-zero state after plugins_loaded.
	 *
	 * @since 1.10.0
	 *
	 * @var bool|null
	 */
	private $shield_threshold_zero;

	/**
	 * Shield-flagged form IDs during the current request.
	 *
	 * Set by filter_entry_is_spam() when Shield flags a form and GFCommon::set_spam_filter()
	 * is unavailable. Read and cleared by maybe_add_entry_note() to add a fallback entry note.
	 *
	 * @since 1.10.0
	 *
	 * @var array<int, bool>
	 */
	private $flagged_forms = [];

	/**
	 * Cached Shield bot verdict for the current request.
	 *
	 * Avoids calling Shield's callable twice on Save & Continue submissions,
	 * where both filter_entry_is_spam() and maybe_abort_submission() fire.
	 *
	 * @since 1.10.0
	 *
	 * @var bool|null|string 'unset' when not yet resolved, bool|null after.
	 */
	private $cached_verdict = 'unset';

	/**
	 * Pending verdict notes for already-flagged entries, keyed by form ID.
	 *
	 * @since 1.10.0
	 *
	 * @var array<int, array{text: string, type: string}>
	 */
	private $secondary_notes = [];

	/**
	 * @since 1.10.0
	 *
	 * @param GF_Zero_Spam_AddOn $addon Add-on instance.
	 */
	public function __construct( GF_Zero_Spam_AddOn $addon ) {
		$this->addon = $addon;
	}

	/**
	 * Registers Shield hooks.
	 *
	 * @since 1.10.0
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'gform_form_settings_fields', [ $this, 'add_form_settings_fields' ], 11, 2 );
		add_filter( 'gform_pre_form_settings_save', [ $this, 'normalize_form_settings' ] );
		add_filter( 'gform_tooltips', [ $this, 'add_tooltips' ] );
		add_filter( 'gform_entry_is_spam', [ $this, 'filter_entry_is_spam' ], $this->addon->get_spam_check_priority( 'shield' ), 3 );
		add_filter( 'gform_abort_submission_with_confirmation', [ $this, 'maybe_abort_submission' ], 30, 2 );
		add_action( 'gform_entry_created', [ $this, 'maybe_add_entry_note' ] );
	}

	/**
	 * Adds Shield fields to the existing Spam Blocking plugin settings section.
	 *
	 * @since 1.10.0
	 *
	 * @param array $sections Settings sections.
	 *
	 * @return array
	 */
	public function add_plugin_settings_fields( array $sections ): array {
		$shield_fields = $this->get_plugin_settings_fields();

		foreach ( $sections as &$section ) {
			if ( ! isset( $section['fields'] ) || ! is_array( $section['fields'] ) ) {
				continue;
			}

			if ( ! $this->section_contains_field( $section, 'gf_zero_spam_blocking' ) ) {
				continue;
			}

			$section['fields'] = $this->insert_fields_after_name( $section['fields'], 'gf_zero_spam_blocking', $shield_fields );
			break;
		}

		return $sections;
	}

	/**
	 * Normalizes Shield plugin settings before save.
	 *
	 * @since 1.10.0
	 *
	 * @param array $settings Settings array.
	 *
	 * @return array
	 */
	public function normalize_plugin_settings( $settings ) {
		$submitted = $settings[ self::SETTING_KEY ] ?? null;
		$persisted = array_key_exists( self::PERSIST_KEY, $settings )
			? $settings[ self::PERSIST_KEY ]
			: rgpost( '_gform_setting_' . self::PERSIST_KEY );

		$settings[ self::SETTING_KEY ] = $this->resolve_setting_value_for_save(
			$submitted,
			$persisted,
			$this->get_plugin_setting_value()
		);

		unset( $settings[ self::PERSIST_KEY ], $settings[ self::STATUS_FIELD_KEY ] );

		return $settings;
	}

	/**
	 * Adds Shield per-form fields after the existing Zero Spam toggle.
	 *
	 * @since 1.10.0
	 *
	 * @param array $fields Settings sections.
	 * @param array $form   Current form object.
	 *
	 * @return array
	 */
	public function add_form_settings_fields( $fields, $form ) {
		$section_key = isset( $fields['spam'] ) ? 'spam' : 'form_options';

		if ( ! isset( $fields[ $section_key ]['fields'] ) || ! is_array( $fields[ $section_key ]['fields'] ) ) {
			return $fields;
		}

		$fields[ $section_key ]['fields'] = $this->insert_fields_after_name(
			$fields[ $section_key ]['fields'],
			'enableGFZeroSpam',
			$this->get_form_settings_fields( $form )
		);

		return $fields;
	}

	/**
	 * Normalizes Shield form settings before save.
	 *
	 * @since 1.10.0
	 *
	 * @param array $form Form object.
	 *
	 * @return array
	 */
	public function normalize_form_settings( $form ) {
		$post_key  = '_gform_setting_' . self::SETTING_KEY;
		$submitted = isset( $_POST[ $post_key ] ) ? rgpost( $post_key ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by GF's form settings save handler.
		$persisted = rgpost( '_gform_setting_' . self::PERSIST_KEY );

		// GF merges the materialized setting values into $form before this filter fires,
		// so pre-save state must come from the stored form meta.
		$saved_form = GFFormsModel::get_form_meta( (int) rgar( $form, 'id' ) );

		if ( ! is_array( $saved_form ) ) {
			$saved_form = [];
		}

		$had_saved_setting = $this->has_saved_form_setting( $saved_form );

		// The Shield fields are dependency-hidden while the Zero Spam master toggle is off,
		// so nothing was visible to submit; keep the stored state untouched.
		if ( empty( rgpost( '_gform_setting_enableGFZeroSpam' ) ) ) {
			if ( $had_saved_setting ) {
				$form[ self::SETTING_KEY ] = $this->normalize_setting_value( $saved_form[ self::SETTING_KEY ] );
			} else {
				unset( $form[ self::SETTING_KEY ] );
			}

			unset( $form[ self::PERSIST_KEY ], $form[ self::STATUS_FIELD_KEY ] );

			return $form;
		}

		$current_value = $this->get_effective_form_setting_value( $saved_form );
		$resolved      = $this->resolve_setting_value_for_save( $submitted, $persisted, $current_value );

		// Keep missing-key inheritance intact unless the form already had an override or the user changed the value.
		if ( ! $had_saved_setting && $resolved === $current_value ) {
			unset( $form[ self::SETTING_KEY ] );
		} else {
			$form[ self::SETTING_KEY ] = $resolved;
		}

		unset( $form[ self::PERSIST_KEY ], $form[ self::STATUS_FIELD_KEY ] );

		return $form;
	}

	/**
	 * Adds the Shield form-settings tooltip.
	 *
	 * Public filter boundary. Gravity Forms intends this value to be an array,
	 * but other callbacks can still pass through a non-array value.
	 *
	 * @since 1.10.0
	 *
	 * @param mixed $tooltips Existing tooltips.
	 *
	 * @return mixed
	 */
	public function add_tooltips( $tooltips ) {
		if ( ! is_array( $tooltips ) ) {
			return $tooltips;
		}

		$form = $this->addon->get_current_form();

		if ( empty( $form ) ) {
			return $tooltips;
		}

		$tooltips[ self::SETTING_KEY ] = esc_html( $this->get_form_tooltip_text( $form ) );

		return $tooltips;
	}

	/**
	 * Flags the entry as spam when Shield returns a strict true verdict.
	 *
	 * @since 1.10.0
	 *
	 * @param bool  $is_spam Existing spam state.
	 * @param array $form    Form object.
	 * @param array $entry   Entry object.
	 *
	 * @return bool
	 */
	public function filter_entry_is_spam( $is_spam, $form, $entry ) {
		if ( $is_spam ) {
			return $this->evaluate_already_flagged_entry( $is_spam, $form, $entry );
		}

		if ( GFCommon::current_user_can_any( 'gravityforms_edit_entries' ) ) {
			return false;
		}

		if ( ! $this->is_submission_context_supported( $entry ) ) {
			return $is_spam;
		}

		if ( ! $this->is_shield_enabled_for_form( $form ) ) {
			return $is_spam;
		}

		if ( true !== $this->resolve_shield_bot_verdict() ) {
			return $is_spam;
		}

		$form_id = (int) rgar( $form, 'id' );

		if ( method_exists( 'GF_Zero_Spam', 'record_non_token_spam_source' ) ) {
			GF_Zero_Spam::record_non_token_spam_source( $form_id, 'shield_silent_captcha' );
		}

		if ( method_exists( 'GFCommon', 'set_spam_filter' ) ) {
			$message = strtr(
				/* translators: placeholders in [brackets] are replaced and must not be translated. */
				__( 'This submission was flagged as spam by [filter].', 'gravity-forms-zero-spam' ),
				[ '[filter]' => self::SPAM_FILTER_NAME ]
			);

			GFCommon::set_spam_filter( $form_id, self::SPAM_FILTER_NAME, $message );
		} else {
			$this->flagged_forms[ $form_id ] = true;
		}

		return true;
	}

	/**
	 * Evaluates and records the Shield verdict for an already-flagged entry when the stop setting is off.
	 *
	 * @since 1.10.0
	 *
	 * @param bool  $is_spam Existing spam state (always returned unchanged).
	 * @param array $form    Form object.
	 * @param array $entry   Entry object.
	 *
	 * @return bool
	 */
	private function evaluate_already_flagged_entry( $is_spam, $form, $entry ) {
		if ( $this->addon->should_stop_after_first_detection() ) {
			return $is_spam;
		}

		if ( GFCommon::current_user_can_any( 'gravityforms_edit_entries' ) ) {
			return $is_spam;
		}

		if ( ! $this->is_submission_context_supported( $entry ) ) {
			return $is_spam;
		}

		if ( ! $this->is_shield_enabled_for_form( $form ) ) {
			return $is_spam;
		}

		$verdict = $this->resolve_shield_bot_verdict();

		if ( null === $verdict ) {
			return $is_spam;
		}

		$form_id = (int) rgar( $form, 'id' );

		// A bot verdict keeps the AI rescue guard aware of Shield regardless of check order.
		if ( true === $verdict && method_exists( 'GF_Zero_Spam', 'record_non_token_spam_source' ) ) {
			GF_Zero_Spam::record_non_token_spam_source( $form_id, 'shield_silent_captcha' );
		}

		if ( true === $verdict ) {
			$this->secondary_notes[ $form_id ] = [
				'text' => __( 'Shield silentCAPTCHA also identified this submission as spam.', 'gravity-forms-zero-spam' ),
				'type' => 'warning',
			];
		} else {
			$this->secondary_notes[ $form_id ] = [
				'text' => __( 'Shield silentCAPTCHA did not identify this submission as spam.', 'gravity-forms-zero-spam' ),
				'type' => 'success',
			];
		}

		return $is_spam;
	}

	/**
	 * Aborts Save and Continue draft creation when Shield returns a strict true verdict.
	 *
	 * @since 1.10.0
	 *
	 * @param bool  $do_abort Existing abort state.
	 * @param array $form     Form object.
	 *
	 * @return bool
	 */
	public function maybe_abort_submission( $do_abort, $form ) {
		if ( $do_abort || GFCommon::current_user_can_any( 'gravityforms_edit_entries' ) ) {
			return $do_abort;
		}

		if ( ! rgpost( 'gform_save' ) || GFCommon::is_preview() ) {
			return false;
		}

		if ( ! $this->is_shield_enabled_for_form( $form ) ) {
			return false;
		}

		return true === $this->resolve_shield_bot_verdict();
	}

	/**
	 * Adds a fallback entry note when GFCommon::set_spam_filter() is unavailable.
	 *
	 * @since 1.10.0
	 *
	 * @param array $entry Entry object.
	 *
	 * @return void
	 */
	public function maybe_add_entry_note( $entry ) {
		$form_id = (int) rgar( $entry, 'form_id' );

		$this->maybe_add_secondary_verdict_note( $entry, $form_id );

		if ( 'spam' !== rgar( $entry, 'status' ) || empty( $this->flagged_forms[ $form_id ] ) ) {
			return;
		}

		if ( ! method_exists( 'GFAPI', 'add_note' ) ) {
			return;
		}

		GFAPI::add_note(
			$entry['id'],
			0,
			self::SPAM_FILTER_NAME,
			strtr(
				/* translators: placeholders in [brackets] are replaced and must not be translated. */
				__( 'This entry has been marked as spam by [filter].', 'gravity-forms-zero-spam' ),
				[ '[filter]' => self::SPAM_FILTER_NAME ]
			),
			'gf-zero-spam',
			'warning'
		);

		unset( $this->flagged_forms[ $form_id ] );
	}

	/**
	 * Adds the scheduled Shield verdict note to an entry flagged by another check.
	 *
	 * @since 1.10.0
	 *
	 * @param array $entry   Entry object.
	 * @param int   $form_id Form ID.
	 *
	 * @return void
	 */
	private function maybe_add_secondary_verdict_note( $entry, $form_id ) {
		if ( empty( $this->secondary_notes[ $form_id ] ) ) {
			return;
		}

		$note = $this->secondary_notes[ $form_id ];
		unset( $this->secondary_notes[ $form_id ] );

		if ( ! method_exists( 'GFAPI', 'add_note' ) ) {
			return;
		}

		GFAPI::add_note( (int) $entry['id'], 0, self::SPAM_FILTER_NAME, $note['text'], 'gf-zero-spam', $note['type'] );
	}

	/**
	 * Renders the plugin-settings status/help field.
	 *
	 * @since 1.10.0
	 *
	 * @param array $field Field config.
	 * @param bool  $echo  Whether to echo markup.
	 */
	public function render_plugin_status_field( $field, $echo = true ): ?string {
		$html = $this->render_plugin_status_message();

		if ( ! $this->is_shield_available() ) {
			$html .= $this->render_disable_control_script( [ '_gform_setting_' . self::SETTING_KEY, self::SETTING_KEY ] );
		}

		if ( $echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is escaped at source.

			return null;
		}

		return $html;
	}

	/**
	 * Renders the form-settings status field.
	 *
	 * Emits the disable-script fallback when Shield is unavailable and the inline
	 * threshold warning when Shield is available but its bot threshold is zero.
	 *
	 * @since 1.10.0
	 *
	 * @param array $field Field config.
	 * @param bool  $echo  Whether to echo markup.
	 */
	public function render_form_status_field( $field, $echo = true ): ?string {
		$html = '';

		if ( ! $this->is_shield_available() ) {
			$html = $this->render_disable_control_script( [ '_gform_setting_' . self::SETTING_KEY, self::SETTING_KEY ] );
		} elseif ( $this->is_threshold_zero() ) {
			$html = sprintf(
				'<p class="description" style="color:#996800;">%s</p>',
				esc_html( $this->get_form_tooltip_text( $this->addon->get_current_form() ) )
			);
		}

		if ( $echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is escaped at source.

			return null;
		}

		return $html;
	}

	/**
	 * Builds the Shield plugin settings field definitions.
	 *
	 * @since 1.10.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_plugin_settings_fields(): array {
		return [
			[
				'label'         => esc_html__( 'Enable Shield silentCAPTCHA by Default', 'gravity-forms-zero-spam' ),
				'type'          => 'toggle',
				'name'          => self::SETTING_KEY,
				'default_value' => $this->get_plugin_setting_value(),
				'disabled'      => ! $this->is_shield_available(),
			],
			[
				'type'          => 'hidden',
				'name'          => self::PERSIST_KEY,
				'default_value' => $this->get_plugin_setting_value(),
			],
			[
				'type'     => 'html',
				'name'     => self::STATUS_FIELD_KEY,
				'label'    => '',
				'callback' => [ $this, 'render_plugin_status_field' ],
			],
		];
	}

	/**
	 * Builds the Shield form settings field definitions.
	 *
	 * @since 1.10.0
	 *
	 * @param array $form Form object.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_form_settings_fields( $form ): array {
		$current_value        = $this->get_effective_form_setting_value( $form );
		$zero_spam_dependency = [
			'live'   => true,
			'fields' => [
				[
					'field' => 'enableGFZeroSpam',
				],
			],
		];

		return [
			[
				'name'          => self::SETTING_KEY,
				'type'          => 'toggle',
				'label'         => esc_html__( 'Enable Shield silentCAPTCHA for this form', 'gravity-forms-zero-spam' ),
				'tooltip'       => gform_tooltip( self::SETTING_KEY, '', true ),
				'default_value' => $current_value,
				'disabled'      => ! $this->is_shield_available(),
				'dependency'    => $zero_spam_dependency,
			],
			[
				'type'          => 'hidden',
				'name'          => self::PERSIST_KEY,
				'default_value' => $current_value,
			],
			[
				'hidden'     => ! $this->is_threshold_zero(),
				'type'       => 'html',
				'name'       => self::STATUS_FIELD_KEY,
				'label'      => '',
				'callback'   => [ $this, 'render_form_status_field' ],
				'dependency' => $zero_spam_dependency,
			],
		];
	}

	/**
	 * Returns the stored global Shield setting in normalized string form.
	 *
	 * @since 1.10.0
	 *
	 * @return string
	 */
	private function get_plugin_setting_value(): string {
		return $this->normalize_setting_value( $this->addon->get_plugin_setting( self::SETTING_KEY ) );
	}

	/**
	 * Determines whether the form has a saved Shield setting.
	 *
	 * @since 1.10.0
	 *
	 * @param array $form Form object.
	 *
	 * @return bool
	 */
	private function has_saved_form_setting( $form ): bool {
		return is_array( $form ) && array_key_exists( self::SETTING_KEY, $form );
	}

	/**
	 * Returns the saved per-form Shield setting when present.
	 *
	 * @since 1.10.0
	 *
	 * @param array $form Form object.
	 *
	 * @return string|null
	 */
	private function get_saved_form_setting_value( $form ): ?string {
		if ( ! $this->has_saved_form_setting( $form ) ) {
			return null;
		}

		return $this->normalize_setting_value( $form[ self::SETTING_KEY ] );
	}

	/**
	 * Returns the effective Shield setting for the form.
	 *
	 * @since 1.10.0
	 *
	 * @param array $form Form object.
	 *
	 * @return string
	 */
	private function get_effective_form_setting_value( $form ): string {
		$saved_value = $this->get_saved_form_setting_value( $form );

		return null !== $saved_value ? $saved_value : $this->get_plugin_setting_value();
	}

	/**
	 * Determines if Shield is enabled for the current form.
	 *
	 * @since 1.10.0
	 *
	 * @param array $form Form object.
	 *
	 * @return bool
	 */
	private function is_shield_enabled_for_form( $form ): bool {
		// The Zero Spam master gate covers the whole pipeline, Shield included.
		if ( ! $this->is_zero_spam_gate_open( $form ) ) {
			return false;
		}

		return '1' === $this->get_effective_form_setting_value( $form );
	}

	/**
	 * Resolves the Zero Spam master gate through the public filter, like the token and AI checks.
	 *
	 * @since 1.10.0
	 *
	 * @param array $form Form object.
	 *
	 * @return bool
	 */
	private function is_zero_spam_gate_open( $form ): bool {
		$form_id = (int) rgar( $form, 'id' );
		$enabled = true;

		if ( $form_id > 0 ) {
			/** This filter is documented in includes/class-gf-zero-spam.php. */
			$enabled = gf_apply_filters( 'gf_zero_spam_check_key_field', $form_id, $enabled, $form, [] );
		} else {
			/** This filter is documented in includes/class-gf-zero-spam.php. */
			$enabled = apply_filters( 'gf_zero_spam_check_key_field', $enabled, $form );
		}

		return false !== $enabled;
	}

	/**
	 * Normalizes a stored toggle value into the committed string shape.
	 *
	 * @since 1.10.0
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	private function normalize_setting_value( $value ): string {
		return in_array( (string) $value, [ '1', 'true', 'on', 'yes' ], true ) ? '1' : '0';
	}

	/**
	 * Resolves the stored Shield setting value during a save operation.
	 *
	 * @since 1.10.0
	 *
	 * @param mixed  $submitted     Submitted toggle value, if present.
	 * @param mixed  $persisted     Hidden persisted value, if present.
	 * @param string $current_value Current stored normalized value.
	 *
	 * @return string
	 */
	private function resolve_setting_value_for_save( $submitted, $persisted, string $current_value ): string {
		if ( null !== $submitted && '' !== $submitted ) {
			return $this->normalize_setting_value( $submitted );
		}

		// Unchecked and disabled toggles both arrive without an affirmative value. Shield
		// availability disambiguates: available means the toggle was interactive (no value =
		// unchecked); unavailable means it was disabled, so preserve the previous value.
		if ( $this->is_shield_available() ) {
			return '0';
		}

		if ( null !== $persisted && '' !== $persisted ) {
			return $this->normalize_setting_value( $persisted );
		}

		return $current_value;
	}

	/**
	 * Determines if the current request is a supported submission context.
	 *
	 * @since 1.10.0
	 *
	 * @param array $entry Entry object.
	 *
	 * @return bool
	 */
	private function is_submission_context_supported( $entry ): bool {
		if ( GFCommon::is_preview() ) {
			return false;
		}

		$supports_context = method_exists( 'GFFormDisplay', 'get_submission_context' );
		if ( $supports_context && GFFormDisplay::get_submission_context() !== 'form-submit' ) {
			return false;
		}

		if ( ! $supports_context && ! did_action( 'gform_pre_submission' ) ) {
			return false;
		}

		if ( isset( $entry['user_agent'] ) && 'API' === $entry['user_agent'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Resolves the Shield verdict using the documented callable order.
	 *
	 * @since 1.10.0
	 *
	 * @return bool|null True if bot, false if not, null if undetermined.
	 */
	private function resolve_shield_bot_verdict(): ?bool {
		if ( 'unset' !== $this->cached_verdict ) {
			return $this->cached_verdict;
		}

		if ( ! did_action( 'plugins_loaded' ) ) {
			return null;
		}

		$this->cached_verdict = null;

		foreach ( $this->get_shield_callables() as $callable ) {
			if ( ! is_callable( $callable ) ) {
				continue;
			}

			try {
				$verdict = call_user_func( $callable );
			} catch ( \Throwable $e ) {
				continue;
			}

			$normalized = $this->normalize_verdict( $verdict );

			if ( null !== $normalized ) {
				$this->cached_verdict = $normalized;

				return $normalized;
			}
		}

		return null;
	}

	/**
	 * Determines if Shield is available for the current request.
	 *
	 * @since 1.10.0
	 *
	 * @return bool
	 */
	public function is_shield_available(): bool {
		if ( ! did_action( 'plugins_loaded' ) ) {
			return false;
		}

		if ( null !== $this->shield_available ) {
			return $this->shield_available;
		}

		$this->shield_available = false;

		foreach ( $this->get_shield_callables() as $callable ) {
			if ( is_callable( $callable ) ) {
				$this->shield_available = true;
				break;
			}
		}

		return $this->shield_available;
	}

	/**
	 * Determines if Shield is available and the threshold is zero.
	 *
	 * @since 1.10.0
	 *
	 * @return bool
	 */
	private function is_threshold_zero(): bool {
		if ( ! did_action( 'plugins_loaded' ) ) {
			return false;
		}

		if ( null !== $this->shield_threshold_zero ) {
			return $this->shield_threshold_zero;
		}

		$this->shield_threshold_zero = false;

		if ( ! $this->is_shield_available() ) {
			return $this->shield_threshold_zero;
		}

		foreach ( $this->get_shield_threshold_callables() as $callable ) {
			if ( ! is_callable( $callable ) ) {
				continue;
			}

			try {
				$threshold = call_user_func( $callable );
			} catch ( \Throwable $e ) {
				continue;
			}

			if ( ! is_numeric( $threshold ) ) {
				continue;
			}

			$this->shield_threshold_zero = 0 === (int) $threshold;
			break;
		}

		return $this->shield_threshold_zero;
	}

	/**
	 * Returns the ordered list of Shield bot-detection callables.
	 *
	 * @since 1.10.0
	 *
	 * @return array<int, string>
	 */
	private function get_shield_callables(): array {
		return [
			'\\FernleafSystems\\Wordpress\\Plugin\\Shield\\Functions\\test_ip_is_bot',
			'shield_test_ip_is_bot',
		];
	}

	/**
	 * Returns the ordered list of Shield threshold callables.
	 *
	 * @since 1.10.0
	 *
	 * @return array<int, string>
	 */
	private function get_shield_threshold_callables(): array {
		return [
			'\\FernleafSystems\\Wordpress\\Plugin\\Shield\\Functions\\get_silentcaptcha_bot_threshold',
			'shield_get_silentcaptcha_bot_threshold',
		];
	}

	/**
	 * Normalizes the Shield verdict into a strict tri-state.
	 *
	 * @since 1.10.0
	 *
	 * @param mixed $verdict Raw verdict.
	 *
	 * @return bool|null
	 */
	private function normalize_verdict( $verdict ): ?bool {
		if ( true === $verdict ) {
			return true;
		}

		if ( false === $verdict ) {
			return false;
		}

		return null;
	}

	/**
	 * Returns the form tooltip text for the current Shield state.
	 *
	 * @since 1.10.0
	 *
	 * @param array|false $form Form object or false when no form context.
	 *
	 * @return string
	 */
	private function get_form_tooltip_text( $form ): string {
		$setting_scope = $this->has_saved_form_setting( $form )
			? __( 'This setting is currently overriding the global default setting.', 'gravity-forms-zero-spam' )
			: __( 'This setting is currently inheriting the global default setting.', 'gravity-forms-zero-spam' );

		if ( ! $this->is_shield_available() ) {
			return $setting_scope . ' ' . $this->get_unavailable_message();
		}

		if ( $this->is_threshold_zero() ) {
			return $setting_scope . ' ' . $this->get_threshold_zero_message();
		}

		return $setting_scope . ' ' . $this->get_form_help_text();
	}

	/**
	 * Returns the plugin-settings status text for the current Shield state.
	 *
	 * @since 1.10.0
	 *
	 * @return string
	 */
	private function get_plugin_status_text(): string {
		if ( ! $this->is_shield_available() ) {
			return $this->get_unavailable_message();
		}

		if ( $this->is_threshold_zero() ) {
			return $this->get_threshold_zero_message();
		}

		return $this->get_global_help_text();
	}

	/**
	 * Renders the plugin-settings status message with the Learn More link.
	 *
	 * @since 1.10.0
	 *
	 * @return string
	 */
	private function render_plugin_status_message(): string {
		$style = $this->is_shield_available() && $this->is_threshold_zero()
			? ' style="color:#996800;"'
			: '';

		return sprintf(
			'<p class="description"%s>%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
			$style,
			esc_html( $this->get_plugin_status_text() ),
			esc_url( self::HELP_URL ),
			esc_html__( 'Learn More', 'gravity-forms-zero-spam' )
		);
	}

	/**
	 * Creates a tiny inline script to disable the visible Shield toggle.
	 *
	 * This is a defensive fallback alongside GF's native 'disabled' field property.
	 *
	 * @since 1.10.0
	 *
	 * @param array<int, string> $field_names Candidate field names.
	 *
	 * @return string
	 */
	private function render_disable_control_script( $field_names ): string {
		$field_names_json = wp_json_encode( array_values( $field_names ) );

		return <<<HTML
<script>
(function() {
	'use strict';

	const names = {$field_names_json};

	function disableByName() {
		for ( let i = 0; i < names.length; i++ ) {
			const inputs = document.getElementsByName( names[i] );

			for ( let j = 0; j < inputs.length; j++ ) {
				if ( 'checkbox' === inputs[j].type || 'radio' === inputs[j].type ) {
					inputs[j].disabled = true;
				}
			}
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', disableByName );
		return;
	}

	disableByName();
})();
</script>
HTML;
	}

	/**
	 * Determines whether the section contains a given field name.
	 *
	 * @since 1.10.0
	 *
	 * @param array  $section    Section config.
	 * @param string $field_name Field name.
	 *
	 * @return bool
	 */
	private function section_contains_field( $section, $field_name ): bool {
		foreach ( $section['fields'] as $field ) {
			if ( rgar( $field, 'name' ) === $field_name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Inserts fields immediately after a named field, appending if not found.
	 *
	 * @since 1.10.0
	 *
	 * @param array  $fields     Existing fields.
	 * @param string $field_name Target field name.
	 * @param array  $new_fields Fields to insert.
	 *
	 * @return array
	 */
	private function insert_fields_after_name( $fields, $field_name, $new_fields ): array {
		$updated  = [];
		$inserted = false;

		foreach ( $fields as $field ) {
			$updated[] = $field;

			if ( rgar( $field, 'name' ) === $field_name ) {
				foreach ( $new_fields as $new_field ) {
					$updated[] = $new_field;
				}

				$inserted = true;
			}
		}

		if ( ! $inserted ) {
			foreach ( $new_fields as $new_field ) {
				$updated[] = $new_field;
			}
		}

		return $updated;
	}

	/**
	 * Returns the form-level help text.
	 *
	 * @since 1.10.0
	 *
	 * @return string
	 */
	private function get_form_help_text(): string {
		return __( 'Runs Shield silentCAPTCHA server-side for this form. No field needs to be added to the form.', 'gravity-forms-zero-spam' );
	}

	/**
	 * Returns the global-level help text.
	 *
	 * @since 1.10.0
	 *
	 * @return string
	 */
	private function get_global_help_text(): string {
		return __( 'Enable Shield silentCAPTCHA by default for forms. No field needs to be added to the form. Forms without their own Shield setting will use this default.', 'gravity-forms-zero-spam' );
	}

	/**
	 * Returns the message shown when Shield is not available.
	 *
	 * @since 1.10.0
	 *
	 * @return string
	 */
	private function get_unavailable_message(): string {
		return __( 'Shield silentCAPTCHA is currently unavailable because Shield Security is not installed or active. This setting is disabled until Shield becomes available again.', 'gravity-forms-zero-spam' );
	}

	/**
	 * Returns the message shown when Shield's bot threshold is zero.
	 *
	 * @since 1.10.0
	 *
	 * @return string
	 */
	private function get_threshold_zero_message(): string {
		return __( 'Shield silentCAPTCHA is available, but Shield\'s bot threshold is 0, so it will not flag submissions until that threshold is increased.', 'gravity-forms-zero-spam' );
	}
}
