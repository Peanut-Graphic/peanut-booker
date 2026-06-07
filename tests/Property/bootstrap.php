<?php
/**
 * Bootstrap for property-based tests (net 6).
 *
 * Property tests exercise PURE PHP functions that need NO WordPress runtime.
 * We stub only the handful of WP helpers the targeted classes reference at
 * call time (translation + error wrapper), then load the specific include
 * files under test. This deliberately avoids the full plugin/WP boot so the
 * property suite is fast, deterministic, and isolated from unrelated debt in
 * the legacy unit suite.
 *
 * @package Peanut_Booker\Tests\Property
 */

declare( strict_types=1 );

// Production code under test calls error_log() on its amount-discrepancy audit
// path (an EXPECTED branch the property suite exercises heavily). Route those
// lines to a temp file so the runner's stdout stays clean and assertion-only.
ini_set( 'error_log', sys_get_temp_dir() . '/peanut-booker-property.log' );

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

// The plugin include files guard with `defined('WPINC') || die;` — satisfy it.
if ( ! defined( 'WPINC' ) ) {
    define( 'WPINC', 'wp-includes' );
}

// Minimal i18n stub: identity passthrough (deterministic, no gettext).
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

// Minimal WP_Error stub mirroring the shape the code relies on.
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        /** @var string */
        public $code;
        /** @var string */
        public $message;
        /** @var mixed */
        public $data;

        public function __construct( $code = '', $message = '', $data = '' ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code() {
            return $this->code;
        }

        public function get_error_message() {
            return $this->message;
        }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
    }
}

// Load the include files containing the pure functions under test.
$includes = dirname( __DIR__, 2 ) . '/includes';
require_once $includes . '/class-booking.php';
require_once $includes . '/class-market.php';
require_once $includes . '/class-availability.php';
