<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Safaei_Queue {
	public static function init() {
		add_action( 'admin_post_safaei_enqueue_job', array( __CLASS__, 'handle_enqueue_action' ) );
		add_action( 'wp_ajax_safaei_enqueue_job', array( __CLASS__, 'ajax_enqueue_job' ) );
	}

	public static function enqueue_job( $product_id ) {
		global $wpdb;
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return false;
		}

		$table = $wpdb->prefix . Safaei_Image_Loader::JOBS_TABLE;
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE product_id = %d AND status IN ('queued','running') LIMIT 1",
				$product_id
			)
		);

		if ( $existing ) {
			return (int) $existing;
		}

		$now = current_time( 'mysql' );
		$inserted = $wpdb->insert(
			$table,
			array(
				'product_id' => $product_id,
				'status'     => 'queued',
				'attempts'   => 0,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);

		if ( $inserted ) {
			self::log( 'Enqueued job for product ' . $product_id );
			return (int) $wpdb->insert_id;
		}

		return false;
	}

	public static function handle_enqueue_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'safaei-auto-image-loader' ) );
		}
		check_admin_referer( 'safaei_enqueue_job' );

		if ( Safaei_Usage::is_quota_reached() ) {
			wp_safe_redirect( add_query_arg( 'safaei_quota_reached', 1, wp_get_referer() ?: admin_url( 'edit.php?post_type=product' ) ) );
			exit;
		}

		$product_id = absint( $_GET['product_id'] ?? 0 );
		if ( $product_id ) {
			self::enqueue_job( $product_id );
		}

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=product' ) );
		exit;
	}

	public static function ajax_enqueue_job() {
		check_ajax_referer( 'safaei_image_loader_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'safaei-auto-image-loader' ) ) );
		}

		if ( Safaei_Usage::is_quota_reached() ) {
			wp_send_json_error( array( 'message' => __( 'Daily quota reached', 'safaei-auto-image-loader' ) ) );
		}

		$product_id = absint( $_POST['product_id'] ?? 0 );
		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product.', 'safaei-auto-image-loader' ) ) );
		}

		$job_id = self::enqueue_job( $product_id );
		if ( $job_id ) {
			wp_send_json_success( array( 'job_id' => $job_id ) );
		}

		wp_send_json_error( array( 'message' => __( 'Could not enqueue job.', 'safaei-auto-image-loader' ) ) );
	}

	public static function get_job_status( $product_id ) {
		global $wpdb;
		$table = $wpdb->prefix . Safaei_Image_Loader::JOBS_TABLE;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE product_id = %d ORDER BY created_at DESC LIMIT 1",
				$product_id
			)
		);
	}

	public static function log( $message, $level = 'info' ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
		$logger->log( $level, $message, array( 'source' => 'safaei_image_loader' ) );
	}
}
