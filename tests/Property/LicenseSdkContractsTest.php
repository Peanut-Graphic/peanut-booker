<?php
declare(strict_types=1);
namespace Peanut_Booker\Tests\Property;

use PHPUnit\Framework\TestCase;

final class LicenseSdkContractsTest extends TestCase {
    public function test_transport_and_response_contracts(): void {
        $root = dirname(__DIR__, 2);
        foreach (['pr98-client-requests.php', 'sdk-response-contract.php'] as $script) {
            $process = proc_open([PHP_BINARY, $root . '/scripts/tests/' . $script, $root . '/includes/class-peanut-license-client.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $output);
            $this->assertStringContainsString('PASS:', $output);
        }
    }
}
