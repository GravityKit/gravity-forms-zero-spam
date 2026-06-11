<?php

use Throwable as BaseThrowable;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Reviews token-cleared Gravity Forms submissions using the WordPress AI Client.
 *
 * @since 1.9.0
 */
final class GF_Zero_Spam_AI_Review {
	/**
	 * Spam filter name recorded on AI spam verdicts.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	const SPAM_FILTER_NAME = 'Zero Spam (AI)';

	/**
	 * Spam filter name recorded on AI rescue notes.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	const RESCUE_FILTER_NAME = 'Zero Spam (AI Rescue)';

	/**
	 * Default model confidence threshold.
	 *
	 * @since 1.9.0
	 *
	 * @var float
	 */
	const DEFAULT_CONFIDENCE_THRESHOLD = 0.90;

	/**
	 * Default model confidence threshold for token false-positive rescue.
	 *
	 * @since 1.9.0
	 *
	 * @var float
	 */
	const DEFAULT_RESCUE_CONFIDENCE_THRESHOLD = 0.90;

	/**
	 * Default AI request timeout in seconds.
	 *
	 * @since 1.9.0
	 *
	 * @var float
	 */
	const DEFAULT_TIMEOUT = 8.0;

	/**
	 * Default maximum generated tokens.
	 *
	 * The JSON verdict is small, but reasoning models such as Gemini 2.5 can
	 * consume output budget on internal thinking before emitting the answer.
	 * A 200-token cap truncated Gemini to empty candidates; 512 gives headroom
	 * for thinking, the JSON verdict, and a short reason.
	 *
	 * @since 1.9.0
	 *
	 * @var int
	 */
	const DEFAULT_MAX_TOKENS = 512;

	/**
	 * Default maximum serialized payload size.
	 *
	 * @since 1.9.0
	 *
	 * @var int
	 */
	const DEFAULT_MAX_INPUT_CHARS = 8000;

	/**
	 * Default maximum serialized field value size.
	 *
	 * @since 1.9.0
	 *
	 * @var int
	 */
	const DEFAULT_FIELD_CHARS = 1000;

	/**
	 * Rolling rate-cap window in seconds.
	 *
	 * @since 1.9.0
	 *
	 * @var int
	 */
	const RATE_CAP_WINDOW = HOUR_IN_SECONDS;

	/**
	 * AI catch-bypasser review context.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	const CONTEXT_REVIEW = 'review';

	/**
	 * AI false-positive rescue context.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	const CONTEXT_RESCUE = 'rescue';

	/**
	 * The GF Zero Spam AddOn instance.
	 *
	 * @since 1.9.0
	 *
	 * @var GF_Zero_Spam_AddOn
	 */
	private $addon;

	/**
	 * Request-local verdict cache.
	 *
	 * @since 1.9.0
	 *
	 * @var array<string, array|null>
	 */
	private static $verdict_cache = [];

	/**
	 * Request-local rescued entries waiting for an audit note.
	 *
	 * @since 1.9.0
	 *
	 * @var array<int, array{confidence: float}>
	 */
	private static $rescue_notes = [];

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
	 * Registers the runtime spam-review callback.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function init() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return;
		}

		add_filter( 'gform_entry_is_spam', [ $this, 'maybe_mark_spam' ], 20, 3 );
	}

	/**
	 * Reviews an entry that previous spam filters have not marked as spam.
	 *
	 * @since 1.9.0
	 *
	 * @param bool  $is_spam Whether the entry is currently spam.
	 * @param array $form    The form currently being processed.
	 * @param array $entry   The entry currently being processed.
	 *
	 * @return bool The spam verdict.
	 */
	public function maybe_mark_spam( $is_spam = false, $form = [], $entry = [] ) {
		if ( $is_spam ) {
			return $this->maybe_rescue( $is_spam, $form, $entry );
		}

		if ( ! $this->is_submission_eligible( $form, $entry ) ) {
			return $is_spam;
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return $is_spam;
		}

		$result = $this->classify( $form, $entry );

		return $this->apply_classification_result( $result, $is_spam, $form );
	}

	/**
	 * Attempts to rescue a token-flagged submission when AI is confident it is legitimate.
	 *
	 * @since 1.9.0
	 *
	 * @param bool  $is_spam Current spam verdict.
	 * @param array $form    The form currently being processed.
	 * @param array $entry   The entry currently being processed.
	 *
	 * @return bool Spam verdict.
	 */
	private function maybe_rescue( $is_spam, $form, $entry ) {
		$form_id = $this->get_form_id( $form );

		if ( ! $this->rescue_enabled_for_form( $form, $entry ) ) {
			return $is_spam;
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return $is_spam;
		}

		if ( ! $this->is_rescue_submission_eligible( $form, $entry ) ) {
			return $is_spam;
		}

		$reason_code = $this->get_zero_spam_token_rejection_reason_code( $form_id );

		// Rescue only runs for submissions blocked by Zero Spam's token check.
		// If another filter flags spam before the token check, the token check steps aside and no reason code is set.
		// A later spam filter cannot be distinguished because Gravity Forms records only the first spam source.
		// That residual limitation is accepted and mitigated by the high rescue confidence threshold.
		if ( '' === $reason_code ) {
			return $is_spam;
		}

		if ( $this->has_non_token_spam_source( $form_id ) ) {
			$this->log_rescue_decision(
				'not_rescued_non_token_source',
				$form_id,
				[
					'Reason code' => $reason_code,
				]
			);

			return $is_spam;
		}

		$threshold = $this->get_rescue_confidence_threshold( $form, $entry );
		$verdict   = $this->resolve_verdict( $form, $entry, $threshold, self::CONTEXT_RESCUE );

		if ( null === $verdict ) {
			$this->log_rescue_decision(
				'not_rescued_no_verdict',
				$form_id,
				[
					'Reason code' => $reason_code,
				]
			);

			return $is_spam;
		}

		$confidence      = (float) $verdict['confidence'];
		$proposed_rescue = empty( $verdict['is_spam'] ) && $confidence >= $threshold;

		/**
		 * Filters whether a token-flagged submission should be restored as legitimate.
		 *
		 * Returning true restores the entry as not-spam. Returning false keeps the
		 * token-flagged submission blocked.
		 *
		 * @since 1.9.0
		 *
		 * @param bool  $is_rescued Proposed decision (true to restore the entry as not-spam).
		 * @param array $verdict    The normalized AI verdict: is_spam (bool), confidence (float 0-1), reason (string).
		 * @param array $form       The form currently being processed.
		 * @param array $entry      The entry currently being processed. Note: pre-save entry; no ID yet.
		 */
		try {
			$is_rescued = (bool) apply_filters( 'gf_zero_spam_ai_rescue_result', $proposed_rescue, $verdict, $form, $entry );
		} catch ( BaseThrowable $e ) {
			$this->log( 'AI rescue result filter failed: ' . get_class( $e ) . ' ' . $e->getMessage() );

			return $is_spam;
		}

		if ( ! $is_rescued ) {
			$this->log_rescue_rejection( $form_id, $verdict, $confidence, $threshold );

			return $is_spam;
		}

		$this->log_rescue_decision(
			'rescued',
			$form_id,
			[
				'Confidence'  => $this->format_log_number( $confidence ),
				'Threshold'   => $this->format_log_number( $threshold ),
				'Reason code' => $reason_code,
			]
		);
		$this->schedule_rescue_note( $form, $verdict );
		$this->clear_zero_spam_spam_filter( $form_id );

		if ( method_exists( 'GF_Zero_Spam', 'clear_token_rejection_reason_code' ) ) {
			GF_Zero_Spam::clear_token_rejection_reason_code( $form_id );
		}

		return false;
	}

	/**
	 * Classifies an entry using AI without mutating the entry.
	 *
	 * @since 1.9.0
	 *
	 * @param array $form  The form currently being processed.
	 * @param array $entry The entry currently being processed.
	 *
	 * @return array|null Classification result, or null when review failed open.
	 */
	public function classify( $form, $entry ) {
		$threshold = $this->get_confidence_threshold( $form, $entry );
		$verdict   = $this->resolve_verdict( $form, $entry, $threshold, self::CONTEXT_REVIEW );

		return $this->get_classification_result( $verdict, $form, $entry, $threshold );
	}

	/**
	 * Resolves a normalized AI verdict without applying the spam-direction result hook.
	 *
	 * @since 1.9.0
	 *
	 * @param array      $form            The form currently being processed.
	 * @param array      $entry           The entry currently being processed.
	 * @param float|null $cache_threshold Optional threshold used to preserve the existing cache key.
	 * @param string     $context         Either "review" or "rescue".
	 *
	 * @return array|null Normalized verdict, or null when review failed open/closed.
	 */
	private function resolve_verdict( $form, $entry, $cache_threshold = null, $context = self::CONTEXT_REVIEW ) {
		try {
			return $this->resolve_verdict_without_exception_boundary( $form, $entry, $cache_threshold, $context );
		} catch ( BaseThrowable $e ) {
			$this->log( 'AI verdict resolution failed: ' . get_class( $e ) . ' ' . $e->getMessage() );

			return null;
		}
	}

	/**
	 * Resolves a normalized AI verdict without applying the spam-direction result hook.
	 *
	 * @since 1.9.0
	 *
	 * @param array      $form            The form currently being processed.
	 * @param array      $entry           The entry currently being processed.
	 * @param float|null $cache_threshold Optional threshold used to preserve the existing cache key.
	 * @param string     $context         Either "review" or "rescue".
	 *
	 * @return array|null Normalized verdict, or null when review failed open/closed.
	 */
	private function resolve_verdict_without_exception_boundary( $form, $entry, $cache_threshold = null, $context = self::CONTEXT_REVIEW ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return null;
		}

		$payload = $this->serialize_submission( $form, $entry );

		if ( '' === $payload ) {
			return null;
		}

		$system_instruction = $this->get_system_instruction( $form, $entry, $payload );
		$threshold          = is_numeric( $cache_threshold ) ? (float) $cache_threshold : $this->get_confidence_threshold( $form, $entry );
		$provider_id        = $this->get_provider_id( $context, $form, $entry );
		$cache_key          = $this->get_cache_key( $form, $payload, $system_instruction, $threshold, $provider_id );

		if ( array_key_exists( $cache_key, self::$verdict_cache ) ) {
			return self::$verdict_cache[ $cache_key ];
		}

		$rate_cap = $this->get_rate_cap_context( $form );

		if ( $rate_cap['over_limit'] ) {
			self::$verdict_cache[ $cache_key ] = null;

			return null;
		}

		$filtered_verdict = $this->get_filtered_verdict( $payload, $form, $entry );

		if ( is_wp_error( $filtered_verdict ) ) {
			self::$verdict_cache[ $cache_key ] = null;

			return null;
		}

		if ( null !== $filtered_verdict ) {
			$verdict = $this->normalize_verdict( $filtered_verdict );

			if ( null === $verdict ) {
				$this->log( 'AI returned an unparseable verdict.' );
				self::$verdict_cache[ $cache_key ] = null;

				return null;
			}

			$this->increment_rate_cap( $rate_cap );
			self::$verdict_cache[ $cache_key ] = $verdict;

			return $verdict;
		}

		$verdict = $this->get_ai_verdict( $payload, $system_instruction, $form, $entry, $provider_id );

		if ( null === $verdict ) {
			self::$verdict_cache[ $cache_key ] = null;

			return null;
		}

		$this->increment_rate_cap( $rate_cap );
		self::$verdict_cache[ $cache_key ] = $verdict;

		return $verdict;
	}

	/**
	 * Checks if the submission should be reviewed by AI.
	 *
	 * @since 1.9.0
	 *
	 * @param array $form  The form currently being processed.
	 * @param array $entry The entry currently being processed.
	 *
	 * @return bool Whether the submission is eligible.
	 */
	private function is_submission_eligible( $form, $entry ) {
		if ( ! $this->passes_pre_filter_guards( $form, $entry ) ) {
			return false;
		}

		/**
		 * Modifies whether AI should process this submission for the current context.
		 *
		 * @since 1.9.0
		 *
		 * @param bool   $enabled Whether AI processing is enabled.
		 * @param string $context Either "review" or "rescue".
		 * @param array  $form    The form currently being processed.
		 * @param array  $entry   The entry currently being processed.
		 */
		$ai_enabled = apply_filters( 'gf_zero_spam_ai_enabled', false, self::CONTEXT_REVIEW, $form, $entry );

		if ( false === $ai_enabled ) {
			return false;
		}

		return $this->passes_post_filter_guards( $form, $entry );
	}

	/**
	 * Checks if a token-flagged submission can be considered for AI rescue.
	 *
	 * @since 1.9.0
	 *
	 * @param array $form  The form currently being processed.
	 * @param array $entry The entry currently being processed.
	 *
	 * @return bool Whether the submission is eligible for rescue.
	 */
	private function is_rescue_submission_eligible( $form, $entry ) {
		return $this->passes_pre_filter_guards( $form, $entry ) && $this->passes_post_filter_guards( $form, $entry );
	}

	/**
	 * Checks guards that must run before the AI-review-enabled filter.
	 *
	 * @since 1.9.0
	 *
	 * @param array $form  The form currently being processed.
	 * @param array $entry The entry currently being processed.
	 *
	 * @return bool Whether pre-filter guards passed.
	 */
	private function passes_pre_filter_guards( $form, $entry ) {
		if ( GFCommon::current_user_can_any( 'gravityforms_edit_entries' ) ) {
			return false;
		}

		$form_id = $this->get_form_id( $form );

		if ( $form_id < 1 ) {
			return false;
		}

		$should_check_key_field = ! GFCommon::is_preview();

		/** This filter is documented in includes/class-gf-zero-spam.php. */
		$should_check_key_field = gf_apply_filters( 'gf_zero_spam_check_key_field', $form_id, $should_check_key_field, $form, $entry );

		if ( false === $should_check_key_field ) {
			return false;
		}

		return true;
	}

	/**
	 * Checks guards that run after AI-review-specific filters.
	 *
	 * @since 1.9.0
	 *
	 * @param array $form  The form currently being processed.
	 * @param array $entry The entry currently being processed.
	 *
	 * @return bool Whether post-filter guards passed.
	 */
	private function passes_post_filter_guards( $form, $entry ) {
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
	 * Checks whether false-positive rescue is enabled for a form.
	 *
	 * @since 1.9.0
	 *
	 * @param array $form  The form currently being processed.
	 * @param array $entry The entry currently being processed.
	 *
	 * @return bool Whether rescue is enabled.
	 */
	private function rescue_enabled_for_form( $form, $entry ) {
		$form_id = $this->get_form_id( $form );

		if ( $form_id < 1 ) {
			return false;
		}

		/**
		 * Modifies whether AI should process this submission for the current context.
		 *
		 * @since 1.9.0
		 *
		 * @param bool   $enabled Whether AI processing is enabled.
		 * @param string $context Either "review" or "rescue".
		 * @param array  $form    The form currently being processed.
		 * @param array  $entry   The entry currently being processed.
		 */
		return (bool) apply_filters( 'gf_zero_spam_ai_enabled', false, self::CONTEXT_RESCUE, $form, $entry );
	}

	/**
	 * Serializes submission content for AI review.
	 *
	 * @since 1.9.0
	 *
	 * @param array $form  The form currently being processed.
	 * @param array $entry The entry currently being processed.
	 *
	 * @return string Serialized submission payload.
	 */
	private function serialize_submission( $form, $entry ) {
		$max_chars = max( 0, (int) self::DEFAULT_MAX_INPUT_CHARS );

		if ( $max_chars < 1 ) {
			return '';
		}

		$lines = [
			'Gravity Forms submission',
			'Form ID: ' . $this->get_form_id( $form ),
			'Form title: ' . $this->normalize_text( rgar( $form, 'title', '' ) ),
		];

		$source = $this->get_source_path( $entry );

		if ( '' !== $source ) {
			$lines[] = 'Source path: ' . $source;
		}

		$field_lines = $this->get_serialized_field_lines( $form, $entry );

		if ( empty( $field_lines ) ) {
			return '';
		}

		$lines[] = 'Fields:';
		$lines   = array_merge( $lines, $field_lines );
		$payload = implode( "\n", $lines );

		if ( mb_strlen( $payload, 'UTF-8' ) > $max_chars ) {
			$payload = mb_substr( $payload, 0, $max_chars, 'UTF-8' );
		}

		return trim( $payload );
	}

	/**
	 * Gets serialized field lines.
	 *
	 * @since 1.9.0
	 *
	 * @param array $form  The form currently being processed.
	 * @param array $entry The entry currently being processed.
	 *
	 * @return string[] Serialized field lines.
	 */
	private function get_serialized_field_lines( $form, $entry ) {
		$fields = rgar( $form, 'fields' );

		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return [];
		}

		$lines            = [];
		$field_type_rules = $this->get_field_type_rules( $form, $entry );

		foreach ( $fields as $field ) {
			if ( $this->should_skip_field( $field, $form, $entry, $field_type_rules ) ) {
				continue;
			}

			$type  = $this->get_field_property( $field, 'type', '' );
			$value = $this->get_field_value( $field, $entry );

			if ( '' === $value ) {
				continue;
			}

			if ( ! $this->is_included_field_type( $type, $value, $field_type_rules ) ) {
				continue;
			}

			$value = $this->redact_email_value( $value );

			$label = $this->normalize_text( $this->get_field_property( $field, 'label', '' ) );

			if ( '' === $label ) {
				$label = 'Field ' . $this->get_field_property( $field, 'id', '' );
			}

			$lines[] = '- ' . $label . ' [' . $type . ']: ' . $this->limit_text( $value, self::DEFAULT_FIELD_CHARS );
		}

		return $lines;
	}

	/**
	 * Checks whether a field should be skipped before value extraction.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $field            The Gravity Forms field object or array.
	 * @param array $form             The form currently being processed.
	 * @param array $entry            The entry currently being processed.
	 * @param array $field_type_rules Included and excluded field type rules.
	 *
	 * @return bool Whether the field should be skipped.
	 */
	private function should_skip_field( $field, $form, $entry, $field_type_rules ) {
		$type       = (string) $this->get_field_property( $field, 'type', '' );
		$field_id   = $this->normalize_field_id( $this->get_field_property( $field, 'id', '' ) );
		$visibility = (string) $this->get_field_property( $field, 'visibility', '' );
		$input_type = (string) $this->get_field_property( $field, 'inputType', '' );

		if ( $this->get_field_property( $field, 'adminOnly', false ) ) {
			return true;
		}

		if ( $this->get_field_property( $field, 'displayOnly', false ) ) {
			return true;
		}

		if ( in_array( $visibility, [ 'hidden', 'administrative' ], true ) ) {
			return true;
		}

		if ( 'hidden' === $input_type ) {
			return true;
		}

		if ( in_array( $field_id, $this->get_excluded_field_ids( $form ), true ) ) {
			return true;
		}

		$excluded_types = $field_type_rules['excluded'];

		return in_array( $type, $excluded_types, true );
	}

	/**
	 * Gets normalized field IDs excluded from AI review for this form.
	 *
	 * @since 1.9.0
	 *
	 * @param array $form The form currently being processed.
	 *
	 * @return string[] Excluded field IDs.
	 */
	private function get_excluded_field_ids( $form ) {
		$excluded_ids = (array) rgar( $form, 'gfZeroSpamAIExcludedFields' );
		$excluded_ids = array_map( [ $this, 'normalize_field_id' ], $excluded_ids );

		return array_values(
			array_filter(
				$excluded_ids,
				static function ( $value ) {
					return '' !== $value;
				}
			)
		);
	}

	/**
	 * Normalizes a field or input ID to its parent field ID.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $field_id Field or input ID.
	 *
	 * @return string Parent field ID.
	 */
	private function normalize_field_id( $field_id ) {
		$field_id = trim( (string) $field_id );

		if ( '' === $field_id ) {
			return '';
		}

		$parts = explode( '.', $field_id );

		return (string) $parts[0];
	}

	/**
	 * Checks whether a field type should be included.
	 *
	 * @since 1.9.0
	 *
	 * @param string $type             The field type.
	 * @param string $value            The normalized field value.
	 * @param array  $field_type_rules Included and excluded field type rules.
	 *
	 * @return bool Whether the field type should be included.
	 */
	private function is_included_field_type( $type, $value, $field_type_rules ) {
		$included_types = $field_type_rules['included'];

		if ( in_array( $type, $included_types, true ) ) {
			return true;
		}

		return is_scalar( $value ) && '' !== $value;
	}

	/**
	 * Gets AI payload field type rules.
	 *
	 * @since 1.9.0
	 *
	 * @param array $form  The form currently being processed.
	 * @param array $entry The entry currently being processed.
	 *
	 * @return array{included: string[], excluded: string[]} Field type rules.
	 */
	private function get_field_type_rules( $form, $entry ) {
		$field_types = [
			'included' => [
				'text',
				'textarea',
				'website',
				'url',
				'select',
				'multiselect',
				'radio',
				'checkbox',
				'list',
				'name',
				'address',
				'phone',
				'post_title',
				'post_content',
				'post_excerpt',
			],
			'excluded' => [
				'hidden',
				'password',
				'creditcard',
				'product',
				'singleproduct',
				'hiddenproduct',
				'calculation',
				'quantity',
				'option',
				'shipping',
				'singleshipping',
				'total',
				'coupon',
				'donation',
				'fileupload',
				'consent',
				'captcha',
				'honeypot',
				'html',
				'section',
				'page',
				'signature',
			],
		];

		/**
		 * Modifies field types included in or excluded from AI payloads.
		 *
		 * @since 1.9.0
		 *
		 * @param array $field_types Field type rules with included and excluded string arrays.
		 * @param array $form        The form currently being processed.
		 * @param array $entry       The entry currently being processed.
		 */
		$filtered_field_types = apply_filters( 'gf_zero_spam_ai_field_types', $field_types, $form, $entry );

		if ( ! is_array( $filtered_field_types ) ) {
			$filtered_field_types = $field_types;
		}

		return [
			'included' => $this->normalize_type_list( $filtered_field_types['included'] ?? $field_types['included'] ),
			'excluded' => $this->normalize_type_list( $filtered_field_types['excluded'] ?? $field_types['excluded'] ),
		];
	}

	/**
	 * Normalizes a field type list to strings.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $type_list Field type list.
	 *
	 * @return string[] Normalized type list.
	 */
	private function normalize_type_list( $type_list ) {
		return is_array( $type_list ) ? array_map( 'strval', $type_list ) : [];
	}

	/**
	 * Gets a field property from a GF field object or array.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed  $field    The Gravity Forms field object or array.
	 * @param string $property The property name.
	 * @param mixed  $fallback The fallback value.
	 *
	 * @return mixed The field property value.
	 */
	private function get_field_property( $field, $property, $fallback = null ) {
		if ( is_array( $field ) ) {
			return rgar( $field, $property, $fallback );
		}

		if ( is_object( $field ) && isset( $field->{$property} ) ) {
			return $field->{$property};
		}

		return $fallback;
	}

	/**
	 * Gets a field value from the entry.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $field The Gravity Forms field object or array.
	 * @param array $entry The entry currently being processed.
	 *
	 * @return string The normalized field value.
	 */
	private function get_field_value( $field, $entry ) {
		if ( is_object( $field ) && method_exists( $field, 'get_value_export' ) ) {
			$value = $field->get_value_export( $entry, '', true, false );
		} else {
			$value = rgar( $entry, (string) $this->get_field_property( $field, 'id', '' ), '' );
		}

		$value = $this->flatten_value( $value );

		return $this->normalize_text( $value );
	}

	/**
	 * Flattens a field value into text.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $value The raw field value.
	 *
	 * @return string Flattened field value.
	 */
	private function flatten_value( $value ) {
		if ( is_scalar( $value ) || null === $value ) {
			return (string) $value;
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		$parts = [];

		foreach ( $value as $item ) {
			$item = $this->flatten_value( $item );

			if ( '' !== $item ) {
				$parts[] = $item;
			}
		}

		return implode( ', ', $parts );
	}

	/**
	 * Redacts email local parts.
	 *
	 * @since 1.9.0
	 *
	 * @param string $value The field value.
	 *
	 * @return string Redacted field value.
	 */
	private function redact_email_value( $value ) {
		return preg_replace_callback(
			'/[A-Z0-9._%+\-]+@([A-Z0-9.\-]+\.[A-Z]{2,})/i',
			static function ( $matches ) {
				return '*@' . strtolower( $matches[1] );
			},
			$value
		);
	}

	/**
	 * Normalizes text for AI payloads.
	 *
	 * @since 1.9.0
	 *
	 * @param string $value The raw text.
	 *
	 * @return string Normalized text.
	 */
	private function normalize_text( $value ) {
		$value = html_entity_decode( (string) $value, ENT_QUOTES, get_bloginfo( 'charset' ) );

		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/\s+/', ' ', $value );

		return trim( (string) $value );
	}

	/**
	 * Limits text to a maximum character length.
	 *
	 * @since 1.9.0
	 *
	 * @param string $value     The text to limit.
	 * @param int    $max_chars Maximum characters.
	 *
	 * @return string Limited text.
	 */
	private function limit_text( $value, $max_chars ) {
		if ( mb_strlen( $value, 'UTF-8' ) <= $max_chars ) {
			return $value;
		}

		return mb_substr( $value, 0, $max_chars, 'UTF-8' );
	}

	/**
	 * Gets the source host and path without query parameters.
	 *
	 * @since 1.9.0
	 *
	 * @param array $entry The entry currently being processed.
	 *
	 * @return string Source host and path.
	 */
	private function get_source_path( $entry ) {
		$source_url = rgar( $entry, 'source_url', '' );

		if ( '' === $source_url ) {
			return '';
		}

		$host = wp_parse_url( $source_url, PHP_URL_HOST );
		$path = wp_parse_url( $source_url, PHP_URL_PATH );

		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}

		return $host . ( is_string( $path ) ? $path : '' );
	}

	/**
	 * Gets the AI system instruction.
	 *
	 * @since 1.9.0
	 *
	 * @param array  $form    The form currently being processed.
	 * @param array  $entry   The entry currently being processed.
	 * @param string $payload The serialized payload.
	 *
	 * @return string System instruction.
	 */
	private function get_system_instruction( $form, $entry, $payload ) {
		$prompt = rgar( $form, 'gfZeroSpamAIPrompt' );

		if ( ! is_string( $prompt ) || '' === trim( $prompt ) ) {
			$prompt = $this->addon->get_plugin_setting( 'gf_zero_spam_ai_default_prompt' );
		}

		if ( ! is_string( $prompt ) || '' === trim( $prompt ) ) {
			$prompt = GF_Zero_Spam_AI_Review_Settings::get_default_prompt();
		}

		/**
		 * Modifies the AI prompt used for spam review and false-positive rescue.
		 *
		 * @since 1.9.0
		 *
		 * @param string $prompt  The AI prompt.
		 * @param array  $form    The form currently being processed.
		 * @param array  $entry   The entry currently being processed.
		 * @param string $payload The serialized payload.
		 */
		$prompt = apply_filters( 'gf_zero_spam_ai_prompt', $prompt, $form, $entry, $payload );

		if ( ! is_string( $prompt ) || '' === trim( $prompt ) ) {
			return GF_Zero_Spam_AI_Review_Settings::get_default_prompt();
		}

		return trim( $prompt );
	}

	/**
	 * Gets the confidence threshold.
	 *
	 * @since 1.9.0
	 *
	 * @param array $form  The form currently being processed.
	 * @param array $entry The entry currently being processed.
	 *
	 * @return float Confidence threshold.
	 */
	private function get_confidence_threshold( $form, $entry ) {
		return $this->get_threshold_setting( 'gf_zero_spam_ai_confidence_threshold', self::DEFAULT_CONFIDENCE_THRESHOLD, self::CONTEXT_REVIEW, $form, $entry );
	}

	/**
	 * Gets the confidence threshold for AI false-positive rescue.
	 *
	 * @since 1.9.0
	 *
	 * @param array $form  The form currently being processed.
	 * @param array $entry The entry currently being processed.
	 *
	 * @return float Rescue confidence threshold.
	 */
	private function get_rescue_confidence_threshold( $form, $entry ) {
		return $this->get_threshold_setting( 'gf_zero_spam_ai_rescue_confidence_threshold', self::DEFAULT_RESCUE_CONFIDENCE_THRESHOLD, self::CONTEXT_RESCUE, $form, $entry );
	}

	/**
	 * Gets a confidence threshold setting with shared validation rules.
	 *
	 * @since 1.9.0
	 *
	 * @param string $option_key The plugin setting key.
	 * @param float  $fallback   Fallback threshold.
	 * @param string $context    Either "review" or "rescue".
	 * @param array  $form       The form currently being processed.
	 * @param array  $entry      The entry currently being processed.
	 *
	 * @return float Confidence threshold.
	 */
	private function get_threshold_setting( $option_key, $fallback, $context, $form, $entry ) {
		$threshold = $this->addon->get_plugin_setting( $option_key );
		$threshold = $this->clamp_threshold( $threshold, $fallback );

		/**
		 * Modifies the AI confidence threshold for the current context.
		 *
		 * Return any value from 0.5 to 1; use the $context and $form arguments for
		 * context-specific and per-form overrides.
		 *
		 * @since 1.9.0
		 *
		 * @param float  $threshold Confidence threshold.
		 * @param string $context   Either "review" or "rescue".
		 * @param array  $form      The form currently being processed.
		 * @param array  $entry     The entry currently being processed.
		 */
		$threshold = apply_filters( 'gf_zero_spam_ai_confidence_threshold', $threshold, $context, $form, $entry );

		return $this->clamp_threshold( $threshold, $fallback );
	}

	/**
	 * Clamps a confidence threshold value to the supported range.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $threshold Threshold candidate.
	 * @param float $fallback  Fallback threshold.
	 *
	 * @return float Confidence threshold.
	 */
	private function clamp_threshold( $threshold, $fallback ) {
		if ( ! is_numeric( $threshold ) ) {
			return $fallback;
		}

		$threshold = (float) $threshold;

		if ( $threshold < 0.5 || $threshold > 1 ) {
			return $fallback;
		}

		return $threshold;
	}

	/**
	 * Gets the AI provider ID for the current request.
	 *
	 * @since 1.9.0
	 *
	 * @param string $context Either "review" or "rescue".
	 * @param array  $form    The form currently being processed.
	 * @param array  $entry   The entry currently being processed.
	 *
	 * @return string Provider ID, or empty string for automatic selection.
	 */
	private function get_provider_id( $context, $form, $entry ) {
		$provider_id = $this->addon->get_plugin_setting( 'gf_zero_spam_ai_provider' );
		$provider_id = is_string( $provider_id ) ? trim( $provider_id ) : '';

		/**
		 * Modifies the AI provider used for the current classification request.
		 *
		 * Return an empty string to use the WordPress AI Client default provider.
		 *
		 * @since 1.9.0
		 *
		 * @param string $provider_id Provider ID, or empty string for automatic selection.
		 * @param string $context     Either "review" or "rescue".
		 * @param array  $form        The form currently being processed.
		 * @param array  $entry       The entry currently being processed.
		 */
		$provider_id = apply_filters( 'gf_zero_spam_ai_provider', $provider_id, $context, $form, $entry );

		return is_string( $provider_id ) ? trim( $provider_id ) : '';
	}

	/**
	 * Gets a request-local cache key.
	 *
	 * @since 1.9.0
	 *
	 * @param array  $form               The form currently being processed.
	 * @param string $payload            The serialized payload.
	 * @param string $system_instruction The AI system instruction.
	 * @param float  $threshold          The confidence threshold.
	 * @param string $provider_id        Provider ID, or empty string for automatic selection.
	 *
	 * @return string Cache key.
	 */
	private function get_cache_key( $form, $payload, $system_instruction, $threshold, $provider_id = '' ) {
		return $this->get_form_id( $form ) . ':' . md5( $payload . '|' . $system_instruction . '|' . $threshold . '|' . $provider_id );
	}

	/**
	 * Gets the rate-cap state for a form.
	 *
	 * @since 1.9.0
	 *
	 * @param array $form The form currently being processed.
	 *
	 * @return array{enabled: bool, over_limit: bool, key: string, timestamps: int[]}
	 */
	private function get_rate_cap_context( $form ) {
		$limit = (int) rgar( $form, 'gfZeroSpamAIMaxCallsPerHour', '0' );

		if ( $limit < 1 ) {
			return [
				'enabled'    => false,
				'over_limit' => false,
				'key'        => '',
				'timestamps' => [],
			];
		}

		$form_id    = $this->get_form_id( $form );
		$key        = 'gf_zs_ai_rate_' . $form_id;
		$now        = time();
		$timestamps = get_transient( $key );

		// This is a best-effort soft limit; concurrent requests may slightly exceed it.
		// It always fails open.
		if ( ! is_array( $timestamps ) ) {
			$timestamps = [];
		}

		$timestamps = array_values(
			array_filter(
				array_map( 'intval', $timestamps ),
				static function ( $timestamp ) use ( $now ) {
					return $timestamp > ( $now - self::RATE_CAP_WINDOW );
				}
			)
		);

		return [
			'enabled'    => true,
			'over_limit' => count( $timestamps ) >= $limit,
			'key'        => $key,
			'timestamps' => $timestamps,
		];
	}

	/**
	 * Increments a form rate cap.
	 *
	 * @since 1.9.0
	 *
	 * @param array $rate_cap Rate-cap context.
	 *
	 * @return void
	 */
	private function increment_rate_cap( $rate_cap ) {
		if ( empty( $rate_cap['enabled'] ) || empty( $rate_cap['key'] ) ) {
			return;
		}

		$timestamps   = isset( $rate_cap['timestamps'] ) && is_array( $rate_cap['timestamps'] ) ? $rate_cap['timestamps'] : [];
		$timestamps[] = time();

		set_transient( $rate_cap['key'], $timestamps, self::RATE_CAP_WINDOW );
	}

	/**
	 * Applies the short-circuit verdict filter.
	 *
	 * @since 1.9.0
	 *
	 * @param string $payload The serialized payload.
	 * @param array  $form    The form currently being processed.
	 * @param array  $entry   The entry currently being processed.
	 *
	 * @return array|WP_Error|null Filtered verdict.
	 */
	private function get_filtered_verdict( $payload, $form, $entry ) {
		/**
		 * Short-circuits AI Spam Review with a deterministic verdict.
		 *
		 * Return null to use the WordPress AI Client, a WP_Error to fail open, or
		 * an array containing is_spam, confidence, and reason to skip the AI call.
		 *
		 * @since 1.9.0
		 *
		 * @param array|WP_Error|null $verdict The short-circuit verdict.
		 * @param string              $payload The serialized payload.
		 * @param array               $form    The form currently being processed.
		 * @param array               $entry   The entry currently being processed.
		 */
		return apply_filters( 'gf_zero_spam_ai_verdict', null, $payload, $form, $entry );
	}

	/**
	 * Gets a verdict from the WordPress AI Client.
	 *
	 * @since 1.9.0
	 *
	 * @param string $payload            The serialized payload.
	 * @param string $system_instruction The AI system instruction.
	 * @param array  $form               The form currently being processed.
	 * @param array  $entry              The entry currently being processed.
	 * @param string $provider_id        Provider ID, or empty string for automatic selection.
	 *
	 * @return array|null Normalized verdict, or null on failure.
	 */
	private function get_ai_verdict( $payload, $system_instruction, $form, $entry, $provider_id = '' ) {
		$prompt = wp_ai_client_prompt( $payload )
			->using_system_instruction( $system_instruction )
			->using_max_tokens( self::DEFAULT_MAX_TOKENS )
			->using_request_options(
				RequestOptions::fromArray(
					[
						RequestOptions::KEY_TIMEOUT => $this->get_timeout( $form, $entry, $payload ),
					]
				)
			)
			->as_json_response( self::get_json_schema() );

		if ( '' !== $provider_id ) {
			$prompt = $prompt->using_provider( $provider_id );
		}

		$response = $prompt->generate_text();

		if ( is_wp_error( $response ) ) {
			$this->log( 'AI request failed: ' . $response->get_error_code() . ' ' . $response->get_error_message() );

			return null;
		}

		if ( ! is_string( $response ) ) {
			$this->log( 'AI response was not valid JSON.' );

			return null;
		}

		$decoded = json_decode( $response, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$this->log( 'AI response was not valid JSON.' );

			return null;
		}

		$verdict = $this->normalize_verdict( $decoded );

		if ( null === $verdict ) {
			$this->log( 'AI returned an unparseable verdict.' );

			return null;
		}

		return $verdict;
	}

	/**
	 * Gets the JSON schema for AI verdicts.
	 *
	 * @since 1.9.0
	 *
	 * @return array JSON schema.
	 */
	public static function get_json_schema() {
		// Anthropic rejects numeric minimum/maximum schema keywords; normalize_verdict() enforces the range.
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'is_spam'    => [
					'type' => 'boolean',
				],
				'confidence' => [
					'type'        => 'number',
					'description' => 'Confidence between 0 and 1.',
				],
				'reason'     => [
					'type' => 'string',
				],
			],
			'required'             => [
				'is_spam',
				'confidence',
				'reason',
			],
		];
	}

	/**
	 * Gets the AI request timeout.
	 *
	 * @since 1.9.0
	 *
	 * @param array  $form    The form currently being processed.
	 * @param array  $entry   The entry currently being processed.
	 * @param string $payload The serialized payload.
	 *
	 * @return float Timeout in seconds.
	 */
	private function get_timeout( $form, $entry, $payload ) {
		/**
		 * Modifies the per-call AI request timeout.
		 *
		 * @since 1.9.0
		 *
		 * @param float  $timeout Timeout in seconds.
		 * @param array  $form    The form currently being processed.
		 * @param array  $entry   The entry currently being processed.
		 * @param string $payload The serialized payload.
		 */
		$timeout = apply_filters( 'gf_zero_spam_ai_timeout', self::DEFAULT_TIMEOUT, $form, $entry, $payload );

		if ( ! is_numeric( $timeout ) || (float) $timeout < 0 ) {
			return self::DEFAULT_TIMEOUT;
		}

		return (float) $timeout;
	}

	/**
	 * Normalizes and validates a verdict.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $verdict Raw verdict.
	 *
	 * @return array|null Normalized verdict, or null when invalid.
	 */
	private function normalize_verdict( $verdict ) {
		if ( ! is_array( $verdict ) ) {
			return null;
		}

		if ( ! array_key_exists( 'is_spam', $verdict ) || ! is_bool( $verdict['is_spam'] ) ) {
			return null;
		}

		if ( ! array_key_exists( 'confidence', $verdict ) || ! is_numeric( $verdict['confidence'] ) ) {
			return null;
		}

		if ( ! array_key_exists( 'reason', $verdict ) || ! is_string( $verdict['reason'] ) ) {
			return null;
		}

		$confidence = (float) $verdict['confidence'];

		if ( $confidence < 0 || $confidence > 1 ) {
			return null;
		}

		$reason = sanitize_text_field( $verdict['reason'] );

		if ( '' === $reason && $verdict['is_spam'] ) {
			$reason = __( 'AI classified this submission as spam.', 'gravity-forms-zero-spam' );
		}

		$reason = $this->limit_text( $reason, 500 );

		return [
			'is_spam'    => $verdict['is_spam'],
			'confidence' => $confidence,
			'reason'     => $reason,
		];
	}

	/**
	 * Gets the final classification result for a normalized verdict.
	 *
	 * @since 1.9.0
	 *
	 * @param array|null $verdict   Normalized verdict.
	 * @param array      $form      The form currently being processed.
	 * @param array      $entry     The entry currently being processed.
	 * @param float      $threshold Confidence threshold.
	 *
	 * @return array|null Classification result, or null when no verdict is available.
	 */
	private function get_classification_result( $verdict, $form, $entry, $threshold ) {
		if ( null === $verdict ) {
			return null;
		}

		$proposed_is_spam = ! empty( $verdict['is_spam'] ) && (float) $verdict['confidence'] >= $threshold;

		/**
		 * Filters the final AI spam decision after the model has returned a verdict.
		 *
		 * Returning true marks the entry as spam. Returning false leaves the entry
		 * unchanged.
		 *
		 * @since 1.9.0
		 *
		 * @param bool  $is_spam Proposed decision (true to mark spam).
		 * @param array $verdict The normalized AI verdict: is_spam (bool), confidence (float 0-1), reason (string).
		 * @param array $form    The form currently being processed.
		 * @param array $entry   The entry currently being processed.
		 */
		try {
			$final_is_spam = (bool) apply_filters( 'gf_zero_spam_ai_result', $proposed_is_spam, $verdict, $form, $entry );
		} catch ( BaseThrowable $e ) {
			$this->log( 'AI result filter failed: ' . get_class( $e ) . ' ' . $e->getMessage() );
			$final_is_spam = false;
		}

		return [
			'is_spam'   => $final_is_spam,
			'verdict'   => $verdict,
			'threshold' => $threshold,
		];
	}

	/**
	 * Applies a classification result to the synchronous spam decision.
	 *
	 * @since 1.9.0
	 *
	 * @param array|null $result  Classification result.
	 * @param bool       $is_spam Current spam verdict.
	 * @param array      $form    The form currently being processed.
	 *
	 * @return bool Spam verdict.
	 */
	private function apply_classification_result( $result, $is_spam, $form ) {
		if ( empty( $result['is_spam'] ) ) {
			return $is_spam;
		}

		if ( empty( $result['verdict'] ) || ! is_array( $result['verdict'] ) ) {
			return $is_spam;
		}

		if ( ! method_exists( 'GFCommon', 'set_spam_filter' ) ) {
			return $is_spam;
		}

		GFCommon::set_spam_filter( $this->get_form_id( $form ), self::SPAM_FILTER_NAME, $result['verdict']['reason'] );

		return true;
	}

	/**
	 * Gets the request-local Zero Spam token rejection reason code.
	 *
	 * @since 1.9.0
	 *
	 * @param int $form_id The form ID.
	 *
	 * @return string Token rejection reason code.
	 */
	private function get_zero_spam_token_rejection_reason_code( $form_id ) {
		if ( ! method_exists( 'GF_Zero_Spam', 'get_token_rejection_reason_code' ) ) {
			return '';
		}

		return (string) GF_Zero_Spam::get_token_rejection_reason_code( $form_id );
	}

	/**
	 * Checks whether another Zero Spam spam source flagged this request.
	 *
	 * @since 1.9.0
	 *
	 * @param int $form_id The form ID.
	 *
	 * @return bool Whether another Zero Spam spam source flagged this request.
	 */
	private function has_non_token_spam_source( $form_id ) {
		if ( ! method_exists( 'GF_Zero_Spam', 'has_non_token_spam_source' ) ) {
			return false;
		}

		return (bool) GF_Zero_Spam::has_non_token_spam_source( $form_id );
	}

	/**
	 * Logs a non-rescue decision with the most specific available reason.
	 *
	 * @since 1.9.0
	 *
	 * @param int   $form_id    The form ID.
	 * @param array $verdict    The normalized AI verdict.
	 * @param float $confidence The verdict confidence.
	 * @param float $threshold  The rescue threshold.
	 *
	 * @return void
	 */
	private function log_rescue_rejection( $form_id, $verdict, $confidence, $threshold ) {
		if ( ! empty( $verdict['is_spam'] ) ) {
			$this->log_rescue_decision(
				'not_rescued_ai_spam',
				$form_id,
				[
					'Confidence' => $this->format_log_number( $confidence ),
					'Threshold'  => $this->format_log_number( $threshold ),
				]
			);

			return;
		}

		if ( $confidence < $threshold ) {
			$this->log_rescue_decision(
				'not_rescued_low_confidence',
				$form_id,
				[
					'Confidence' => $this->format_log_number( $confidence ),
					'Threshold'  => $this->format_log_number( $threshold ),
				]
			);

			return;
		}

		$this->log_rescue_decision(
			'not_rescued_filter_veto',
			$form_id,
			[
				'Confidence' => $this->format_log_number( $confidence ),
				'Threshold'  => $this->format_log_number( $threshold ),
			]
		);
	}

	/**
	 * Logs a formatted AI rescue decision.
	 *
	 * @since 1.9.0
	 *
	 * @param string $code    Decision code.
	 * @param int    $form_id The form ID.
	 * @param array  $parts   Additional label/value parts.
	 *
	 * @return void
	 */
	private function log_rescue_decision( $code, $form_id, array $parts = [] ) {
		$message = 'AI rescue decision ' . $code . '. Form #' . $form_id . '.';

		foreach ( $parts as $label => $value ) {
			$message .= ' ' . $label . ': ' . $value . '.';
		}

		$this->log_debug( $message );
	}

	/**
	 * Schedules a post-create audit note for a rescued entry.
	 *
	 * @since 1.9.0
	 *
	 * @param array $form    The form currently being processed.
	 * @param array $verdict The normalized AI verdict.
	 *
	 * @return void
	 */
	private function schedule_rescue_note( $form, $verdict ) {
		$form_id = $this->get_form_id( $form );

		if ( $form_id < 1 ) {
			return;
		}

		self::$rescue_notes[ $form_id ] = [
			'confidence' => (float) $verdict['confidence'],
		];

		add_action( 'gform_entry_created', [ $this, 'add_rescue_note' ], 20, 2 );
	}

	/**
	 * Adds an audit note to a rescued entry.
	 *
	 * @since 1.9.0
	 *
	 * @param array $entry The created entry.
	 * @param array $form  The submitted form.
	 *
	 * @return void
	 */
	public function add_rescue_note( $entry, $form ) {
		$form_id = $this->get_form_id( $form );

		if ( empty( self::$rescue_notes[ $form_id ] ) ) {
			return;
		}

		$context = self::$rescue_notes[ $form_id ];
		unset( self::$rescue_notes[ $form_id ] );

		if ( empty( self::$rescue_notes ) ) {
			remove_action( 'gform_entry_created', [ $this, 'add_rescue_note' ], 20 );
		}

		$entry_id = (int) rgar( $entry, 'id' );

		if ( $entry_id < 1 ) {
			return;
		}

		if ( 'spam' === rgar( $entry, 'status' ) ) {
			return;
		}

		$note = strtr(
			__( 'Zero Spam: AI recovered this submission as legitimate (confidence [confidence]).', 'gravity-forms-zero-spam' ),
			[
				'[confidence]' => $this->format_log_number( (float) $context['confidence'] ),
			]
		);

		GFAPI::add_note( $entry_id, 0, self::RESCUE_FILTER_NAME, sanitize_text_field( $note ) );
	}

	/**
	 * Clears the current Zero Spam spam-filter reason after a successful rescue.
	 *
	 * @since 1.9.0
	 *
	 * @param int $form_id The form ID.
	 *
	 * @return void
	 */
	private function clear_zero_spam_spam_filter( $form_id ) {
		if ( ! class_exists( 'GFFormDisplay' ) ) {
			return;
		}

		$spam_filter = rgars( GFFormDisplay::$submission, (int) $form_id . '/spam_filter' );

		if ( ! is_array( $spam_filter ) || 'Zero Spam' !== rgar( $spam_filter, 'filter' ) ) {
			return;
		}

		unset( GFFormDisplay::$submission[ (int) $form_id ]['spam_filter'] );
	}

	/**
	 * Logs an AI review error when Gravity Forms logging is available.
	 *
	 * @since 1.9.0
	 *
	 * @param string $message The message to log.
	 *
	 * @return void
	 */
	private function log( $message ) {
		if ( method_exists( 'GFCommon', 'log_error' ) ) {
			GFCommon::log_error( 'GF Zero Spam AI Review: ' . $message );
		}
	}

	/**
	 * Logs an AI review debug message when the add-on logger is available.
	 *
	 * @since 1.9.0
	 *
	 * @param string $message The message to log.
	 *
	 * @return void
	 */
	private function log_debug( $message ) {
		if ( method_exists( $this->addon, 'log_debug' ) ) {
			$this->addon->log_debug( 'GF Zero Spam AI Review: ' . $message );
		}
	}

	/**
	 * Formats a float for plain-ASCII log lines and notes.
	 *
	 * @since 1.9.0
	 *
	 * @param float $number The number to format.
	 *
	 * @return string Formatted number.
	 */
	private function format_log_number( $number ) {
		return number_format( (float) $number, 2, '.', '' );
	}

	/**
	 * Gets a form ID.
	 *
	 * @since 1.9.0
	 *
	 * @param array $form The form currently being processed.
	 *
	 * @return int Form ID.
	 */
	private function get_form_id( $form ) {
		return (int) rgar( $form, 'id' );
	}
}
