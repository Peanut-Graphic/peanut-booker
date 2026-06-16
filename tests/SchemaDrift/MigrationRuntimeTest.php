<?php
/**
 * Runtime behaviour guard for the self-healing migration (item D).
 *
 * Loads the real Peanut_Booker_Database against a recording $wpdb stub and
 * asserts:
 *   - check_db_version() no-ops when the version matches AND the schema is whole;
 *   - it re-runs (drift self-heal) when a current-version column is missing even
 *     though the option says we're up to date;
 *   - the ALTER migrations are idempotent (no ADD COLUMN when the column exists).
 *
 * Uses a minimal stub rather than the full WP test DB so it runs on the
 * self-hosted CI runner without a database.
 *
 * @package Peanut_Booker\Tests\SchemaDrift
 */

declare( strict_types=1 );

namespace Peanut_Booker\Tests\SchemaDrift;

use PHPUnit\Framework\TestCase;
use Peanut_Booker_Database;

final class MigrationRuntimeTest extends TestCase {

    protected function setUp(): void {
        require_once __DIR__ . '/wp-migration-stubs.php';
        \pb_migration_reset();
        require_once PEANUT_BOOKER_TEST_ROOT . '/includes/class-database.php';
    }

    public function test_no_op_when_version_and_schema_current(): void {
        global $wpdb, $pb_options;
        $pb_options['peanut_booker_db_version'] = PEANUT_BOOKER_DB_VERSION;
        // Every expected column present → schema whole.
        $wpdb->set_all_columns_present( true );

        Peanut_Booker_Database::check_db_version();

        $this->assertSame( array(), $wpdb->alter_queries, 'A current install must not run any ALTER.' );
        $this->assertFalse( $wpdb->create_tables_called, 'A current install must not re-run create_tables.' );
    }

    public function test_self_heals_drift_when_option_lies(): void {
        global $wpdb, $pb_options;
        // Option says current, but a current-version column is missing (drift).
        $pb_options['peanut_booker_db_version'] = PEANUT_BOOKER_DB_VERSION;
        $wpdb->set_all_columns_present( false );

        Peanut_Booker_Database::check_db_version();

        $this->assertTrue(
            $wpdb->create_tables_called,
            'Drift (missing column despite matching option) must trigger create_tables() self-heal.'
        );
    }

    public function test_alter_is_idempotent_when_columns_exist(): void {
        global $wpdb, $pb_options;
        // Old install → triggers migration path, but pretend columns already there.
        $pb_options['peanut_booker_db_version'] = '1.3.0';
        $wpdb->set_all_columns_present( true );

        Peanut_Booker_Database::check_db_version();

        $this->assertSame(
            array(),
            $wpdb->alter_queries,
            'Migrations must not ADD COLUMN when the column already exists.'
        );
    }

    public function test_alter_adds_missing_140_columns_on_old_install(): void {
        global $wpdb, $pb_options;
        $pb_options['peanut_booker_db_version'] = '1.3.0';
        $wpdb->set_all_columns_present( false );

        Peanut_Booker_Database::check_db_version();

        $joined = implode( ' ', $wpdb->alter_queries );
        foreach ( array( 'event_time', 'is_public', 'ticket_url', 'cancellation_date' ) as $col ) {
            $this->assertStringContainsString(
                'ADD COLUMN ' . $col,
                $joined,
                "Old install missing {$col} must get an ADD COLUMN."
            );
        }
    }
}
