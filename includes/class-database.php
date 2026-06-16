<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database operations helper class.
 *
 * @package Peanut_Booker
 * @since   1.0.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Database operations helper class.
 */
class Peanut_Booker_Database {

    /**
     * Get table name with prefix.
     *
     * @param string $table Table name without prefix.
     * @return string
     */
    public static function get_table( $table ) {
        global $wpdb;
        return $wpdb->prefix . 'pb_' . $table;
    }

    /**
     * Insert a row into a table.
     *
     * @param string $table Table name without prefix.
     * @param array  $data  Data to insert.
     * @param array  $format Optional format array.
     * @return int|false Insert ID or false on failure.
     */
    public static function insert( $table, $data, $format = null ) {
        global $wpdb;

        $result = $wpdb->insert(
            self::get_table( $table ),
            $data,
            $format
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update rows in a table.
     *
     * @param string $table        Table name without prefix.
     * @param array  $data         Data to update.
     * @param array  $where        Where conditions.
     * @param array  $format       Optional format array for data.
     * @param array  $where_format Optional format array for where.
     * @return int|false Number of rows updated or false on failure.
     */
    public static function update( $table, $data, $where, $format = null, $where_format = null ) {
        global $wpdb;

        return $wpdb->update(
            self::get_table( $table ),
            $data,
            $where,
            $format,
            $where_format
        );
    }

    /**
     * Delete rows from a table.
     *
     * @param string $table        Table name without prefix.
     * @param array  $where        Where conditions.
     * @param array  $where_format Optional format array.
     * @return int|false Number of rows deleted or false on failure.
     */
    public static function delete( $table, $where, $where_format = null ) {
        global $wpdb;

        return $wpdb->delete(
            self::get_table( $table ),
            $where,
            $where_format
        );
    }

    /**
     * Get a single row from a table.
     *
     * @param string $table  Table name without prefix.
     * @param array  $where  Where conditions.
     * @param string $output Output type (OBJECT, ARRAY_A, ARRAY_N).
     * @return object|array|null
     */
    public static function get_row( $table, $where, $output = OBJECT ) {
        global $wpdb;

        $table_name = self::get_table( $table );
        $conditions = array();
        $values     = array();

        foreach ( $where as $key => $value ) {
            $conditions[] = "`$key` = %s";
            $values[]     = $value;
        }

        $where_clause = implode( ' AND ', $conditions );
        $sql          = $wpdb->prepare(
            "SELECT * FROM $table_name WHERE $where_clause LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $values
        );

        return $wpdb->get_row( $sql, $output ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Get multiple rows from a table.
     *
     * @param string $table   Table name without prefix.
     * @param array  $where   Where conditions.
     * @param string $orderby Order by column.
     * @param string $order   Order direction (ASC/DESC).
     * @param int    $limit   Number of rows to return.
     * @param int    $offset  Offset for pagination.
     * @param string $output  Output type (OBJECT, ARRAY_A, ARRAY_N).
     * @return array
     */
    public static function get_results( $table, $where = array(), $orderby = 'id', $order = 'DESC', $limit = 0, $offset = 0, $output = OBJECT ) {
        global $wpdb;

        $table_name = self::get_table( $table );
        $sql        = "SELECT * FROM $table_name"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( ! empty( $where ) ) {
            $conditions = array();
            $values     = array();

            foreach ( $where as $key => $value ) {
                // Validate column name to prevent SQL injection
                if ( ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key ) ) {
                    continue;
                }
                if ( is_array( $value ) ) {
                    $placeholders = implode( ', ', array_fill( 0, count( $value ), '%s' ) );
                    $conditions[] = "`$key` IN ($placeholders)";
                    $values       = array_merge( $values, $value );
                } else {
                    $conditions[] = "`$key` = %s";
                    $values[]     = $value;
                }
            }

            if ( ! empty( $conditions ) ) {
                $where_clause = implode( ' AND ', $conditions );
                $sql         .= $wpdb->prepare( " WHERE $where_clause", $values ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            }
        }

        // Validate ORDER BY column name and direction to prevent SQL injection
        if ( preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/', $orderby ) ) {
            $order = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';
            $sql  .= " ORDER BY `$orderby` $order";
        }

        if ( $limit > 0 ) {
            $sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
        }

        return $wpdb->get_results( $sql, $output ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Count rows in a table.
     *
     * @param string $table Table name without prefix.
     * @param array  $where Where conditions.
     * @return int
     */
    public static function count( $table, $where = array() ) {
        global $wpdb;

        $table_name = self::get_table( $table );
        $sql        = "SELECT COUNT(*) FROM $table_name"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( ! empty( $where ) ) {
            $conditions = array();
            $values     = array();

            foreach ( $where as $key => $value ) {
                $conditions[] = "`$key` = %s";
                $values[]     = $value;
            }

            $where_clause = implode( ' AND ', $conditions );
            $sql         .= $wpdb->prepare( " WHERE $where_clause", $values ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Generate a unique booking number.
     *
     * @return string
     */
    public static function generate_booking_number() {
        $prefix = 'PB';
        $date   = gmdate( 'Ymd' );
        $random = strtoupper( wp_generate_password( 6, false ) );

        return $prefix . $date . $random;
    }

    /**
     * Register the upgrade hook. Called once from the core plugin bootstrap.
     *
     * Uses a cheap `get_option` version gate on every request (see
     * check_db_version) so the common, already-migrated path costs a single
     * option read.
     */
    public static function init() {
        add_action( 'plugins_loaded', array( __CLASS__, 'check_db_version' ) );
    }

    /**
     * Check if database needs update.
     *
     * @return bool
     */
    public static function needs_update() {
        $current_version = get_option( 'peanut_booker_db_version', '0' );
        return version_compare( $current_version, PEANUT_BOOKER_DB_VERSION, '<' );
    }

    /**
     * Run migrations on upgrade — and self-heal schema drift.
     *
     * Two conditions trigger work:
     *   1. The stored DB version is older than PEANUT_BOOKER_DB_VERSION (the
     *      normal upgrade case).
     *   2. The stored version already MATCHES but a column we expect for the
     *      current version is actually missing (drift / partial-migration).
     *
     * Mirrors the gold-standard pattern in PEANUT-CONNECT
     * (Peanut_Booker_Database :: check_db_version). Trusting the option without
     * verifying the schema is a one-way trap: once the option is wrong, the
     * migration never re-runs and the missing column breaks writes forever.
     * This is exactly how the analytics table (no CREATE TABLE anywhere) and the
     * external-gig columns reached production silently broken.
     *
     * dbDelta / create_tables() is idempotent, so re-running it is safe.
     */
    public static function check_db_version() {
        $installed_version = get_option( 'peanut_booker_db_version', '0' );

        $needs_migration = version_compare( $installed_version, PEANUT_BOOKER_DB_VERSION, '!=' )
            || ! self::schema_matches_current_version();

        if ( ! $needs_migration ) {
            return;
        }

        // Idempotent column migrations for older installs, then a full dbDelta
        // sweep (which creates any wholly-missing tables, e.g. analytics).
        self::run_migrations( $installed_version );

        require_once PEANUT_BOOKER_PATH . 'includes/class-activator.php';
        if ( method_exists( 'Peanut_Booker_Activator', 'create_tables' ) ) {
            Peanut_Booker_Activator::create_tables();
        } else {
            // create_tables is private in some builds; activate() is a superset.
            Peanut_Booker_Activator::activate();
        }

        update_option( 'peanut_booker_db_version', PEANUT_BOOKER_DB_VERSION );
    }

    /**
     * Backwards-compatible alias retained for any external callers.
     */
    public static function maybe_migrate() {
        self::check_db_version();
    }

    /**
     * Columns / tables introduced by each DB version bump. Keep this map in
     * sync with includes/class-activator.php — every column a version bump adds
     * must be listed here so drift detection can verify it post-migration.
     *
     * @return array<string, string[]> table-without-prefix => expected columns.
     */
    public static function expected_schema_additions() {
        return array(
            // 1.3.0: external-gig metadata on availability blocks.
            'availability'        => array( 'block_type', 'event_name', 'venue_name', 'event_type', 'event_location' ),
            // 1.4.0: external-gig public-listing fields + cancellation timestamp
            //        + the previously-uncreated microsite analytics table.
            'bookings'            => array( 'cancellation_date' ),
            'microsite_analytics' => array( 'microsite_id', 'date', 'hour_of_day', 'referrer_domain', 'page_views', 'unique_visitors', 'booking_clicks' ),
        );
    }

    /**
     * Verify that every column expected for the current DB version actually
     * exists. Returns false on the first miss (drift) so the caller re-runs the
     * migration. Cheap: SHOW COLUMNS / SHOW TABLES, only reached after the
     * version gate.
     *
     * @return bool
     */
    public static function schema_matches_current_version() {
        global $wpdb;

        foreach ( self::expected_schema_additions() as $table => $columns ) {
            $table_name = $wpdb->prefix . 'pb_' . $table;

            $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
            if ( ! $table_exists ) {
                return false;
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $existing = $wpdb->get_col( "SHOW COLUMNS FROM `$table_name`" );
            foreach ( $columns as $column ) {
                if ( ! in_array( $column, $existing, true ) ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Run specific idempotent migrations for schema changes.
     *
     * @param string $current_version DB version recorded before this run.
     */
    private static function run_migrations( $current_version ) {
        // 1.3.0: external gigs base columns on the availability table.
        if ( version_compare( $current_version, '1.3.0', '<' ) ) {
            self::migrate_external_gigs_columns();
        }

        // 1.4.0: external-gig public-listing columns + booking cancellation date.
        // (The microsite analytics table is created by the dbDelta sweep in
        // check_db_version; dbDelta can ADD columns but cannot CREATE a missing
        // table reliably across all stored states, so the sweep handles it.)
        if ( version_compare( $current_version, '1.4.0', '<' ) ) {
            self::migrate_external_gig_listing_columns();
            self::migrate_booking_cancellation_date();
        }
    }

    /**
     * 1.4.0: add event_time / is_public / ticket_url to the availability table.
     * Idempotent — guarded by SHOW COLUMNS.
     */
    private static function migrate_external_gig_listing_columns() {
        global $wpdb;

        $table   = $wpdb->prefix . 'pb_availability';
        $columns = $wpdb->get_col( "SHOW COLUMNS FROM `$table`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( ! in_array( 'event_time', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE `$table` ADD COLUMN event_time time DEFAULT NULL AFTER event_location" ); // phpcs:ignore WordPress.DB
        }
        if ( ! in_array( 'is_public', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE `$table` ADD COLUMN is_public tinyint(1) NOT NULL DEFAULT 1 AFTER event_time" ); // phpcs:ignore WordPress.DB
        }
        if ( ! in_array( 'ticket_url', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE `$table` ADD COLUMN ticket_url varchar(255) DEFAULT NULL AFTER is_public" ); // phpcs:ignore WordPress.DB
        }
    }

    /**
     * 1.4.0: add cancellation_date to the bookings table. Idempotent.
     */
    private static function migrate_booking_cancellation_date() {
        global $wpdb;

        $table   = $wpdb->prefix . 'pb_bookings';
        $columns = $wpdb->get_col( "SHOW COLUMNS FROM `$table`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( ! in_array( 'cancellation_date', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE `$table` ADD COLUMN cancellation_date datetime DEFAULT NULL AFTER cancellation_reason" ); // phpcs:ignore WordPress.DB
        }
    }

    /**
     * Add external gigs columns to availability table.
     */
    private static function migrate_external_gigs_columns() {
        global $wpdb;

        $table = $wpdb->prefix . 'pb_availability';

        // Check if columns already exist.
        $columns = $wpdb->get_col( "SHOW COLUMNS FROM $table" );

        if ( ! in_array( 'block_type', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE $table ADD COLUMN block_type varchar(20) DEFAULT 'manual' AFTER booking_id" );
        }

        if ( ! in_array( 'event_name', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE $table ADD COLUMN event_name varchar(255) DEFAULT NULL AFTER block_type" );
        }

        if ( ! in_array( 'venue_name', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE $table ADD COLUMN venue_name varchar(255) DEFAULT NULL AFTER event_name" );
        }

        if ( ! in_array( 'event_type', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE $table ADD COLUMN event_type varchar(100) DEFAULT NULL AFTER venue_name" );
        }

        if ( ! in_array( 'event_location', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE $table ADD COLUMN event_location varchar(255) DEFAULT NULL AFTER event_type" );
        }

        // Add index for block_type if not exists.
        $indexes = $wpdb->get_results( "SHOW INDEX FROM $table WHERE Key_name = 'block_type'" );
        if ( empty( $indexes ) ) {
            $wpdb->query( "ALTER TABLE $table ADD INDEX block_type (block_type)" );
        }

        // Update existing blocked records to have block_type = 'manual'.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table SET block_type = 'manual' WHERE status = %s AND block_type IS NULL",
                'blocked'
            )
        );

        // Update existing booked records to have block_type = 'booking'.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table SET block_type = 'booking' WHERE status = %s AND block_type IS NULL",
                'booked'
            )
        );
    }
}
