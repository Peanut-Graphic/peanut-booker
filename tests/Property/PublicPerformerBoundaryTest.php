<?php
declare(strict_types=1);
namespace Peanut_Booker\Tests\Property;

use PHPUnit\Framework\TestCase;

final class PublicPerformerBoundaryTest extends TestCase {
    public function test_public_performer_boundaries(): void {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/security/public-performer-boundary.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output);
        $this->assertStringContainsString('42 checks, 0 failures', $output);
    }
}
