<?php
/**
 * Plugin Name: Zero Spam E2E Helpers
 * Description: REST endpoints used by Playwright tests to read captured mail, reset Zero Spam state between tests, toggle global/form settings, and mint tokens for negative-path tests.
 * Version: 1.0.0
 *
 * All routes require the same X-E2E-TEST-TOKEN header used by @gravitykit/e2e-fixtures
 * so we don't introduce a second auth scheme.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ZS_E2E_NAMESPACE              = 'zs-e2e/v1';
const ZS_E2E_TEST_TOKEN             = 'gravitykit-e2e-test';
const ZS_E2E_AI_REVIEW_OPTION       = 'zs_e2e_ai_review';
const ZS_E2E_TOKEN_REJECTION_OPTION = 'gf_zero_spam_e2e_last_rejection';

/**
 * The token endpoint rate limit (default 30/IP/min) is fine in production but
 * trips during a serial E2E run where the suite mints many tokens from the
 * same loopback IP. Bump it in the test environment so suite size doesn't
 * silently start failing tests once we cross the threshold.
 */
add_filter(
	'gf_zero_spam_rate_limit',
	static function () {
		return 1000;
	}
);

/**
 * Keep native Gravity Forms notifications synchronous so mailcatch can inspect wp_mail calls.
 */
add_filter( 'gform_is_asynchronous_notifications_enabled', '__return_false' );

add_action(
	'gf_zero_spam_token_rejected',
	static function ( $reason_code, $form, $entry ) {
		$form_id = (int) rgar( $form, 'id' );
		$record  = [
			'reason_code' => (string) $reason_code,
			'form_id'     => $form_id,
			'captured_at' => time(),
		];
		$state   = (array) get_option( ZS_E2E_TOKEN_REJECTION_OPTION, [] );
		$by_form = isset( $state['by_form'] ) && is_array( $state['by_form'] ) ? $state['by_form'] : [];

		$by_form[ (string) $form_id ] = $record;
		$state['latest']              = $record;
		$state['by_form']             = $by_form;

		update_option( ZS_E2E_TOKEN_REJECTION_OPTION, $state, false );
	},
	10,
	3
);

add_filter(
	'gf_zero_spam_ai_prompt',
	static function ( $prompt, $form, $entry, $payload ) {
		$state                            = (array) get_option( ZS_E2E_AI_REVIEW_OPTION, [] );
		$state['last_system_instruction'] = is_string( $prompt ) ? $prompt : '';
		$state['last_system_form_id']     = (int) rgar( $form, 'id' );

		update_option( ZS_E2E_AI_REVIEW_OPTION, $state, false );

		return $prompt;
	},
	10,
	4
);

add_filter(
	'gf_zero_spam_ai_verdict',
	static function ( $verdict, $payload, $form, $entry ) {
		$state = (array) get_option( ZS_E2E_AI_REVIEW_OPTION, [] );
		$mode  = isset( $state['mode'] ) ? (string) $state['mode'] : 'none';

		if ( 'none' === $mode ) {
			return $verdict;
		}

		$state['calls']        = (int) ( $state['calls'] ?? 0 ) + 1;
		$state['last_payload'] = (string) $payload;
		$state['last_form_id'] = (int) rgar( $form, 'id' );

		update_option( ZS_E2E_AI_REVIEW_OPTION, $state, false );

		if ( 'error' === $mode ) {
			return new WP_Error(
				(string) ( $state['error_code'] ?? 'zs_e2e_ai_error' ),
				(string) ( $state['error_message'] ?? 'E2E AI error' )
			);
		}

		if ( 'verdict' === $mode ) {
			return isset( $state['verdict'] ) && is_array( $state['verdict'] ) ? $state['verdict'] : [];
		}

		return null;
	},
	10,
	4
);

add_filter(
	'gf_zero_spam_ai_result',
	static function ( $result, $verdict, $form, $entry ) {
		$state        = (array) get_option( ZS_E2E_AI_REVIEW_OPTION, [] );
		$force_result = (string) ( $state['force_result'] ?? '' );

		if ( 'spam' === $force_result ) {
			return true;
		}

		if ( 'ham' === $force_result ) {
			return false;
		}

		return $result;
	},
	10,
	4
);

add_filter(
	'gf_zero_spam_ai_rescue_result',
	static function ( $result, $verdict, $form, $entry ) {
		$state               = (array) get_option( ZS_E2E_AI_REVIEW_OPTION, [] );
		$force_rescue_result = (string) ( $state['force_rescue_result'] ?? '' );

		if ( 'ham' === $force_rescue_result ) {
			return true;
		}

		if ( 'spam' === $force_rescue_result ) {
			return false;
		}

		return $result;
	},
	10,
	4
);

add_filter(
	'gform_entry_is_spam',
	static function ( $is_spam, $form, $entry ) {
		$state = (array) get_option( ZS_E2E_AI_REVIEW_OPTION, [] );

		if ( empty( $state['other_spam'] ) ) {
			return $is_spam;
		}

		$form_id        = (int) rgar( $form, 'id' );
		$target_form_id = (int) ( $state['other_spam_form_id'] ?? 0 );

		if ( $target_form_id > 0 && $target_form_id !== $form_id ) {
			return $is_spam;
		}

		if ( method_exists( 'GFCommon', 'set_spam_filter' ) ) {
			GFCommon::set_spam_filter( $form_id, 'E2E Other Spam', 'E2E non-Zero-Spam source.' );
		}

		return true;
	},
	5,
	3
);

add_action(
	'rest_api_init',
	function () {
		$auth = static function ( WP_REST_Request $request ) {
			$header = (string) $request->get_header( 'X-E2E-TEST-TOKEN' );

			if ( ! hash_equals( ZS_E2E_TEST_TOKEN, $header ) ) {
				return new WP_Error( 'rest_forbidden', 'Invalid E2E token', [ 'status' => 401 ] );
			}

			return true;
		};

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/mail',
			[
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => static function () {
					return rest_ensure_response( get_option( ZS_E2E_MAILCATCH_OPTION, [] ) );
				},
			]
		);

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/mail',
			[
				'methods'             => 'DELETE',
				'permission_callback' => $auth,
				'callback'            => static function () {
					delete_option( ZS_E2E_MAILCATCH_OPTION );

					return rest_ensure_response( [ 'cleared' => true ] );
				},
			]
		);

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/reset',
			[
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'callback'            => static function () {
					// Clear captured mail.
					delete_option( ZS_E2E_MAILCATCH_OPTION );

					// Reset Zero Spam plugin settings to defaults.
					delete_option( 'gravityformsaddon_gf-zero-spam_settings' );

					// Reset legacy and key-related options so a fresh key is regenerated.
					delete_option( 'gf_zero_spam_key' );

					// Clear last report timestamp so cron behaves deterministically.
					delete_option( 'gf_zero_spam_report_last_date' );

							// Clear AI review E2E control state.
								delete_option( ZS_E2E_AI_REVIEW_OPTION );
								delete_option( ZS_E2E_TOKEN_REJECTION_OPTION );

					// Clear all scheduled instances of the report hook.
							// wp_unschedule_event removes only the next occurrence and
							// would leave any later queued runs in place.
								wp_clear_scheduled_hook( 'gf_zero_spam_send_report' );

						return rest_ensure_response( [ 'reset' => true ] );
				},
			]
        );

			register_rest_route(
				ZS_E2E_NAMESPACE,
				'/token-rejection',
				[
					'methods'             => 'GET',
					'permission_callback' => $auth,
					'args'                => [
						'form_id' => [
							'type'     => 'integer',
							'required' => false,
						],
					],
					'callback'            => static function ( WP_REST_Request $request ) {
						$state   = (array) get_option( ZS_E2E_TOKEN_REJECTION_OPTION, [] );
						$form_id = (int) $request->get_param( 'form_id' );

						if ( $form_id > 0 ) {
							$by_form = isset( $state['by_form'] ) && is_array( $state['by_form'] ) ? $state['by_form'] : [];
							$key     = (string) $form_id;

							return rest_ensure_response( isset( $by_form[ $key ] ) ? $by_form[ $key ] : null );
						}

						return rest_ensure_response( $state );
					},
				]
			);

				register_rest_route(
					ZS_E2E_NAMESPACE,
					'/form/(?P<form_id>\d+)/zero-spam',
                    [
						'methods'             => 'POST',
						'permission_callback' => $auth,
						'args'                => [
							'enabled' => [
								'type'     => 'boolean',
								'required' => true,
							],
						],
						'callback'            => static function ( WP_REST_Request $request ) {
							if ( ! class_exists( 'GFAPI' ) ) {
								return new WP_Error( 'gf_missing', 'Gravity Forms not loaded', [ 'status' => 500 ] );
							}

							$form_id = (int) $request->get_param( 'form_id' );
							$form    = GFAPI::get_form( $form_id );

							if ( ! $form ) {
								return new WP_Error( 'form_missing', 'Form not found', [ 'status' => 404 ] );
							}

							$form['enableGFZeroSpam'] = (bool) $request->get_param( 'enabled' );

							$result = GFAPI::update_form( $form );

							if ( is_wp_error( $result ) ) {
								return $result;
							}

							return rest_ensure_response(
                                [
									'form_id'          => $form_id,
									'enableGFZeroSpam' => $form['enableGFZeroSpam'],
                                ]
							);
						},
					]
                );

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/email-rules',
			[
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => static function () {
					$settings = (array) get_option( 'gravityformsaddon_gf-zero-spam_settings', [] );

					return rest_ensure_response(
						[
							'enabled' => rgar( $settings, 'gf_zero_spam_email_rejection_enabled' ) ? true : false,
							'rules'   => (array) ( $settings['gf_zero_spam_email_rules'] ?? [] ),
							'message' => rgar( $settings, 'gf_zero_spam_email_rejection_message' ),
						]
					);
				},
			]
		);

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/email-rules',
			[
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'args'                => [
					'enabled' => [
						'type'     => 'boolean',
						'required' => false,
					],
					'rules'   => [
						'type'     => 'array',
						'required' => false,
					],
					'message' => [
						'type'     => 'string',
						'required' => false,
					],
				],
				'callback'            => static function ( WP_REST_Request $request ) {
					$settings = (array) get_option( 'gravityformsaddon_gf-zero-spam_settings', [] );

					if ( null !== $request->get_param( 'enabled' ) ) {
						$settings['gf_zero_spam_email_rejection_enabled'] = $request->get_param( 'enabled' ) ? '1' : '';
					}

					if ( null !== $request->get_param( 'rules' ) ) {
						$rules = (array) $request->get_param( 'rules' );

						// Rules are stored as a PHP array in the addon settings
						// (parse_rules_from_post decodes JSON before saving). Mirror
						// that shape so the runtime read path sees the rules.
						foreach ( $rules as &$rule ) {
							if ( ! isset( $rule['enabled'] ) ) {
								$rule['enabled'] = true;
							}
						}
						unset( $rule );

						$settings['gf_zero_spam_email_rules'] = $rules;
					}

					if ( null !== $request->get_param( 'message' ) ) {
						$settings['gf_zero_spam_email_rejection_message'] = (string) $request->get_param( 'message' );
					}

					update_option( 'gravityformsaddon_gf-zero-spam_settings', $settings );

					// Reset Email_Rejection cached state so the new rules apply on the
					// next request without needing a full PHP-FPM bounce.
					if ( class_exists( 'GF_Zero_Spam_Email_Rejection' ) ) {
						GF_Zero_Spam_Email_Rejection::reset();
					}

					return rest_ensure_response(
						[
							'enabled' => rgar( $settings, 'gf_zero_spam_email_rejection_enabled' ) ? true : false,
							'rules'   => (array) ( $settings['gf_zero_spam_email_rules'] ?? [] ),
							'message' => rgar( $settings, 'gf_zero_spam_email_rejection_message' ),
						]
					);
				},
			]
		);

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/global-default',
			[
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'args'                => [
					'enabled' => [
						'type'     => 'boolean',
						'required' => true,
					],
				],
				'callback'            => static function ( WP_REST_Request $request ) {
					$settings                          = (array) get_option( 'gravityformsaddon_gf-zero-spam_settings', [] );
					$settings['gf_zero_spam_blocking'] = $request->get_param( 'enabled' ) ? '1' : '0';
					update_option( 'gravityformsaddon_gf-zero-spam_settings', $settings );

					return rest_ensure_response( [ 'gf_zero_spam_blocking' => $settings['gf_zero_spam_blocking'] ] );
				},
			]
		);

			register_rest_route(
				ZS_E2E_NAMESPACE,
				'/ai-review',
				[
					'methods'             => 'GET',
					'permission_callback' => $auth,
					'callback'            => static function () {
						$state    = (array) get_option( ZS_E2E_AI_REVIEW_OPTION, [] );
						$settings = (array) get_option( 'gravityformsaddon_gf-zero-spam_settings', [] );

						return rest_ensure_response(
                            [
								'mode'                    => (string) ( $state['mode'] ?? 'none' ),
								'verdict'                 => isset( $state['verdict'] ) ? $state['verdict'] : null,
								'error_code'              => (string) ( $state['error_code'] ?? '' ),
								'error_message'           => (string) ( $state['error_message'] ?? '' ),
								'force_result'            => (string) ( $state['force_result'] ?? '' ),
								'force_rescue_result'     => (string) ( $state['force_rescue_result'] ?? '' ),
								'other_spam'              => ! empty( $state['other_spam'] ),
								'other_spam_form_id'      => (int) ( $state['other_spam_form_id'] ?? 0 ),
								'calls'                   => (int) ( $state['calls'] ?? 0 ),
								'last_payload'            => (string) ( $state['last_payload'] ?? '' ),
								'last_form_id'            => (int) ( $state['last_form_id'] ?? 0 ),
								'last_system_instruction' => (string) ( $state['last_system_instruction'] ?? '' ),
								'last_system_form_id'     => (int) ( $state['last_system_form_id'] ?? 0 ),
								'global_enabled'          => rgar( $settings, 'gf_zero_spam_ai_review_enabled' ) ? true : false,
								'threshold'               => rgar( $settings, 'gf_zero_spam_ai_confidence_threshold' ),
								'default_prompt'          => rgar( $settings, 'gf_zero_spam_ai_default_prompt' ),
								'rescue_global_enabled'   => rgar( $settings, 'gf_zero_spam_ai_rescue_enabled' ) ? true : false,
								'rescue_threshold'        => rgar( $settings, 'gf_zero_spam_ai_rescue_confidence_threshold' ),
							]
						);
					},
				]
			);

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/ai-review',
			[
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'args'                => [
					'mode'                  => [
						'type'     => 'string',
						'required' => false,
					],
					'verdict'               => [
						'type'     => 'object',
						'required' => false,
					],
					'error_code'            => [
						'type'     => 'string',
						'required' => false,
					],
					'error_message'         => [
						'type'     => 'string',
						'required' => false,
					],
					'force_result'          => [
						'required' => false,
					],
					'force_rescue_result'   => [
						'required' => false,
					],
					'other_spam'            => [
						'type'     => 'boolean',
						'required' => false,
					],
					'other_spam_form_id'    => [
						'type'     => 'integer',
						'required' => false,
					],
					'global_enabled'        => [
						'type'     => 'boolean',
						'required' => false,
					],
					'threshold'             => [
						'type'     => 'number',
						'required' => false,
					],
					'rescue_global_enabled' => [
						'type'     => 'boolean',
						'required' => false,
					],
					'rescue_threshold'      => [
						'type'     => 'number',
						'required' => false,
					],
					'default_prompt'        => [
						'type'     => 'string',
						'required' => false,
					],
				],
				'callback'            => static function ( WP_REST_Request $request ) {
						$mode = (string) ( $request->get_param( 'mode' ) ?? 'none' );

					if ( ! in_array( $mode, [ 'none', 'null', 'verdict', 'error' ], true ) ) {
						return new WP_Error( 'invalid_ai_mode', 'Invalid AI review mode', [ 'status' => 400 ] );
					}

						$force_result = null === $request->get_param( 'force_result' ) ? '' : (string) $request->get_param( 'force_result' );

					if ( ! in_array( $force_result, [ '', 'spam', 'ham' ], true ) ) {
						return new WP_Error( 'invalid_ai_force_result', 'Invalid AI review force result', [ 'status' => 400 ] );
					}

						$force_rescue_result = null === $request->get_param( 'force_rescue_result' ) ? '' : (string) $request->get_param( 'force_rescue_result' );

					if ( ! in_array( $force_rescue_result, [ '', 'spam', 'ham' ], true ) ) {
						return new WP_Error( 'invalid_ai_force_rescue_result', 'Invalid AI rescue force result', [ 'status' => 400 ] );
					}

								$state = [
									'mode'                => $mode,
									'verdict'             => null,
									'error_code'          => '',
									'error_message'       => '',
									'force_result'        => $force_result,
									'force_rescue_result' => $force_rescue_result,
									'other_spam'          => $request->get_param( 'other_spam' ) ? true : false,
									'other_spam_form_id'  => (int) $request->get_param( 'other_spam_form_id' ),
									'calls'               => 0,
									'last_payload'        => '',
									'last_form_id'        => 0,
									'last_system_instruction' => '',
									'last_system_form_id' => 0,
								];

								if ( null !== $request->get_param( 'verdict' ) ) {
									$state['verdict'] = (array) $request->get_param( 'verdict' );
								}

								if ( null !== $request->get_param( 'error_code' ) ) {
									$state['error_code'] = (string) $request->get_param( 'error_code' );
								}

								if ( null !== $request->get_param( 'error_message' ) ) {
									$state['error_message'] = (string) $request->get_param( 'error_message' );
								}

								update_option( ZS_E2E_AI_REVIEW_OPTION, $state, false );

								$settings = (array) get_option( 'gravityformsaddon_gf-zero-spam_settings', [] );

								if ( null !== $request->get_param( 'global_enabled' ) ) {
									$settings['gf_zero_spam_ai_review_enabled'] = $request->get_param( 'global_enabled' ) ? '1' : '';
								}

								if ( null !== $request->get_param( 'threshold' ) ) {
									$settings['gf_zero_spam_ai_confidence_threshold'] = (string) $request->get_param( 'threshold' );
								}

								if ( null !== $request->get_param( 'rescue_global_enabled' ) ) {
									$settings['gf_zero_spam_ai_rescue_enabled'] = $request->get_param( 'rescue_global_enabled' ) ? '1' : '';
								}

								if ( null !== $request->get_param( 'rescue_threshold' ) ) {
									$settings['gf_zero_spam_ai_rescue_confidence_threshold'] = (string) $request->get_param( 'rescue_threshold' );
								}

								if ( null !== $request->get_param( 'default_prompt' ) ) {
									$settings['gf_zero_spam_ai_default_prompt'] = (string) $request->get_param( 'default_prompt' );
								}

								if ( empty( $settings['gf_zero_spam_ai_default_prompt'] ) && class_exists( 'GF_Zero_Spam_AI_Review_Settings' ) ) {
									$settings['gf_zero_spam_ai_default_prompt'] = GF_Zero_Spam_AI_Review_Settings::get_default_prompt();
								}

								update_option( 'gravityformsaddon_gf-zero-spam_settings', $settings );

								return rest_ensure_response(
                                    [
										'mode'             => $state['mode'],
										'verdict'          => $state['verdict'],
										'error_code'       => $state['error_code'],
										'error_message'    => $state['error_message'],
										'force_result'     => $state['force_result'],
										'force_rescue_result' => $state['force_rescue_result'],
										'other_spam'       => $state['other_spam'],
										'other_spam_form_id' => $state['other_spam_form_id'],
										'calls'            => $state['calls'],
										'last_system_instruction' => $state['last_system_instruction'],
										'last_system_form_id' => $state['last_system_form_id'],
										'global_enabled'   => rgar( $settings, 'gf_zero_spam_ai_review_enabled' ) ? true : false,
										'threshold'        => rgar( $settings, 'gf_zero_spam_ai_confidence_threshold' ),
										'default_prompt'   => rgar( $settings, 'gf_zero_spam_ai_default_prompt' ),
										'rescue_global_enabled' => rgar( $settings, 'gf_zero_spam_ai_rescue_enabled' ) ? true : false,
										'rescue_threshold' => rgar( $settings, 'gf_zero_spam_ai_rescue_confidence_threshold' ),
                                    ]
                                );
				},
			]
        );

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/ai-review',
			[
				'methods'             => 'DELETE',
				'permission_callback' => $auth,
				'callback'            => static function () {
						delete_option( ZS_E2E_AI_REVIEW_OPTION );
						$settings = (array) get_option( 'gravityformsaddon_gf-zero-spam_settings', [] );
							unset(
								$settings['gf_zero_spam_ai_review_enabled'],
								$settings['gf_zero_spam_ai_confidence_threshold'],
                                $settings['gf_zero_spam_ai_default_prompt'],
                                $settings['gf_zero_spam_ai_rescue_enabled'],
                                $settings['gf_zero_spam_ai_rescue_confidence_threshold']
                            );
						update_option( 'gravityformsaddon_gf-zero-spam_settings', $settings );

						return rest_ensure_response( [ 'reset' => true ] );
				},
			]
		);

			register_rest_route(
				ZS_E2E_NAMESPACE,
				'/form/(?P<form_id>\d+)/ai-review',
				[
					'methods'             => 'POST',
					'permission_callback' => $auth,
					'args'                => [
						'enabled'            => [
							'type'     => 'boolean',
							'required' => false,
						],
						'rescue_enabled'     => [
							'type'     => 'boolean',
							'required' => false,
						],
						'max_calls_per_hour' => [
							'type'     => 'integer',
							'required' => false,
						],
						'prompt'             => [
							'type'     => 'string',
							'required' => false,
						],
						'excluded_fields'    => [
							'type'     => 'array',
							'required' => false,
						],
					],
					'callback'            => static function ( WP_REST_Request $request ) {
						if ( ! class_exists( 'GFAPI' ) ) {
							return new WP_Error( 'gf_missing', 'Gravity Forms not loaded', [ 'status' => 500 ] );
						}

						$form_id = (int) $request->get_param( 'form_id' );
						$form    = GFAPI::get_form( $form_id );

						if ( ! $form ) {
							return new WP_Error( 'form_missing', 'Form not found', [ 'status' => 404 ] );
						}

						if ( null !== $request->get_param( 'enabled' ) ) {
							$form['enableGFZeroSpamAI'] = (bool) $request->get_param( 'enabled' );
						}

						if ( null !== $request->get_param( 'rescue_enabled' ) ) {
							$form['enableGFZeroSpamAIRescue'] = (bool) $request->get_param( 'rescue_enabled' );
						}

						if ( null !== $request->get_param( 'max_calls_per_hour' ) ) {
							$form['gfZeroSpamAIMaxCallsPerHour'] = max( 0, (int) $request->get_param( 'max_calls_per_hour' ) );
						}

						if ( null !== $request->get_param( 'prompt' ) ) {
							$form['gfZeroSpamAIPrompt'] = (string) $request->get_param( 'prompt' );
						}

						if ( null !== $request->get_param( 'excluded_fields' ) ) {
							$excluded_fields                    = array_map( 'strval', (array) $request->get_param( 'excluded_fields' ) );
							$form['gfZeroSpamAIExcludedFields'] = array_values(
                                array_filter(
                                    $excluded_fields,
                                    static function ( $value ) {
										return '' !== $value;
                                    }
                                )
							);
						}

								$result = GFAPI::update_form( $form );

						if ( is_wp_error( $result ) ) {
							return $result;
						}

						return rest_ensure_response(
                            [
								'form_id'                  => $form_id,
								'enableGFZeroSpamAI'       => isset( $form['enableGFZeroSpamAI'] ) ? (bool) $form['enableGFZeroSpamAI'] : null,
								'enableGFZeroSpamAIRescue' => isset( $form['enableGFZeroSpamAIRescue'] ) ? (bool) $form['enableGFZeroSpamAIRescue'] : null,
								'gfZeroSpamAIPrompt'       => isset( $form['gfZeroSpamAIPrompt'] ) ? (string) $form['gfZeroSpamAIPrompt'] : null,
								'gfZeroSpamAIExcludedFields' => isset( $form['gfZeroSpamAIExcludedFields'] ) ? (array) $form['gfZeroSpamAIExcludedFields'] : [],
								'gfZeroSpamAIMaxCallsPerHour' => isset( $form['gfZeroSpamAIMaxCallsPerHour'] ) ? (int) $form['gfZeroSpamAIMaxCallsPerHour'] : null,
							]
                        );
					},
				]
			);

			register_rest_route(
				ZS_E2E_NAMESPACE,
				'/form/(?P<form_id>\d+)/notification',
				[
					'methods'             => 'POST',
					'permission_callback' => $auth,
					'args'                => [
						'id'      => [
							'type'     => 'string',
							'required' => false,
						],
						'to'      => [
							'type'     => 'string',
							'required' => false,
						],
						'subject' => [
							'type'     => 'string',
							'required' => false,
						],
						'message' => [
							'type'     => 'string',
							'required' => false,
						],
					],
					'callback'            => static function ( WP_REST_Request $request ) {
						if ( ! class_exists( 'GFAPI' ) ) {
							return new WP_Error( 'gf_missing', 'Gravity Forms not loaded', [ 'status' => 500 ] );
						}

						$form_id = (int) $request->get_param( 'form_id' );
						$form    = GFAPI::get_form( $form_id );

						if ( ! $form ) {
							return new WP_Error( 'form_missing', 'Form not found', [ 'status' => 404 ] );
						}

							$notification_id = $request->get_param( 'id' ) ? (string) $request->get_param( 'id' ) : 'zs_e2e_ai_review_notification';

						if ( empty( $form['notifications'] ) || ! is_array( $form['notifications'] ) ) {
							$form['notifications'] = [];
						}

						$form['notifications'][ $notification_id ] = [
							'id'                => $notification_id,
							'name'              => 'Zero Spam E2E Notification',
							'event'             => 'form_submission',
							'isActive'          => true,
							'to'                => $request->get_param( 'to' ) ? (string) $request->get_param( 'to' ) : 'zero-spam-e2e@example.test',
							'toType'            => 'email',
							'from'              => '{admin_email}',
							'fromName'          => '',
							'subject'           => $request->get_param( 'subject' ) ? (string) $request->get_param( 'subject' ) : 'Zero Spam E2E Notification',
							'message'           => $request->get_param( 'message' ) ? (string) $request->get_param( 'message' ) : 'Zero Spam E2E message for entry {entry_id}.',
							'message_format'    => 'text',
							'type'              => 'admin',
							'service'           => 'wordpress',
							'disableAutoformat' => true,
						];

						$result = GFAPI::update_form( $form );

						if ( is_wp_error( $result ) ) {
							return $result;
						}

						return rest_ensure_response(
							[
								'form_id'         => $form_id,
								'notification_id' => $notification_id,
							]
						);
					},
				]
			);

			register_rest_route(
				ZS_E2E_NAMESPACE,
				'/form/(?P<form_id>\d+)/trash',
				[
					'methods'             => 'POST',
					'permission_callback' => $auth,
					'args'                => [
						'trashed' => [
							'type'     => 'boolean',
							'required' => true,
						],
					],
					'callback'            => static function ( WP_REST_Request $request ) {
						if ( ! class_exists( 'GFAPI' ) || ! class_exists( 'GFFormsModel' ) ) {
							return new WP_Error( 'gf_missing', 'Gravity Forms not loaded', [ 'status' => 500 ] );
						}

						$form_id = (int) $request->get_param( 'form_id' );
						$form    = GFFormsModel::get_form_meta( $form_id );

						if ( ! $form ) {
							return new WP_Error( 'form_missing', 'Form not found', [ 'status' => 404 ] );
						}

							$result = GFAPI::update_form_property( $form_id, 'is_trash', $request->get_param( 'trashed' ) ? '1' : '0' );

						if ( is_wp_error( $result ) ) {
							return $result;
						}

						return rest_ensure_response(
							[
								'form_id' => $form_id,
								'trashed' => (bool) $request->get_param( 'trashed' ),
							]
						);
					},
				]
			);

					register_rest_route(
						ZS_E2E_NAMESPACE,
                        '/token',
                        [
							'methods'             => 'POST',
							'permission_callback' => $auth,
							'args'                => [
								'form_id' => [
									'type'     => 'integer',
									'required' => true,
								],
								'ttl'     => [
									'type'     => 'integer',
									'required' => false,
								],
							],
							'callback'            => static function ( WP_REST_Request $request ) {
								if ( ! class_exists( 'GF_Zero_Spam_Token' ) ) {
									return new WP_Error( 'zs_missing', 'Zero Spam not loaded', [ 'status' => 500 ] );
								}

								$form_id = (int) $request->get_param( 'form_id' );
								$ttl     = (int) ( $request->get_param( 'ttl' ) ? $request->get_param( 'ttl' ) : 600 );

								return rest_ensure_response( [ 'token' => GF_Zero_Spam_Token::mint( $form_id, $ttl ) ] );
							},
						]
                    );

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/page',
			[
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'args'                => [
					'title'   => [
						'type'     => 'string',
						'required' => true,
					],
					'content' => [
						'type'     => 'string',
						'required' => true,
					],
					'slug'    => [
						'type'     => 'string',
						'required' => false,
					],
				],
				'callback'            => static function ( WP_REST_Request $request ) {
					$post_id = wp_insert_post(
						[
							'post_title'   => sanitize_text_field( $request->get_param( 'title' ) ),
							'post_content' => $request->get_param( 'content' ),
							'post_status'  => 'publish',
							'post_type'    => 'page',
							'post_name'    => $request->get_param( 'slug' ) ? $request->get_param( 'slug' ) : '',
							'meta_input'   => [ '_zs_e2e_test' => '1' ],
						],
						true
					);

					if ( is_wp_error( $post_id ) ) {
						return $post_id;
					}

					return rest_ensure_response(
						[
							'page_id'   => (int) $post_id,
							'permalink' => get_permalink( $post_id ),
						]
					);
				},
			]
		);

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/pages',
			[
				'methods'             => 'DELETE',
				'permission_callback' => $auth,
				'args'                => [
					'ids' => [
						'type'     => 'array',
						'required' => false,
						'items'    => [ 'type' => 'integer' ],
					],
				],
				'callback'            => static function ( WP_REST_Request $request ) {
					$ids = (array) $request->get_param( 'ids' );

					if ( empty( $ids ) ) {
						$ids = get_posts(
							[
								'post_type'      => 'page',
								'post_status'    => 'any',
								'meta_key'       => '_zs_e2e_test',
								'meta_value'     => '1',
								'posts_per_page' => -1,
								'fields'         => 'ids',
							]
						);
					}

					$deleted = 0;

					foreach ( $ids as $id ) {
						$id = (int) $id;

						// Only delete posts that we tagged.
						if ( '1' === (string) get_post_meta( $id, '_zs_e2e_test', true ) ) {
							if ( wp_delete_post( $id, true ) ) {
								++$deleted;
							}
						}
					}

					return rest_ensure_response( [ 'deleted' => $deleted ] );
				},
			]
		);

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/report-config',
			[
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'args'                => [
					'frequency' => [
						'type'     => 'string',
						'required' => false,
					],
					'recipient' => [
						'type'     => 'string',
						'required' => false,
					],
					'subject'   => [
						'type'     => 'string',
						'required' => false,
					],
					'body'      => [
						'type'     => 'string',
						'required' => false,
					],
				],
				'callback'            => static function ( WP_REST_Request $request ) {
					$settings = (array) get_option( 'gravityformsaddon_gf-zero-spam_settings', [] );

					if ( null !== $request->get_param( 'frequency' ) ) {
						$settings['gf_zero_spam_email_frequency'] = (string) $request->get_param( 'frequency' );
					}

					if ( null !== $request->get_param( 'recipient' ) ) {
						$settings['gf_zero_spam_report_email'] = (string) $request->get_param( 'recipient' );
					}

					if ( null !== $request->get_param( 'subject' ) ) {
						$settings['gf_zero_spam_subject'] = (string) $request->get_param( 'subject' );
					}

					if ( null !== $request->get_param( 'body' ) ) {
						$settings['gf_zero_spam_message'] = (string) $request->get_param( 'body' );
					}

					update_option( 'gravityformsaddon_gf-zero-spam_settings', $settings );

					return rest_ensure_response(
						[
							'frequency' => rgar( $settings, 'gf_zero_spam_email_frequency' ),
							'recipient' => rgar( $settings, 'gf_zero_spam_report_email' ),
							'subject'   => rgar( $settings, 'gf_zero_spam_subject' ),
							'body'      => rgar( $settings, 'gf_zero_spam_message' ),
						]
					);
				},
			]
		);

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/cron-run-report',
			[
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'callback'            => static function () {
					if ( ! has_action( 'gf_zero_spam_send_report' ) ) {
						return new WP_Error(
							'cron_unhooked',
							'gf_zero_spam_send_report has no listeners — addon may not be initialized',
							[ 'status' => 500 ]
						);
					}

					do_action( 'gf_zero_spam_send_report' );

					return rest_ensure_response( [ 'ran' => true ] );
				},
			]
		);

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/cron-scheduled/(?P<hook>[a-z0-9_]+)',
			[
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => static function ( WP_REST_Request $request ) {
					$hook      = (string) $request->get_param( 'hook' );
					$timestamp = wp_next_scheduled( $hook );

					return rest_ensure_response(
						[
							'hook'      => $hook,
							'scheduled' => (bool) $timestamp,
							'timestamp' => $timestamp ? $timestamp : null,
						]
					);
				},
			]
		);

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/entry-notes/(?P<entry_id>\d+)',
			[
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => static function ( WP_REST_Request $request ) {
					$entry_id = (int) $request->get_param( 'entry_id' );

					if ( ! class_exists( 'GFFormsModel' ) ) {
						return new WP_Error( 'gf_missing', 'Gravity Forms not loaded', [ 'status' => 500 ] );
					}

					$notes = GFFormsModel::get_lead_notes( $entry_id );

					$out = [];

					foreach ( (array) $notes as $note ) {
						$out[] = [
							'id'        => (int) $note->id,
							'user_name' => (string) ( $note->user_name ?? '' ),
							'note_type' => (string) ( $note->note_type ?? '' ),
							'sub_type'  => (string) ( $note->sub_type ?? '' ),
							'value'     => (string) ( $note->value ?? '' ),
							'date'      => (string) ( $note->date_created ?? '' ),
						];
					}

					return rest_ensure_response( $out );
				},
			]
		);

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/entries/(?P<form_id>\d+)',
			[
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => static function ( WP_REST_Request $request ) {
					if ( ! class_exists( 'GFAPI' ) ) {
						return new WP_Error( 'gf_missing', 'Gravity Forms not loaded', [ 'status' => 500 ] );
					}

					$form_id = (int) $request->get_param( 'form_id' );
					$status  = (string) $request->get_param( 'status' ); // active|spam|trash, optional.

					$search = $status ? [ 'status' => $status ] : [];

					$entries = GFAPI::get_entries( $form_id, $search, null, [ 'page_size' => 50 ] );

					if ( is_wp_error( $entries ) ) {
						return $entries;
					}

					return rest_ensure_response( $entries );
				},
			]
		);
	}
);
