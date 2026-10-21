<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The gravitykit.com edition's additions: the remote email check, its opt-in setting, the account
 * connection scope it needs, and the privacy policy text.
 *
 * @since TBD
 */
class GF_Zero_Spam_GravityKit {

	/**
	 * Setting that sends addresses to GravityKit's email check. Off until an administrator turns it on.
	 *
	 * @since TBD
	 *
	 * @var string
	 */
	const SETTING_REMOTE = 'gf_zero_spam_email_check_remote';

	/**
	 * @since TBD
	 *
	 * @var GF_Zero_Spam_GravityKit|null
	 */
	private static $instance = null;

	/**
	 * @since TBD
	 *
	 * @var GF_Zero_Spam_Email_Check_Remote
	 */
	private $remote;

	/**
	 * @since TBD
	 *
	 * @param GF_Zero_Spam_Email_Check_Remote|null $remote Remote client; replaced in tests.
	 */
	public function __construct( ?GF_Zero_Spam_Email_Check_Remote $remote = null ) {
		$this->remote = $remote ?: new GF_Zero_Spam_Email_Check_Remote();
	}

	/**
	 * @since TBD
	 *
	 * @return GF_Zero_Spam_GravityKit
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers the hooks.
	 *
	 * @since TBD
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'gk/foundation/connection/requested-scopes', [ $this, 'add_scope' ] );
		add_action( 'gk/foundation/connection/disconnected', [ $this, 'forget_token' ] );
		add_filter( 'gf_zero_spam_email_check_verdict', [ $this, 'add_remote_verdict' ], 10, 2 );
		add_filter( 'gf_zero_spam_email_check_setting_reasons', [ $this, 'add_reasons' ] );
		add_filter( 'gf_zero_spam_email_check_settings_fields', [ $this, 'add_settings_fields' ] );
		add_action( 'admin_init', [ $this, 'add_privacy_policy_content' ] );
	}

	/**
	 * Asks for the email-check scope when the site connects to a GravityKit.com account.
	 *
	 * @since TBD
	 *
	 * @param mixed $scopes Requested scopes.
	 *
	 * @return array
	 */
	public function add_scope( $scopes ) {
		$scopes   = is_array( $scopes ) ? $scopes : [];
		$scopes[] = GF_Zero_Spam_Email_Check_Remote::SCOPE;

		return array_values( array_unique( $scopes ) );
	}

	/**
	 * Drops the stored token, which belongs to the connection that just ended.
	 *
	 * @since TBD
	 *
	 * @return void
	 */
	public function forget_token() {
		delete_transient( GF_Zero_Spam_Email_Check_Remote::TOKEN_TRANSIENT );
	}

	/**
	 * Whether addresses may be sent to GravityKit's email check.
	 *
	 * @since TBD
	 *
	 * @return bool
	 */
	public static function is_remote_enabled() {
		return ! empty( GF_Zero_Spam_AddOn::get_instance()->get_plugin_setting( self::SETTING_REMOTE ) );
	}

	/**
	 * Adds the remote check's verdict when the local checks passed the address.
	 *
	 * @since TBD
	 *
	 * @param array|null $verdict The local verdict.
	 * @param string     $email   The address.
	 *
	 * @return array|null
	 */
	public function add_remote_verdict( $verdict, $email ) {
		if ( null !== $verdict || ! self::is_remote_enabled() ) {
			return $verdict;
		}

		return $this->remote->check( $email );
	}

	/**
	 * Adds the reasons only the remote check finds to the settings page.
	 *
	 * @since TBD
	 *
	 * @param array<string,string> $reasons Reason => label.
	 *
	 * @return array<string,string>
	 */
	public function add_reasons( $reasons ) {
		return array_merge(
			(array) $reasons,
			[
				'typo'           => __( 'Mistyped addresses, such as jane@gmial.com', 'gravity-forms-zero-spam' ),
				'no_mail_server' => __( 'Domains with no mail server', 'gravity-forms-zero-spam' ),
				'no_mailbox'     => __( 'Mailboxes that do not exist', 'gravity-forms-zero-spam' ),
			]
		);
	}

	/**
	 * Adds the opt-in setting for the remote check, right after the main toggle.
	 *
	 * @since TBD
	 *
	 * @param array $fields Email Check settings fields.
	 *
	 * @return array
	 */
	public function add_settings_fields( $fields ) {
		$remote_field = [
			'name'          => self::SETTING_REMOTE,
			'label'         => esc_html__( 'Check with GravityKit', 'gravity-forms-zero-spam' ),
			'type'          => 'toggle',
			'default_value' => false,
			'description'   => $this->remote_description(),
			'dependency'    => [
				'live'   => true,
				'fields' => [ [ 'field' => GF_Zero_Spam_Email_Check::SETTING_ENABLED ] ],
			],
		];

		array_splice( $fields, 1, 0, [ $remote_field ] );

		return $fields;
	}

	/**
	 * Explains what the remote check sends, and the connection it needs.
	 *
	 * @since TBD
	 *
	 * @return string HTML.
	 */
	private function remote_description() {
		$description = '<p>' . esc_html__( 'Also checks that the address has a mail server and a mailbox, and suggests a fix for common typos. When this is on, each email address submitted on your forms is sent to GravityKit\'s email check service. Only the address is sent. If the service is slow or unavailable, submissions always go through.', 'gravity-forms-zero-spam' ) . '</p>';

		$description .= '<p><a href="https://www.gravitykit.com/privacy-policy/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'What is sent and why', 'gravity-forms-zero-spam' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'gravity-forms-zero-spam' ) . '</span></a></p>';

		$connect_url = admin_url( 'admin.php?page=gk_licenses' );

		switch ( $this->remote->connection_state() ) {
			case 'not_connected':
				$notice = esc_html__( 'Connect this site to your GravityKit account to use this check. A free account is enough.', 'gravity-forms-zero-spam' );
				break;
			case 'needs_scope':
				$notice = esc_html__( 'This site was connected to your GravityKit account before the email check existed. Reconnect it and approve the email check to use this.', 'gravity-forms-zero-spam' );
				break;
			default:
				$notice = '';
		}

		if ( '' !== $notice ) {
			$description .= '<p><strong>' . $notice . '</strong> <a href="' . esc_url( $connect_url ) . '">' . esc_html__( 'Manage the connection', 'gravity-forms-zero-spam' ) . '</a></p>';
		}

		return $description;
	}

	/**
	 * Suggests privacy policy text for the site owner, under Settings > Privacy, while the remote check is on.
	 *
	 * @since TBD
	 *
	 * @return void
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) || ! self::is_remote_enabled() ) {
			return;
		}

		$content = '<p>' . esc_html__( 'When you submit a form on this site, the email address you enter may be sent to GravityKit\'s email check service to confirm it can receive mail. Only the address is sent. GravityKit does not store the address; it keeps only a scrambled reference to the result for up to 30 days. To check the address, the service may contact your email provider\'s mail server.', 'gravity-forms-zero-spam' ) . '</p>';

		wp_add_privacy_policy_content( 'Gravity Forms Zero Spam', wp_kses_post( $content ) );
	}
}
