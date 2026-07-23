<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

class GF_Zero_Spam {

	/**
	 * Scripts queued for output after each form.
	 *
	 * @since 1.7.3
	 *
	 * @var array<int, string> Keyed by form ID.
	 */
	private $pending_scripts = [];

	/**
	 * Request-local token rejection reason codes keyed by form ID.
	 *
	 * @since 1.9.0
	 *
	 * @var array<int, string>
	 */
	private static $token_rejections = [];

	/**
	 * Request-local non-token spam sources keyed by form ID.
	 *
	 * @since 1.9.0
	 *
	 * @var array<int, array<int, string>>
	 */
	private static $non_token_spam_sources = [];

	/**
	 * Pending verdict notes for already-flagged entries, keyed by form ID.
	 *
	 * @since 1.10.0
	 *
	 * @var array<int, array{text: string, type: string}>
	 */
	private $secondary_notes = [];

	/**
	 * Instantiates the plugin on Gravity Forms loading.
	 *
	 * @since 1.0.5
	 *
	 * @return void
	 */
	public static function gform_loaded() {
		include_once GF_ZERO_SPAM_DIR . 'includes/class-gf-zero-spam-addon.php';

		require_once GF_ZERO_SPAM_DIR . 'includes/class-gf-zero-spam-token.php';
		require_once GF_ZERO_SPAM_DIR . 'includes/class-gf-zero-spam-token-endpoint.php';

		new self();
	}

	/**
	 * Cleans up plugin options when deactivating.
	 *
	 * @since 1.0
	 *
	 * @return void
	 */
	public static function deactivate() {
		delete_option( 'gf_zero_spam_key' );
		delete_option( 'gf_zero_spam_salt_version' );
		delete_option( 'gf_zero_spam_prev_salt_version' );
		delete_option( 'gf_zero_spam_legacy_deadline' );

		wp_clear_scheduled_hook( 'gf_zero_spam_send_report' );
	}

	/**
	 * Gets the token rejection reason code for this request.
	 *
	 * @since 1.9.0
	 *
	 * @param int $form_id The form ID.
	 *
	 * @return string The rejection reason code.
	 */
	public static function get_token_rejection_reason_code( $form_id ) {
		$form_id = (int) $form_id;

		return isset( self::$token_rejections[ $form_id ] ) ? self::$token_rejections[ $form_id ] : '';
	}

	/**
	 * Clears the token rejection reason code for this request.
	 *
	 * @since 1.9.0
	 *
	 * @param int $form_id The form ID.
	 *
	 * @return void
	 */
	public static function clear_token_rejection_reason_code( $form_id ) {
		unset( self::$token_rejections[ (int) $form_id ] );
	}

	/**
	 * Resets request-local spam markers before processing a submission.
	 *
	 * @since 1.9.0
	 *
	 * @param array|null $form The form being processed when called as a filter.
	 *
	 * @return array|null The form being processed.
	 */
	public static function reset_request_markers( $form = null ) {
		self::$token_rejections       = [];
		self::$non_token_spam_sources = [];

		return $form;
	}

	/**
	 * Records a non-token Zero Spam spam source for this request.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $form_id The form ID.
	 * @param string $source  The non-token spam source code.
	 *
	 * @return void
	 */
	public static function record_non_token_spam_source( $form_id, $source ) {
		$form_id = (int) $form_id;

		if ( $form_id < 1 ) {
			return;
		}

		if ( empty( self::$non_token_spam_sources[ $form_id ] ) ) {
			self::$non_token_spam_sources[ $form_id ] = [];
		}

		$source = (string) $source;

		if ( in_array( $source, self::$non_token_spam_sources[ $form_id ], true ) ) {
			return;
		}

		self::$non_token_spam_sources[ $form_id ][] = $source;
	}

	/**
	 * Checks whether a non-token Zero Spam spam source flagged this request.
	 *
	 * @since 1.9.0
	 *
	 * @param int $form_id The form ID.
	 *
	 * @return bool Whether a non-token Zero Spam spam source flagged this request.
	 */
	public static function has_non_token_spam_source( $form_id ) {
		return ! empty( self::$non_token_spam_sources[ (int) $form_id ] );
	}

	/**
	 * Constructor. Registers Gravity Forms hooks.
	 *
	 * @since 1.0
	 */
	public function __construct() {
		new GF_Zero_Spam_Token_Endpoint();

		add_action( 'gform_register_init_scripts', [ $this, 'add_key_field' ], 1 );
		add_filter( 'gform_pre_process', [ __CLASS__, 'reset_request_markers' ] );
		add_filter( 'gform_get_form_filter', [ $this, 'enqueue_script' ], 10, 2 );

		$priority = method_exists( 'GF_Zero_Spam_AddOn', 'get_instance' )
			? GF_Zero_Spam_AddOn::get_instance()->get_spam_check_priority( 'token' )
			: 12;

		add_filter( 'gform_entry_is_spam', [ $this, 'check_key_field' ], $priority, 3 );
		add_filter( 'gform_incomplete_submission_pre_save', [ $this, 'add_zero_spam_key_to_entry' ], 10, 3 );
		add_filter( 'gform_abort_submission_with_confirmation', [ $this, 'maybe_abort_submission' ], 20, 2 );
		add_action( 'admin_notices', [ $this, 'migration_notice' ] );
		add_action( 'admin_init', [ $this, 'maybe_cleanup_legacy' ] );
	}

	/**
	 * Adds the Zero Spam key to the Gravity Forms entry during form submission.
	 *
	 * @see GFFormsModel::filter_draft_submission_pre_save() The source of the hook.
	 * @since 1.4.1
	 *
	 * @param string $submission_json The JSON representation of the form submission.
	 * @param string $resume_token    The resume token for the submission.
	 * @param array  $form            The Gravity Forms form object.
	 *
	 * @return string The modified JSON representation of the form submission.
	 */
	public function add_zero_spam_key_to_entry( $submission_json, $resume_token, $form ) {
		$submission = json_decode( $submission_json, true );

		// Not valid JSON.
		if ( ! is_array( $submission ) ) {
			return $submission_json;
		}

		/**
		 * Something isn't right...bail. This should be set.
         *
		 * @see GFFormsModel::save_draft_submission()
		 */
		if ( ! isset( $submission['partial_entry'] ) ) {
			return $submission_json;
		}

		// The Zero Spam token is already set; we don't need to do anything.
		if ( isset( $submission['partial_entry']['gf_zero_spam_token'] ) ) {
			return $submission_json;
		}

		// Store the token in the draft JSON for audit purposes only; not re-validated on resume.
		$submission['partial_entry']['gf_zero_spam_token'] = rgpost( 'gf_zero_spam_token' );

		return wp_json_encode( $submission );
	}

	/**
	 * Aborts a Save and Continue submission if the zero spam key is missing or invalid.
	 *
	 * Prevents bots from creating draft entries via Save and Continue by validating the
	 * spam key before the draft is saved. Uses the same abort pattern as the GF honeypot.
	 *
	 * @since 1.5.0
	 *
	 * @see https://docs.gravityforms.com/gform_abort_submission_with_confirmation/
	 *
	 * @param bool  $do_abort Whether to abort the submission. Default false.
	 * @param array $form     The form currently being processed.
	 *
	 * @return bool True to abort the submission; false to allow it.
	 */
	public function maybe_abort_submission( $do_abort, $form ) {
		// Another filter already decided to abort.
		if ( $do_abort ) {
			return true;
		}

		// Not a Save and Continue action.
		if ( ! rgpost( 'gform_save' ) ) {
			return false;
		}

		$should_check_key_field = ! GFCommon::is_preview();

		/** This filter is documented in includes/class-gf-zero-spam.php. */
		$should_check_key_field = gf_apply_filters( 'gf_zero_spam_check_key_field', rgar( $form, 'id' ), $should_check_key_field, $form, [] );

		if ( false === $should_check_key_field ) {
			return false;
		}

		$submitted_token = rgpost( 'gf_zero_spam_token' );

		// Check for signed token first.
		if ( ! rgblank( $submitted_token ) ) {
			$result = GF_Zero_Spam_Token::validate( $submitted_token, (int) rgar( $form, 'id' ) );

			return ! $result['valid'];
		}

		// Fall back to legacy static key during migration.
		$submitted_key = rgpost( 'gf_zero_spam_key' );

		if ( rgblank( $submitted_key ) ) {
			return true;
		}

		return true !== $this->validate_legacy_key( $submitted_key );
	}

	/**
	 * Retrieves the zero spam key (generating if needed).
	 *
	 * @since 1.0
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
	 * Collects the Zero Spam configuration for a form.
	 *
	 * The configuration is passed to a separate JavaScript file via
	 * wp_localize_script to avoid breaking Gravity Forms' conditional logic
	 * if a JS optimization plugin mangles the inline script.
	 *
	 * @since 1.0
	 *
	 * @param array $form The Form Object.
	 *
	 * @return void
	 */
	public function add_key_field( $form ) {
		/**
		 * Allows the zero spam key field to be disabled by returning false.
		 *
		 * @since 1.4
		 *
		 * @param bool $add_key_field Whether to add the key field to the form. Default true.
		 */
		$add_key_field = apply_filters( 'gf_zero_spam_add_key_field', true );

		if ( ! $add_key_field ) {
			return;
		}

		// Respect per-form toggle (same filter used during validation).
		$add_key_field = gf_apply_filters( 'gf_zero_spam_check_key_field', rgar( $form, 'id' ), true, $form, [] );

		if ( ! $add_key_field ) {
			return;
		}

		$form_id = (int) $form['id'];

		/**
		 * Filters the timeout (in milliseconds) for AJAX token fetch attempts.
		 *
		 * @since 1.7.0
		 *
		 * @param int $timeout Timeout in milliseconds. Default 3000.
		 */
		$timeout = (int) apply_filters( 'gf_zero_spam_token_fetch_timeout', 3000 );

		/**
		 * Filters the fallback token TTL embedded in the page HTML.
		 *
		 * The fallback token is used when the AJAX token fetch fails.
		 * Its TTL should exceed the longest page cache duration on the site.
		 *
		 * @since 1.7.3
		 *
		 * @param int $ttl Fallback token lifetime in seconds. Default 604800 (7 days).
		 */
		$fallback_ttl = (int) apply_filters( 'gf_zero_spam_fallback_token_ttl', GF_Zero_Spam_AddOn::get_instance()->get_token_ttl_seconds() );

		$this->pending_scripts[ $form_id ] = [
			'ajaxUrl'       => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'fallbackToken' => GF_Zero_Spam_Token::mint( $form_id, $fallback_ttl ),
			'formId'        => $form_id,
			'timeout'       => $timeout,
		];
	}

	/**
	 * Enqueues the Zero Spam script with collected form configurations.
	 *
	 * Uses a separate JavaScript file loaded after Gravity Forms' scripts so
	 * that any error does not prevent conditional logic from executing, which
	 * would leave the form hidden with display:none.
	 *
	 * @since 1.7.3
	 *
	 * @param string $form_string The form HTML.
	 * @param array  $form        The Form Object.
	 *
	 * @return string The unmodified form HTML.
	 */
	public function enqueue_script( $form_string, $form ) {
		if ( empty( $this->pending_scripts ) ) {
			return $form_string;
		}

		if ( wp_script_is( 'gf-zero-spam', 'enqueued' ) ) {
			return $form_string;
		}

		$handle = version_compare( GFForms::$version, '2.9.0', '>=' )
			? 'gform_gravityforms_utils'
			: 'gform_gravityforms';

		wp_enqueue_script(
			'gf-zero-spam',
			plugins_url( 'dist/js/gf-zero-spam.js', GF_ZERO_SPAM_FILE ),
			[ $handle ],
			(string) @filemtime( GF_ZERO_SPAM_DIR . 'dist/js/gf-zero-spam.js' ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Graceful fallback when file is missing.
			true
		);

		// Inject the config at print time via script_loader_tag rather than
		// wp_localize_script in a wp_footer callback. This guarantees all forms
		// have been collected because script_loader_tag fires during
		// wp_print_footer_scripts (wp_footer priority 20), after themes and
		// plugins that render forms via wp_footer at default priority.
		add_filter( 'script_loader_tag', [ $this, 'inject_config' ], 10, 2 );

		return $form_string;
	}

	/**
	 * Injects the form configuration inline before the Zero Spam script tag.
	 *
	 * Uses script_loader_tag instead of wp_localize_script so the config is
	 * built at print time, after all forms on the page (including those
	 * rendered via wp_footer by themes and plugins) have been collected.
	 *
	 * @since 1.7.5
	 *
	 * @param string $tag    The script tag HTML.
	 * @param string $handle The script handle.
	 *
	 * @return string The (possibly modified) script tag HTML.
	 */
	public function inject_config( $tag, $handle ) {
		if ( 'gf-zero-spam' !== $handle || empty( $this->pending_scripts ) ) {
			return $tag;
		}

		$config = wp_json_encode(
			[
				'forms' => array_values( $this->pending_scripts ),
				'debug' => defined( 'WP_DEBUG' ) && WP_DEBUG,
			]
		);

		$inline = sprintf( "<script type='text/javascript'>var gfZeroSpamConfig = %s;</script>\n", $config );

		return $inline . $tag;
	}

	/**
	 * Checks for our zero spam key during validation.
	 *
	 * @since 1.0
	 *
	 * @param bool  $is_spam Indicates if the submission has been flagged as spam.
	 * @param array $form    The form currently being processed.
	 * @param array $entry   The entry currently being processed.
	 *
	 * @return bool True: it's spam; False: it's not spam!
	 */
	public function check_key_field( $is_spam = false, $form = [], $entry = [] ) {
		// If the user can edit entries, they're not a spammer. It may be spam, but it's their
		// prerogative. Returns the incoming status so another check's verdict is never cleared.
		if ( GFCommon::current_user_can_any( 'gravityforms_edit_entries' ) ) {
			return $is_spam;
		}

		$already_flagged = (bool) $is_spam;

		// Another filter (e.g. honeypot) already flagged this as spam; evaluate anyway when the stop setting is off.
		if ( $already_flagged && $this->should_stop_after_first_detection() ) {
			return $is_spam;
		}

		$should_check_key_field = ! GFCommon::is_preview();

		/**
		 * Modifies whether to process this entry submission for spam.
		 *
		 * @since 1.2
		 *
		 * @param bool  $should_check_key_field Whether the Zero Spam plugin should check for the existence and validity of the key field. Default: true.
		 * @param array $form                   The form currently being processed.
		 * @param array $entry                  The entry currently being processed.
		 */
		$should_check_key_field = gf_apply_filters( 'gf_zero_spam_check_key_field', rgar( $form, 'id' ), $should_check_key_field, $form, $entry );

		if ( false === $should_check_key_field ) {
			return $is_spam;
		}

		$supports_context = method_exists( 'GFFormDisplay', 'get_submission_context' );
		if ( $supports_context && GFFormDisplay::get_submission_context() !== 'form-submit' ) {
			return $is_spam;
		}

	    // This was not submitted using a web form; created using API.
		if ( ! $supports_context && ! did_action( 'gform_pre_submission' ) ) {
			return $is_spam;
		}

		// Created using REST API or GFAPI.
		if ( isset( $entry['user_agent'] ) && 'API' === $entry['user_agent'] ) {
			return $is_spam;
		}

		$verdict = $this->get_token_verdict( $form );

		// Record the verdict as an entry note without changing the existing spam status.
		if ( $already_flagged ) {
			$this->schedule_secondary_verdict_note( $form, $verdict );

			return $is_spam;
		}

		if ( ! $verdict['is_spam'] ) {
			return false;
		}

		if ( method_exists( 'GFCommon', 'set_spam_filter' ) ) {
			GFCommon::set_spam_filter( rgar( $form, 'id' ), 'Zero Spam', $verdict['reason'] );
		} else {
			add_action( 'gform_entry_created', [ $this, 'add_entry_note' ] );
		}

		$this->record_token_rejection( $verdict['reason_code'], $form, $entry, __METHOD__ );

		return true;
	}

	/**
	 * Evaluates the token or legacy-key verdict for the current submission without side effects.
	 *
	 * @since 1.10.0
	 *
	 * @param array $form The form currently being processed.
	 *
	 * @return array{is_spam: bool, reason: string, reason_code: string} The verdict.
	 */
	private function get_token_verdict( $form ) {
		$submitted_token = rgpost( 'gf_zero_spam_token' );

		// Validate signed token if present.
		if ( ! rgblank( $submitted_token ) ) {
			$result = GF_Zero_Spam_Token::validate( $submitted_token, (int) rgar( $form, 'id' ) );

			if ( $result['valid'] ) {
				return [
					'is_spam'     => false,
					'reason'      => '',
					'reason_code' => '',
				];
			}

			$reason_map = [
				'token_missing' => __( 'The submission did not include a spam prevention token.', 'gravity-forms-zero-spam' ),
				'bad_format'    => __( 'The spam prevention token format is invalid.', 'gravity-forms-zero-spam' ),
				'expired'       => __( 'The spam prevention token has expired. This may be caused by page caching.', 'gravity-forms-zero-spam' ),
				'form_mismatch' => __( 'The spam prevention token was issued for a different form.', 'gravity-forms-zero-spam' ),
				'sig_invalid'   => __( 'The spam prevention token signature is invalid.', 'gravity-forms-zero-spam' ),
			];

			$reason_code = (string) $result['reason'];

			return [
				'is_spam'     => true,
				'reason'      => isset( $reason_map[ $reason_code ] ) ? $reason_map[ $reason_code ] : $reason_code,
				'reason_code' => $reason_code,
			];
		}

		// Fall back to legacy static key during migration.
		$submitted_key = rgpost( 'gf_zero_spam_key' );

		if ( rgblank( $submitted_key ) ) {
			return [
				'is_spam'     => true,
				'reason'      => __( 'The submission did not include a spam prevention token.', 'gravity-forms-zero-spam' ),
				'reason_code' => 'legacy_missing',
			];
		}

		$legacy_result = $this->validate_legacy_key( $submitted_key );

		if ( true !== $legacy_result ) {
			return [
				'is_spam'     => true,
				'reason'      => (string) $legacy_result,
				'reason_code' => 'legacy_invalid',
			];
		}

		return [
			'is_spam'     => false,
			'reason'      => '',
			'reason_code' => '',
		];
	}

	/**
	 * Schedules an entry note recording the token verdict for an already-flagged entry.
	 *
	 * @since 1.10.0
	 *
	 * @param array $form    The form currently being processed.
	 * @param array $verdict The token verdict.
	 *
	 * @return void
	 */
	private function schedule_secondary_verdict_note( $form, $verdict ) {
		$form_id = (int) rgar( $form, 'id' );

		if ( $form_id < 1 ) {
			return;
		}

		if ( $verdict['is_spam'] ) {
			$note = [
				'text' => strtr(
					/* translators: placeholders in [brackets] are replaced and must not be translated. */
					__( 'The Zero Spam token check also flagged this submission: [reason]', 'gravity-forms-zero-spam' ),
					[ '[reason]' => $verdict['reason'] ]
				),
				'type' => 'warning',
			];
		} else {
			$note = [
				'text' => __( 'The Zero Spam token check found a valid token for this submission.', 'gravity-forms-zero-spam' ),
				'type' => 'success',
			];
		}

		$this->secondary_notes[ $form_id ] = $note;

		add_action( 'gform_entry_created', [ $this, 'add_secondary_verdict_note' ] );
	}

	/**
	 * Adds the scheduled token verdict note to the created entry.
	 *
	 * @since 1.10.0
	 *
	 * @param array $entry The created entry.
	 *
	 * @return void
	 */
	public function add_secondary_verdict_note( $entry ) {
		$form_id = (int) rgar( $entry, 'form_id' );

		if ( empty( $this->secondary_notes[ $form_id ] ) ) {
			return;
		}

		$note = $this->secondary_notes[ $form_id ];
		unset( $this->secondary_notes[ $form_id ] );

		if ( ! method_exists( 'GFAPI', 'add_note' ) ) {
			return;
		}

		GFAPI::add_note( $entry['id'], 0, 'Zero Spam', $note['text'], 'gf-zero-spam', $note['type'] );
	}

	/**
	 * Returns whether later checks are skipped once a check flags a submission as spam.
	 *
	 * @since 1.10.0
	 *
	 * @return bool Whether to stop after the first detection.
	 */
	private function should_stop_after_first_detection() {
		if ( ! method_exists( 'GF_Zero_Spam_AddOn', 'get_instance' ) ) {
			return true;
		}

		return GF_Zero_Spam_AddOn::get_instance()->should_stop_after_first_detection();
	}

	/**
	 * Records a rejected token check for diagnostics.
	 *
	 * @since 1.9.0
	 *
	 * @param string $reason_code Raw rejection reason code.
	 * @param array  $form        The form currently being processed.
	 * @param array  $entry       The pre-save entry currently being processed.
	 * @param string $method      The method that rejected the token.
	 *
	 * @return void
	 */
	private function record_token_rejection( $reason_code, $form, $entry, $method ) {
		self::$token_rejections[ (int) rgar( $form, 'id' ) ] = (string) $reason_code;

		if ( method_exists( 'GF_Zero_Spam_AddOn', 'get_instance' ) ) {
			GF_Zero_Spam_AddOn::get_instance()->log_debug( $method . '(): Submission rejected by Zero Spam token check. Reason: ' . $reason_code . '. Form #' . (int) rgar( $form, 'id' ) . '.' );
		}

		/**
		 * Fires when a submission is rejected by the Zero Spam token check.
		 *
		 * @since 1.9.0
		 *
		 * @param string $reason_code Raw rejection reason code. Accepted values: token_missing, bad_format, expired, form_mismatch, sig_invalid, legacy_missing, legacy_invalid.
		 * @param array  $form        The form currently being processed.
		 * @param array  $entry       The entry currently being processed. Note: pre-save entry; no ID yet.
		 */
		do_action( 'gf_zero_spam_token_rejected', $reason_code, $form, $entry );
	}

	/**
	 * Validates a legacy static key during the migration period.
	 *
	 * Sets a migration deadline on first encounter and accepts the legacy key
	 * until the deadline passes. After the deadline, the legacy key is rejected.
	 *
	 * @since 1.7.0
	 *
	 * @param string $submitted_key The submitted legacy key.
	 *
	 * @return true|string True if valid, or an error message string if invalid.
	 */
	private function validate_legacy_key( string $submitted_key ) {
		$deadline = get_option( 'gf_zero_spam_legacy_deadline' );

		// Set the migration deadline on first encounter.
		if ( false === $deadline ) {
			$deadline = time() + ( 14 * DAY_IN_SECONDS );

			update_option( 'gf_zero_spam_legacy_deadline', $deadline, false );
		}

		// Migration window has closed.
		if ( time() >= (int) $deadline ) {
			return __( 'Legacy spam prevention key no longer accepted. Please clear your page cache.', 'gravity-forms-zero-spam' );
		}

		$key = get_option( 'gf_zero_spam_key' );

		if ( ! $key ) {
			return __( 'The submitted key is invalid.', 'gravity-forms-zero-spam' );
		}

		if ( html_entity_decode( sanitize_text_field( $submitted_key ) ) !== $key ) {
			return __( 'The submitted key is invalid.', 'gravity-forms-zero-spam' );
		}

		return true;
	}

	/**
	 * Displays an admin notice during the migration from static key to signed tokens.
	 *
	 * @since 1.7.0
	 *
	 * @return void
	 */
	public function migration_notice() {
		$screen = get_current_screen();

		if ( ! $screen || 'dashboard' !== $screen->id || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$deadline = get_option( 'gf_zero_spam_legacy_deadline' );

		if ( false === $deadline || time() >= (int) $deadline ) {
			return;
		}

		$date = date_i18n( get_option( 'date_format' ), (int) $deadline );

		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			sprintf(
				/* translators: %s: The date when legacy support ends. */
				esc_html__( 'Gravity Forms Zero Spam has been upgraded with improved spam protection. Please clear your page cache to ensure all forms use the new protection. Legacy support ends on %s.', 'gravity-forms-zero-spam' ),
				'<strong>' . esc_html( $date ) . '</strong>'
			)
		);
	}

	/**
	 * Cleans up legacy options after the migration deadline has passed.
	 *
	 * @since 1.7.0
	 *
	 * @return void
	 */
	public function maybe_cleanup_legacy() {
		$deadline = get_option( 'gf_zero_spam_legacy_deadline' );

		if ( false === $deadline ) {
			return;
		}

		if ( time() < (int) $deadline ) {
			return;
		}

		delete_option( 'gf_zero_spam_key' );
		delete_option( 'gf_zero_spam_legacy_deadline' );
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

		GFAPI::add_note( $entry['id'], 0, 'Zero Spam', __( 'This entry has been marked as spam.', 'gravity-forms-zero-spam' ), 'gf-zero-spam', 'success' );
	}
}
