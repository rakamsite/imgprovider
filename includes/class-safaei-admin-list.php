<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Safaei_Admin_List {
	public static function init() {
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'add_row_action' ), 10, 2 );
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'register_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		add_action( 'manage_posts_extra_tablenav', array( __CLASS__, 'render_quota_widget' ), 10, 1 );
	}

	public static function add_column( $columns ) {
		$columns['safaei_image_loader'] = __( 'Image Loader', 'safaei-auto-image-loader' );
		return $columns;
	}

	public static function render_column( $column, $post_id ) {
		if ( 'safaei_image_loader' !== $column ) {
			return;
		}

		$status = self::get_status_label( $post_id );
		echo esc_html( $status );
	}

	public static function add_row_action( $actions, $post ) {
		if ( 'product' !== $post->post_type || ! current_user_can( 'manage_woocommerce' ) ) {
			return $actions;
		}

		if ( Safaei_Usage::is_quota_reached() ) {
			$actions['safaei_enqueue'] = sprintf(
				'<span class="safaei-disabled-action">%s</span>',
				esc_html__( 'Find Image', 'safaei-auto-image-loader' )
			);
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'safaei_enqueue_job',
					'product_id' => $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			'safaei_enqueue_job'
		);

		$actions['safaei_enqueue'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Find Image', 'safaei-auto-image-loader' )
		);

		return $actions;
	}

	public static function register_bulk_action( $actions ) {
		if ( Safaei_Usage::is_quota_reached() ) {
			return $actions;
		}
		$actions['safaei_find_images'] = __( 'Safaei: Find Images', 'safaei-auto-image-loader' );
		return $actions;
	}

	public static function handle_bulk_action( $redirect_url, $action, $post_ids ) {
		if ( 'safaei_find_images' !== $action ) {
			return $redirect_url;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect_url;
		}

		if ( Safaei_Usage::is_quota_reached() ) {
			return add_query_arg( 'safaei_quota_reached', 1, $redirect_url );
		}

		$count = 0;
		foreach ( $post_ids as $post_id ) {
			if ( Safaei_Queue::enqueue_job( $post_id ) ) {
				$count++;
			}
		}

		return add_query_arg( 'safaei_enqueued', $count, $redirect_url );
	}

	public static function render_quota_widget( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}

		Safaei_Usage::render_widget( 'products_list' );
	}

	public static function get_status_label( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( $product && $product->get_image_id() ) {
			return __( 'Has image', 'safaei-auto-image-loader' );
		}

		$job = Safaei_Queue::get_job_status( $product_id );
		if ( ! $job ) {
			return __( 'Missing', 'safaei-auto-image-loader' );
		}

		switch ( $job->status ) {
			case 'queued':
			case 'running':
				return __( 'Queued', 'safaei-auto-image-loader' );
			case 'failed':
				return __( 'Failed', 'safaei-auto-image-loader' );
			case 'done':
				return __( 'Done', 'safaei-auto-image-loader' );
			default:
				return __( 'Missing', 'safaei-auto-image-loader' );
		}
	}
}
