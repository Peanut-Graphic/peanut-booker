<?php
declare(strict_types=1);
namespace Peanut_Booker\Tests\Property;

use PHPUnit\Framework\TestCase;

final class PublicMarketBoundaryTest extends TestCase {
    public function test_public_market_callbacks_do_not_disclose_private_data(): void {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/security/public-market-boundary.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output);
        $this->assertStringContainsString('15 checks, 0 failures', $output);
    }
}
