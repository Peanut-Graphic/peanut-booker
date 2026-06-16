<?php
/**
 * Minimal WordPress runtime stubs for MigrationRuntimeTest.
 *
 * Provides a recording $wpdb and the few WP helpers the database + activator
 * upgrade path touches, so the real Peanut_Booker_Database::check_db_version()
 * can run with no database.
 *
 * @package Peanut_Booker\Tests\SchemaDrift
 */

declare( strict_types=1 );

// The activator's create_tables() does `require_once ABSPATH . 'wp-admin/includes/upgrade.php'`
// (where dbDelta() normally lives). Point ABSPATH at a temp dir holding a stub
// upgrade.php so that require succeeds without a real WordPress install. We
// define dbDelta() ourselves below, so the stub file just needs to exist.
if ( ! defined( 'ABSPATH' ) ) {
    $pb_stub_abspath = sys_get_temp_dir() . '/pb-migration-abspath/';
    if ( ! is_dir( $pb_stub_abspath . 'wp-admin/includes' ) ) {
        mkdir( $pb_stub_abspath . 'wp-admin/includes', 0777, true );
    }
    if ( ! file_exists( $pb_stub_abspath . 'wp-admin/includes/upgrade.php' ) ) {
        file_put_contents( $pb_stub_abspath . 'wp-admin/includes/upgrade.php', "<?php\n// stub: dbDelta is defined by the test harness\n" );
    }
    define( 'ABSPATH', $pb_stub_abspath );
}
if ( ! defined( 'WPINC' ) ) {
    define( 'WPINC', 'wp-includes' );
}
if ( ! defined( 'PEANUT_BOOKER_PATH' ) ) {
    define( 'PEANUT_BOOKER_PATH', PEANUT_BOOKER_TEST_ROOT . '/' );
}
if ( ! defined( 'PEANUT_BOOKER_VERSION' ) ) {
    define( 'PEANUT_BOOKER_VERSION', '1.7.2' );
}
if ( ! defined( 'PEANUT_BOOKER_DB_VERSION' ) ) {
    // Mirror the real plugin define so the version gate is meaningful.
    $plugin_src = file_get_contents( PEANUT_BOOKER_TEST_ROOT . '/peanut-booker.php' );
    preg_match( "/define\(\s*'PEANUT_BOOKER_DB_VERSION',\s*'([0-9.]+)'\s*\)/", $plugin_src, $m );
    define( 'PEANUT_BOOKER_DB_VERSION', $m[1] ?? '1.4.0' );
}
if ( ! defined( 'OBJECT' ) ) {
    define( 'OBJECT', 'OBJECT' );
}

/**
 * Recording wpdb double. SHOW COLUMNS / SHOW TABLES answers are driven by
 * set_all_columns_present(); ALTER queries are captured.
 */
class PB_Migration_WPDB {
    public $prefix = 'wp_';
    public $alter_queries = array();
    public $create_tables_called = false;
    private $all_present = true;

    public function set_all_columns_present( bool $present ): void {
        $this->all_present = $present;
    }

    public function prepare( $query, ...$args ) {
        foreach ( $args as $a ) {
            $query = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $query, 1 );
        }
        return $query;
    }

    public function get_var( $query ) {
        // SHOW TABLES LIKE '<name>' → return the name when "present", else null.
        if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false ) {
            if ( ! $this->all_present ) {
                return null;
            }
            if ( preg_match( "/LIKE\s+'([^']+)'/i", $query, $mm ) ) {
                return $mm[1];
            }
        }
        return null;
    }

    public function get_col( $query ) {
        // SHOW COLUMNS FROM `table` → all expected columns, or none when drifting.
        if ( $this->all_present ) {
            // Superset of every column the migration/guard ever checks.
            return array(
                'block_type', 'event_name', 'venue_name', 'event_type', 'event_location',
                'event_time', 'is_public', 'ticket_url',
                'cancellation_date', 'cancellation_reason',
                'microsite_id', 'date', 'hour_of_day', 'referrer_domain',
                'page_views', 'unique_visitors', 'booking_clicks',
            );
        }
        return array();
    }

    public function query( $query ) {
        if ( stripos( $query, 'ALTER TABLE' ) !== false && stripos( $query, 'ADD COLUMN' ) !== false ) {
            $this->alter_queries[] = $query;
        }
        return true;
    }

    public function get_charset_collate() {
        return '';
    }

    public function get_results( $query = null, $output = OBJECT ) {
        return array();
    }
}

global $wpdb, $pb_options;
$wpdb       = new PB_Migration_WPDB();
$pb_options = array();

function pb_migration_reset(): void {
    global $wpdb, $pb_options;
    $wpdb       = new PB_Migration_WPDB();
    $pb_options = array();
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $name, $default = false ) {
        global $pb_options;
        return $pb_options[ $name ] ?? $default;
    }
}
if ( ! function_exists( 'update_option' ) ) {
    function update_option( $name, $value, $autoload = null ) {
        global $pb_options;
        $pb_options[ $name ] = $value;
        return true;
    }
}
if ( ! function_exists( 'add_action' ) ) {
    function add_action( ...$args ) {
        return true;
    }
}
if ( ! function_exists( 'dbDelta' ) ) {
    function dbDelta( $sql ) {
        global $wpdb;
        $wpdb->create_tables_called = true;
        return array();
    }
}
if ( ! function_exists( 'flush_rewrite_rules' ) ) {
    function flush_rewrite_rules( $hard = true ) {}
}
if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $k, $v, $e = 0 ) { return true; }
}
if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
}
