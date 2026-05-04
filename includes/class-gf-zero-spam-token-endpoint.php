<?php
/**
 * Admin-ajax endpoint for token minting.
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
	 * Registers the admin-ajax endpoint hooks.
	 *
	 * @since 1.7.0
	 */
	public function __construct() {
		add_action( 'wp_ajax_gf_zero_spam_token', [ $this, 'handle_ajax' ] );
		add_action( 'wp_ajax_nopriv_gf_zero_spam_token', [ $this, 'handle_ajax' ] );
	}

	/**
	 * Handles the admin-ajax token request.
	 *
	 * @since 1.7.0
	 *
	 * @return void
	 */
	public function handle_ajax() {
		nocache_headers();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public endpoint; no nonce needed.
		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

		if ( ! $this->is_request_origin_allowed() ) {
			$this->log_debug( sprintf( 'rejecting mint for form %d: bad origin (origin=%s referer=%s sec-fetch-site=%s ip=%s ua=%s)', $form_id, $this->server_value( 'HTTP_ORIGIN' ), $this->server_value( 'HTTP_REFERER' ), $this->server_value( 'HTTP_SEC_FETCH_SITE' ), $this->client_ip(), $this->server_value( 'HTTP_USER_AGENT' ) ) );

			wp_send_json_error( __( 'Invalid request origin.', 'gravity-forms-zero-spam' ), 403 );
		}

		$result = $this->handle_token_request( $form_id );

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			$status     = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 500;

			$this->log_debug( sprintf( 'rejecting mint for form %d: %s (status=%d ip=%s)', $form_id, $result->get_error_code(), $status, $this->client_ip() ) );

			wp_send_json_error( $result->get_error_message(), $status );
		}

		$this->log_debug( sprintf( 'minted token for form %d (ip=%s)', $form_id, $this->client_ip() ) );

		wp_send_json( $result );
	}

	/**
	 * Checks whether the mint request comes from an allowed origin.
	 *
	 * Real browsers send `Origin` on POST fetch() requests (mandatory per Fetch spec
	 * for cross-origin and reliably present same-origin in evergreen browsers).
	 * Older browsers may omit `Origin` on same-origin XHR but include `Referer`.
	 * Trivial scripted clients (curl, requests, axios with default headers) send
	 * neither, which is the signature this check rejects.
	 *
	 * @since TBD
	 *
	 * @return bool True if the request origin is acceptable, false otherwise.
	 */
	private function is_request_origin_allowed() {
		/**
		 * Filters whether to enforce request-origin checks on the token mint endpoint.
		 *
		 * Disable on sites that proxy or rewrite Origin/Referer headers in a way that
		 * makes the check unreliable (rare, but possible behind certain WAFs).
		 *
		 * @since TBD
		 *
		 * @param bool $enforce Whether to enforce origin checks. Default true.
		 */
		if ( ! apply_filters( 'gf_zero_spam_enforce_mint_origin', true ) ) {
			return true;
		}

		$sec_fetch_site = $this->server_value( 'HTTP_SEC_FETCH_SITE' );

		// When Sec-Fetch-Site is present, it must indicate same-origin/site/none.
		// `none` covers user-initiated direct navigation, `same-site` covers subdomain forms.
		if ( '' !== $sec_fetch_site && ! in_array( $sec_fetch_site, [ 'same-origin', 'same-site', 'none' ], true ) ) {
			return false;
		}

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! is_string( $site_host ) || '' === $site_host ) {
			// Cannot determine our own host; fail open rather than break every mint.
			return true;
		}

		$origin = $this->server_value( 'HTTP_ORIGIN' );

		if ( '' !== $origin ) {
			$origin_host = wp_parse_url( $origin, PHP_URL_HOST );

			return is_string( $origin_host ) && 0 === strcasecmp( $origin_host, $site_host );
		}

		// No Origin — fall back to Referer (allowed because some browsers omit Origin
		// on same-origin XHR in older releases or restrictive privacy configurations).
		$referer = $this->server_value( 'HTTP_REFERER' );

		if ( '' !== $referer ) {
			$referer_host = wp_parse_url( $referer, PHP_URL_HOST );

			return is_string( $referer_host ) && 0 === strcasecmp( $referer_host, $site_host );
		}

		// Neither header present: real browsers issuing a POST fetch() send at least one.
		// A trivial scripted client typically sends neither.
		return false;
	}

	/**
	 * Returns a sanitized $_SERVER value or empty string.
	 *
	 * @since TBD
	 *
	 * @param string $key The $_SERVER key to read.
	 *
	 * @return string The sanitized value or empty string.
	 */
	private function server_value( $key ) {
		if ( ! isset( $_SERVER[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
	}

	/**
	 * Returns the client IP using the same filter the rate limiter uses.
	 *
	 * @since TBD
	 *
	 * @return string The client IP or 'unknown'.
	 */
	private function client_ip() {
		$ip = $this->server_value( 'REMOTE_ADDR' );

		if ( '' === $ip ) {
			$ip = 'unknown';
		}

		/** This filter is documented in includes/class-gf-zero-spam-token-endpoint.php. */
		return (string) apply_filters( 'gf_zero_spam_client_ip', $ip );
	}

	/**
	 * Writes a debug log line via the addon when WP_DEBUG is on.
	 *
	 * @since TBD
	 *
	 * @param string $message The message to log (will be prefixed with the method name).
	 *
	 * @return void
	 */
	private function log_debug( $message ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		if ( ! class_exists( 'GF_Zero_Spam_AddOn' ) ) {
			return;
		}

		GF_Zero_Spam_AddOn::get_instance()->log_debug( 'GF_Zero_Spam_Token_Endpoint: ' . $message );
	}

	/**
	 * Validates the request and mints a token.
	 *
	 * @since 1.7.0
	 *
	 * @param int $form_id The form ID to mint a token for.
	 *
	 * @return array{token: string, expires: int}|WP_Error
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

		/**
		 * Filters the token time-to-live in seconds for dynamically fetched tokens.
		 *
		 * @since 1.7.3
		 *
		 * @param int $ttl Token lifetime in seconds. Default 604800 (7 days).
		 */
		$ttl = (int) apply_filters( 'gf_zero_spam_token_ttl', GF_Zero_Spam_AddOn::get_instance()->get_token_ttl_seconds() );

		return [
			'token'   => GF_Zero_Spam_Token::mint( $form_id, $ttl ),
			'expires' => time() + $ttl,
		];
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
