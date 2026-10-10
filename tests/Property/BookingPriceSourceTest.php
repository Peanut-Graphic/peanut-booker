<?php
declare(strict_types=1);
namespace Peanut_Booker\Tests\Property;

use PHPUnit\Framework\TestCase;

/** Runs tests/security/booking-price-source.php in its own process (it defines synthetic WP/WC seams). */
final class BookingPriceSourceTest extends TestCase {
    public function test_booking_price_comes_from_the_server(): void {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/security/booking-price-source.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output);
        $this->assertStringContainsString('13 checks, 0 failures', $output);
    }
}
