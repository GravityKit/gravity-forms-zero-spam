<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Email rejection rule evaluation engine.
 *
 * Evaluates email addresses against rejection rules (domain, email, wildcard, regex)
 * and applies the configured action (block, flag, log).
 *
 * @since 1.5.0
 */
class GF_Zero_Spam_Email_Rejection {

	/**
	 * Allowed rule types.
	 *
	 * @since 1.5.0
	 *
	 * @var string[]
	 */
	const ALLOWED_TYPES = [ 'domain', 'email', 'wildcard', 'regex' ];

	/**
	 * Allowed rule actions.
	 *
	 * @since 1.5.0
	 *
	 * @var string[]
	 */
	const ALLOWED_ACTIONS = [ 'block', 'flag', 'log' ];

	/**
	 * Cached global rules for the current request.
	 *
	 * @since 1.5.0
	 *
	 * @var array|null
	 */
	private static $cached_rules = null;

	/**
	 * Whether the block action is supported (requires GF 2.9.15+).
	 *
	 * @since 1.5.0
	 *
	 * @var bool|null
	 */
	private static $block_supported = null;

	/**
	 * Entries flagged for spam by flag-action rules during validation.
	 *
	 * @since 1.5.0
	 *
	 * @var array Keyed by form_id.
	 */
	private static $flagged_entries = [];

	/**
	 * Rule matches to log after submission.
	 *
	 * @since 1.5.0
	 *
	 * @var array Keyed by form_id.
	 */
	private static $log_matches = [];

	/**
	 * Cached per-field rules for the current request.
	 *
	 * @since 1.5.0
	 *
	 * @var array Keyed by "{form_id}_{field_id}".
	 */
	private static $field_rules_cache = [];

	/**
	 * Initializes hooks.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function init() {
		$addon = GF_Zero_Spam_AddOn::get_instance();

		if ( empty( $addon->get_plugin_setting( 'gf_zero_spam_email_rejection_enabled' ) ) ) {
			return;
		}

		// Block action: integrate with GF's native email rejection filter.
		if ( self::is_block_supported() ) {
			add_filter( 'gform_email_field_rejectable_values', [ $this, 'filter_rejectable_values' ], 10, 3 );
			add_filter( 'gform_field_validation', [ $this, 'replace_block_validation_message' ], 20, 4 );
		}

		// Flag + Log actions: evaluate during validation, apply after submission.
		add_filter( 'gform_validation', [ $this, 'evaluate_flag_and_log_rules' ], 20 );
		add_filter( 'gform_entry_is_spam', [ $this, 'flag_matched_entries' ], 10, 3 );
		add_action( 'gform_after_submission', [ $this, 'log_matched_entries' ], 10, 2 );
	}

	/**
	 * Checks if the block action is supported (GF 2.9.15+).
	 *
	 * @since 1.5.0
	 *
	 * @return bool
	 */
	public static function is_block_supported() {
		if ( null === self::$block_supported ) {
			self::$block_supported = class_exists( 'GFForms' ) && version_compare( GFForms::$version, '2.9.15', '>=' );
		}

		return self::$block_supported;
	}

	/**
	 * Resets static state between requests.
	 *
	 * Clears all cached data. Necessary in persistent PHP environments
	 * (e.g., PHP-FPM with keep-alive, unit tests) where static properties
	 * survive across requests.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public static function reset() {
		self::$cached_rules      = null;
		self::$block_supported   = null;
		self::$flagged_entries   = [];
		self::$log_matches       = [];
		self::$field_rules_cache = [];
	}

	/**
	 * Gets the global rejection rules.
	 *
	 * @since 1.5.0
	 *
	 * @return array
	 */
	public static function get_global_rules() {
		if ( null !== self::$cached_rules ) {
			return self::$cached_rules;
		}

		$addon = GF_Zero_Spam_AddOn::get_instance();
		$rules = $addon->get_plugin_setting( 'gf_zero_spam_email_rules' );

		if ( ! is_array( $rules ) ) {
			$rules = [];
		}

		self::$cached_rules = $rules;

		return $rules;
	}

	/**
	 * Gets the merged ruleset for a specific email field.
	 *
	 * @since 1.5.0
	 *
	 * @param GF_Field_Email|GF_Field $field The email field.
	 * @param array                   $form  The form object.
	 *
	 * @return array Merged array of rules.
	 */
	public static function get_rules_for_field( $field, $form ) {
		$cache_key = rgar( $form, 'id' ) . '_' . $field->id;

		if ( isset( self::$field_rules_cache[ $cache_key ] ) ) {
			return self::$field_rules_cache[ $cache_key ];
		}

		$global_rules   = self::get_global_rules();
		$field_settings = isset( $field->emailRejection ) ? $field->emailRejection : [];

		if ( empty( $field_settings['enabled'] ) ) {
			$rules = $global_rules;
		} elseif ( rgar( $field_settings, 'mode' ) === 'replace' ) {
			$rules = isset( $field_settings['rules'] ) ? $field_settings['rules'] : [];
		} else {
			// inherit_add: merge global + field rules.
			$field_rules = isset( $field_settings['rules'] ) ? $field_settings['rules'] : [];
			$rules       = array_merge( $global_rules, $field_rules );
		}

		/**
		 * Modifies the email rejection rules before evaluation.
		 *
		 * @since 1.5.0
		 *
		 * @param array                   $rules The merged rules array.
		 * @param GF_Field_Email|GF_Field $field The email field.
		 * @param array                   $form  The form object.
		 */
		$rules = apply_filters( 'gf_zero_spam_email_rules', $rules, $field, $form );

		self::$field_rules_cache[ $cache_key ] = $rules;

		return $rules;
	}

	/**
	 * Evaluates an email against a set of rules.
	 *
	 * Returns the first matching rule, or null if no match.
	 *
	 * @since 1.5.0
	 *
	 * @param string $email The email address to check.
	 * @param array  $rules The rules to evaluate.
	 *
	 * @return array|null The matched rule, or null.
	 */
	public static function evaluate( $email, $rules ) {
		$email = strtolower( trim( $email ) );

		if ( empty( $email ) || empty( $rules ) ) {
			return null;
		}

		foreach ( $rules as $rule ) {
			// Skip disabled rules (global rules have an 'enabled' key).
			if ( isset( $rule['enabled'] ) && ! $rule['enabled'] ) {
				continue;
			}

			$value = strtolower( trim( rgar( $rule, 'value', '' ) ) );

			if ( empty( $value ) ) {
				continue;
			}

			$matched = false;

			switch ( rgar( $rule, 'type' ) ) {
				case 'domain':
					$matched = self::match_domain( $email, $value );
					break;
				case 'email':
					$matched = self::match_email( $email, $value );
					break;
				case 'wildcard':
					$matched = self::match_wildcard( $email, $value );
					break;
				case 'regex':
					$matched = self::match_regex( $email, $value );
					break;
			}

			if ( $matched ) {
				/**
				 * Fires when an email matches a rejection rule.
				 *
				 * @since 1.5.0
				 *
				 * @param array  $rule  The matched rule.
				 * @param string $email The email that matched.
				 */
				do_action( 'gf_zero_spam_email_rule_match', $rule, $email );

				return $rule;
			}
		}

		return null;
	}

	/**
	 * Matches email by domain (exact match on domain part).
	 *
	 * @since 1.5.0
	 *
	 * @param string $email The email to check.
	 * @param string $value The domain to match against.
	 *
	 * @return bool
	 */
	public static function match_domain( $email, $value ) {
		$parts = explode( '@', $email );

		if ( count( $parts ) !== 2 ) {
			return false;
		}

		return $parts[1] === $value;
	}

	/**
	 * Matches email by exact address (case-insensitive).
	 *
	 * @since 1.5.0
	 *
	 * @param string $email The email to check.
	 * @param string $value The email to match against.
	 *
	 * @return bool
	 */
	public static function match_email( $email, $value ) {
		return $email === $value;
	}

	/**
	 * Matches email against a wildcard pattern.
	 *
	 * Supports: *.example.com, *@example.com, prefix*@example.com
	 *
	 * @since 1.5.0
	 *
	 * @param string $email   The email to check.
	 * @param string $pattern The wildcard pattern.
	 *
	 * @return bool
	 */
	public static function match_wildcard( $email, $pattern ) {
		// Convert wildcard to regex: escape dots, replace * with .*.
		$regex = '/^' . str_replace(
			[ '\*', '\.' ],
			[ '.*', '\.' ],
			preg_quote( $pattern, '/' )
		) . '$/i';

		return (bool) @preg_match( $regex, $email );
	}

	/**
	 * Wraps a user-supplied regex pattern with delimiters and flags.
	 *
	 * @since 1.5.0
	 *
	 * @param string $pattern The regex pattern (without delimiters).
	 *
	 * @return string The delimited regex.
	 */
	private static function wrap_regex( $pattern ) {
		return '/' . str_replace( '/', '\/', $pattern ) . '/i';
	}

	/**
	 * Matches email against a regex pattern.
	 *
	 * @since 1.5.0
	 *
	 * @param string $email   The email to check.
	 * @param string $pattern The regex pattern (without delimiters).
	 *
	 * @return bool
	 */
	public static function match_regex( $email, $pattern ) {
		$regex = self::wrap_regex( $pattern );

		$old_limit = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.backtrack_limit', '10000' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Temporarily limits backtracking to prevent ReDoS.

		$result = @preg_match( $regex, $email );

		ini_set( 'pcre.backtrack_limit', (string) $old_limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores original backtrack limit.

		return (bool) $result;
	}

	/**
	 * Validates a regex pattern is syntactically correct.
	 *
	 * @since 1.5.0
	 *
	 * @param string $pattern The regex pattern to validate.
	 *
	 * @return bool True if valid.
	 */
	public static function validate_regex( $pattern ) {
		return @preg_match( self::wrap_regex( $pattern ), '' ) !== false;
	}

	/**
	 * Filters GF's rejectable values for email fields (Block action).
	 *
	 * @since 1.5.0
	 *
	 * @see https://docs.gravityforms.com/gform_email_field_rejectable_values/
	 *
	 * @param array          $rejectable_values Existing rejectable values.
	 * @param string         $email             The submitted email.
	 * @param GF_Field_Email $field             The email field.
	 *
	 * @return array
	 */
	public function filter_rejectable_values( $rejectable_values, $email, $field ) {
		// GF passes an array for email fields with confirmation enabled.
		$email = is_array( $email ) ? $email[0] : $email;
		$form  = GFAPI::get_form( $field->formId );
		$rules = self::get_rules_for_field( $field, $form );

		$block_rules = array_filter(
            $rules,
            function ( $rule ) {
				return rgar( $rule, 'action' ) === 'block';
			}
        );

		if ( ! empty( $block_rules ) && self::evaluate( $email, $block_rules ) ) {
			$rejectable_values[] = $email;
		}

		return $rejectable_values;
	}

	/**
	 * Replaces GF's default rejection message with our custom validation message.
	 *
	 * GF's email rejection sets its own generic message. This filter runs after
	 * field validation to substitute our per-field or global custom message when
	 * the rejection was triggered by one of our block rules.
	 *
	 * @since 1.5.0
	 *
	 * @param array    $result The validation result array with 'is_valid' and 'message'.
	 * @param mixed    $value  The submitted field value.
	 * @param array    $form   The form object.
	 * @param GF_Field $field  The field object.
	 *
	 * @return array
	 */
	public function replace_block_validation_message( $result, $value, $form, $field ) {
		if ( 'email' !== $field->type || $result['is_valid'] ) {
			return $result;
		}

		$email = is_array( $value ) ? $value[0] : $value;

		if ( empty( $email ) ) {
			return $result;
		}

		$rules       = self::get_rules_for_field( $field, $form );
		$block_rules = array_filter(
            $rules,
            function ( $rule ) {
				return rgar( $rule, 'action' ) === 'block';
			}
        );

		if ( empty( $block_rules ) ) {
			return $result;
		}

		$matched = self::evaluate( $email, $block_rules );

		if ( $matched ) {
			$result['message'] = self::get_validation_message( $field );
		}

		return $result;
	}

	/**
	 * Evaluates flag and log rules during form validation.
	 *
	 * @since 1.5.0
	 *
	 * @param array $validation_result The validation result array.
	 *
	 * @return array
	 */
	public function evaluate_flag_and_log_rules( $validation_result ) {
		$form = $validation_result['form'];

		foreach ( $form['fields'] as $field ) {
			if ( 'email' !== $field->type ) {
				continue;
			}

			$email = rgpost( 'input_' . $field->id );

			if ( empty( $email ) ) {
				continue;
			}

			$rules = self::get_rules_for_field( $field, $form );

			// Only evaluate flag and log rules here (block handled by GF filter).
			$flag_log_rules = array_filter(
                $rules,
                function ( $rule ) {
					return in_array( rgar( $rule, 'action' ), [ 'flag', 'log' ], true );
				}
            );

			$matched = self::evaluate( $email, $flag_log_rules );

			if ( ! $matched ) {
				continue;
			}

			$match_info = [
				'rule'  => $matched,
				'email' => $email,
				'field' => $field->id,
			];

			if ( rgar( $matched, 'action' ) === 'flag' ) {
				self::$flagged_entries[ $form['id'] ][] = $match_info;
			}

			if ( rgar( $matched, 'action' ) === 'log' ) {
				self::$log_matches[ $form['id'] ][] = $match_info;
			}
		}

		return $validation_result;
	}

	/**
	 * Marks entry as spam if flag rule matched.
	 *
	 * @since 1.5.0
	 *
	 * @param bool  $is_spam Whether the entry is spam.
	 * @param array $form    The form object.
	 * @param array $entry   The entry object.
	 *
	 * @return bool
	 */
	public function flag_matched_entries( $is_spam, $form, $entry ) {
		if ( ! empty( self::$flagged_entries[ $form['id'] ] ) ) {
			return true;
		}

		return $is_spam;
	}

	/**
	 * Adds entry note for log-action matches.
	 *
	 * @since 1.5.0
	 *
	 * @param array $entry The entry object.
	 * @param array $form  The form object.
	 *
	 * @return void
	 */
	public function log_matched_entries( $entry, $form ) {
		if ( ! method_exists( 'GFAPI', 'add_note' ) ) {
			return;
		}

		// Log matches.
		if ( ! empty( self::$log_matches[ $form['id'] ] ) ) {
			foreach ( self::$log_matches[ $form['id'] ] as $match ) {
				$note = sprintf(
					'Email Rejection Rule matched: %s "%s" (%s action) for email "%s" on field #%d.',
					rgar( $match['rule'], 'type' ),
					rgar( $match['rule'], 'value' ),
					rgar( $match['rule'], 'action' ),
					$match['email'],
					$match['field']
				);

				GFAPI::add_note( $entry['id'], 0, 'Zero Spam', $note, 'gf-zero-spam', 'info' );
			}
		}

		// Also log flag matches.
		if ( ! empty( self::$flagged_entries[ $form['id'] ] ) ) {
			foreach ( self::$flagged_entries[ $form['id'] ] as $match ) {
				$note = sprintf(
					'Email Rejection Rule matched: %s "%s" — entry flagged as spam for email "%s" on field #%d.',
					rgar( $match['rule'], 'type' ),
					rgar( $match['rule'], 'value' ),
					$match['email'],
					$match['field']
				);

				GFAPI::add_note( $entry['id'], 0, 'Zero Spam', $note, 'gf-zero-spam', 'warning' );
			}
		}
	}

	/**
	 * Gets the validation message for a rejected email.
	 *
	 * Checks per-field message first, falls back to global default.
	 *
	 * @since 1.5.0
	 *
	 * @param GF_Field_Email|GF_Field $field The email field.
	 *
	 * @return string
	 */
	public static function get_validation_message( $field ) {
		$field_settings = isset( $field->emailRejection ) ? $field->emailRejection : [];
		$field_message  = rgar( $field_settings, 'message', '' );

		if ( ! empty( $field_message ) ) {
			return $field_message;
		}

		$addon          = GF_Zero_Spam_AddOn::get_instance();
		$global_message = $addon->get_plugin_setting( 'gf_zero_spam_email_rejection_message' );

		if ( ! empty( $global_message ) ) {
			return $global_message;
		}

		return self::get_default_validation_message();
	}

	/**
	 * Returns the default translated validation message.
	 *
	 * @since 1.5.0
	 *
	 * @return string
	 */
	public static function get_default_validation_message() {
		return __( 'The email address you entered is not allowed. Please use a different email address.', 'gravity-forms-zero-spam' );
	}
}
