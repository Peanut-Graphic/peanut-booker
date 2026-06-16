<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Plugin Name: Peanut Booker
 * Plugin URI: https://peanutgraphic.com/peanut-booker
 * Description: A membership and booking platform connecting performers with event organizers. Features performer profiles, booking engine, bidding market, reviews, and escrow payments.
 * Version: 1.7.2
 * Author: Peanut Graphic
 * Author URI: https://peanutgraphic.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: peanut-booker
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 *
 * @package Peanut_Booker
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Plugin version.
 */
define( 'PEANUT_BOOKER_VERSION', '1.7.2' );

/**
 * Plugin base path.
 */
define( 'PEANUT_BOOKER_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin base URL.
 */
define( 'PEANUT_BOOKER_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename.
 */
define( 'PEANUT_BOOKER_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Database version for schema updates.
 */
define( 'PEANUT_BOOKER_DB_VERSION', '1.4.0' );

/**
 * Check for WooCommerce dependency.
 *
 * @return bool
 */
function peanut_booker_check_woocommerce() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'peanut_booker_woocommerce_notice' );
        return false;
    }
    return true;
}

/**
 * Display WooCommerce requirement notice.
 */
function peanut_booker_woocommerce_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php esc_html_e( 'Peanut Booker requires WooCommerce to be installed and activated.', 'peanut-booker' ); ?></p>
    </div>
    <?php
}

/**
 * Code that runs during plugin activation.
 */
function peanut_booker_activate() {
    require_once PEANUT_BOOKER_PATH . 'includes/class-activator.php';
    Peanut_Booker_Activator::activate();
}

/**
 * Code that runs during plugin deactivation.
 */
function peanut_booker_deactivate() {
    require_once PEANUT_BOOKER_PATH . 'includes/class-deactivator.php';
    Peanut_Booker_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'peanut_booker_activate' );
register_deactivation_hook( __FILE__, 'peanut_booker_deactivate' );

/**
 * The core plugin class.
 */
require PEANUT_BOOKER_PATH . 'includes/class-peanut-booker.php';

/**
 * License client SDK.
 */
require PEANUT_BOOKER_PATH . 'includes/class-peanut-license-client.php';

/**
 * Global license client instance.
 *
 * @var Peanut_License_Client|null
 */
$peanut_booker_license = null;

/**
 * Get the license client instance.
 *
 * @return Peanut_License_Client|null
 */
function peanut_booker_license() {
    global $peanut_booker_license;
    return $peanut_booker_license;
}

/**
 * Check if plugin has active license.
 *
 * @return bool
 */
function peanut_booker_is_licensed() {
    $license = peanut_booker_license();
    return $license && $license->is_active();
}

/**
 * Begins execution of the plugin.
 *
 * @since 1.0.0
 */
function peanut_booker_run() {
    global $peanut_booker_license;

    // Check WooCommerce dependency.
    if ( ! peanut_booker_check_woocommerce() ) {
        return;
    }

    // Initialize license client.
    $license_server_url = get_option( 'peanut_booker_license_server', 'https://peanutgraphic.com/wp-json/peanut-api/v1' );

    $peanut_booker_license = new Peanut_License_Client( array(
        'api_url'        => $license_server_url,
        'plugin_slug'    => 'peanut-booker',
        'plugin_file'    => __FILE__,
        'plugin_name'    => 'Peanut Booker',
        'version'        => PEANUT_BOOKER_VERSION,
        'license_option' => 'peanut_booker_license_key',
        'status_option'  => 'peanut_booker_license_status',
        'auto_updates'   => true,
    ) );

    $plugin = new Peanut_Booker();
    $plugin->run();
}

add_action( 'plugins_loaded', 'peanut_booker_run' );

/**
 * Declare HPOS compatibility for WooCommerce.
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

/**
 * Global ML predictor instance.
 *
 * @var Peanut_Booker_ML_Predictor|null
 */
$peanut_booker_ml_predictor = null;

/**
 * Get the ML Booking Predictor instance.
 *
 * @return Peanut_Booker_ML_Predictor|null
 */
function peanut_booker_ml_predictor() {
    global $peanut_booker_ml_predictor;
    return $peanut_booker_ml_predictor;
}
