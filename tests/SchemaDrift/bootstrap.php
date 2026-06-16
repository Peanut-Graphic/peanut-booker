<?php
/**
 * Bootstrap for the schema-drift guard suite.
 *
 * Pure static analysis — no WordPress runtime is booted. The helpers here parse
 * the activator's dbDelta CREATE TABLE statements and scan the plugin source for
 * the custom-table columns it writes, so the guard can assert the two agree.
 *
 * @package Peanut_Booker\Tests\SchemaDrift
 */

declare( strict_types=1 );

define( 'PEANUT_BOOKER_TEST_ROOT', dirname( __DIR__, 2 ) );

/**
 * Parse every `CREATE TABLE {prefix}pb_<name> ( ... )` block out of the
 * activator and return a map of table-short-name => list of column names.
 *
 * The activator builds each statement as a PHP heredoc/double-quoted string of
 * the form:  "CREATE TABLE $table_xxx ( col type, ... ) $charset_collate;"
 * where $table_xxx is `$wpdb->prefix . 'pb_<name>'`. We resolve the table short
 * name from the nearest preceding `$wpdb->prefix . 'pb_<name>'` assignment.
 *
 * @return array<string, string[]> e.g. ['bookings' => ['id','booking_number',...]]
 */
function peanut_booker_parse_schema_columns(): array {
    $activator = PEANUT_BOOKER_TEST_ROOT . '/includes/class-activator.php';
    $source    = file_get_contents( $activator );
    if ( false === $source ) {
        throw new RuntimeException( 'Could not read activator: ' . $activator );
    }

    $tables = array();

    // Match each CREATE TABLE statement up to the closing ") $charset_collate;".
    // The table identifier in the statement is a PHP variable like $table_bookings;
    // we map that variable to its pb_<name> via the assignment that precedes it.
    if ( ! preg_match_all(
        '/CREATE TABLE\s+(\$[a-zA-Z_][a-zA-Z0-9_]*)\s*\((.*?)\)\s*\$charset_collate;/s',
        $source,
        $matches,
        PREG_SET_ORDER
    ) ) {
        return $tables;
    }

    foreach ( $matches as $match ) {
        $table_var = $match[1];       // e.g. $table_bookings
        $body      = $match[2];       // column definitions + keys

        // Resolve the short table name from: $table_bookings = $wpdb->prefix . 'pb_bookings';
        $short = null;
        if ( preg_match(
            '/' . preg_quote( $table_var, '/' ) . '\s*=\s*\$wpdb->prefix\s*\.\s*[\'"]pb_([a-zA-Z0-9_]+)[\'"]/',
            $source,
            $assign
        ) ) {
            $short = $assign[1];
        }

        if ( null === $short ) {
            continue;
        }

        $tables[ $short ] = peanut_booker_extract_columns_from_body( $body );
    }

    return $tables;
}

/**
 * Extract column names from a CREATE TABLE body, ignoring KEY/INDEX/PRIMARY/UNIQUE
 * lines (which reference columns but do not define new ones).
 *
 * @param string $body The text between the outer parentheses of a CREATE TABLE.
 * @return string[] Column names.
 */
function peanut_booker_extract_columns_from_body( string $body ): array {
    $columns = array();
    $lines   = preg_split( '/,\s*\n/', $body );

    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( '' === $line ) {
            continue;
        }
        // Skip key/index definitions.
        if ( preg_match( '/^(PRIMARY\s+KEY|UNIQUE\s+KEY|KEY|INDEX|FOREIGN\s+KEY)\b/i', $line ) ) {
            continue;
        }
        // A column definition starts with the column name (optionally backticked).
        if ( preg_match( '/^`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s+/', $line, $col ) ) {
            $columns[] = $col[1];
        }
    }

    return $columns;
}

/**
 * Scan a PHP source file for array-literal write calls into a custom pb_ table
 * and return the [table_short_name => columns[]] pairs discovered.
 *
 * Handles two shapes:
 *   1. $wpdb->insert( $wpdb->prefix . 'pb_<name>', array( 'col' => ..., ) )
 *      $wpdb->update( $wpdb->prefix . 'pb_<name>', array( 'col' => ..., ), ... )
 *   2. Peanut_Booker_Database::insert( '<name>', array( 'col' => ..., ) )
 *      Peanut_Booker_Database::update( '<name>', array( 'col' => ..., ), ... )
 *
 * Only the FIRST array literal after the table argument (the data array) is read.
 *
 * @param string $file Absolute path to a PHP source file.
 * @return array<int, array{table:string, columns:string[], file:string}>
 */
function peanut_booker_scan_writes( string $file ): array {
    $source = file_get_contents( $file );
    if ( false === $source ) {
        return array();
    }

    $writes = array();

    // Shape 1: $wpdb->insert/update with an inline prefix . 'pb_<name>' table.
    if ( preg_match_all(
        '/\$wpdb->(insert|update)\s*\(\s*\$wpdb->prefix\s*\.\s*[\'"]pb_([a-zA-Z0-9_]+)[\'"]\s*,\s*array\s*\((.*?)\)\s*\)/s',
        $source,
        $m1,
        PREG_SET_ORDER
    ) ) {
        foreach ( $m1 as $hit ) {
            $writes[] = array(
                'table'   => $hit[2],
                'columns' => peanut_booker_extract_assoc_keys( $hit[3] ),
                'file'    => $file,
            );
        }
    }

    // Shape 2: Peanut_Booker_Database::insert/update with a short table name.
    if ( preg_match_all(
        '/Peanut_Booker_Database::(insert|update)\s*\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*,\s*array\s*\((.*?)\)\s*(?:,|\))/s',
        $source,
        $m2,
        PREG_SET_ORDER
    ) ) {
        foreach ( $m2 as $hit ) {
            $writes[] = array(
                'table'   => $hit[2],
                'columns' => peanut_booker_extract_assoc_keys( $hit[3] ),
                'file'    => $file,
            );
        }
    }

    return $writes;
}

/**
 * Pull the top-level associative-array keys out of an array-literal body.
 * Nested arrays are skipped at depth; only depth-0 `'key' =>` pairs are returned.
 *
 * @param string $body The text inside array( ... ).
 * @return string[] Key names.
 */
function peanut_booker_extract_assoc_keys( string $body ): array {
    $keys  = array();
    $depth = 0;
    $len   = strlen( $body );
    $buf   = '';

    // Walk char-by-char so we only capture keys at the top nesting level.
    for ( $i = 0; $i < $len; $i++ ) {
        $ch = $body[ $i ];
        if ( '(' === $ch ) {
            $depth++;
            $buf = '';
            continue;
        }
        if ( ')' === $ch ) {
            $depth--;
            $buf = '';
            continue;
        }
        $buf .= $ch;
        if ( 0 === $depth && '>' === $ch && $i > 0 && '=' === $body[ $i - 1 ] ) {
            if ( preg_match( '/[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]\s*=>$/', trim( $buf ) ) ) {
                preg_match( '/[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]\s*=>$/', trim( $buf ), $k );
                $keys[] = $k[1];
            }
            $buf = '';
        }
    }

    return array_values( array_unique( $keys ) );
}

/**
 * Return every plugin PHP source file that may contain DB writes.
 *
 * @return string[] Absolute paths.
 */
function peanut_booker_source_files(): array {
    $dir   = PEANUT_BOOKER_TEST_ROOT . '/includes';
    $files = array();
    $it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
    foreach ( $it as $file ) {
        if ( $file->isFile() && 'php' === $file->getExtension() ) {
            $files[] = $file->getPathname();
        }
    }
    sort( $files );
    return $files;
}
