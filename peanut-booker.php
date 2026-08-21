<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Plugin Name: Peanut Booker
 * Plugin URI: https://peanutgraphic.com/peanut-booker
 * Description: A membership and booking platform connecting performers with event organizers. Features performer profiles, booking engine, bidding market, reviews, and escrow payments.
 * Version: 1.7.3
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
define( 'PEANUT_BOOKER_VERSION', '1.7.3' );

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
 * Ed25519 public key that Peanut release packages are signed with — the key the
 * central publisher (Peanut-meta/scripts/publish-plugin.sh) signs manifests
 * against.
 */
define( 'PEANUT_BOOKER_SIGNING_PUBKEY', 'NtHnWTBLVzCBKMAq9CO8LHDSD9ZfpGV0UloQdgToIwM=' );

// Composer autoload — bundles peanut/formflow-core, which carries the shared
// signed-update verifier. Guarded so a missing vendor/ degrades to an admin
// notice instead of a fatal.
if ( file_exists( PEANUT_BOOKER_PATH . 'vendor/autoload.php' ) ) {
	require_once PEANUT_BOOKER_PATH . 'vendor/autoload.php';
}

/**
 * Refuse to install an update package that is not cryptographically ours.
 *
 * Booker shipped without any signature gate: whatever package a site was handed
 * for this plugin, it installed, on transport trust alone. Transport trust is
 * not authenticity.
 *
 * The gate downloads the package, fetches its `.manifest.json` sidecar, and
 * verifies sha256 plus a detached Ed25519 signature before WordPress installs
 * anything. It is FAIL-CLOSED: an unsigned or unverifiable package is refused.
 *
 * NOTE: every Booker release from here on must go through
 * Peanut-meta/scripts/publish-plugin.sh, which signs and ships the manifest.
 * An unsigned release will be correctly refused by every install running this.
 */
function peanut_booker_register_update_gate(): void {
	if ( ! class_exists( '\Peanut\FormCore\Update\SignedUpdateGate' ) ) {
		add_action(
			'admin_notices',
			function () {
				if ( ! current_user_can( 'update_plugins' ) ) {
					return;
				}
				echo '<div class="notice notice-error"><p><strong>Peanut Booker:</strong> '
					. esc_html__( 'update signature verification is unavailable (formflow-core missing from vendor/). Updates are NOT being verified — reinstall from an official release package.', 'peanut-booker' )
					. '</p></div>';
			}
		);

		return;
	}

	( new \Peanut\FormCore\Update\SignedUpdateGate(
		PEANUT_BOOKER_BASENAME,
		array( 'peanutgraphic.com', 'github.com' ),
		PEANUT_BOOKER_SIGNING_PUBKEY,
		'peanut-booker'
	) )->register();
}
add_action( 'plugins_loaded', 'peanut_booker_register_update_gate', 1 );

/**
 * Database version for schema updates.
 */
define( 'PEANUT_BOOKER_DB_VERSION', '1.3.0' );

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
