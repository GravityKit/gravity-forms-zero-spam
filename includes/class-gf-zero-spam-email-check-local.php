<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The email checks that run on this server with no outside call: placeholder addresses and
 * throwaway-inbox domains.
 *
 * The rules match GravityKit's email verifier (GravityKit/Demos, containers/src/verifier/checks.ts),
 * so an address gets the same answer here as from the gravitykit.com edition's remote check.
 *
 * @since TBD
 */
class GF_Zero_Spam_Email_Check_Local {

	/**
	 * Local parts people type to get past a form. Role addresses such as info@ or admin@ are left
	 * alone, because a business may use them.
	 *
	 * @since TBD
	 *
	 * @var string[]
	 */
	const PLACEHOLDER_LOCAL_PARTS = [
		'you',
		'your',
		'yourname',
		'your.name',
		'youremail',
		'your.email',
		'name',
		'email',
		'e-mail',
		'user',
		'username',
		'test',
		'testing',
		'example',
		'sample',
		'fake',
		'none',
		'noemail',
		'no-email',
		'nobody',
		'someone',
		'somebody',
		'asdf',
		'qwerty',
		'foo',
		'bar',
		'foobar',
		'john.doe',
		'jane.doe',
		'johndoe',
		'janedoe',
		'first.last',
		'firstname.lastname',
	];

	/**
	 * Domains reserved for examples (RFC 2606, RFC 6761) or copied from a form's hint text.
	 *
	 * @since TBD
	 *
	 * @var string
	 */
	const PLACEHOLDER_DOMAIN_PATTERN = '/(^|\.)(example(\.(com|net|org))?|test|invalid|localhost|yourcompany\.com|yourdomain\.com|yoursite\.com|company\.com)$/';

	/**
	 * Throwaway domains, keyed by domain, loaded on first use.
	 *
	 * @since TBD
	 *
	 * @var array<string,true>|null
	 */
	private static $disposable = null;

	/**
	 * Returns why an address cannot be a real contact address, or null when the local checks pass it.
	 *
	 * @since TBD
	 *
	 * @param string $email The submitted address.
	 *
	 * @return string|null `placeholder`, `disposable`, or null.
	 */
	public static function check( $email ) {
		$parts = self::split( (string) $email );

		if ( null === $parts ) {
			return null;
		}

		if ( self::is_placeholder( $parts['local'], $parts['domain'] ) ) {
			return 'placeholder';
		}

		if ( self::is_disposable( $parts['domain'] ) ) {
			return 'disposable';
		}

		return null;
	}

	/**
	 * Whether an address is a placeholder.
	 *
	 * @since TBD
	 *
	 * @param string $local  Local part, lowercase.
	 * @param string $domain Domain, lowercase.
	 *
	 * @return bool
	 */
	public static function is_placeholder( $local, $domain ) {
		return in_array( $local, self::PLACEHOLDER_LOCAL_PARTS, true ) || 1 === preg_match( self::PLACEHOLDER_DOMAIN_PATTERN, $domain );
	}

	/**
	 * Whether a domain, or any parent of it, is a throwaway-inbox provider.
	 *
	 * @since TBD
	 *
	 * @param string $domain Domain, lowercase.
	 *
	 * @return bool
	 */
	public static function is_disposable( $domain ) {
		$list   = self::disposable_domains();
		$labels = explode( '.', $domain );
		$count  = count( $labels );

		for ( $i = 0; $i < $count - 1; $i++ ) {
			if ( isset( $list[ implode( '.', array_slice( $labels, $i ) ) ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Splits an address into lowercase local part and domain.
	 *
	 * @since TBD
	 *
	 * @param string $email The address.
	 *
	 * @return array{local:string,domain:string}|null Null when there is no usable `@`.
	 */
	public static function split( $email ) {
		$email = strtolower( trim( $email ) );
		$at    = strrpos( $email, '@' );

		if ( false === $at || $at < 1 || strlen( $email ) - 1 === $at ) {
			return null;
		}

		return [
			'local'  => substr( $email, 0, $at ),
			'domain' => substr( $email, $at + 1 ),
		];
	}

	/**
	 * Returns the throwaway domain list.
	 *
	 * @since TBD
	 *
	 * @return array<string,true>
	 */
	private static function disposable_domains() {
		if ( null !== self::$disposable ) {
			return self::$disposable;
		}

		$json    = file_get_contents( __DIR__ . '/data/disposable-domains.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$domains = is_string( $json ) ? json_decode( $json, true ) : null;

		/**
		 * Modifies the list of throwaway-inbox domains the email check rejects.
		 *
		 * @since TBD
		 *
		 * @param string[] $domains Lowercase domains. A subdomain of a listed domain also matches.
		 */
		$domains = apply_filters( 'gf_zero_spam_email_check_disposable_domains', is_array( $domains ) ? $domains : [] );

		self::$disposable = array_fill_keys( array_map( 'strval', (array) $domains ), true );

		return self::$disposable;
	}
}
