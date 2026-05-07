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

const ZS_E2E_NAMESPACE = 'zs-e2e/v1';
const ZS_E2E_TEST_TOKEN = 'gravitykit-e2e-test';

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

					// Clear any scheduled reports.
					$timestamp = wp_next_scheduled( 'gf_zero_spam_send_report' );

					if ( $timestamp ) {
						wp_unschedule_event( $timestamp, 'gf_zero_spam_send_report' );
					}

					return rest_ensure_response( [ 'reset' => true ] );
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
					'enabled' => [ 'type' => 'boolean', 'required' => true ],
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
						[ 'form_id' => $form_id, 'enableGFZeroSpam' => $form['enableGFZeroSpam'] ]
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
					'enabled' => [ 'type' => 'boolean', 'required' => false ],
					'rules'   => [ 'type' => 'array', 'required' => false ],
					'message' => [ 'type' => 'string', 'required' => false ],
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
					'enabled' => [ 'type' => 'boolean', 'required' => true ],
				],
				'callback'            => static function ( WP_REST_Request $request ) {
					$settings = (array) get_option( 'gravityformsaddon_gf-zero-spam_settings', [] );
					$settings['gf_zero_spam_blocking'] = $request->get_param( 'enabled' ) ? '1' : '0';
					update_option( 'gravityformsaddon_gf-zero-spam_settings', $settings );

					return rest_ensure_response( [ 'gf_zero_spam_blocking' => $settings['gf_zero_spam_blocking'] ] );
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
					'form_id' => [ 'type' => 'integer', 'required' => true ],
					'ttl'     => [ 'type' => 'integer', 'required' => false ],
				],
				'callback'            => static function ( WP_REST_Request $request ) {
					if ( ! class_exists( 'GF_Zero_Spam_Token' ) ) {
						return new WP_Error( 'zs_missing', 'Zero Spam not loaded', [ 'status' => 500 ] );
					}

					$form_id = (int) $request->get_param( 'form_id' );
					$ttl     = (int) ( $request->get_param( 'ttl' ) ?: 600 );

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
					'title'   => [ 'type' => 'string', 'required' => true ],
					'content' => [ 'type' => 'string', 'required' => true ],
					'slug'    => [ 'type' => 'string', 'required' => false ],
				],
				'callback'            => static function ( WP_REST_Request $request ) {
					$post_id = wp_insert_post(
						[
							'post_title'   => sanitize_text_field( $request->get_param( 'title' ) ),
							'post_content' => $request->get_param( 'content' ),
							'post_status'  => 'publish',
							'post_type'    => 'page',
							'post_name'    => $request->get_param( 'slug' ) ?: '',
							'meta_input'   => [ '_zs_e2e_test' => '1' ],
						],
						true
					);

					if ( is_wp_error( $post_id ) ) {
						return $post_id;
					}

					return rest_ensure_response(
						[ 'page_id' => (int) $post_id, 'permalink' => get_permalink( $post_id ) ]
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
					'frequency' => [ 'type' => 'string', 'required' => false ],
					'recipient' => [ 'type' => 'string', 'required' => false ],
					'subject'   => [ 'type' => 'string', 'required' => false ],
					'body'      => [ 'type' => 'string', 'required' => false ],
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
							'timestamp' => $timestamp ?: null,
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
					$status  = (string) $request->get_param( 'status' ); // active|spam|trash, optional

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
