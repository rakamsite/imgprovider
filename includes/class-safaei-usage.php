<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Safaei_Usage {
	const OPTION_KEY = 'safaei_img_usage_daily';

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_safaei_reset_quota', array( __CLASS__, 'ajax_reset_quota' ) );
	}

	public static function get_usage() {
		$today = current_time( 'Y-m-d' );
		$usage = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $usage ) ) {
			$usage = array();
		}

		$usage = wp_parse_args(
			$usage,
			array(
				'date_key'     => $today,
				'total'        => 0,
				'success'      => 0,
				'failed'       => 0,
				'last_call_at' => 0,
			)
		);

		if ( $usage['date_key'] !== $today ) {
			$usage['date_key'] = $today;
			$usage['total'] = 0;
			$usage['success'] = 0;
			$usage['failed'] = 0;
			$usage['last_call_at'] = 0;
			update_option( self::OPTION_KEY, $usage, false );
		}

		$usage['total'] = absint( $usage['total'] );
		$usage['success'] = absint( $usage['success'] );
		$usage['failed'] = absint( $usage['failed'] );
		$usage['last_call_at'] = absint( $usage['last_call_at'] );

		return $usage;
	}

	public static function record_request( $success ) {
		$usage = self::get_usage();
		$usage['total']++;
		if ( $success ) {
			$usage['success']++;
		} else {
			$usage['failed']++;
		}
		$usage['last_call_at'] = time();
		update_option( self::OPTION_KEY, $usage, false );
	}

	public static function reset_today() {
		$usage = self::get_usage();
		$usage['total'] = 0;
		$usage['success'] = 0;
		$usage['failed'] = 0;
		$usage['last_call_at'] = 0;
		update_option( self::OPTION_KEY, $usage, false );
		return $usage;
	}

	public static function get_limit() {
		$options = Safaei_Image_Loader::get_settings();
		return absint( $options['daily_quota_limit'] ?? 0 );
	}

	public static function should_stop_when_reached() {
		$options = Safaei_Image_Loader::get_settings();
		return ! empty( $options['stop_when_limit_reached'] );
	}

	public static function is_quota_reached() {
		if ( ! self::should_stop_when_reached() ) {
			return false;
		}
		$limit = self::get_limit();
		if ( $limit <= 0 ) {
			return false;
		}
		$usage = self::get_usage();
		return $usage['total'] >= $limit;
	}

	public static function render_widget( $context = '' ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$usage = self::get_usage();
		$limit = self::get_limit();
		$percent = 0;
		$show_bar = $limit > 0;
		if ( $show_bar ) {
			$percent = round( ( $usage['total'] / $limit ) * 100 );
			$percent = max( 0, min( 100, $percent ) );
		}

		$bar_class = 'safaei-quota__fill--green';
		if ( $percent > 80 ) {
			$bar_class = 'safaei-quota__fill--red';
		} elseif ( $percent > 50 ) {
			$bar_class = 'safaei-quota__fill--yellow';
		}

		$last_call = $usage['last_call_at'] ? sprintf(
			/* translators: %s: human time diff */
			esc_html__( '%s ago', 'safaei-auto-image-loader' ),
			human_time_diff( $usage['last_call_at'], time() )
		) : esc_html__( 'Never', 'safaei-auto-image-loader' );

		$quota_reached = self::is_quota_reached();
		$reset_nonce = wp_create_nonce( 'safaei_quota_reset' );
		?>
		<div class="safaei-quota" data-context="<?php echo esc_attr( $context ); ?>">
			<div class="safaei-quota__head">
				<div class="safaei-quota__title">
					<?php
					printf(
						esc_html__( "Today's usage: %1\$d / %2\$d (%3\$d%%)", 'safaei-auto-image-loader' ),
						absint( $usage['total'] ),
						absint( $limit ),
						absint( $percent )
					);
					?>
				</div>
				<button type="button" class="button button-small safaei-quota__reset" data-nonce="<?php echo esc_attr( $reset_nonce ); ?>">
					<?php esc_html_e( 'Reset today counters', 'safaei-auto-image-loader' ); ?>
				</button>
			</div>
			<?php if ( ! $show_bar ) : ?>
				<p class="safaei-quota__message">
					<?php esc_html_e( 'Set daily quota limit to enable quota UI.', 'safaei-auto-image-loader' ); ?>
				</p>
			<?php else : ?>
				<div class="safaei-quota__bar">
					<div class="safaei-quota__fill <?php echo esc_attr( $bar_class ); ?>" style="width: <?php echo esc_attr( $percent ); ?>%;"></div>
				</div>
				<div class="safaei-quota__meta">
					<?php
					printf(
						esc_html__( 'Success: %1$d • Failed: %2$d • Last call: %3$s', 'safaei-auto-image-loader' ),
						absint( $usage['success'] ),
						absint( $usage['failed'] ),
						esc_html( $last_call )
					);
					?>
				</div>
				<?php if ( $percent >= 100 ) : ?>
					<div class="safaei-quota__status">
						<?php esc_html_e( 'Quota reached', 'safaei-auto-image-loader' ); ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( $quota_reached ) : ?>
				<div class="safaei-quota__alert">
					<?php esc_html_e( 'Daily quota reached. Search is paused until quota resets.', 'safaei-auto-image-loader' ); ?>
				</div>
			<?php endif; ?>
			<div class="safaei-quota__note">
				<?php esc_html_e( 'Usage is tracked by this plugin. If the API key is used elsewhere, Google’s actual quota may differ.', 'safaei-auto-image-loader' ); ?>
			</div>
		</div>
		<?php
	}

	public static function enqueue_assets() {
		if ( ! is_admin() ) {
			return;
		}

		wp_enqueue_script(
			'safaei-image-loader-usage',
			SAFAEI_IMAGE_LOADER_URL . 'assets/admin-usage.js',
			array( 'jquery' ),
			SAFAEI_IMAGE_LOADER_VERSION,
			true
		);

		wp_localize_script(
			'safaei-image-loader-usage',
			'safaeiUsage',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'safaei_quota_reset' ),
				'confirmText' => __( 'Reset today counters?', 'safaei-auto-image-loader' ),
				'successText' => __( 'Counters reset.', 'safaei-auto-image-loader' ),
				'errorText'   => __( 'Could not reset counters.', 'safaei-auto-image-loader' ),
			)
		);

		wp_register_style( 'safaei-image-loader-usage', false, array(), SAFAEI_IMAGE_LOADER_VERSION );
		wp_enqueue_style( 'safaei-image-loader-usage' );
		wp_add_inline_style(
			'safaei-image-loader-usage',
			'.safaei-quota{background:#fff;border:1px solid #ccd0d4;border-radius:4px;margin:12px 0;padding:12px;}' .
			'.safaei-quota__head{align-items:center;display:flex;gap:12px;justify-content:space-between;margin-bottom:8px;}' .
			'.safaei-quota__title{font-weight:600;}' .
			'.safaei-quota__bar{background:#e5e5e5;border-radius:8px;height:16px;overflow:hidden;margin-bottom:6px;}' .
			'.safaei-quota__fill{height:16px;}' .
			'.safaei-quota__fill--green{background:#46b450;}' .
			'.safaei-quota__fill--yellow{background:#ffb900;}' .
			'.safaei-quota__fill--red{background:#dc3232;}' .
			'.safaei-quota__meta{color:#555;font-size:12px;margin-top:6px;}' .
			'.safaei-quota__status{color:#dc3232;font-weight:600;margin-top:6px;}' .
			'.safaei-quota__alert{color:#dc3232;font-weight:600;margin-top:6px;}' .
			'.safaei-quota__note{color:#666;font-size:12px;margin-top:8px;}' .
			'.safaei-quota__message{color:#666;font-size:12px;margin:6px 0;}' .
			'.safaei-disabled-action{color:#a7aaad;pointer-events:none;}'
		);
	}

	public static function ajax_reset_quota() {
		check_ajax_referer( 'safaei_quota_reset', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'safaei-auto-image-loader' ) ) );
		}

		$usage = self::reset_today();
		wp_send_json_success( array( 'usage' => $usage ) );
	}
}
