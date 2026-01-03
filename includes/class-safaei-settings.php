<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Safaei_Settings {
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Safaei Image Loader', 'safaei-auto-image-loader' ),
			__( 'Safaei Image Loader', 'safaei-auto-image-loader' ),
			'manage_woocommerce',
			'safaei-image-loader',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'safaei_image_loader_settings_group',
			Safaei_Image_Loader::OPTION_KEY,
			array( __CLASS__, 'sanitize_settings' )
		);

		add_settings_section(
			'safaei_image_loader_main',
			__( 'Settings', 'safaei-auto-image-loader' ),
			'__return_false',
			'safaei-image-loader'
		);

		self::add_field( 'enabled', __( 'Enable', 'safaei-auto-image-loader' ), 'checkbox' );
		self::add_field( 'google_api_key', __( 'Google API Key', 'safaei-auto-image-loader' ), 'text' );
		self::add_field( 'google_cx', __( 'Google CSE CX', 'safaei-auto-image-loader' ), 'text' );
		self::add_field( 'query_template', __( 'Query Template', 'safaei-auto-image-loader' ), 'text' );
		self::add_field( 'fallback_queries', __( 'Fallback Queries', 'safaei-auto-image-loader' ), 'textarea' );
		self::add_field( 'max_results_per_product', __( 'Max Results Per Product', 'safaei-auto-image-loader' ), 'number' );
		self::add_field( 'max_retries', __( 'Max Retries', 'safaei-auto-image-loader' ), 'number' );
		self::add_field( 'batch_size', __( 'Batch Size', 'safaei-auto-image-loader' ), 'number' );
		self::add_field( 'min_image_width', __( 'Minimum Image Width', 'safaei-auto-image-loader' ), 'number' );
		self::add_field( 'allowed_domains', __( 'Allowed Domains', 'safaei-auto-image-loader' ), 'textarea' );
		self::add_field( 'set_gallery', __( 'Set Gallery', 'safaei-auto-image-loader' ), 'checkbox' );
		self::add_field( 'gallery_count', __( 'Gallery Count', 'safaei-auto-image-loader' ), 'number' );
		self::add_field( 'skip_if_has_image', __( 'Skip if Has Image', 'safaei-auto-image-loader' ), 'checkbox' );
		self::add_field( 'cron_interval_minutes', __( 'Cron Interval (minutes)', 'safaei-auto-image-loader' ), 'number' );
	}

	private static function add_field( $key, $label, $type ) {
		add_settings_field(
			$key,
			$label,
			array( __CLASS__, 'render_field' ),
			'safaei-image-loader',
			'safaei_image_loader_main',
			array(
				'key'  => $key,
				'type' => $type,
			)
		);
	}

	public static function sanitize_settings( $input ) {
		$defaults = Safaei_Image_Loader::default_settings();
		$sanitized = array();

		$sanitized['enabled'] = ! empty( $input['enabled'] );
		$sanitized['google_api_key'] = sanitize_text_field( $input['google_api_key'] ?? $defaults['google_api_key'] );
		$sanitized['google_cx'] = sanitize_text_field( $input['google_cx'] ?? $defaults['google_cx'] );
		$sanitized['query_template'] = sanitize_text_field( $input['query_template'] ?? $defaults['query_template'] );
		$sanitized['fallback_queries'] = sanitize_textarea_field( $input['fallback_queries'] ?? $defaults['fallback_queries'] );
		$sanitized['max_results_per_product'] = max( 1, min( 10, absint( $input['max_results_per_product'] ?? $defaults['max_results_per_product'] ) ) );
		$sanitized['max_retries'] = max( 1, absint( $input['max_retries'] ?? $defaults['max_retries'] ) );
		$sanitized['batch_size'] = max( 1, absint( $input['batch_size'] ?? $defaults['batch_size'] ) );
		$sanitized['min_image_width'] = max( 1, absint( $input['min_image_width'] ?? $defaults['min_image_width'] ) );
		$sanitized['allowed_domains'] = sanitize_textarea_field( $input['allowed_domains'] ?? '' );
		$sanitized['set_gallery'] = ! empty( $input['set_gallery'] );
		$sanitized['gallery_count'] = max( 1, absint( $input['gallery_count'] ?? $defaults['gallery_count'] ) );
		$sanitized['skip_if_has_image'] = ! empty( $input['skip_if_has_image'] );
		$sanitized['cron_interval_minutes'] = max( 1, absint( $input['cron_interval_minutes'] ?? $defaults['cron_interval_minutes'] ) );

		return $sanitized;
	}

	public static function render_field( $args ) {
		$options = Safaei_Image_Loader::get_settings();
		$key = $args['key'];
		$type = $args['type'];
		$value = $options[ $key ] ?? '';

		switch ( $type ) {
			case 'checkbox':
				printf(
					'<input type="checkbox" name="%s[%s]" value="1" %s />',
					esc_attr( Safaei_Image_Loader::OPTION_KEY ),
					esc_attr( $key ),
					checked( $value, true, false )
				);
				break;
			case 'textarea':
				printf(
					'<textarea name="%s[%s]" rows="5" cols="50" class="large-text">%s</textarea>',
					esc_attr( Safaei_Image_Loader::OPTION_KEY ),
					esc_attr( $key ),
					esc_textarea( $value )
				);
				break;
			default:
				$input_type = $type === 'number' ? 'number' : 'text';
				printf(
					'<input type="%s" name="%s[%s]" value="%s" class="regular-text" />',
					esc_attr( $input_type ),
					esc_attr( Safaei_Image_Loader::OPTION_KEY ),
					esc_attr( $key ),
					esc_attr( $value )
				);
		}
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Safaei Image Loader', 'safaei-auto-image-loader' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'safaei_image_loader_settings_group' );
				do_settings_sections( 'safaei-image-loader' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
