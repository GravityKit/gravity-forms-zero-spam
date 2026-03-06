<?php
/**
 * Stateless HMAC-SHA256 token minting and validation.
 *
 * @since 1.7.0
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class GF_Zero_Spam_Token {

	/**
	 * Mints a signed anti-spam token for a given form.
	 *
	 * @since 1.7.0
	 *
	 * @param int $form_id The Gravity Forms form ID.
	 * @param int $ttl     Token time-to-live in seconds. Default 600 (10 minutes).
	 *
	 * @return string The signed token in base64url(payload).base64url(signature) format.
	 */
	public static function mint( int $form_id, int $ttl = 600 ): string {
		$iat          = time();
		$exp          = $iat + $ttl;
		$nonce        = bin2hex( random_bytes( 16 ) );
		$salt_version = self::get_salt_version();

		$payload   = "{$form_id}|{$iat}|{$exp}|{$nonce}|{$salt_version}";
		$signature = hash_hmac( 'sha256', $payload, self::get_site_secret( $salt_version ) );

		return self::base64url_encode( $payload ) . '.' . self::base64url_encode( $signature );
	}

	/**
	 * Validates a signed anti-spam token.
	 *
	 * @since 1.7.0
	 *
	 * @param string $token            The token to validate.
	 * @param int    $expected_form_id The form ID the token must match.
	 *
	 * @return array{valid: bool, reason: string} Validation result with reason code.
	 */
	public static function validate( string $token, int $expected_form_id ): array {
		if ( '' === $token ) {
			return [
				'valid'  => false,
				'reason' => 'token_missing',
			];
		}

		$parts = explode( '.', $token );

		if ( 2 !== count( $parts ) ) {
			return [
				'valid'  => false,
				'reason' => 'bad_format',
			];
		}

		$payload_raw = self::base64url_decode( $parts[0] );
		$sig_raw     = self::base64url_decode( $parts[1] );

		if ( false === $payload_raw || false === $sig_raw ) {
			return [
				'valid'  => false,
				'reason' => 'bad_format',
			];
		}

		$fields = explode( '|', $payload_raw );

		if ( 5 !== count( $fields ) ) {
			return [
				'valid'  => false,
				'reason' => 'bad_format',
			];
		}

		$form_id      = (int) $fields[0];
		$exp          = (int) $fields[2];
		$salt_version = (int) $fields[4];

		if ( $form_id !== $expected_form_id ) {
			return [
				'valid'  => false,
				'reason' => 'form_mismatch',
			];
		}

		// Allow 2-minute clock skew.
		if ( time() > $exp + 120 ) {
			return [
				'valid'  => false,
				'reason' => 'expired',
			];
		}

		// Only accept tokens signed with the current or previous salt version.
		$current_version = self::get_salt_version();
		$prev_version    = self::get_previous_salt_version();

		if ( $salt_version !== $current_version && $salt_version !== $prev_version ) {
			return [
				'valid'  => false,
				'reason' => 'sig_invalid',
			];
		}

		// Verify signature against the token's claimed salt version.
		$expected_sig = hash_hmac( 'sha256', $payload_raw, self::get_site_secret( $salt_version ) );

		if ( hash_equals( $expected_sig, $sig_raw ) ) {
			return [
				'valid'  => true,
				'reason' => '',
			];
		}

		// Try the other accepted version during rotation window.
		$other_version = ( $salt_version === $current_version ) ? $prev_version : $current_version;

		if ( null !== $other_version ) {
			$other_sig = hash_hmac( 'sha256', $payload_raw, self::get_site_secret( $other_version ) );

			if ( hash_equals( $other_sig, $sig_raw ) ) {
				return [
					'valid'  => true,
					'reason' => '',
				];
			}
		}

		return [
			'valid'  => false,
			'reason' => 'sig_invalid',
		];
	}

	/**
	 * Derives a site-specific signing secret for a given salt version.
	 *
	 * Uses WordPress AUTH_KEY and SECURE_AUTH_KEY constants, which are unique per
	 * site and never exposed in HTML output.
	 *
	 * @since 1.7.0
	 *
	 * @param int $salt_version The salt version number.
	 *
	 * @return string The derived HMAC secret.
	 */
	public static function get_site_secret( int $salt_version ): string {
		$auth_key        = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
		$secure_auth_key = defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '';

		// Fall back to a DB-stored secret if wp-config.php salts are missing.
		if ( '' === $auth_key && '' === $secure_auth_key ) {
			$fallback = get_option( 'gf_zero_spam_fallback_secret' );

			if ( ! $fallback ) {
				$fallback = wp_generate_password( 64, true, true );

				if ( ! add_option( 'gf_zero_spam_fallback_secret', $fallback, '', false ) ) {
					$fallback = get_option( 'gf_zero_spam_fallback_secret' );
				}
			}

			$auth_key        = $fallback;
			$secure_auth_key = $fallback;
		}

		return hash_hmac( 'sha256', $salt_version . '|' . $auth_key, $secure_auth_key );
	}

	/**
	 * Returns the current salt version.
	 *
	 * @since 1.7.0
	 *
	 * @return int The current salt version number.
	 */
	public static function get_salt_version(): int {
		return (int) get_option( 'gf_zero_spam_salt_version', 1 );
	}

	/**
	 * Returns the previous salt version, if one exists during a rotation window.
	 *
	 * @since 1.7.0
	 *
	 * @return int|null The previous salt version, or null if not in a rotation window.
	 */
	public static function get_previous_salt_version(): ?int {
		$prev = get_option( 'gf_zero_spam_prev_salt_version' );

		if ( false === $prev || '' === $prev ) {
			return null;
		}

		return (int) $prev;
	}

	/**
	 * Encodes data using base64url (RFC 4648 section 5).
	 *
	 * @since 1.7.0
	 *
	 * @param string $data The data to encode.
	 *
	 * @return string The base64url-encoded string (no padding).
	 */
	public static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Decodes a base64url-encoded string (RFC 4648 section 5).
	 *
	 * @since 1.7.0
	 *
	 * @param string $data The base64url-encoded string.
	 *
	 * @return string|false The decoded data, or false on failure.
	 */
	public static function base64url_decode( string $data ) {
		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true );

		return $decoded;
	}
}
