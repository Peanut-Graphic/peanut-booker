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

        // Refuse an unbounded write. Today this is caught only by accident:
        // wpdb builds "UPDATE `t` SET ... WHERE " from an empty $where and
        // MySQL rejects the malformed SQL. That is defence by syntax error, on
        // the one code path where being wrong rewrites every row in the table.
        // All 40 callers pass an explicit condition, so nothing legitimate is
        // refused here.
        if ( empty( $where ) ) {
            error_log( 'Peanut Booker: refused an UPDATE with no WHERE condition on ' . $table . '.' );
            return false;
        }

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

        // Same guard as update(), and it matters more here: an empty $where on
        // a DELETE is "remove every row in the table".
        if ( empty( $where ) ) {
            error_log( 'Peanut Booker: refused a DELETE with no WHERE condition on ' . $table . '.' );
            return false;
        }

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
     * Check if database needs update.
     *
     * @return bool
     */
    public static function needs_update() {
        $current_version = get_option( 'peanut_booker_db_version', '0' );
        return version_compare( $current_version, PEANUT_BOOKER_DB_VERSION, '<' );
    }

    /**
     * Run database migrations if needed.
     */
    public static function maybe_migrate() {
        if ( self::needs_update() ) {
            self::run_migrations();
            require_once PEANUT_BOOKER_PATH . 'includes/class-activator.php';
            Peanut_Booker_Activator::activate();
        }
    }

    /**
     * Run specific migrations for schema changes.
     */
    private static function run_migrations() {
        global $wpdb;

        $current_version = get_option( 'peanut_booker_db_version', '0' );

        // Migration for external gigs feature (added in 1.3.0).
        if ( version_compare( $current_version, '1.3.0', '<' ) ) {
            self::migrate_external_gigs_columns();
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
