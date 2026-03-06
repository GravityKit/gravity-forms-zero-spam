<?php
/**
 * REST API and admin-ajax endpoints for token minting.
 *
 * @since 1.7.0
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class GF_Zero_Spam_Token_Endpoint {

	/**
	 * Maximum token requests per IP per minute.
	 *
	 * @since 1.7.0
	 *
	 * @var int
	 */
	const RATE_LIMIT = 30;

	/**
	 * REST API namespace.
	 *
	 * @since 1.7.0
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'gf-zero-spam/v1';

	/**
	 * Registers hooks for both REST and admin-ajax endpoints.
	 *
	 * @since 1.7.0
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_rest_route' ] );
		add_action( 'wp_ajax_gf_zero_spam_token', [ $this, 'handle_ajax' ] );
		add_action( 'wp_ajax_nopriv_gf_zero_spam_token', [ $this, 'handle_ajax' ] );
	}

	/**
	 * Registers the REST API route for token minting.
	 *
	 * @since 1.7.0
	 *
	 * @return void
	 */
	public function register_rest_route() {
		register_rest_route(
            self::REST_NAMESPACE,
            '/token',
            [
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_rest' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'form_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
        );
	}

	/**
	 * Handles the REST API token request.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_rest( $request ) {
		$form_id = (int) $request->get_param( 'form_id' );

		return $this->handle_token_request( $form_id );
	}

	/**
	 * Handles the admin-ajax token request.
	 *
	 * @since 1.7.0
	 *
	 * @return void
	 */
	public function handle_ajax() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public endpoint; no nonce needed.
		$form_id = isset( $_REQUEST['form_id'] ) ? absint( $_REQUEST['form_id'] ) : 0;
		$result  = $this->handle_token_request( $form_id );

		nocache_headers();

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			$status     = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 500;

			wp_send_json_error( $result->get_error_message(), $status );
		}

		wp_send_json( $result->get_data() );
	}

	/**
	 * Shared handler that validates the request and mints a token.
	 *
	 * @since 1.7.0
	 *
	 * @param int $form_id The form ID to mint a token for.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	private function handle_token_request( int $form_id ) {
		if ( $form_id < 1 ) {
			return new WP_Error( 'missing_form_id', __( 'A valid form_id is required.', 'gravity-forms-zero-spam' ), [ 'status' => 400 ] );
		}

		$form = GFAPI::get_form( $form_id );

		if ( ! $form ) {
			return new WP_Error( 'invalid_form', __( 'Form not found.', 'gravity-forms-zero-spam' ), [ 'status' => 400 ] );
		}

		// Check if Zero Spam is enabled for this form.
		$enabled = gf_apply_filters( 'gf_zero_spam_check_key_field', $form_id, true, $form, [] );

		if ( false === $enabled ) {
			return new WP_Error( 'zero_spam_disabled', __( 'Zero Spam is not enabled for this form.', 'gravity-forms-zero-spam' ), [ 'status' => 400 ] );
		}

		$rate_check = $this->check_rate_limit();

		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$ttl     = 600;
		$token   = GF_Zero_Spam_Token::mint( $form_id, $ttl );
		$expires = time() + $ttl;

		$response = new WP_REST_Response(
            [
				'token'   => $token,
				'expires' => $expires,
			]
        );

		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );

		return $response;
	}

	/**
	 * Checks per-IP rate limit using transients.
	 *
	 * @since 1.7.0
	 *
	 * @return true|WP_Error True if within limits, WP_Error if exceeded.
	 */
	private function check_rate_limit() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- IP used only for hashing.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown';

		/**
		 * Filters the client IP address used for rate limiting.
		 *
		 * Useful for sites behind Cloudflare, load balancers, or reverse proxies
		 * where REMOTE_ADDR is the proxy IP, not the visitor's IP.
		 *
		 * @since 1.7.0
		 *
		 * @param string $ip The client IP address. Default: $_SERVER['REMOTE_ADDR'].
		 */
		$ip = apply_filters( 'gf_zero_spam_client_ip', $ip );

		$ip_hash = md5( $ip );
		$key     = 'gf_zs_rate_' . $ip_hash;

		$count = (int) get_transient( $key );

		/**
		 * Filters the maximum number of token requests allowed per IP per minute.
		 *
		 * Increase for sites behind corporate NAT or shared IP environments.
		 *
		 * @since 1.7.0
		 *
		 * @param int $limit The maximum request count per minute. Default: 30.
		 */
		$limit = (int) apply_filters( 'gf_zero_spam_rate_limit', self::RATE_LIMIT );

		if ( $count >= $limit ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'gravity-forms-zero-spam' ), [ 'status' => 429 ] );
		}

		set_transient( $key, $count + 1, 60 );

		return true;
	}
}
