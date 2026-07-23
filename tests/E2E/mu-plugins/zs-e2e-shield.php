<?php
/**
 * Plugin Name: Zero Spam E2E Shield Mock
 * Description: Simulates Shield Security's silentCAPTCHA callables for Playwright tests. The integration under test discovers Shield via is_callable() on the documented global-function fallbacks (shield_test_ip_is_bot, shield_get_silentcaptcha_bot_threshold), so defining them here makes Shield "available" without installing the plugin. Availability and the verdict are controlled per-test through a REST-managed option, mirroring the AI-review mock pattern in zs-e2e-helpers.php.
 * Version: 1.0.0
 *
 * All routes require the same X-E2E-TEST-TOKEN header used by @gravitykit/e2e-fixtures
 * so we don't introduce a second auth scheme.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ZS_E2E_SHIELD_OPTION = 'zs_e2e_shield';

/**
 * Read the current Shield mock state.
 *
 * @return array
 */
function zs_e2e_shield_state() {
	return (array) get_option( ZS_E2E_SHIELD_OPTION, [] );
}

// The integration probes is_callable() on these names, so defining them conditionally IS
// the availability toggle. Re-evaluated every request; REST toggles apply on the next one.
if ( ! empty( zs_e2e_shield_state()['available'] ) && ! function_exists( 'shield_test_ip_is_bot' ) ) {
	/**
	 * Mocked Shield bot-detection callable.
	 *
	 * Verdict modes: 'bot' => true, 'human' => false, 'throw' => throws,
	 * 'garbage' => truthy non-boolean (must fail open), anything else => null.
	 *
	 * @throws RuntimeException When the configured verdict mode is 'throw'.
	 *
	 * @return mixed
	 */
	function shield_test_ip_is_bot() {
		$state          = zs_e2e_shield_state();
		$state['calls'] = (int) ( $state['calls'] ?? 0 ) + 1;

		update_option( ZS_E2E_SHIELD_OPTION, $state, false );

		switch ( (string) ( $state['verdict'] ?? 'null' ) ) {
			case 'bot':
				return true;
			case 'human':
				return false;
			case 'throw':
				throw new RuntimeException( 'E2E Shield mock failure' );
			case 'garbage':
				return 'yes';
			default:
				return null;
		}
	}

	/**
	 * Mocked Shield silentCAPTCHA bot-threshold callable.
	 *
	 * @return int
	 */
	function shield_get_silentcaptcha_bot_threshold() {
		$state = zs_e2e_shield_state();

		return isset( $state['threshold'] ) ? (int) $state['threshold'] : 25;
	}
}

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

		$shield_state_response = static function () {
			$state = zs_e2e_shield_state();

			return rest_ensure_response(
				[
					'available' => ! empty( $state['available'] ),
					'verdict'   => (string) ( $state['verdict'] ?? 'null' ),
					'threshold' => isset( $state['threshold'] ) ? (int) $state['threshold'] : 25,
					'calls'     => (int) ( $state['calls'] ?? 0 ),
				]
			);
		};

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/shield',
			[
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => $shield_state_response,
			]
		);

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/shield',
			[
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'args'                => [
					'available' => [
						'type'     => 'boolean',
						'required' => false,
					],
					'verdict'   => [
						'type'     => 'string',
						'required' => false,
					],
					'threshold' => [
						'type'     => 'integer',
						'required' => false,
					],
				],
				'callback'            => static function ( WP_REST_Request $request ) use ( $shield_state_response ) {
					$verdict = null === $request->get_param( 'verdict' ) ? 'null' : (string) $request->get_param( 'verdict' );

					if ( ! in_array( $verdict, [ 'null', 'bot', 'human', 'throw', 'garbage' ], true ) ) {
						return new WP_Error( 'invalid_shield_verdict', 'Invalid Shield mock verdict', [ 'status' => 400 ] );
					}

					$state = [
						'available' => $request->get_param( 'available' ) ? true : false,
						'verdict'   => $verdict,
						'calls'     => 0,
					];

					if ( null !== $request->get_param( 'threshold' ) ) {
						$state['threshold'] = (int) $request->get_param( 'threshold' );
					}

					update_option( ZS_E2E_SHIELD_OPTION, $state, false );

					return $shield_state_response();
				},
			]
		);

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/shield',
			[
				'methods'             => 'DELETE',
				'permission_callback' => $auth,
				'callback'            => static function () {
					delete_option( ZS_E2E_SHIELD_OPTION );

					return rest_ensure_response( [ 'reset' => true ] );
				},
			]
		);

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/shield/plugin-setting',
			[
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => static function () {
					$settings = (array) get_option( 'gravityformsaddon_gf-zero-spam_settings', [] );
					$present  = array_key_exists( 'shield_silent_captcha', $settings );

					return rest_ensure_response(
						[
							'present' => $present,
							'value'   => $present ? (string) $settings['shield_silent_captcha'] : null,
						]
					);
				},
			]
		);

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/shield/plugin-setting',
			[
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'args'                => [
					'value'  => [
						'type'     => 'string',
						'required' => false,
					],
					'remove' => [
						'type'     => 'boolean',
						'required' => false,
					],
				],
				'callback'            => static function ( WP_REST_Request $request ) {
					$settings = (array) get_option( 'gravityformsaddon_gf-zero-spam_settings', [] );

					if ( $request->get_param( 'remove' ) ) {
						unset( $settings['shield_silent_captcha'] );
					} else {
						$settings['shield_silent_captcha'] = (string) $request->get_param( 'value' );
					}

					update_option( 'gravityformsaddon_gf-zero-spam_settings', $settings );

					$present = array_key_exists( 'shield_silent_captcha', $settings );

					return rest_ensure_response(
						[
							'present' => $present,
							'value'   => $present ? (string) $settings['shield_silent_captcha'] : null,
						]
					);
				},
			]
		);

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/form/(?P<form_id>\d+)/shield-meta',
			[
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => static function ( WP_REST_Request $request ) {
					if ( ! class_exists( 'GFFormsModel' ) ) {
						return new WP_Error( 'gf_missing', 'Gravity Forms not loaded', [ 'status' => 500 ] );
					}

					$form = GFFormsModel::get_form_meta( (int) $request->get_param( 'form_id' ) );

					if ( ! is_array( $form ) ) {
						return new WP_Error( 'form_missing', 'Form not found', [ 'status' => 404 ] );
					}

					$present = array_key_exists( 'shield_silent_captcha', $form );

					return rest_ensure_response(
						[
							'form_id' => (int) $request->get_param( 'form_id' ),
							'present' => $present,
							'value'   => $present ? (string) $form['shield_silent_captcha'] : null,
						]
					);
				},
			]
		);

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/form/(?P<form_id>\d+)/shield-meta',
			[
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'args'                => [
					'value'  => [
						'type'     => 'string',
						'required' => false,
					],
					'remove' => [
						'type'     => 'boolean',
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

					if ( $request->get_param( 'remove' ) ) {
						unset( $form['shield_silent_captcha'] );
					} else {
						$form['shield_silent_captcha'] = (string) $request->get_param( 'value' );
					}

					$result = GFAPI::update_form( $form );

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					$present = array_key_exists( 'shield_silent_captcha', $form );

					return rest_ensure_response(
						[
							'form_id' => $form_id,
							'present' => $present,
							'value'   => $present ? (string) $form['shield_silent_captcha'] : null,
						]
					);
				},
			]
		);
	}
);
