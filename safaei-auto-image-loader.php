<?php
/**
 * Plugin Name: Safaei Auto Image Loader
 * Description: Automatically find and set WooCommerce product images using Google Programmable Search Engine.
 * Version: 1.0.0
 * Author: Safaei
 * Text Domain: safaei-auto-image-loader
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SAFAEI_IMAGE_LOADER_VERSION', '1.0.0' );
define( 'SAFAEI_IMAGE_LOADER_PATH', plugin_dir_path( __FILE__ ) );
define( 'SAFAEI_IMAGE_LOADER_URL', plugin_dir_url( __FILE__ ) );

require_once SAFAEI_IMAGE_LOADER_PATH . 'includes/class-safaei-image-loader.php';
require_once SAFAEI_IMAGE_LOADER_PATH . 'includes/class-safaei-settings.php';
require_once SAFAEI_IMAGE_LOADER_PATH . 'includes/class-safaei-queue.php';
require_once SAFAEI_IMAGE_LOADER_PATH . 'includes/class-safaei-provider-google.php';
require_once SAFAEI_IMAGE_LOADER_PATH . 'includes/class-safaei-worker.php';
require_once SAFAEI_IMAGE_LOADER_PATH . 'includes/class-safaei-admin-list.php';
require_once SAFAEI_IMAGE_LOADER_PATH . 'includes/class-safaei-metabox.php';

register_activation_hook( __FILE__, array( 'Safaei_Image_Loader', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Safaei_Image_Loader', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Safaei_Image_Loader', 'init' ) );
