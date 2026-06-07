<?php
/**
 * Property-based tests (net 6) for pure Peanut Booker logic.
 *
 * Each test asserts an INVARIANT that must hold across a wide, seeded space of
 * inputs — not a single hand-picked example. Seeds are fixed so the suite is
 * deterministic (per the testing-standard determinism rule). A failing property
 * indicates a real bug in the production code, never a reason to weaken the
 * assertion.
 *
 * Targets (all pure — no WordPress runtime, no $wpdb):
 *   - Peanut_Booker_Booking::verify_booking_amount   (tolerance-bounded check)
 *   - Peanut_Booker_Market::format_budget_range      (price-range formatter)
 *   - Peanut_Booker_Availability::get_status_color   (total status->color map)
 *
 * @package Peanut_Booker\Tests\Property
 */

declare( strict_types=1 );

namespace Peanut_Booker\Tests\Property;

use PHPUnit\Framework\TestCase;
use Peanut_Booker_Booking;
use Peanut_Booker_Market;
use Peanut_Booker_Availability;
use WP_Error;

final class BookerPropertyTest extends TestCase {

    private const RUNS = 500;

    public static function setUpBeforeClass(): void {
        // Works under either bootstrap: the dedicated Property bootstrap already
        // loads these, and the legacy unit bootstrap loads them too — but guard so
        // the suite is runnable in isolation regardless of which config invoked it.
        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
        }
        if ( ! defined( 'WPINC' ) ) {
            define( 'WPINC', 'wp-includes' );
        }
        $includes = dirname( __DIR__, 2 ) . '/includes';
        foreach ( array( 'class-booking', 'class-market', 'class-availability' ) as $f ) {
            if ( is_file( "$includes/$f.php" ) ) {
                require_once "$includes/$f.php";
            }
        }
    }

    protected function setUp(): void {
        // Fixed seed → identical input sequence on every run / CI machine.
        mt_srand( 424242 );
    }

    private function randFloat( float $min, float $max ): float {
        return $min + ( mt_rand() / mt_getrandmax() ) * ( $max - $min );
    }

    // ---------------------------------------------------------------------
    // verify_booking_amount — tolerance-bounded amount verification.
    //
    // Invariants:
    //  (a) Exact match always verifies true, for any positive calculated amount.
    //  (b) Any client amount within the tolerance band [calc*(1-t/100),
    //      calc*(1+t/100)] verifies true; anything strictly outside is a WP_Error.
    //  (c) Symmetry: a relative deviation of +d% and -d% are treated identically
    //      (both pass or both fail), since the check uses abs().
    //  (d) Non-positive calculated amount is always rejected (guards div-by-zero
    //      and nonsensical pricing) regardless of client amount.
    // ---------------------------------------------------------------------

    public function test_exact_match_always_verifies(): void {
        for ( $i = 0; $i < self::RUNS; $i++ ) {
            $calc = $this->randFloat( 0.01, 100000.0 );
            $result = Peanut_Booker_Booking::verify_booking_amount( $calc, $calc, 1.0 );
            $this->assertTrue(
                $result,
                sprintf( 'Exact match should verify true; calc=%.6f', $calc )
            );
        }
    }

    public function test_within_tolerance_passes_outside_fails(): void {
        for ( $i = 0; $i < self::RUNS; $i++ ) {
            $calc      = $this->randFloat( 1.0, 50000.0 );
            $tolerance = $this->randFloat( 0.1, 25.0 );
            $deviation = $this->randFloat( -40.0, 40.0 ); // percent

            $client = $calc * ( 1.0 + $deviation / 100.0 );
            $result = Peanut_Booker_Booking::verify_booking_amount( $client, $calc, $tolerance );

            // The production check: difference% = |client-calc|/calc*100;
            // pass iff difference% <= tolerance.
            $diffPercent = abs( ( $client - $calc ) / $calc ) * 100.0;

            if ( $diffPercent <= $tolerance ) {
                $this->assertTrue(
                    $result,
                    sprintf( 'Should pass: diff=%.6f%% <= tol=%.6f%%', $diffPercent, $tolerance )
                );
            } else {
                $this->assertInstanceOf(
                    WP_Error::class,
                    $result,
                    sprintf( 'Should fail: diff=%.6f%% > tol=%.6f%%', $diffPercent, $tolerance )
                );
            }
        }
    }

    public function test_deviation_is_symmetric(): void {
        for ( $i = 0; $i < self::RUNS; $i++ ) {
            $calc      = $this->randFloat( 1.0, 50000.0 );
            $tolerance = $this->randFloat( 0.5, 20.0 );
            $deviation = $this->randFloat( 0.1, 30.0 ); // percent magnitude

            $over  = Peanut_Booker_Booking::verify_booking_amount( $calc * ( 1 + $deviation / 100 ), $calc, $tolerance );
            $under = Peanut_Booker_Booking::verify_booking_amount( $calc * ( 1 - $deviation / 100 ), $calc, $tolerance );

            $this->assertSame(
                $over === true,
                $under === true,
                sprintf( '+/-%.4f%% deviation must be treated identically (calc=%.4f, tol=%.4f)', $deviation, $calc, $tolerance )
            );
        }
    }

    public function test_nonpositive_calculated_always_rejected(): void {
        for ( $i = 0; $i < self::RUNS; $i++ ) {
            $calc   = $this->randFloat( -10000.0, 0.0 ); // <= 0
            $client = $this->randFloat( -10000.0, 10000.0 );
            $result = Peanut_Booker_Booking::verify_booking_amount( $client, $calc, 1.0 );
            $this->assertInstanceOf(
                WP_Error::class,
                $result,
                sprintf( 'Non-positive calc=%.6f must be rejected', $calc )
            );
        }
    }

    // ---------------------------------------------------------------------
    // format_budget_range — pure price-range formatter.
    //
    // Invariants:
    //  (a) Totality: always returns a non-empty string for any numeric input.
    //  (b) When both bounds are positive, BOTH formatted numbers appear in the
    //      output (no silent dropping of a bound).
    //  (c) The output never contains a raw negative sign for a bound — negatives
    //      and zero collapse to the "flexible"/single-bound phrasings, so a
    //      "$-" token must never leak into the rendered range.
    // ---------------------------------------------------------------------

    public function test_budget_range_is_total_nonempty_string(): void {
        for ( $i = 0; $i < self::RUNS; $i++ ) {
            $min = $this->randFloat( -5000.0, 100000.0 );
            $max = $this->randFloat( -5000.0, 100000.0 );
            $out = Peanut_Booker_Market::format_budget_range( $min, $max );
            $this->assertIsString( $out );
            $this->assertNotSame( '', trim( $out ), sprintf( 'Empty output for min=%.2f max=%.2f', $min, $max ) );
        }
    }

    public function test_budget_range_includes_both_positive_bounds(): void {
        for ( $i = 0; $i < self::RUNS; $i++ ) {
            $min = $this->randFloat( 1.0, 100000.0 );
            $max = $this->randFloat( 1.0, 100000.0 );
            $out = Peanut_Booker_Market::format_budget_range( $min, $max );

            $this->assertStringContainsString(
                number_format( floatval( $min ) ),
                $out,
                sprintf( 'Min bound %.2f missing from "%s"', $min, $out )
            );
            $this->assertStringContainsString(
                number_format( floatval( $max ) ),
                $out,
                sprintf( 'Max bound %.2f missing from "%s"', $max, $out )
            );
        }
    }

    public function test_budget_range_never_renders_negative_token(): void {
        for ( $i = 0; $i < self::RUNS; $i++ ) {
            $min = $this->randFloat( -100000.0, 100000.0 );
            $max = $this->randFloat( -100000.0, 100000.0 );
            $out = Peanut_Booker_Market::format_budget_range( $min, $max );
            $this->assertStringNotContainsString(
                '$-',
                $out,
                sprintf( 'Negative bound leaked into "%s" (min=%.2f max=%.2f)', $out, $min, $max )
            );
        }
    }

    // ---------------------------------------------------------------------
    // get_status_color — total status -> hex color map.
    //
    // Invariants:
    //  (a) Totality: always returns a 7-char "#rrggbb" hex string, even for an
    //      unknown status (falls back to gray) — never empty / null.
    //  (b) Determinism: same (status, block_type) always yields the same color.
    //  (c) External-gig precedence: whenever block_type is the external-gig type,
    //      the color is the external-gig purple REGARDLESS of status.
    // ---------------------------------------------------------------------

    public function test_status_color_is_total_hex(): void {
        $statuses = array(
            Peanut_Booker_Availability::STATUS_AVAILABLE,
            Peanut_Booker_Availability::STATUS_BOOKED,
            Peanut_Booker_Availability::STATUS_BLOCKED,
            Peanut_Booker_Availability::STATUS_EXTERNAL_GIG,
            'past',
            'totally-unknown-status',
            '',
        );
        $blockTypes = array(
            null,
            Peanut_Booker_Availability::BLOCK_TYPE_MANUAL,
            Peanut_Booker_Availability::BLOCK_TYPE_BOOKING,
            Peanut_Booker_Availability::BLOCK_TYPE_EXTERNAL_GIG,
            Peanut_Booker_Availability::BLOCK_TYPE_VACATION,
        );

        foreach ( $statuses as $status ) {
            foreach ( $blockTypes as $bt ) {
                $color = Peanut_Booker_Availability::get_status_color( $status, $bt );
                $this->assertMatchesRegularExpression(
                    '/^#[0-9a-fA-F]{6}$/',
                    $color,
                    sprintf( 'Non-hex color "%s" for status="%s" block="%s"', $color, $status, (string) $bt )
                );

                // Determinism.
                $this->assertSame(
                    $color,
                    Peanut_Booker_Availability::get_status_color( $status, $bt ),
                    'get_status_color must be deterministic'
                );

                // External-gig precedence.
                if ( $bt === Peanut_Booker_Availability::BLOCK_TYPE_EXTERNAL_GIG ) {
                    $this->assertSame(
                        '#9333ea',
                        $color,
                        sprintf( 'External-gig block must force purple regardless of status="%s"', $status )
                    );
                }
            }
        }
    }
}
