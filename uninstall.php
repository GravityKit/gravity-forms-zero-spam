<?php
/**
 * Gravity Forms Zero Spam Uninstaller
 *
 * Removes all plugin data when the plugin is deleted.
 *
 * @package GravityFormsZeroSpam
 * @since 1.5.0
 */

// Exit if uninstall is not called from WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load the cron class if not already loaded.
if ( ! class_exists( 'GF_Zero_Spam_Cron' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'class-gf-zero-spam-cron.php';
}

// Clean up cron jobs.
if ( class_exists( 'GF_Zero_Spam_Cron' ) ) {
	GF_Zero_Spam_Cron::cleanup();
}

// Remove plugin options.
delete_option( 'gf_zero_spam_key' );
delete_option( 'gravityformsaddon_GF_Zero_Spam_AddOn_settings' );
delete_option( 'gf_zero_spam_report_last_date' );

// Remove any transients that might have been set.
delete_transient( 'gf_zero_spam_report_cache' );