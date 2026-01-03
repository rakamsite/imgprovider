<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Safaei_Metabox {
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_safaei_search_candidates', array( __CLASS__, 'ajax_search_candidates' ) );
		add_action( 'wp_ajax_safaei_set_candidate', array( __CLASS__, 'ajax_set_candidate' ) );
	}

	public static function add_metabox() {
		add_meta_box(
			'safaei-image-loader',
			__( 'Safaei Image Loader', 'safaei-auto-image-loader' ),
			array( __CLASS__, 'render_metabox' ),
			'product',
			'side',
			'default'
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'safaei-image-loader-admin',
			SAFAEI_IMAGE_LOADER_URL . 'assets/admin.js',
			array( 'jquery' ),
			SAFAEI_IMAGE_LOADER_VERSION,
			true
		);

		wp_localize_script(
			'safaei-image-loader-admin',
			'safaeiImageLoader',
			array(
				'nonce'       => wp_create_nonce( 'safaei_image_loader_nonce' ),
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'productId'   => get_the_ID(),
				'setText'     => __( 'Set Image', 'safaei-auto-image-loader' ),
				'searchText'  => __( 'Search Now', 'safaei-auto-image-loader' ),
				'errorText'   => __( 'An error occurred.', 'safaei-auto-image-loader' ),
				'quotaReached'=> Safaei_Usage::is_quota_reached(),
				'quotaText'   => __( 'Daily quota reached. Search is paused until quota resets.', 'safaei-auto-image-loader' ),
			)
		);
	}

	public static function render_metabox( $post ) {
		$status = Safaei_Admin_List::get_status_label( $post->ID );
		$job = Safaei_Queue::get_job_status( $post->ID );
		$refcode = get_post_meta( $post->ID, '_safaei_refcode', true );
		$last_error = $job ? $job->last_error : '';
		$quota_reached = Safaei_Usage::is_quota_reached();
		?>
		<p><strong><?php esc_html_e( 'Status:', 'safaei-auto-image-loader' ); ?></strong> <?php echo esc_html( $status ); ?></p>
		<p><strong><?php esc_html_e( 'Refcode:', 'safaei-auto-image-loader' ); ?></strong> <?php echo esc_html( $refcode ); ?></p>
		<?php Safaei_Usage::render_widget( 'product_metabox' ); ?>
		<?php if ( $last_error ) : ?>
			<p><strong><?php esc_html_e( 'Last error:', 'safaei-auto-image-loader' ); ?></strong> <?php echo esc_html( $last_error ); ?></p>
		<?php endif; ?>
		<p>
			<button type="button" class="button" id="safaei-search-now" <?php disabled( $quota_reached ); ?>><?php esc_html_e( 'Search Now', 'safaei-auto-image-loader' ); ?></button>
			<button type="button" class="button" id="safaei-enqueue-job" <?php disabled( $quota_reached ); ?>><?php esc_html_e( 'Retry', 'safaei-auto-image-loader' ); ?></button>
		</p>
		<div id="safaei-candidates" style="max-height:240px; overflow:auto;"></div>
		<?php
	}

	public static function ajax_search_candidates() {
		check_ajax_referer( 'safaei_image_loader_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'safaei-auto-image-loader' ) ) );
		}

		$product_id = absint( $_POST['product_id'] ?? 0 );
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => __( 'Product not found.', 'safaei-auto-image-loader' ) ) );
		}

		if ( Safaei_Usage::is_quota_reached() ) {
			wp_send_json_error( array( 'message' => __( 'Daily quota reached', 'safaei-auto-image-loader' ) ) );
		}

		$options = Safaei_Image_Loader::get_settings();
		$refcode = get_post_meta( $product_id, '_safaei_refcode', true );
		if ( ! $refcode ) {
			wp_send_json_error( array( 'message' => __( 'Missing refcode.', 'safaei-auto-image-loader' ) ) );
		}

		$queries = Safaei_Worker::build_queries( $product, $refcode, $options );
		$candidates = array();

		foreach ( $queries as $query ) {
			$new_candidates = Safaei_Provider_Google::fetch_candidates( $query, $options['max_results_per_product'] );
			if ( is_wp_error( $new_candidates ) ) {
				wp_send_json_error( array( 'message' => $new_candidates->get_error_message() ) );
			}
			foreach ( $new_candidates as $candidate ) {
				$candidate['query'] = $query;
				$candidates[] = $candidate;
			}
			if ( count( $candidates ) >= $options['max_results_per_product'] ) {
				break;
			}
		}

		$candidates = Safaei_Worker::filter_and_score_candidates( $candidates, $options );
		$candidates = array_slice( $candidates, 0, $options['max_results_per_product'] );

		wp_send_json_success( array( 'candidates' => $candidates ) );
	}

	public static function ajax_set_candidate() {
		check_ajax_referer( 'safaei_image_loader_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'safaei-auto-image-loader' ) ) );
		}

		$product_id = absint( $_POST['product_id'] ?? 0 );
		$image_url = esc_url_raw( $_POST['image_url'] ?? '' );
		if ( ! $product_id || ! $image_url ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'safaei-auto-image-loader' ) ) );
		}

		$attachment_id = Safaei_Worker::download_and_attach( $image_url, $product_id );
		if ( ! $attachment_id ) {
			wp_send_json_error( array( 'message' => __( 'Download failed.', 'safaei-auto-image-loader' ) ) );
		}

		set_post_thumbnail( $product_id, $attachment_id );
		update_post_meta( $product_id, '_safaei_img_last_attachment_id', $attachment_id );
		update_post_meta( $product_id, '_safaei_img_last_source_url', $image_url );
		update_post_meta( $product_id, '_safaei_img_last_run_at', time() );
		update_post_meta( $product_id, '_safaei_img_last_status', 'done' );

		wp_send_json_success( array( 'attachment_id' => $attachment_id ) );
	}
}
