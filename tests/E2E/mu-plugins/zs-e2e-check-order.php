<?php
/**
 * Plugin Name: Zero Spam E2E Check Order Seam
 * Description: REST endpoints used by Playwright tests to seed and read the Spam Check Order plugin settings (gf_zero_spam_check_order_1..3 and gf_zero_spam_stop_after_first_detection) without going through the admin UI.
 * Version: 1.0.0
 *
 * All routes require the same X-E2E-TEST-TOKEN header used by @gravitykit/e2e-fixtures
 * so we don't introduce a second auth scheme.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ZS_E2E_ORDER_KEYS = [
	'gf_zero_spam_check_order_1',
	'gf_zero_spam_check_order_2',
	'gf_zero_spam_check_order_3',
];
const ZS_E2E_STOP_KEY   = 'gf_zero_spam_stop_after_first_detection';

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

		$order_state_response = static function () {
			$settings = (array) get_option( 'gravityformsaddon_gf-zero-spam_settings', [] );
			$order    = [];

			foreach ( ZS_E2E_ORDER_KEYS as $key ) {
				$order[] = array_key_exists( $key, $settings ) ? (string) $settings[ $key ] : null;
			}

			return rest_ensure_response(
				[
					'order' => $order,
					'stop'  => array_key_exists( ZS_E2E_STOP_KEY, $settings ) ? (string) $settings[ ZS_E2E_STOP_KEY ] : null,
				]
			);
		};

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/check-order',
			[
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => $order_state_response,
			]
		);

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/check-order',
			[
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'args'                => [
					'order' => [
						'type'     => 'array',
						'required' => false,
						'items'    => [ 'type' => 'string' ],
					],
					'stop'  => [
						'type'     => 'boolean',
						'required' => false,
					],
				],
				'callback'            => static function ( WP_REST_Request $request ) use ( $order_state_response ) {
					$settings = (array) get_option( 'gravityformsaddon_gf-zero-spam_settings', [] );

					if ( null !== $request->get_param( 'order' ) ) {
						$order = array_values( (array) $request->get_param( 'order' ) );

						if ( 3 !== count( $order ) ) {
							return new WP_Error( 'invalid_order', 'Order must contain exactly 3 slugs', [ 'status' => 400 ] );
						}

						foreach ( ZS_E2E_ORDER_KEYS as $index => $key ) {
							$settings[ $key ] = (string) $order[ $index ];
						}
					}

					if ( null !== $request->get_param( 'stop' ) ) {
						$settings[ ZS_E2E_STOP_KEY ] = $request->get_param( 'stop' ) ? '1' : '0';
					}

					update_option( 'gravityformsaddon_gf-zero-spam_settings', $settings );

					return $order_state_response();
				},
			]
		);

		register_rest_route(
			ZS_E2E_NAMESPACE,
			'/check-order',
			[
				'methods'             => 'DELETE',
				'permission_callback' => $auth,
				'callback'            => static function () use ( $order_state_response ) {
					$settings = (array) get_option( 'gravityformsaddon_gf-zero-spam_settings', [] );

					foreach ( array_merge( ZS_E2E_ORDER_KEYS, [ ZS_E2E_STOP_KEY ] ) as $key ) {
						unset( $settings[ $key ] );
					}

					update_option( 'gravityformsaddon_gf-zero-spam_settings', $settings );

					return $order_state_response();
				},
			]
		);
	}
);
