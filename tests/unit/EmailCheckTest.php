<?php
/**
 * The email check on every install: placeholder and throwaway addresses are found locally, the
 * owner's action for each reason is applied at validation, and Flag marks the entry as spam.
 */
class EmailCheckTest extends WP_UnitTestCase {

	/**
	 * @var array
	 */
	private $form;

	public function set_up() {
		parent::set_up();

		GF_Zero_Spam_Email_Check::reset();

		$form_id = GFAPI::add_form(
			[
				'title'  => 'Email check ' . wp_generate_uuid4(),
				'fields' => [
					[
						'id'    => 1,
						'type'  => 'email',
						'label' => 'Email',
					],
				],
			]
		);

		$this->form = GFAPI::get_form( $form_id );

		$this->set_settings( [ GF_Zero_Spam_Email_Check::SETTING_ENABLED => '1' ] );
	}

	public function tear_down() {
		GF_Zero_Spam_Email_Check::reset();
		remove_all_filters( 'gf_zero_spam_email_check_verdict' );
		parent::tear_down();
	}

	private function set_settings( array $settings ) {
		GF_Zero_Spam_AddOn::get_instance()->update_plugin_settings( $settings );
	}

	private function validate( $email, $context = 'form-submit', $form = null ) {
		$form  = $form ?: $this->form;
		$field = GFAPI::get_field( $form, 1 );

		return ( new GF_Zero_Spam_Email_Check() )->validate_field(
			[
				'is_valid' => true,
				'message'  => '',
			],
			$email,
			$form,
			$field,
			$context
		);
	}

	private function is_spam() {
		return ( new GF_Zero_Spam_Email_Check() )->flag_entry( false, $this->form, [] );
	}

	/**
	 * @dataProvider placeholder_addresses
	 */
	public function test_finds_placeholder_addresses( $email ) {
		$this->assertSame( 'placeholder', GF_Zero_Spam_Email_Check_Local::check( $email ) );
	}

	public function placeholder_addresses() {
		return [
			[ 'you@company.com' ],
			[ 'email@email.com' ],
			[ 'test@gmail.com' ],
			[ 'jane@example.com' ],
			[ 'a@b.test' ],
			[ 'JohnDoe@Gmail.com' ],
		];
	}

	/**
	 * @dataProvider real_addresses
	 */
	public function test_passes_real_addresses( $email ) {
		$this->assertNull( GF_Zero_Spam_Email_Check_Local::check( $email ) );
	}

	public function real_addresses() {
		return [
			[ 'info@acme.io' ],
			[ 'zack@gravitykit.com' ],
			[ 'admin@shop.co' ],
			[ 'jane@gmail.com' ],
		];
	}

	public function test_finds_throwaway_domains_and_their_subdomains() {
		$this->assertSame( 'disposable', GF_Zero_Spam_Email_Check_Local::check( 'x@mailinator.com' ) );
		$this->assertSame( 'disposable', GF_Zero_Spam_Email_Check_Local::check( 'x@inbox.mailinator.com' ) );
		$this->assertFalse( GF_Zero_Spam_Email_Check_Local::is_disposable( 'gmail.com' ) );
	}

	public function test_flags_by_default_without_an_error() {
		$result = $this->validate( 'you@company.com' );

		$this->assertTrue( $result['is_valid'], 'Flag must not show the visitor an error.' );
		$this->assertTrue( $this->is_spam() );
	}

	public function test_block_shows_the_error_and_does_not_flag() {
		$this->set_settings(
			[
				GF_Zero_Spam_Email_Check::SETTING_ENABLED     => '1',
				'gf_zero_spam_email_check_action_disposable' => 'block',
			]
		);

		$result = $this->validate( 'x@mailinator.com' );

		$this->assertFalse( $result['is_valid'] );
		$this->assertSame( 'Please use a permanent email address, not a temporary one.', $result['message'] );
		$this->assertFalse( $this->is_spam() );
	}

	public function test_note_only_neither_blocks_nor_flags() {
		$this->set_settings(
			[
				GF_Zero_Spam_Email_Check::SETTING_ENABLED      => '1',
				'gf_zero_spam_email_check_action_placeholder' => 'log',
			]
		);

		$this->assertTrue( $this->validate( 'you@company.com' )['is_valid'] );
		$this->assertFalse( $this->is_spam() );
	}

	public function test_a_real_address_leaves_the_entry_alone() {
		$this->assertTrue( $this->validate( 'jane@gmail.com' )['is_valid'] );
		$this->assertFalse( $this->is_spam() );
	}

	public function test_skips_submissions_that_are_not_from_the_web_form() {
		$this->validate( 'you@company.com', 'api-submit' );

		$this->assertFalse( $this->is_spam() );
	}

	public function test_skips_a_form_with_the_check_switched_off() {
		$form                                             = $this->form;
		$form[ GF_Zero_Spam_Email_Check::FORM_SETTING ] = '0';

		$this->validate( 'you@company.com', 'form-submit', $form );

		$this->assertFalse( $this->is_spam() );
	}

	public function test_skips_people_who_can_edit_entries() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->validate( 'you@company.com' );

		$this->assertFalse( $this->is_spam() );
	}

	public function test_registers_no_hooks_while_switched_off() {
		$this->set_settings( [ GF_Zero_Spam_Email_Check::SETTING_ENABLED => '0' ] );

		$check = new GF_Zero_Spam_Email_Check();
		$check->init();

		$this->assertFalse( has_filter( 'gform_field_validation', [ $check, 'validate_field' ] ) );
	}

	public function test_uses_the_confirmation_input_value() {
		$this->validate( [ 'you@company.com', 'you@company.com' ] );

		$this->assertTrue( $this->is_spam() );
	}

	public function test_a_remote_verdict_comes_in_through_the_filter_with_its_suggestion() {
		add_filter(
			'gf_zero_spam_email_check_verdict',
			static function ( $verdict ) {
				return $verdict ?: [
					'reason'     => 'no_mail_server',
					'suggestion' => 'jane@gmail.com',
				];
			}
		);
		$this->set_settings(
			[
				GF_Zero_Spam_Email_Check::SETTING_ENABLED         => '1',
				'gf_zero_spam_email_check_action_no_mail_server' => 'block',
			]
		);

		$result = $this->validate( 'jane@gmial-not-real.com' );

		$this->assertFalse( $result['is_valid'] );
		$this->assertSame( "This email address can't receive mail. Please check it for typos. Did you mean jane@gmail.com?", $result['message'] );
	}

	public function test_ignores_an_unknown_reason_from_the_filter() {
		add_filter(
			'gf_zero_spam_email_check_verdict',
			static function () {
				return [ 'reason' => 'made_up' ];
			}
		);

		$this->assertTrue( $this->validate( 'jane@gmail.com' )['is_valid'] );
		$this->assertFalse( $this->is_spam() );
	}

	public function test_flagged_entry_gets_a_note_naming_the_reason() {
		$this->validate( 'you@company.com' );

		$entry_id = GFAPI::add_entry(
			[
				'form_id' => $this->form['id'],
				'1'       => 'you@company.com',
			]
		);

		( new GF_Zero_Spam_Email_Check() )->add_notes( GFAPI::get_entry( $entry_id ), $this->form );

		$notes = GFAPI::get_notes( [ 'entry_id' => $entry_id ] );

		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'placeholder', $notes[0]->value );
		$this->assertStringContainsString( 'marked as spam', $notes[0]->value );
	}
}
