<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

use GravityKit\ZeroSpam\Foundation\AccountConnection\ConnectClient;
use GravityKit\ZeroSpam\Foundation\AccountConnection\TokenStore;

/**
 * Asks GravityKit's email check whether an address can receive mail (gravitykit.com edition).
 *
 * Sends only the address. Every failure (no connection, a slow or broken service, a quota) passes the
 * address, so a visitor's submission is never held up by this check.
 *
 * @since TBD
 */
class GF_Zero_Spam_Email_Check_Remote {

	/**
	 * Where the check is sent. Override with the GF_ZERO_SPAM_EMAIL_CHECK_URL constant.
	 *
	 * @since TBD
	 *
	 * @var string
	 */
	const DEFAULT_ENDPOINT = 'https://email-check.gravitykit.com/v1/check';

	/**
	 * Connection scope the token route requires.
	 *
	 * @since TBD
	 *
	 * @var string
	 */
	const SCOPE = 'email_check';

	/**
	 * Reasons the service may return.
	 *
	 * @since TBD
	 *
	 * @var string[]
	 */
	const REASONS = [ 'placeholder', 'disposable', 'typo', 'no_mail_server', 'no_mailbox' ];

	/**
	 * Transient holding the site token.
	 *
	 * @since TBD
	 *
	 * @var string
	 */
	const TOKEN_TRANSIENT = 'gf_zero_spam_email_check_token';

	/**
	 * Transient set while calls are paused: the service asked us to wait, or it kept failing.
	 *
	 * @since TBD
	 *
	 * @var string
	 */
	const PAUSE_TRANSIENT = 'gf_zero_spam_email_check_pause';

	/**
	 * Transient counting recent failures, for the circuit breaker.
	 *
	 * @since TBD
	 *
	 * @var string
	 */
	const FAILURES_TRANSIENT = 'gf_zero_spam_email_check_failures';

	/**
	 * Failures within FAILURE_WINDOW that pause calls for FAILURE_PAUSE.
	 *
	 * @since TBD
	 *
	 * @var int
	 */
	const FAILURE_LIMIT = 3;

	/**
	 * @since TBD
	 *
	 * @var int Seconds.
	 */
	const FAILURE_WINDOW = 5 * MINUTE_IN_SECONDS;

	/**
	 * @since TBD
	 *
	 * @var int Seconds.
	 */
	const FAILURE_PAUSE = 10 * MINUTE_IN_SECONDS;

	/**
	 * Rejections per visitor IP within REJECTION_WINDOW before that IP stops being checked, so the form
	 * cannot be used to test which addresses exist.
	 *
	 * @since TBD
	 *
	 * @var int
	 */
	const REJECTION_LIMIT = 5;

	/**
	 * @since TBD
	 *
	 * @var int Seconds.
	 */
	const REJECTION_WINDOW = 10 * MINUTE_IN_SECONDS;

	/**
	 * Longest pause the service can ask for.
	 *
	 * @since TBD
	 *
	 * @var int Seconds.
	 */
	const MAX_PAUSE = DAY_IN_SECONDS;

	/**
	 * Builds the Foundation client for a connection; replaced in tests.
	 *
	 * @since TBD
	 *
	 * @var callable|null fn( string $scope ): array{connection:array|null,client:object|null}
	 */
	private $connection_factory;

	/**
	 * @since TBD
	 *
	 * @param callable|null $connection_factory Optional factory, for tests.
	 */
	public function __construct( ?callable $connection_factory = null ) {
		$this->connection_factory = $connection_factory;
	}

	/**
	 * Returns the problem with an address, or null when the service passes it or cannot answer.
	 *
	 * @since TBD
	 *
	 * @param string $email The address, trimmed.
	 *
	 * @return array{reason:string,suggestion:string}|null
	 */
	public function check( $email ) {
		$email     = strtolower( trim( (string) $email ) );
		$cache_key = 'gf_zs_ec_' . md5( wp_hash( $email ) );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return empty( $cached['reason'] ) ? null : $cached;
		}

		if ( get_transient( self::PAUSE_TRANSIENT ) || $this->visitor_is_limited() ) {
			return null;
		}

		$answer = $this->ask( $email, false );

		if ( null === $answer ) {
			return null;
		}

		if ( ! empty( $answer['limited'] ) ) {
			$this->pause( (int) ( $answer['retry_after'] ?? 0 ) );

			return null;
		}

		$is_reject = 'reject' === ( $answer['result'] ?? '' ) && in_array( $answer['reason'] ?? '', self::REASONS, true );

		if ( ! $is_reject ) {
			set_transient( $cache_key, [ 'reason' => '' ], DAY_IN_SECONDS );

			return null;
		}

		$verdict = [
			'reason'     => (string) $answer['reason'],
			'suggestion' => is_string( $answer['suggestion'] ?? null ) && is_email( $answer['suggestion'] ) ? $answer['suggestion'] : '',
		];

		set_transient( $cache_key, $verdict, HOUR_IN_SECONDS );
		$this->count_rejection();

		return $verdict;
	}

	/**
	 * Calls the service. A rejected token is replaced once.
	 *
	 * @since TBD
	 *
	 * @param string $email       The address, lowercase.
	 * @param bool   $is_retry    Whether this is the retry with a fresh token.
	 *
	 * @return array|null The decoded answer, or null on any failure.
	 */
	private function ask( $email, $is_retry ) {
		$token = $this->get_token( $is_retry );

		if ( '' === $token ) {
			return null;
		}

		/**
		 * Modifies how long a submission waits for the email check.
		 *
		 * @since TBD
		 *
		 * @param float $timeout Seconds. WordPress rounds anything under 1 up to 1.
		 */
		$timeout = (float) apply_filters( 'gf_zero_spam_email_check_timeout', 3.0 );

		$response = wp_remote_post(
			self::endpoint(),
			[
				'timeout' => $timeout,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( [ 'email' => $email ] ),
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->record_failure();

			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $status && ! $is_retry ) {
			delete_transient( self::TOKEN_TRANSIENT );

			return $this->ask( $email, true );
		}

		$answer = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status || ! is_array( $answer ) ) {
			$this->record_failure();

			return null;
		}

		return $answer;
	}

	/**
	 * Returns a site token, fetching one from gravitykit.com through the account connection when needed.
	 *
	 * @since TBD
	 *
	 * @param bool $force Ignore the stored token.
	 *
	 * @return string The token, or an empty string when none can be had now.
	 */
	public function get_token( $force = false ) {
		$stored = get_transient( self::TOKEN_TRANSIENT );

		if ( ! $force && is_array( $stored ) ) {
			return (string) ( $stored['token'] ?? '' );
		}

		$connection = $this->connection();

		if ( null === $connection ) {
			return '';
		}

		$result = $connection['client']->signed_request( 'POST', '/email-check/token', [] );

		if ( is_wp_error( $result ) || empty( $result['token'] ) || ! is_string( $result['token'] ) ) {
			$code = is_wp_error( $result ) ? $result->get_error_code() : 'bad_response';

			// A site outside the beta, or without the scope, is refused the same way all day: ask again later.
			$retry = in_array( $code, [ 'not_permitted', 'scope_not_granted', 'rest_no_route' ], true ) ? 6 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS;

			set_transient(
                self::TOKEN_TRANSIENT,
                [
					'token' => '',
					'error' => $code,
				],
				$retry
            );

			return '';
		}

		$lifetime = (int) ( $result['expires_at'] ?? 0 ) - time() - 5 * MINUTE_IN_SECONDS;

		set_transient( self::TOKEN_TRANSIENT, [ 'token' => $result['token'] ], max( MINUTE_IN_SECONDS, min( DAY_IN_SECONDS, $lifetime ) ) );

		return $result['token'];
	}

	/**
	 * Returns the site's account connection when it holds the email-check scope.
	 *
	 * @since TBD
	 *
	 * @return array{connection:array,client:object}|null
	 */
	public function connection() {
		foreach ( [ 'site', 'network' ] as $scope ) {
			$found = $this->connection_factory
				? call_user_func( $this->connection_factory, $scope )
				: self::foundation_connection( $scope );

			$connection = $found['connection'] ?? null;

			if ( ! is_array( $connection ) || ! empty( $connection['decrypt_failed'] ) || empty( $connection['connection_id'] ) ) {
				continue;
			}

			if ( ! in_array( self::SCOPE, (array) ( $connection['scopes'] ?? [] ), true ) ) {
				continue;
			}

			return $found;
		}

		return null;
	}

	/**
	 * Describes the connection state for the settings page.
	 *
	 * @since TBD
	 *
	 * @return string `ready`, `needs_scope` (connected before the email check existed), or `not_connected`.
	 */
	public function connection_state() {
		if ( null !== $this->connection() ) {
			return 'ready';
		}

		foreach ( [ 'site', 'network' ] as $scope ) {
			$found = $this->connection_factory ? call_user_func( $this->connection_factory, $scope ) : self::foundation_connection( $scope );

			if ( ! empty( $found['connection']['connection_id'] ) ) {
				return 'needs_scope';
			}
		}

		return 'not_connected';
	}

	/**
	 * Returns the endpoint URL.
	 *
	 * @since TBD
	 *
	 * @return string
	 */
	public static function endpoint() {
		$url = defined( 'GF_ZERO_SPAM_EMAIL_CHECK_URL' ) ? (string) GF_ZERO_SPAM_EMAIL_CHECK_URL : self::DEFAULT_ENDPOINT;

		/**
		 * Modifies where the email check is sent.
		 *
		 * @since TBD
		 *
		 * @param string $url The endpoint.
		 */
		return (string) apply_filters( 'gf_zero_spam_email_check_endpoint', $url );
	}

	/**
	 * Reads one connection slot through the bundled Foundation.
	 *
	 * @since TBD
	 *
	 * @param string $scope `site` or `network`.
	 *
	 * @return array{connection:array|null,client:object|null}
	 */
	private static function foundation_connection( $scope ) {
		if ( ! class_exists( TokenStore::class ) || ! class_exists( ConnectClient::class ) ) {
			return [
				'connection' => null,
				'client'     => null,
			];
		}

		$store = new TokenStore( 'network' === $scope ? TokenStore::SCOPE_NETWORK : TokenStore::SCOPE_SITE );

		return [
			'connection' => $store->get(),
			'client'     => new ConnectClient( $store ),
		];
	}

	/**
	 * Pauses calls for as long as the service asked, within limits.
	 *
	 * @since TBD
	 *
	 * @param int $seconds Requested pause.
	 *
	 * @return void
	 */
	private function pause( $seconds ) {
		if ( $seconds > 0 ) {
			set_transient( self::PAUSE_TRANSIENT, 1, min( self::MAX_PAUSE, $seconds ) );
		}
	}

	/**
	 * Counts a failure and opens the circuit after FAILURE_LIMIT in FAILURE_WINDOW.
	 *
	 * @since TBD
	 *
	 * @return void
	 */
	private function record_failure() {
		$failures = (int) get_transient( self::FAILURES_TRANSIENT ) + 1;

		if ( $failures >= self::FAILURE_LIMIT ) {
			delete_transient( self::FAILURES_TRANSIENT );
			$this->pause( self::FAILURE_PAUSE );

			return;
		}

		set_transient( self::FAILURES_TRANSIENT, $failures, self::FAILURE_WINDOW );
	}

	/**
	 * Transient counting this visitor IP's recent rejections.
	 *
	 * @since TBD
	 *
	 * @return string
	 */
	private function visitor_key() {
		$ip = class_exists( 'GFFormsModel' ) ? (string) GFFormsModel::get_ip() : '';

		return 'gf_zs_ec_ip_' . md5( wp_hash( $ip ) );
	}

	/**
	 * Whether this visitor IP has had too many rejections recently.
	 *
	 * @since TBD
	 *
	 * @return bool
	 */
	private function visitor_is_limited() {
		return (int) get_transient( $this->visitor_key() ) >= self::REJECTION_LIMIT;
	}

	/**
	 * Counts one rejection for this visitor IP.
	 *
	 * @since TBD
	 *
	 * @return void
	 */
	private function count_rejection() {
		$key = $this->visitor_key();

		set_transient( $key, (int) get_transient( $key ) + 1, self::REJECTION_WINDOW );
	}
}
