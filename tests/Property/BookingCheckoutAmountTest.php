<?php
declare(strict_types=1);
namespace Peanut_Booker\Tests\Property;

use PHPUnit\Framework\TestCase;

/** Runs tests/security/booking-checkout-amount.php in its own process (it defines synthetic WP/WC seams). */
final class BookingCheckoutAmountTest extends TestCase {
    public function test_booking_is_only_marked_paid_when_the_order_charged_what_it_owes(): void {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/security/booking-checkout-amount.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output);
        $this->assertStringContainsString('44 checks, 0 failures', $output);
    }
}
