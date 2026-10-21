<?php
/**
 * The gravitykit.com edition's remote email check: it needs a connection with the `email_check`
 * scope, sends only the address with the site token, and passes the address on every failure.
 */

require_once dirname( __DIR__, 2 ) . '/includes/gravitykit/class-gf-zero-spam-email-check-remote.php';
require_once dirname( __DIR__, 2 ) . '/includes/gravitykit/class-gf-zero-spam-gravitykit.php';

/**
 * Stands in for Foundation's ConnectClient.
 */
class Zero_Spam_Tests_Connect_Client {
	/** @var array|WP_Error */
	public $answer;

	/** @var array[] */
	public $calls = [];

	public function __construct( $answer ) {
		$this->answer = $answer;
	}

	public function signed_request( $method, $path, $body = null ) {
		$this->calls[] = [ $method, $path, $body ];

		return $this->answer;
	}
}

class EmailCheckRemoteTest extends WP_UnitTestCase {

	/** @var Zero_Spam_Tests_Connect_Client */
	private $client;

	/** @var array|null */
	private $connection;

	/** @var array[] Requests the check sent. */
	private $requests = [];

	/** @var callable Answers each request: fn( array $args ): array|WP_Error */
	private $responder;

	public function set_up() {
		parent::set_up();

		$this->client     = new Zero_Spam_Tests_Connect_Client(
			[
				'token'      => 'v1.site-token',
				'expires_at' => time() + DAY_IN_SECONDS,
			]
		);
		$this->connection = [
			'connection_id' => '11111111-2222-4333-8444-555555555555',
			'scopes'        => [ 'licenses', 'email_check' ],
		];
		$this->requests   = [];
		$this->responder  = function () {
			return $this->json( 200, [ 'result' => 'accept', 'reason' => null, 'suggestion' => null, 'limited' => false ] );
		};

		add_filter( 'gf_zero_spam_tests_http', [ $this, 'answer_http' ], 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'gf_zero_spam_tests_http', [ $this, 'answer_http' ], 10 );

		foreach ( [ GF_Zero_Spam_Email_Check_Remote::TOKEN_TRANSIENT, GF_Zero_Spam_Email_Check_Remote::PAUSE_TRANSIENT, GF_Zero_Spam_Email_Check_Remote::FAILURES_TRANSIENT ] as $transient ) {
			delete_transient( $transient );
		}

		parent::tear_down();
	}

	public function answer_http( $response, $args, $url ) {
		if ( GF_Zero_Spam_Email_Check_Remote::DEFAULT_ENDPOINT !== $url ) {
			return $response;
		}

		$this->requests[] = $args;

		return call_user_func( $this->responder, $args );
	}

	private function json( $status, array $body ) {
		return [
			'headers'  => [],
			'body'     => wp_json_encode( $body ),
			'response' => [ 'code' => $status, 'message' => '' ],
			'cookies'  => [],
		];
	}

	private function remote() {
		return new GF_Zero_Spam_Email_Check_Remote(
			function ( $scope ) {
				return 'site' === $scope ? [ 'connection' => $this->connection, 'client' => $this->client ] : [ 'connection' => null, 'client' => null ];
			}
		);
	}

	private function unique_email() {
		return 'jane+' . wp_generate_password( 8, false ) . '@acme-' . wp_generate_password( 6, false ) . '.io';
	}

	public function test_sends_nothing_without_a_connection() {
		$this->connection = null;
		$remote           = $this->remote();

		$this->assertNull( $remote->check( $this->unique_email() ) );
		$this->assertSame( [], $this->requests );
		$this->assertSame( 'not_connected', $remote->connection_state() );
	}

	public function test_sends_nothing_when_the_connection_lacks_the_scope() {
		$this->connection['scopes'] = [ 'licenses' ];
		$remote                     = $this->remote();

		$this->assertNull( $remote->check( $this->unique_email() ) );
		$this->assertSame( [], $this->requests );
		$this->assertSame( 'needs_scope', $remote->connection_state() );
		$this->assertSame( [], $this->client->calls, 'No token is asked for without the scope.' );
	}

	public function test_sends_only_the_address_with_the_site_token() {
		$email = strtolower( $this->unique_email() );

		$this->remote()->check( strtoupper( $email ) );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'Bearer v1.site-token', $this->requests[0]['headers']['Authorization'] );
		$this->assertSame( [ 'email' => $email ], json_decode( $this->requests[0]['body'], true ) );
		$this->assertEquals( 3.0, $this->requests[0]['timeout'] );
		$this->assertSame( [ [ 'POST', '/email-check/token', [] ] ], $this->client->calls );
	}

	public function test_reuses_the_token_until_it_expires() {
		$remote = $this->remote();

		$remote->check( $this->unique_email() );
		$remote->check( $this->unique_email() );

		$this->assertCount( 2, $this->requests );
		$this->assertCount( 1, $this->client->calls );
	}

	public function test_returns_a_rejection_with_its_suggestion_and_caches_it() {
		$this->responder = function () {
			return $this->json( 200, [ 'result' => 'reject', 'reason' => 'no_mailbox', 'suggestion' => 'jane@gmail.com', 'limited' => false ] );
		};
		$email  = $this->unique_email();
		$remote = $this->remote();

		$expected = [ 'reason' => 'no_mailbox', 'suggestion' => 'jane@gmail.com' ];

		$this->assertSame( $expected, $remote->check( $email ) );
		$this->assertSame( $expected, $remote->check( $email ) );
		$this->assertCount( 1, $this->requests, 'The second ask is answered from the cache.' );
	}

	public function test_drops_a_suggestion_that_is_not_an_address() {
		$this->responder = function () {
			return $this->json( 200, [ 'result' => 'reject', 'reason' => 'typo', 'suggestion' => '<script>', 'limited' => false ] );
		};

		$this->assertSame( [ 'reason' => 'typo', 'suggestion' => '' ], $this->remote()->check( $this->unique_email() ) );
	}

	public function test_ignores_an_unknown_reason() {
		$this->responder = function () {
			return $this->json( 200, [ 'result' => 'reject', 'reason' => 'mystery', 'limited' => false ] );
		};

		$this->assertNull( $this->remote()->check( $this->unique_email() ) );
	}

	public function test_waits_as_long_as_the_service_asks() {
		$this->responder = function () {
			return $this->json( 200, [ 'result' => 'accept', 'reason' => null, 'limited' => true, 'retry_after' => 600 ] );
		};
		$remote = $this->remote();

		$this->assertNull( $remote->check( $this->unique_email() ) );
		$this->assertNull( $remote->check( $this->unique_email() ) );
		$this->assertCount( 1, $this->requests );
	}

	public function test_replaces_a_rejected_token_once_then_retries() {
		$calls           = 0;
		$this->responder = function () use ( &$calls ) {
			$calls++;

			return 1 === $calls
				? $this->json( 401, [ 'code' => 'invalid_token' ] )
				: $this->json( 200, [ 'result' => 'reject', 'reason' => 'no_mail_server', 'limited' => false ] );
		};

		$this->assertSame( 'no_mail_server', $this->remote()->check( $this->unique_email() )['reason'] );
		$this->assertCount( 2, $this->requests );
		$this->assertCount( 2, $this->client->calls );
	}

	public function test_gives_up_after_a_second_rejected_token() {
		$this->responder = function () {
			return $this->json( 401, [ 'code' => 'invalid_token' ] );
		};

		$this->assertNull( $this->remote()->check( $this->unique_email() ) );
		$this->assertCount( 2, $this->requests );
	}

	public function test_stops_calling_after_three_failures() {
		$this->responder = function () {
			return new WP_Error( 'http_request_failed', 'timed out' );
		};
		$remote = $this->remote();

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertNull( $remote->check( $this->unique_email() ) );
		}

		$this->assertCount( 3, $this->requests );
	}

	public function test_a_server_error_counts_as_a_failure() {
		$this->responder = function () {
			return $this->json( 500, [ 'error' => 'down' ] );
		};

		$this->assertNull( $this->remote()->check( $this->unique_email() ) );
		$this->assertSame( 1, (int) get_transient( GF_Zero_Spam_Email_Check_Remote::FAILURES_TRANSIENT ) );
	}

	public function test_a_site_outside_the_beta_waits_before_asking_for_a_token_again() {
		$this->client->answer = new WP_Error( 'not_permitted', 'no', [ 'status' => 403 ] );
		$remote               = $this->remote();

		$this->assertNull( $remote->check( $this->unique_email() ) );
		$this->assertNull( $remote->check( $this->unique_email() ) );
		$this->assertCount( 1, $this->client->calls );
		$this->assertSame( [], $this->requests );
	}

	public function test_stops_checking_a_visitor_after_five_rejections() {
		$this->responder = function () {
			return $this->json( 200, [ 'result' => 'reject', 'reason' => 'no_mailbox', 'limited' => false ] );
		};
		$remote = $this->remote();

		for ( $i = 0; $i < 7; $i++ ) {
			$remote->check( $this->unique_email() );
		}

		$this->assertCount( 5, $this->requests );
	}

	public function test_adds_the_scope_to_the_connection_request() {
		$this->assertSame( [ 'licenses', 'email_check' ], ( new GF_Zero_Spam_GravityKit( $this->remote() ) )->add_scope( [ 'licenses' ] ) );
		$this->assertSame( [ 'email_check' ], ( new GF_Zero_Spam_GravityKit( $this->remote() ) )->add_scope( null ) );
	}

	public function test_asks_the_service_only_when_turned_on_and_the_local_checks_passed() {
		$this->responder = function () {
			return $this->json( 200, [ 'result' => 'reject', 'reason' => 'no_mail_server', 'limited' => false ] );
		};
		$edition = new GF_Zero_Spam_GravityKit( $this->remote() );
		$local   = [ 'reason' => 'disposable', 'suggestion' => '' ];

		GF_Zero_Spam_AddOn::get_instance()->update_plugin_settings( [ GF_Zero_Spam_GravityKit::SETTING_REMOTE => '0' ] );
		$this->assertNull( $edition->add_remote_verdict( null, $this->unique_email() ) );
		$this->assertSame( [], $this->requests, 'Nothing is sent while the opt-in is off.' );

		GF_Zero_Spam_AddOn::get_instance()->update_plugin_settings( [ GF_Zero_Spam_GravityKit::SETTING_REMOTE => '1' ] );
		$this->assertSame( $local, $edition->add_remote_verdict( $local, $this->unique_email() ) );
		$this->assertSame( [], $this->requests, 'A local verdict is enough.' );

		$this->assertSame( 'no_mail_server', $edition->add_remote_verdict( null, $this->unique_email() )['reason'] );
		$this->assertCount( 1, $this->requests );
	}

	public function test_the_settings_page_lists_the_remote_reasons_and_opt_in() {
		$edition = new GF_Zero_Spam_GravityKit( $this->remote() );
		$reasons = $edition->add_reasons( [ 'placeholder' => 'x' ] );
		$fields  = $edition->add_settings_fields( [ [ 'name' => GF_Zero_Spam_Email_Check::SETTING_ENABLED ], [ 'name' => 'next' ] ] );

		$this->assertSame( [ 'placeholder', 'typo', 'no_mail_server', 'no_mailbox' ], array_keys( $reasons ) );
		$this->assertSame( [ GF_Zero_Spam_Email_Check::SETTING_ENABLED, GF_Zero_Spam_GravityKit::SETTING_REMOTE, 'next' ], wp_list_pluck( $fields, 'name' ) );
		$this->assertFalse( $fields[1]['default_value'], 'Sending addresses is off until an administrator turns it on.' );
	}
}
