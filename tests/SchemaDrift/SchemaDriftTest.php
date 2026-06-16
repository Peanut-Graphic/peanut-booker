<?php
/**
 * Schema-vs-code drift guard.
 *
 * Asserts that every column the plugin writes (via $wpdb->insert/->update or the
 * Peanut_Booker_Database::insert/update wrappers) into a custom pb_* table exists
 * in that table's dbDelta CREATE schema in the activator.
 *
 * This is the CI guard for item E of the schema-drift remediation. It is pure
 * static analysis (no WP runtime) so it runs anywhere. Keep it GREEN: if you add
 * a column write, add the column to includes/class-activator.php (and a migration).
 *
 * @package Peanut_Booker\Tests\SchemaDrift
 */

declare( strict_types=1 );

namespace Peanut_Booker\Tests\SchemaDrift;

use PHPUnit\Framework\TestCase;

final class SchemaDriftTest extends TestCase {

    /**
     * Columns that are legitimately written but are NOT plain dbDelta columns,
     * or write sites whose "table"/"column" tokens are false positives of the
     * static scanner (e.g. a variable that only looks like a column). Keep this
     * list empty if at all possible; every entry is documented.
     *
     * Format: "table.column".
     *
     * @var string[]
     */
    private const ALLOWLIST = array();

    /**
     * The parsed dbDelta schema: table-short-name => column[].
     *
     * @var array<string, string[]>|null
     */
    private static $schema = null;

    public static function setUpBeforeClass(): void {
        self::$schema = peanut_booker_parse_schema_columns();
    }

    /**
     * Sanity: the parser actually found the known core tables. If this breaks,
     * the parser regex drifted from the activator and the whole guard is blind.
     */
    public function test_schema_parser_finds_core_tables(): void {
        $expected = array(
            'performers',
            'bookings',
            'reviews',
            'events',
            'bids',
            'availability',
            'transactions',
            'subscriptions',
            'messages',
            'sponsored_slots',
            'microsites',
            'microsite_analytics',
        );

        foreach ( $expected as $table ) {
            $this->assertArrayHasKey(
                $table,
                self::$schema,
                "Activator dbDelta schema is missing a CREATE TABLE for pb_{$table}."
            );
            $this->assertNotEmpty(
                self::$schema[ $table ],
                "pb_{$table} parsed with zero columns — parser or schema is broken."
            );
        }
    }

    /**
     * The guard proper: every written column must exist in its table's schema.
     */
    public function test_every_written_column_exists_in_schema(): void {
        $schema = self::$schema;
        $errors = array();

        foreach ( peanut_booker_source_files() as $file ) {
            foreach ( peanut_booker_scan_writes( $file ) as $write ) {
                $table = $write['table'];

                // A write to a table we don't define is itself drift.
                if ( ! isset( $schema[ $table ] ) ) {
                    $errors[] = sprintf(
                        'Write to undefined table pb_%s in %s',
                        $table,
                        $this->rel( $write['file'] )
                    );
                    continue;
                }

                foreach ( $write['columns'] as $column ) {
                    if ( in_array( $table . '.' . $column, self::ALLOWLIST, true ) ) {
                        continue;
                    }
                    if ( ! in_array( $column, $schema[ $table ], true ) ) {
                        $errors[] = sprintf(
                            'Column `%s` written to pb_%s does not exist in dbDelta schema (%s)',
                            $column,
                            $table,
                            $this->rel( $write['file'] )
                        );
                    }
                }
            }
        }

        $this->assertSame(
            array(),
            $errors,
            "Schema drift detected:\n - " . implode( "\n - ", $errors )
        );
    }

    private function rel( string $path ): string {
        return str_replace( PEANUT_BOOKER_TEST_ROOT . '/', '', $path );
    }
}
