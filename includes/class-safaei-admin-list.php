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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_footer-edit.php', array( __CLASS__, 'render_modal' ) );

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

		$refcode = get_post_meta( $post->ID, '_safaei_refcode', true );

		$actions['safaei_enqueue'] = sprintf(
			'<a href="%s" class="safaei-find-image" data-product-id="%d" data-refcode="%s">%s</a>',
			esc_url( $url ),
			(int) $post->ID,
			esc_attr( $refcode ),
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

	public static function enqueue_assets( $hook ) {
		if ( 'edit.php' !== $hook ) {
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
				'nonce'              => wp_create_nonce( 'safaei_image_loader_nonce' ),
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'setText'            => __( 'Set Image', 'safaei-auto-image-loader' ),
				'searchText'         => __( 'Search Now', 'safaei-auto-image-loader' ),
				'errorText'          => __( 'An error occurred.', 'safaei-auto-image-loader' ),
				'modalTitle'         => __( 'Find Image', 'safaei-auto-image-loader' ),
				'refcodeLabel'       => __( 'Refcode', 'safaei-auto-image-loader' ),
				'searchPlaceholder'  => __( 'Type to customize the search query', 'safaei-auto-image-loader' ),
				'closeText'          => __( 'Close', 'safaei-auto-image-loader' ),
				'searchHelpText'     => __( 'Leave empty to search by refcode.', 'safaei-auto-image-loader' ),
			)
		);
	}

	public static function render_modal() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}
		?>
		<style>
			#safaei-image-modal {
				position: fixed;
				inset: 0;
				z-index: 100000;
				display: none;
			}
			#safaei-image-modal .safaei-modal-backdrop {
				position: absolute;
				inset: 0;
				background: rgba(0, 0, 0, 0.45);
			}
			#safaei-image-modal .safaei-modal-content {
				position: relative;
				background: #fff;
				max-width: 720px;
				margin: 6vh auto;
				padding: 20px;
				border-radius: 6px;
				box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
				max-height: 80vh;
				overflow: hidden;
				display: flex;
				flex-direction: column;
				gap: 12px;
			}
			#safaei-image-modal .safaei-modal-close {
				position: absolute;
				top: 12px;
				right: 12px;
			}
			#safaei-image-modal .safaei-modal-body {
				overflow: auto;
			}
			#safaei-modal-candidates {
				max-height: 360px;
				overflow: auto;
			}
		</style>
		<div id="safaei-image-modal" aria-hidden="true">
			<div class="safaei-modal-backdrop"></div>
			<div class="safaei-modal-content" role="dialog" aria-modal="true" aria-labelledby="safaei-modal-title">
				<button type="button" class="button-link safaei-modal-close" aria-label="<?php esc_attr_e( 'Close', 'safaei-auto-image-loader' ); ?>">
					<span class="dashicons dashicons-no-alt"></span>
				</button>
				<h2 id="safaei-modal-title"><?php esc_html_e( 'Find Image', 'safaei-auto-image-loader' ); ?></h2>
				<div class="safaei-modal-body">
					<p><strong><?php esc_html_e( 'Refcode:', 'safaei-auto-image-loader' ); ?></strong> <span id="safaei-modal-refcode"></span></p>
					<p>
						<label for="safaei-modal-query"><?php esc_html_e( 'Search query', 'safaei-auto-image-loader' ); ?></label><br />
						<input type="text" id="safaei-modal-query" class="regular-text" />
						<span class="description"><?php esc_html_e( 'Leave empty to search by refcode.', 'safaei-auto-image-loader' ); ?></span>
					</p>
					<p>
						<button type="button" class="button button-primary" id="safaei-modal-search-now"><?php esc_html_e( 'Search Now', 'safaei-auto-image-loader' ); ?></button>
					</p>
					<div id="safaei-modal-candidates"></div>
				</div>
			</div>
		</div>
		<?php
	}
}
