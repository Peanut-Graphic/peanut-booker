<?php
/**
 * Authenticated (V2) encryption contract for Booker data at rest.
 *
 * V2 = XChaCha20-Poly1305 (ext-sodium / sodium_compat), framed as
 * ENCRYPTED_PREFIX . 'v2:' . base64(nonce . ciphertext). Legacy AES-256-CBC
 * values written by <= 1.7.3 must keep decrypting. The guessable fallback key
 * (used only when AUTH_KEY is missing/default) must never encrypt new data.
 *
 * @package Peanut_Booker\Tests
 */

namespace Peanut_Booker\Tests\SecurityCore;

use Peanut_Booker\Tests\TestCase;
use Peanut_Booker_Encryption;

class AuthenticatedEncryptionTest extends TestCase {

	/**
	 * Written by the 1.7.3 implementation (AES-256-CBC, PBKDF2 over the
	 * bootstrap AUTH_KEY . SECURE_AUTH_KEY). Frozen: if this stops decrypting,
	 * every existing booking address on every site stops decrypting.
	 */
	private const LEGACY_CBC_FIXTURE = '$PB_ENC$fcCDtxfw5umQHkK9loL9CG+CDqyz224wgJqYhHGsSrZFuw3IeEjN6q46rDhzVwp+Owtseme0av3Cq3PG5xO+zw==';
	private const LEGACY_CBC_PLAINTEXT = '123 Legacy Lane, Montclair, NJ 07042';

	/**
	 * Legacy CBC under the fallback key for material
	 * 'https://booker.example' . 'wp_' . '/var/www/html/'.
	 */
	private const LEGACY_FALLBACK_FIXTURE = '$PB_ENC$BwcHBwcHBwcHBwcHBwcHBzWhgcfarGwfErCWB5WNng4=';
	private const LEGACY_FALLBACK_PLAINTEXT = '555-0100';
	private const FALLBACK_MATERIAL = 'https://booker.example' . 'wp_' . '/var/www/html/';

	private function fallback_keyring(): array {
		return Peanut_Booker_Encryption::derive_keyring( null, null, self::FALLBACK_MATERIAL );
	}

	public function test_new_writes_use_the_v2_authenticated_format(): void {
		$plaintext = '123 Test Street, Montclair, NJ';

		$stored = Peanut_Booker_Encryption::encrypt( $plaintext );

		$this->assertStringStartsWith( Peanut_Booker_Encryption::V2_PREFIX, $stored );
		$this->assertStringStartsWith( Peanut_Booker_Encryption::ENCRYPTED_PREFIX, $stored );
		$this->assertTrue( Peanut_Booker_Encryption::is_encrypted( $stored ) );
		$this->assertStringNotContainsString( 'Montclair', $stored );
		$this->assertSame( $plaintext, Peanut_Booker_Encryption::decrypt( $stored ) );
	}

	public function test_v2_payload_is_nonce_plus_ciphertext_with_tag(): void {
		$stored = Peanut_Booker_Encryption::encrypt( 'abc' );
		$raw = base64_decode( substr( $stored, strlen( Peanut_Booker_Encryption::V2_PREFIX ) ), true );

		$this->assertNotFalse( $raw );
		$this->assertSame(
			SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES + 3 + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES,
			strlen( $raw )
		);
	}

	/**
	 * @dataProvider tamper_offsets
	 */
	public function test_tampered_v2_ciphertext_fails_closed( string $region ): void {
		$plaintext = '07042';
		$stored = Peanut_Booker_Encryption::encrypt( $plaintext );
		$raw = base64_decode( substr( $stored, strlen( Peanut_Booker_Encryption::V2_PREFIX ) ), true );

		$nonce_len = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
		$offset = array(
			'nonce'      => 0,
			'ciphertext' => $nonce_len,
			'tag'        => strlen( $raw ) - 1,
		)[ $region ];
		$raw[ $offset ] = chr( ord( $raw[ $offset ] ) ^ 0x01 );
		$tampered = Peanut_Booker_Encryption::V2_PREFIX . base64_encode( $raw );

		$result = Peanut_Booker_Encryption::decrypt( $tampered );

		$this->assertNotSame( $plaintext, $result );
		$this->assertSame( $tampered, $result, 'A failed V2 decrypt returns the stored value, never guessed plaintext.' );
	}

	public static function tamper_offsets(): array {
		return array(
			'nonce'      => array( 'nonce' ),
			'ciphertext' => array( 'ciphertext' ),
			'tag'        => array( 'tag' ),
		);
	}

	public function test_truncated_v2_payload_fails_closed(): void {
		$short = Peanut_Booker_Encryption::V2_PREFIX . base64_encode( str_repeat( "\0", 20 ) );

		$this->assertSame( $short, Peanut_Booker_Encryption::decrypt( $short ) );
	}

	public function test_legacy_cbc_value_from_1_7_3_still_decrypts(): void {
		$this->assertSame( self::LEGACY_CBC_PLAINTEXT, Peanut_Booker_Encryption::decrypt( self::LEGACY_CBC_FIXTURE ) );
	}

	public function test_wrong_key_fails_for_v2(): void {
		$a = Peanut_Booker_Encryption::derive_keyring( str_repeat( 'a', 64 ), str_repeat( 'b', 64 ), 'unused' );
		$b = Peanut_Booker_Encryption::derive_keyring( str_repeat( 'a', 64 ), str_repeat( 'c', 64 ), 'unused' );

		$stored = Peanut_Booker_Encryption::encrypt( 'secret phone', $a );

		$this->assertSame( 'secret phone', Peanut_Booker_Encryption::decrypt( $stored, $a ) );
		$this->assertSame( $stored, Peanut_Booker_Encryption::decrypt( $stored, $b ) );
	}

	public function test_v2_key_uses_full_key_material_including_secure_auth_key(): void {
		$a = Peanut_Booker_Encryption::derive_keyring( str_repeat( 'a', 64 ), 'x', 'unused' );
		$b = Peanut_Booker_Encryption::derive_keyring( str_repeat( 'a', 64 ), 'y', 'unused' );

		$this->assertNotSame( $a['v2'], $b['v2'] );
		$this->assertSame( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, strlen( $a['v2'] ) );
		$this->assertNotSame( $a['legacy'], $a['v2'], 'V2 subkey is separated from the legacy key.' );
	}

	public function test_fallback_key_refuses_to_encrypt_new_data(): void {
		$keyring = $this->fallback_keyring();

		$this->assertTrue( $keyring['fallback'] );
		$this->assertNull( $keyring['v2'] );
		$this->assertSame( '555-0199', Peanut_Booker_Encryption::encrypt( '555-0199', $keyring ) );
		$this->assertFalse( Peanut_Booker_Encryption::can_encrypt( $keyring ) );
	}

	public function test_fallback_key_still_decrypts_legacy_data(): void {
		$this->assertSame(
			self::LEGACY_FALLBACK_PLAINTEXT,
			Peanut_Booker_Encryption::decrypt( self::LEGACY_FALLBACK_FIXTURE, $this->fallback_keyring() )
		);
	}

	public function test_default_auth_key_is_treated_as_fallback(): void {
		$default = Peanut_Booker_Encryption::derive_keyring( 'put your unique phrase here', 'put your unique phrase here', 'm' );
		$short = Peanut_Booker_Encryption::derive_keyring( 'too-short', 'x', 'm' );

		$this->assertTrue( $default['fallback'] );
		$this->assertTrue( $short['fallback'] );
		$this->assertTrue( Peanut_Booker_Encryption::can_encrypt() );
	}

	public function test_needs_reencrypt_flags_only_legacy_values_when_v2_is_writable(): void {
		$this->assertTrue( Peanut_Booker_Encryption::needs_reencrypt( self::LEGACY_CBC_FIXTURE ) );
		$this->assertFalse( Peanut_Booker_Encryption::needs_reencrypt( Peanut_Booker_Encryption::encrypt( 'x' ) ) );
		$this->assertFalse( Peanut_Booker_Encryption::needs_reencrypt( 'plain' ) );
		$this->assertFalse( Peanut_Booker_Encryption::needs_reencrypt( '' ) );
		$this->assertFalse( Peanut_Booker_Encryption::needs_reencrypt( null ) );
		$this->assertFalse(
			Peanut_Booker_Encryption::needs_reencrypt( self::LEGACY_FALLBACK_FIXTURE, $this->fallback_keyring() )
		);
	}

	public function test_encrypting_a_legacy_value_upgrades_it_to_v2(): void {
		$upgraded = Peanut_Booker_Encryption::encrypt( self::LEGACY_CBC_FIXTURE );

		$this->assertStringStartsWith( Peanut_Booker_Encryption::V2_PREFIX, $upgraded );
		$this->assertSame( self::LEGACY_CBC_PLAINTEXT, Peanut_Booker_Encryption::decrypt( $upgraded ) );
	}

	public function test_undecryptable_legacy_value_is_left_untouched_on_encrypt(): void {
		$corrupt = Peanut_Booker_Encryption::ENCRYPTED_PREFIX . base64_encode( str_repeat( "\x01", 48 ) );

		$this->assertSame( $corrupt, Peanut_Booker_Encryption::encrypt( $corrupt ) );
	}
}
