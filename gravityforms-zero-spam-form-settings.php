<?php

if ( ! class_exists( 'GFForms' ) || ! is_callable( array( 'GFForms', 'include_addon_framework' ) ) ) {
	return;
}

GFForms::include_addon_framework();

/**
 * @since 1.2
 */
class GF_Zero_Spam_AddOn extends GFAddOn {

	protected $_slug        = 'gf-zero-spam';
	protected $_path        = GF_ZERO_SPAM_BASENAME;
	protected $_full_path   = __FILE__;
	protected $_title       = 'Gravity Forms Zero Spam';
	protected $_short_title = 'Zero Spam';

	const REPORT_LAST_SENT_DATE_OPTION = 'gf_zero_spam_report_last_date';

	public function init() {
		parent::init();

		add_filter( 'gform_form_settings_fields', array( $this, 'add_settings_field' ), 10, 2 );
		add_filter( 'gform_tooltips', array( $this, 'add_tooltip' ) );

		// Adding at 20 priority so anyone filtering the default priority (10) will define the default, but the
		// per-form setting may still _override_ the default.
		add_filter( 'gf_zero_spam_check_key_field', array( $this, 'filter_gf_zero_spam_check_key_field' ), 20, 2 );

		add_filter( 'cron_schedules', array( $this, 'add_monthly_schedule' ) );
		add_action( 'gf_zero_spam_send_report', array( $this, 'send_report' ) );
		add_action( 'gform_after_submission', array( $this, 'check_entry_limit' ), 10, 2 );
	}

	/**
	 * @inheritdoc
	 * @since 1.4
	 */
	public function get_menu_icon(): string {
		return '<svg style="height: 28px; width: 37px; max-width: 37px" width="1358" height="1056" viewBox="0 0 1358 1056" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1058.52 262.19L767.31 478.75C729.344 507.251 683.153 522.66 635.68 522.66C588.207 522.66 542.016 507.251 504.05 478.75L212.84 262.19C204 255.619 198.133 245.806 196.529 234.909C194.924 224.012 197.714 212.925 204.285 204.085C210.856 195.245 220.669 189.378 231.566 187.774C242.463 186.169 253.55 188.959 262.39 195.53L553.6 412.13C577.276 429.899 606.078 439.505 635.68 439.505C665.282 439.505 694.084 429.899 717.76 412.13L1008.97 195.53C1013.34 192.276 1018.32 189.916 1023.6 188.585C1028.89 187.253 1034.39 186.975 1039.78 187.768C1045.17 188.56 1050.36 190.407 1055.04 193.204C1059.72 196 1063.8 199.691 1067.05 204.065C1070.31 208.439 1072.67 213.412 1074 218.698C1075.33 223.985 1075.61 229.481 1074.82 234.875C1074.02 240.269 1072.18 245.454 1069.38 250.133C1066.59 254.813 1062.89 258.896 1058.52 262.15V262.19ZM1276.96 974.8C1276.55 975.21 1276.07 975.48 1275.65 975.87C1226.01 1025.06 1159.49 1053.49 1089.62 1055.37C1019.76 1057.25 951.81 1032.45 899.59 986H189.38C84.95 986 0 901 0 796.62V189.45C0 84.99 85 0 189.38 0H1081.96C1186.43 0 1271.41 85 1271.41 189.45V580.16C1298.08 605.314 1319.45 635.55 1334.26 669.087C1349.06 702.625 1357.01 738.786 1357.63 775.442C1358.25 812.097 1351.53 848.507 1337.87 882.526C1324.21 916.546 1303.87 947.488 1278.07 973.53C1277.64 973.91 1277.37 974.39 1276.96 974.8ZM1274.6 780.04C1274.6 673.94 1188.29 587.63 1082.2 587.63C1045.54 587.597 1009.64 598.108 978.78 617.91L1244.32 883.45C1264.12 852.595 1274.63 816.7 1274.6 780.04ZM189.38 902.97H835.82C810.095 851.575 801.133 793.407 810.198 736.653C819.262 679.898 845.896 627.415 886.35 586.59C886.74 586.16 887.02 585.68 887.43 585.27C887.84 584.86 888.32 584.58 888.74 584.19C927.343 545.965 976.404 520.03 1029.73 509.658C1083.06 499.286 1138.26 504.941 1188.38 525.91V189.45C1188.35 161.235 1177.13 134.186 1157.18 114.235C1137.22 94.284 1110.17 83.0618 1081.96 83.03H189.38C161.182 83.0829 134.156 94.3155 114.227 114.265C94.2984 134.214 83.0938 161.252 83.07 189.45V796.62C83.1017 824.809 94.3111 851.835 114.24 871.772C134.169 891.708 161.191 902.928 189.38 902.97ZM1082.2 972.44C1118.86 972.467 1154.75 961.956 1185.61 942.16L920.07 676.62C900.272 707.478 889.762 743.377 889.79 780.04C889.79 886.13 976.07 972.44 1082.2 972.44Z" fill="black"/></svg>';
	}

	/**
	 * Use per-form settings to determine whether to check for spam.
	 *
	 * @param bool  $check_key_field
	 * @param array $form
	 *
	 * @return array|mixed
	 */
	public function filter_gf_zero_spam_check_key_field( $check_key_field = true, $form = array() ) {

		// The setting has been set, but it's not enabled.
		if ( isset( $form['enableGFZeroSpam'] ) && empty( $form['enableGFZeroSpam'] ) ) {
			return false;
		}

		return $check_key_field;
	}

	/**
	 * Include custom tooltip text for the Zero Spam setting in the Form Settings page
	 *
	 * @param array $tooltips Key/Value pair of tooltip/tooltip text
	 *
	 * @return array
	 */
	public function add_tooltip( $tooltips ) {

		$tooltips['enableGFZeroSpam'] = esc_html__( 'Enable to fight spam using a simple, effective method that is more effective than the built-in anti-spam honeypot.', 'gf-zero-spam' );

		return $tooltips;
	}

	/**
	 * Adds the Zero Spam field to the "Form Options" settings group in GF 2.5+
	 *
	 * @see https://docs.gravityforms.com/gform_form_settings_fields/
	 *
	 * @param array $fields Form Settings fields.
	 * @param array $form The current form
	 *
	 * @return array
	 */
	function add_settings_field( $fields, $form = array() ) {

		$fields['form_options']['fields'][] = array(
			'name'          => 'enableGFZeroSpam',
			'type'          => 'toggle',
			'label'         => esc_html__( 'Prevent spam using Gravity Forms Zero Spam', 'gf-zero-spam' ),
			'tooltip'       => gform_tooltip( 'enableGFZeroSpam', '', true ),
			'default_value' => apply_filters( 'gf_zero_spam_check_key_field', true, $form ),
		);

		return $fields;
	}

	/**
	 * Logging is not currently supported.
	 *
	 * @param array $plugins An array of plugins that support logging.
	 *
	 * @return array
	 */
	public function set_logging_supported( $plugins ) {

		return $plugins;
	}


	/**
	 * Register addon global settings
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {


		$email_body = '<h2>' . esc_html_x( 'Spam report', 'The heading inside the email body.', 'gf-zero-spam') . '</h2>';

		// translators: Do not translate the placeholders inside the curly brackets, like this {{placeholders}}.
		$email_body .= wpautop( esc_html__( 'You have received {{total_spam_count}} spam emails across the following forms:', 'gf-zero-spam' ) );
		$email_body .= '{{spam_report_list}}';
		// translators: Do not translate the placeholders inside the curly brackets, like this {{placeholders}}.
		$email_body .= wpautop( esc_html__( 'To turn off this message, visit {{settings_link}}.', 'gf-zero-spam' ) );

		$email_message_description = wpautop( esc_html__( 'The following variables may be used in the email message:', 'gf-zero-spam' ) );
		$email_message_description .= '<ul class="ul-disc">';
		$email_message_description .= '<li style="list-style: disc;"><code>{{site_name}}</code> - ' . esc_html__( 'The total number of spam emails received.', 'gf-zero-spam' ) . '</li>';
		$email_message_description .= '<li style="list-style: disc;"><code>{{total_spam_count}}</code> - ' . esc_html__( 'The total number of spam emails received.', 'gf-zero-spam' ) . '</li>';
		$email_message_description .= '<li style="list-style: disc;"><code>{{spam_report_list}}</code> - ' . esc_html__( 'A list of spam reports.', 'gf-zero-spam' ) . '</li>';
		$email_message_description .= '<li style="list-style: disc;"><code>{{settings_url}}</code> - ' . esc_html__( 'The URL to the plugin settings page.', 'gf-zero-spam' ) . '</li>';
		$email_message_description .= '</ul>';

		return array(
			array(
				'title'  => esc_html__( 'Gravity Forms Zero Spam', 'gf-zero-spam' ),
				'fields' => array(
					array(
						'label'         => esc_html__( 'Email Frequency', 'gf-zero-spam' ),
						'description'   => esc_html__( 'How frequently should spam report emails be sent.', 'gf-zero-spam' ),
						'type'          => 'select',
						'name'          => 'gf_zero_spam_email_frequency',
						'choices'       => array(
							array(
								'label' => __( 'Choose One', 'gf-zero-spam' ),
								'value' => '',
							),
							array(
								'label' => __( 'Daily', 'gf-zero-spam' ),
								'value' => 'daily',
							),
							array(
								'label' => __( 'Weekly', 'gf-zero-spam' ),
								'value' => 'weekly',
							),
							array(
								'label' => __( 'Monthly', 'gf-zero-spam' ),
								'value' => 'monthly',
							),
							array(
								'label' => __( 'Entry Limit', 'gf-zero-spam' ),
								'value' => 'entry_limit',
							),
						),
						'required'      => true,
						'save_callback' => function( $field, $value ) {
							return $this->add_cron_job( $value );
						},
					),
					array(
						'label'               => esc_html__( 'Entry Limit', 'gf-zero-spam' ),
						'description'         => esc_html__( 'A spam report email will be sent when the number of spam messages reaches this number.', 'gf-zero-spam' ),
						'type'                => 'text',
						'input_type'          => 'number',
						'min'                 => 1,
						'value'               => 1,
						'name'                => 'gf_zero_spam_entry_limit',
						'dependency'          => array(
							'live'   => true,
							'fields' => array(
								array(
									'field'  => 'gf_zero_spam_email_frequency',
									'values' => array( 'entry_limit' ),
								),
							),
						),
						'validation_callback' => function( $field, $value ) {
							if ( (int) $value < 1 ) {
								$field->set_error( esc_html__( 'Entry limit has to be 1 or more.', 'gf-zero-spam' ) );
							}
						},
					),

					array(
						'label'               => esc_html__( 'Spam Report Email', 'gf-zero-spam' ),
						'description'         => esc_html__( 'Send spam report to this email address.', 'gf-zero-spam' ),
						'type'                => 'text',
						'input_type'          => 'email',
						'value'               => get_bloginfo( 'admin_email' ),
						'name'                => 'gf_zero_spam_report_email',
						'required'            => true,
						'validation_callback' => function( $field, $value ) {
							if ( is_email( $value ) ) {
								return;
							}
							$field->set_error( esc_html__( 'The email entered is invalid.', 'gf-zero-spam' ) );
						},
						'dependency'          => array(
							'live'   => true,
							'fields' => array(
								array(
									'field'  => 'gf_zero_spam_email_frequency',
								),
							),
						),
					),

					array(
						'name'     => 'gf_zero_spam_subject',
						'label'    => esc_html__( 'Subject', 'gf-zero-spam' ),
						'type'     => 'text',
						'value'    => 'Your Gravity Forms spam report for {{site_name}}',
						'required' => true,
						'dependency'          => array(
							'live'   => true,
							'fields' => array(
								array(
									'field'  => 'gf_zero_spam_email_frequency',
								),
							),
						),
					),
					array(
						'name'       => 'gf_zero_spam_message',
						'label'      => esc_html__( 'Email Message', 'gf-zero-spam' ),
						'description' => $email_message_description,
						'type'       => 'textarea',
						'value'      => trim( $email_body ),
						'use_editor' => true,
						'required'   => true,
						'dependency'          => array(
							'live'   => true,
							'fields' => array(
								array(
									'field'  => 'gf_zero_spam_email_frequency',
								),
							),
						),
					),

				),
			),

		);

	}

	/**
	 * Check if entry limit has been reached.
	 *
	 * @param array $entry
	 * @param array $form
	 * @return void
	 */
	public function check_entry_limit( $entry, $form ) {
		if ( $entry['status'] !== 'spam' ) {
			return;
		}

		if ( ! isset( $form['enableGFZeroSpam'] ) || (int) $form['enableGFZeroSpam'] === 0 ) {
			return;
		}

		$freq  = $this->get_plugin_setting( 'gf_zero_spam_email_frequency' );
		$limit = (int) $this->get_plugin_setting( 'gf_zero_spam_entry_limit' );
		if ( $freq !== 'entry_limit' || $limit <= 0 ) {
			return;
		}

		$results = $this->get_latest_spam_entries();
		if ( count( $results ) >= $limit ) {
			$this->send_report();
		}

	}

	/**
	 * Add monthly interval to schedules.
	 *
	 * @param array $schedules
	 * @return array
	 */
	public function add_monthly_schedule( $schedules ) {

		$schedules['monthly'] = array(
			'interval' => 2635200,
			'display'  => __( 'Once a month', 'gf-zero-spam' ),
		);

		return $schedules;
	}

	/**
	 * Send spam report.
	 *
	 * @return boolean
	 */
	public function send_report() {
		$email = $this->get_plugin_setting( 'gf_zero_spam_report_email' );

		if ( ! is_email( $email ) ) {
			return;
		}

		$subject = $this->get_plugin_setting( 'gf_zero_spam_subject' );
		$message = $this->get_plugin_setting( 'gf_zero_spam_message' );

		if ( $subject === '' || $message === '' ) {
			return;
		}

		$results = $this->get_latest_spam_entries();
		if ( empty( $results ) ) {
			return $output;
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$success = wp_mail( $email, $this->replace_tags( $subject ), $this->replace_tags( $message ), $headers );
		if ( $success ) {
			update_option( self::REPORT_LAST_SENT_DATE_OPTION, current_time( 'mysql' ) );
		}

		return false;
	}

	/**
	 * Replace tags in email template.
	 *
	 * @param string $value
	 * @return string
	 */
	private function replace_tags( $value ) {
		$replace = array(
			'{{site_name}}'        => get_bloginfo( 'name' ),
			'{{total_spam_count}}' => $this->get_spam_count(),
			'{{spam_report_list}}' => $this->get_report_list(),
			'{{settings_url}}'     => esc_url( admin_url( 'admin.php?page=gf_settings&subview=gf-zero-spam' ) ),
		);

		foreach ( $replace as $tag => $val ) {
			$value = str_replace( $tag, $val, $value );
		}

		return $value;
	}

	/**
	 * Get latest spam entries.
	 *
	 * @return array
	 */
	private function get_latest_spam_entries() {
		global $wpdb;

		$sql = $wpdb->prepare( "SELECT id,form_id FROM {$wpdb->prefix}gf_entry WHERE status=%s AND date_created > %s ORDER BY form_id", 'spam', $this->get_last_report_date() );

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Returns the date the last report was set.
	 *
	 * @param null|mixed $default
	 *
	 * @return false|string False, if last report date is not set. Otherwise, the date the last report was sent as a date string.
	 */
	private function get_last_report_date( $default = null ) {

		$default = is_null( $default ) ? date( 'Y-m-d', 0 ) : $default;

		return get_option( self::REPORT_LAST_SENT_DATE_OPTION, $default );
	}

	/**
	 * Get report list.
	 *
	 * @return void
	 */
	private function get_report_list() {
		$results = $this->get_latest_spam_entries();

		$output = '';
		if ( empty( $results ) ) {
			return $output;
		}

		$counted_results = array();
		foreach ( $results as $result ) {
			if ( isset( $counted_results[ $result['form_id'] ] ) ) {
				$counted_results[ $result['form_id'] ]++;
			} else {
				$counted_results[ $result['form_id'] ] = 1;
			}
		}

		$last_date = $this->get_last_report_date( false );

		$output .= '<ul>';
		foreach ( $counted_results as $form_id => $count ) {
			$form = GFAPI::get_form( $form_id );

			if ( ! isset( $form['enableGFZeroSpam'] ) || (int) $form['enableGFZeroSpam'] === 0 ) {
				continue;
			}

			$args = array(
				'id'     => $form_id,
				'filter' => 'spam',
			);

			if ( $last_date ) {
				$args['s']        = $last_date;
				$args['field_id'] = 'date_created';
				$args['orderby']  = '0';
				$args['order']    = 'ASC';
				$args['operator'] = '>';
			}

			$link = add_query_arg(
				$args,
				admin_url( 'admin.php?page=gf_entries&view=entries' )
			);

			$output .= '<li><a href="' . esc_url( $link ) . '">' . esc_html( $form['title'] ) . ' ' . (int) $count . '</a></li>';
		}

		$output .= '</ul>';
		return $output;
	}

	/**
	 * Get spam count.
	 *
	 * @return boolean
	 */
	private function get_spam_count() {
		$results = $this->get_latest_spam_entries();

		return count( $results );
	}

	/**
	 * Add cron job for spam reporting.
	 *
	 * @param string $frequency
	 * @return string
	 */
	public function add_cron_job( $frequency ) {
		if ( empty( $frequency ) ) {
			return $frequency;
		}

		wp_clear_scheduled_hook( 'gf_zero_spam_send_report' );

		if ( $frequency === 'entry_limit' ) {
			return $frequency;
		}

		wp_schedule_event( time(), $frequency, 'gf_zero_spam_send_report' );

		return $frequency;
	}


}

new GF_Zero_Spam_AddOn();
