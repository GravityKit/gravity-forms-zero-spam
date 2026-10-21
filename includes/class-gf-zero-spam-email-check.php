<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Checks that each submitted email address can be a real contact address, and acts on the answer.
 *
 * Every install runs the local checks (placeholder addresses and throwaway inboxes). The
 * gravitykit.com edition adds the mail server, mailbox and typo checks through the
 * `gf_zero_spam_email_check_verdict` filter. The check runs once per email field, at validation.
 *
 * @since TBD
 */
class GF_Zero_Spam_Email_Check {

	/**
	 * Every reason a check can return.
	 *
	 * @since TBD
	 *
	 * @var string[]
	 */
	const REASONS = [ 'placeholder', 'disposable', 'typo', 'no_mail_server', 'no_mailbox' ];

	/**
	 * What a site owner can do with each reason, matching the email rejection rules.
	 *
	 * @since TBD
	 *
	 * @var string[]
	 */
	const ACTIONS = [ 'block', 'flag', 'log' ];

	/**
	 * Action for a reason the owner has not configured.
	 *
	 * @since TBD
	 *
	 * @var string
	 */
	const DEFAULT_ACTION = 'flag';

	/**
	 * Global setting that turns the email check on.
	 *
	 * @since TBD
	 *
	 * @var string
	 */
	const SETTING_ENABLED = 'gf_zero_spam_email_check_enabled';

	/**
	 * Form setting that turns the check off for one form.
	 *
	 * @since TBD
	 *
	 * @var string
	 */
	const FORM_SETTING = 'gfZeroSpamEmailCheck';

	/**
	 * Verdicts found during validation, for the spam and note steps of the same request.
	 *
	 * @since TBD
	 *
	 * @var array<int,array<int,array{reason:string,suggestion:string,action:string}>> Keyed by form ID, then field ID.
	 */
	private static $verdicts = [];

	/**
	 * Registers the hooks when the email check is on.
	 *
	 * @since TBD
	 *
	 * @return void
	 */
	public function init() {
		if ( ! self::is_enabled() ) {
			return;
		}

		// After the email rejection rules (priority 20), so a field an owner's rule already rejected is left alone.
		add_filter( 'gform_field_validation', [ $this, 'validate_field' ], 25, 5 );
		add_filter( 'gform_entry_is_spam', [ $this, 'flag_entry' ], 10, 3 );
		add_action( 'gform_after_submission', [ $this, 'add_notes' ], 10, 2 );
	}

	/**
	 * Whether the email check is on for the site.
	 *
	 * @since TBD
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return ! empty( GF_Zero_Spam_AddOn::get_instance()->get_plugin_setting( self::SETTING_ENABLED ) );
	}

	/**
	 * Whether the email check runs on a form.
	 *
	 * @since TBD
	 *
	 * @param array $form The form.
	 *
	 * @return bool
	 */
	public static function is_enabled_for_form( $form ) {
		$enabled = ! ( isset( $form[ self::FORM_SETTING ] ) && empty( $form[ self::FORM_SETTING ] ) );

		/**
		 * Modifies whether the email check runs on a form.
		 *
		 * @since TBD
		 *
		 * @param bool  $enabled Whether the check runs. Default: the form's setting, on unless switched off.
		 * @param array $form    The form.
		 */
		return (bool) apply_filters( 'gf_zero_spam_email_check_enabled', $enabled, $form );
	}

	/**
	 * Returns the action configured for a reason.
	 *
	 * @since TBD
	 *
	 * @param string $reason One of {@see self::REASONS}.
	 *
	 * @return string One of {@see self::ACTIONS}.
	 */
	public static function action_for( $reason ) {
		$setting = GF_Zero_Spam_AddOn::get_instance()->get_plugin_setting( 'gf_zero_spam_email_check_action_' . $reason );
		$action  = in_array( $setting, self::ACTIONS, true ) ? $setting : self::DEFAULT_ACTION;

		/**
		 * Modifies what happens when the email check finds a problem.
		 *
		 * @since TBD
		 *
		 * @param string $action `block` shows the visitor an error, `flag` marks the entry as spam, `log` adds a note only.
		 * @param string $reason `placeholder`, `disposable`, `typo`, `no_mail_server` or `no_mailbox`.
		 */
		$action = apply_filters( 'gf_zero_spam_email_check_action', $action, $reason );

		return in_array( $action, self::ACTIONS, true ) ? $action : self::DEFAULT_ACTION;
	}

	/**
	 * Returns the problem with an address, or null when every check passes it or cannot tell.
	 *
	 * @since TBD
	 *
	 * @param string   $email The address.
	 * @param array    $form  The form.
	 * @param GF_Field $field The email field.
	 *
	 * @return array{reason:string,suggestion:string}|null
	 */
	public static function get_verdict( $email, $form, $field ) {
		$reason  = GF_Zero_Spam_Email_Check_Local::check( $email );
		$verdict = $reason ? [ 'reason' => $reason, 'suggestion' => '' ] : null;

		/**
		 * Modifies the email check's verdict. The gravitykit.com edition adds its remote check here.
		 *
		 * @since TBD
		 *
		 * @param array|null $verdict `['reason' => string, 'suggestion' => string]`, or null when the address passes.
		 * @param string     $email   The submitted address, trimmed.
		 * @param array      $form    The form.
		 * @param GF_Field   $field   The email field.
		 */
		$verdict = apply_filters( 'gf_zero_spam_email_check_verdict', $verdict, $email, $form, $field );

		if ( ! is_array( $verdict ) || ! in_array( rgar( $verdict, 'reason' ), self::REASONS, true ) ) {
			return null;
		}

		return [
			'reason'     => (string) $verdict['reason'],
			'suggestion' => (string) rgar( $verdict, 'suggestion', '' ),
		];
	}

	/**
	 * Checks an email field during validation; a Block action fails the field.
	 *
	 * @since TBD
	 *
	 * @param array    $result  Validation result with `is_valid` and `message`.
	 * @param mixed    $value   The submitted value; an array when the field has a confirmation input.
	 * @param array    $form    The form.
	 * @param GF_Field $field   The field.
	 * @param string   $context `form-submit`, `api-submit` or `api-validate`.
	 *
	 * @return array
	 */
	public function validate_field( $result, $value, $form, $field, $context = 'form-submit' ) {
		$is_email_field = is_object( $field ) && 'email' === $field->type;

		if ( ! $is_email_field || empty( $result['is_valid'] ) || ! self::should_check( $form, $context ) ) {
			return $result;
		}

		$email = trim( (string) ( is_array( $value ) ? rgar( $value, 0 ) : $value ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return $result;
		}

		$form_id  = (int) rgar( $form, 'id' );
		$field_id = (int) $field->id;
		$verdict  = self::get_verdict( $email, $form, $field );

		if ( null === $verdict ) {
			unset( self::$verdicts[ $form_id ][ $field_id ] );

			return $result;
		}

		$verdict['action'] = self::action_for( $verdict['reason'] );

		self::$verdicts[ $form_id ][ $field_id ] = $verdict;

		if ( 'block' === $verdict['action'] ) {
			$result['is_valid'] = false;
			$result['message']  = self::get_message( $verdict['reason'], $verdict['suggestion'] );
		}

		return $result;
	}

	/**
	 * Marks the entry as spam when a Flag verdict was found.
	 *
	 * @since TBD
	 *
	 * @param bool  $is_spam Whether the entry is spam.
	 * @param array $form    The form.
	 * @param array $entry   The entry.
	 *
	 * @return bool
	 */
	public function flag_entry( $is_spam, $form, $entry ) {
		$form_id = (int) rgar( $form, 'id' );
		$flagged = array_filter(
			self::$verdicts[ $form_id ] ?? [],
			static function ( $verdict ) {
				return 'flag' === $verdict['action'];
			}
		);

		if ( empty( $flagged ) ) {
			return $is_spam;
		}

		if ( method_exists( 'GF_Zero_Spam', 'record_non_token_spam_source' ) ) {
			GF_Zero_Spam::record_non_token_spam_source( $form_id, 'email_check' );
		}

		return true;
	}

	/**
	 * Adds an entry note for each Flag or Log verdict. The note names the reason; the address is already in the entry.
	 *
	 * @since TBD
	 *
	 * @param array $entry The entry.
	 * @param array $form  The form.
	 *
	 * @return void
	 */
	public function add_notes( $entry, $form ) {
		$form_id = (int) rgar( $form, 'id' );

		if ( empty( self::$verdicts[ $form_id ] ) || ! method_exists( 'GFAPI', 'add_note' ) ) {
			return;
		}

		foreach ( self::$verdicts[ $form_id ] as $field_id => $verdict ) {
			if ( 'block' === $verdict['action'] ) {
				continue;
			}

			$note = sprintf(
				/* translators: 1: field ID, 2: what is wrong with the address, 3: what Zero Spam did. */
				__( 'Email check, field #%1$d: %2$s %3$s', 'gravity-forms-zero-spam' ),
				$field_id,
				self::describe( $verdict['reason'] ),
				'flag' === $verdict['action'] ? __( 'The entry was marked as spam.', 'gravity-forms-zero-spam' ) : __( 'No action was taken.', 'gravity-forms-zero-spam' )
			);

			if ( '' !== $verdict['suggestion'] ) {
				/* translators: %s: a corrected email address. */
				$note .= ' ' . sprintf( __( 'Suggested address: %s', 'gravity-forms-zero-spam' ), $verdict['suggestion'] );
			}

			GFAPI::add_note( (int) $entry['id'], 0, 'Zero Spam', $note, 'gf-zero-spam', 'flag' === $verdict['action'] ? 'warning' : 'info' );
		}

		unset( self::$verdicts[ $form_id ] );
	}

	/**
	 * Returns the error a visitor sees when a reason is set to Block.
	 *
	 * @since TBD
	 *
	 * @param string $reason     One of {@see self::REASONS}.
	 * @param string $suggestion A corrected address, or an empty string.
	 *
	 * @return string HTML-safe message.
	 */
	public static function get_message( $reason, $suggestion = '' ) {
		switch ( $reason ) {
			case 'placeholder':
				$message = __( 'Please enter your real email address.', 'gravity-forms-zero-spam' );
				break;
			case 'disposable':
				$message = __( 'Please use a permanent email address, not a temporary one.', 'gravity-forms-zero-spam' );
				break;
			case 'typo':
				$message = __( 'This email address looks mistyped.', 'gravity-forms-zero-spam' );
				break;
			default:
				$message = __( 'This email address can\'t receive mail. Please check it for typos.', 'gravity-forms-zero-spam' );
		}

		if ( '' !== $suggestion ) {
			/* translators: %s: a corrected email address. */
			$message .= ' ' . sprintf( __( 'Did you mean %s?', 'gravity-forms-zero-spam' ), $suggestion );
		}

		/**
		 * Modifies the error a visitor sees when the email check blocks an address.
		 *
		 * @since TBD
		 *
		 * @param string $message    The message, plain text.
		 * @param string $reason     `placeholder`, `disposable`, `typo`, `no_mail_server` or `no_mailbox`.
		 * @param string $suggestion A corrected address, or an empty string.
		 */
		$message = apply_filters( 'gf_zero_spam_email_check_message', $message, $reason, $suggestion );

		return wp_kses_post( $message );
	}

	/**
	 * Describes a reason for an entry note.
	 *
	 * @since TBD
	 *
	 * @param string $reason One of {@see self::REASONS}.
	 *
	 * @return string
	 */
	public static function describe( $reason ) {
		$descriptions = [
			'placeholder'    => __( 'the address looks like a placeholder, such as you@company.com.', 'gravity-forms-zero-spam' ),
			'disposable'     => __( 'the address uses a throwaway inbox provider.', 'gravity-forms-zero-spam' ),
			'typo'           => __( 'the address looks mistyped.', 'gravity-forms-zero-spam' ),
			'no_mail_server' => __( 'the address\'s domain has no mail server.', 'gravity-forms-zero-spam' ),
			'no_mailbox'     => __( 'the mail server says this mailbox does not exist.', 'gravity-forms-zero-spam' ),
		];

		return $descriptions[ $reason ] ?? $reason;
	}

	/**
	 * Clears the verdicts kept for the current request.
	 *
	 * @since TBD
	 *
	 * @return void
	 */
	public static function reset() {
		self::$verdicts = [];
	}

	/**
	 * Whether this submission should be checked: a visitor's web submission of a form that has the check on.
	 *
	 * @since TBD
	 *
	 * @param array  $form    The form.
	 * @param string $context The submission context.
	 *
	 * @return bool
	 */
	private static function should_check( $form, $context ) {
		// Someone who can edit entries is trusted, as the token check trusts them.
		if ( GFCommon::current_user_can_any( 'gravityforms_edit_entries' ) || GFCommon::is_preview() ) {
			return false;
		}

		if ( 'form-submit' !== $context ) {
			return false;
		}

		return self::is_enabled_for_form( $form );
	}
}
