<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Safaei_Image_Loader {
	const OPTION_KEY = 'safaei_image_loader_settings';
	const CRON_HOOK = 'safaei_image_loader_cron';
	const JOBS_TABLE = 'safaei_img_jobs';
	const CANDIDATES_TABLE = 'safaei_img_candidates';

	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		Safaei_Settings::init();
		Safaei_Queue::init();
		Safaei_Worker::init();
		Safaei_Admin_List::init();
		Safaei_Metabox::init();

		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedule' ) );
	}

	public static function activate() {
		self::create_tables();
		self::maybe_add_default_options();		
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'safaei_image_loader_interval', self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	public static function register_cron_schedule( $schedules ) {
		$options = self::get_settings();
		$interval_minutes = max( 1, absint( $options['cron_interval_minutes'] ) );
		$schedules['safaei_image_loader_interval'] = array(
			'interval' => $interval_minutes * MINUTE_IN_SECONDS,
			'display'  => sprintf( __( 'Every %d minutes (Safaei Image Loader)', 'safaei-auto-image-loader' ), $interval_minutes ),
		);
		return $schedules;
	}

	public static function get_settings() {
		$defaults = self::default_settings();
		$options  = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $options ) ) {
			$options = array();
		}
		return wp_parse_args( $options, $defaults );
	}

	public static function default_settings() {
		return array(
			'enabled'                => true,
			'google_api_key'         => 'AIzaSyCAkepq4FEkP3syQrJDvVTHxNktQJB3mcY',
			'google_cx'              => '21396b9aab32e4f6a',
			'query_template'         => '{refcode} {brand} {name}',
			'fallback_queries'       => "{refcode}\n{refcode} mercedes\n{refcode} actros\n{name} {brand} {refcode}",
			'max_results_per_product'=> 6,
			'max_retries'            => 3,
			'batch_size'             => 20,
			'min_image_width'        => 400,
			'allowed_domains'        => '',
			'set_gallery'            => false,
			'gallery_count'          => 3,
			'skip_if_has_image'      => true,
			'cron_interval_minutes'  => 5,
		);
	}

	private static function maybe_add_default_options() {
		if ( false === get_option( self::OPTION_KEY ) ) {
			add_option( self::OPTION_KEY, self::default_settings() );
		}
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$jobs_table = $wpdb->prefix . self::JOBS_TABLE;
		$candidates_table = $wpdb->prefix . self::CANDIDATES_TABLE;

		$jobs_sql = "CREATE TABLE {$jobs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			attempts INT NOT NULL DEFAULT 0,
			last_error TEXT NULL,
			last_query TEXT NULL,
			last_source_url TEXT NULL,
			last_attachment_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY product_id (product_id),
			KEY status (status)
		) {$charset_collate};";

		$candidates_sql = "CREATE TABLE {$candidates_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id BIGINT UNSIGNED NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL,
			image_url TEXT NOT NULL,
			thumb_url TEXT NULL,
			title TEXT NULL,
			domain VARCHAR(255) NULL,
			width INT NULL,
			height INT NULL,
			score FLOAT NULL,
			is_used TINYINT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY job_id (job_id),
			KEY product_id (product_id)
		) {$charset_collate};";

		dbDelta( $jobs_sql );
		dbDelta( $candidates_sql );
	}
}
