<?php
/**
 * Migration / version consistency guard (item D).
 *
 * Locks the upgrade machinery so it cannot silently rot:
 *   - PEANUT_BOOKER_DB_VERSION is wired into check_db_version() and bumped.
 *   - Every column listed in Database::expected_schema_additions() actually
 *     exists in the activator dbDelta schema (so a fresh install and an upgraded
 *     install converge on the same schema).
 *   - The idempotent ALTER migrations add exactly the columns the schema map
 *     promises for the current version.
 *
 * Pure static analysis (no WP runtime).
 *
 * @package Peanut_Booker\Tests\SchemaDrift
 */

declare( strict_types=1 );

namespace Peanut_Booker\Tests\SchemaDrift;

use PHPUnit\Framework\TestCase;

final class MigrationConsistencyTest extends TestCase {

    /** @var string */
    private static $database_src = '';

    public static function setUpBeforeClass(): void {
        self::$database_src = file_get_contents( PEANUT_BOOKER_TEST_ROOT . '/includes/class-database.php' );
    }

    /**
     * The DB version constant must be bumped past the frozen 1.3.0 so existing
     * installs actually trigger the upgrade path.
     */
    public function test_db_version_is_bumped(): void {
        $plugin = file_get_contents( PEANUT_BOOKER_TEST_ROOT . '/peanut-booker.php' );
        $this->assertSame(
            1,
            preg_match( "/define\(\s*'PEANUT_BOOKER_DB_VERSION',\s*'([0-9.]+)'\s*\)/", $plugin, $m ),
            'PEANUT_BOOKER_DB_VERSION define not found.'
        );
        $this->assertTrue(
            version_compare( $m[1], '1.3.0', '>' ),
            "PEANUT_BOOKER_DB_VERSION ({$m[1]}) must be greater than the previously frozen 1.3.0."
        );
    }

    /**
     * The upgrade entrypoint must be hooked so it runs on existing installs.
     * Either via init()->plugins_loaded, or called directly from the bootstrap.
     */
    public function test_check_db_version_is_wired(): void {
        $this->assertStringContainsString(
            'function check_db_version',
            self::$database_src,
            'check_db_version() must exist as the upgrade entrypoint.'
        );

        $core = file_get_contents( PEANUT_BOOKER_TEST_ROOT . '/includes/class-peanut-booker.php' );
        $database_init_hooked = false !== strpos( self::$database_src, "add_action( 'plugins_loaded', array( __CLASS__, 'check_db_version' )" );
        $called_from_core     = false !== strpos( $core, 'Peanut_Booker_Database::check_db_version()' );

        $this->assertTrue(
            $database_init_hooked || $called_from_core,
            'check_db_version() must be wired into the plugins_loaded cycle (via init() or a direct call from the core bootstrap).'
        );
    }

    /**
     * Every column the schema-additions map claims must exist in the activator
     * dbDelta schema. Guarantees fresh installs match upgraded installs.
     */
    public function test_expected_additions_exist_in_activator_schema(): void {
        $schema   = peanut_booker_parse_schema_columns();
        $expected = $this->parse_expected_additions();

        $this->assertNotEmpty( $expected, 'Could not parse expected_schema_additions() map.' );

        foreach ( $expected as $table => $columns ) {
            $this->assertArrayHasKey(
                $table,
                $schema,
                "expected_schema_additions() references pb_{$table} which has no CREATE TABLE."
            );
            foreach ( $columns as $column ) {
                $this->assertContains(
                    $column,
                    $schema[ $table ],
                    "Migration promises column `{$column}` on pb_{$table} but the activator dbDelta schema lacks it."
                );
            }
        }
    }

    /**
     * The idempotent ALTER migrations for the current bump must add exactly the
     * columns the schema map lists for that version (no silent gaps).
     */
    public function test_alter_migrations_cover_140_additions(): void {
        // Columns the migration code adds via ALTER TABLE ... ADD COLUMN.
        preg_match_all( '/ADD COLUMN\s+([a-zA-Z_][a-zA-Z0-9_]*)\s/', self::$database_src, $m );
        $altered = $m[1];

        foreach ( array( 'event_time', 'is_public', 'ticket_url', 'cancellation_date' ) as $col ) {
            $this->assertContains(
                $col,
                $altered,
                "1.4.0 migration must ADD COLUMN {$col} for existing installs."
            );
        }
    }

    /**
     * Parse the expected_schema_additions() PHP map out of class-database.php.
     *
     * @return array<string, string[]>
     */
    private function parse_expected_additions(): array {
        if ( ! preg_match(
            '/function expected_schema_additions\(\).*?return\s+array\s*\((.*?)\n\s*\);/s',
            self::$database_src,
            $body
        ) ) {
            return array();
        }

        $out = array();
        if ( preg_match_all(
            "/'([a-zA-Z0-9_]+)'\s*=>\s*array\(([^)]*)\)/s",
            $body[1],
            $rows,
            PREG_SET_ORDER
        ) ) {
            foreach ( $rows as $row ) {
                $cols = array();
                if ( preg_match_all( "/'([a-zA-Z0-9_]+)'/", $row[2], $c ) ) {
                    $cols = $c[1];
                }
                $out[ $row[1] ] = $cols;
            }
        }

        return $out;
    }
}
