<?php
/**
 * The rate limiter must key on the connecting address, not on headers the
 * client controls. X-Forwarded-For / X-Real-IP are only honoured when the
 * request arrives from a configured trusted proxy (none by default).
 *
 * @package Peanut_Booker\Tests
 */

namespace Peanut_Booker\Tests\SecurityCore;

use Peanut_Booker\Tests\TestCase;
use Peanut_Booker_Rate_Limiter;

class RateLimiterClientIpTest extends TestCase {

    private array $server_backup = array();

    protected function setUp(): void {
        parent::setUp();
        global $mock_options, $mock_transients;
        $mock_options    = array();
        $mock_transients = array();
        $this->server_backup = $_SERVER;
        unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'] );
    }

    protected function tearDown(): void {
        $_SERVER = $this->server_backup;
        global $mock_options, $mock_transients;
        $mock_options    = array();
        $mock_transients = array();
        parent::tearDown();
    }

    public function test_spoofed_forwarded_for_cannot_bypass_the_limit(): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        for ( $request = 1; $request <= 10; $request++ ) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.' . $request;
            $_SERVER['HTTP_X_REAL_IP']       = '192.0.2.' . $request;
            $this->assertTrue( Peanut_Booker_Rate_Limiter::check( 'booking' )['allowed'], "request $request" );
        }

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';
        $this->assertFalse( Peanut_Booker_Rate_Limiter::check( 'booking' )['allowed'], 'a fresh X-Forwarded-For value does not reset the limit' );
    }

    public function test_untrusted_peer_is_identified_by_remote_addr(): void {
        $_SERVER['REMOTE_ADDR']          = '203.0.113.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, 10.0.0.1';
        $_SERVER['HTTP_X_REAL_IP']       = '198.51.100.2';
        $this->assertSame( '203.0.113.7', Peanut_Booker_Rate_Limiter::get_client_ip() );
    }

    public function test_trusted_proxy_forwards_the_client_address(): void {
        update_option( 'peanut_booker_trusted_proxies', array( '10.0.0.5' ) );
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';

        // The client may prepend anything; the proxy appends the real peer.
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 198.51.100.23';
        $this->assertSame( '198.51.100.23', Peanut_Booker_Rate_Limiter::get_client_ip() );

        unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
        $_SERVER['HTTP_X_REAL_IP'] = '198.51.100.24';
        $this->assertSame( '198.51.100.24', Peanut_Booker_Rate_Limiter::get_client_ip() );
    }

    public function test_chain_of_trusted_proxies_and_cidr_ranges(): void {
        update_option( 'peanut_booker_trusted_proxies', '10.0.0.0/8, 2001:db8::/32' );
        $_SERVER['REMOTE_ADDR']          = '10.1.2.3';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.50, 10.9.9.9';
        $this->assertSame( '198.51.100.50', Peanut_Booker_Rate_Limiter::get_client_ip() );

        $_SERVER['REMOTE_ADDR']          = '2001:db8::1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '2001:db8:ffff::2, 198.51.100.51';
        $this->assertSame( '198.51.100.51', Peanut_Booker_Rate_Limiter::get_client_ip() );
    }

    public function test_garbage_forwarded_values_fall_back_to_the_proxy(): void {
        update_option( 'peanut_booker_trusted_proxies', array( '10.0.0.5' ) );
        $_SERVER['REMOTE_ADDR']          = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip, <script>';
        $this->assertSame( '10.0.0.5', Peanut_Booker_Rate_Limiter::get_client_ip() );
    }

    public function test_no_trusted_proxies_by_default(): void {
        $_SERVER['REMOTE_ADDR']          = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.23';
        $this->assertSame( '10.0.0.5', Peanut_Booker_Rate_Limiter::get_client_ip() );
    }
}
