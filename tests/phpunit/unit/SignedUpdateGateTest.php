<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Booker refuses update packages that are not cryptographically ours.
 *
 * Booker shipped with no signature gate at all: whatever package a site was
 * handed for this plugin, it installed, on transport trust alone. Transport
 * trust is not authenticity.
 *
 * These tests pin the wiring rather than the crypto (the verifier itself is
 * covered in formflow-core). The wiring is what was missing, and it is the part
 * a future refactor can silently drop.
 */
final class SignedUpdateGateTest extends TestCase {

	private string $source;

	protected function setUp(): void {
		parent::setUp();
		$this->source = file_get_contents( dirname( __DIR__, 3 ) . '/peanut-booker.php' );
	}

	public function test_the_gate_is_registered_on_plugins_loaded(): void {
		$this->assertStringContainsString(
			"add_action( 'plugins_loaded', 'peanut_booker_register_update_gate', 1 )",
			$this->source,
			'The update gate is never registered, so nothing verifies an update package.'
		);
	}

	public function test_the_gate_is_constructed_with_this_plugins_identity(): void {
		$this->assertStringContainsString( 'PEANUT_BOOKER_BASENAME', $this->source );
		$this->assertStringContainsString( 'PEANUT_BOOKER_SIGNING_PUBKEY', $this->source );
		$this->assertStringContainsString( "'peanut-booker'", $this->source );
	}

	public function test_it_pins_the_fleet_signing_key(): void {
		// The key the central publisher signs manifests against. A different key
		// here means every legitimate release is refused, and — worse — a key an
		// attacker chose would mean none of them are.
		$this->assertStringContainsString(
			"define( 'PEANUT_BOOKER_SIGNING_PUBKEY', 'NtHnWTBLVzCBKMAq9CO8LHDSD9ZfpGV0UloQdgToIwM=' )",
			$this->source
		);
	}

	public function test_only_peanut_and_github_hosts_are_trusted(): void {
		$this->assertStringContainsString( "array( 'peanutgraphic.com', 'github.com' )", $this->source );
	}

	public function test_a_missing_verifier_warns_loudly_instead_of_failing_open(): void {
		// If vendor/ is stripped from a package, the gate cannot load. The plugin
		// must say so rather than quietly install unverified updates.
		$this->assertStringContainsString( 'Updates are NOT being verified', $this->source );
		$this->assertStringContainsString( 'admin_notices', $this->source );
	}

	public function test_formflow_core_is_a_declared_dependency(): void {
		$composer = json_decode( file_get_contents( dirname( __DIR__, 3 ) . '/composer.json' ), true );

		$this->assertArrayHasKey(
			'peanut/formflow-core',
			$composer['require'] ?? array(),
			'Without formflow-core in require, vendor/ will not carry the verifier and the gate degrades to a notice.'
		);
	}

	/**
	 * The behaviour PAR-407 is actually about: an unsigned, tampered or
	 * wrong-key package must be REFUSED.
	 *
	 * The tests above pin that the gate is wired. Registration is not refusal —
	 * a correctly wired verifier that accepts a tampered package is worse than
	 * no verifier, because it converts "unprotected" into "believed protected".
	 */
	public function test_unsigned_tampered_and_foreign_key_packages_are_refused(): void {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'libsodium unavailable' );
		}
		if ( ! class_exists( '\\Peanut\\FormCore\\Update\\PackageVerifier' ) ) {
			$this->markTestSkipped( 'formflow-core not installed in this environment' );
		}

		$verifier = '\\Peanut\\FormCore\\Update\\PackageVerifier';

		$kp    = sodium_crypto_sign_keypair();
		$pub   = base64_encode( sodium_crypto_sign_publickey( $kp ) );
		$sk    = sodium_crypto_sign_secretkey( $kp );
		$bytes = 'PK' . str_repeat( 'booker', 100 );

		$signed = array(
			'sha256'    => hash( 'sha256', $bytes ),
			'signature' => base64_encode( sodium_crypto_sign_detached( $bytes, $sk ) ),
		);

		// Control: without this, every assertion below would pass just as well
		// against a verifier that refuses everything.
		$this->assertTrue( $verifier::verifyBytes( $bytes, $signed, $pub ), 'a correctly signed package must be accepted' );

		$this->assertFalse( $verifier::verifyBytes( $bytes, array(), $pub ), 'an unsigned package must be refused' );
		$this->assertFalse( $verifier::verifyBytes( $bytes . 'evil', $signed, $pub ), 'a tampered package must be refused' );

		$other = sodium_crypto_sign_keypair();
		$this->assertFalse(
			$verifier::verifyBytes( $bytes, $signed, base64_encode( sodium_crypto_sign_publickey( $other ) ) ),
			'a package signed with a foreign key must be refused'
		);

		$this->assertFalse(
			$verifier::verifyBytes( $bytes, array( 'sha256' => hash( 'sha256', $bytes ) ), $pub ),
			'a manifest with a hash but no signature must be refused'
		);
	}

	public function test_only_peanut_hosts_over_tls_are_trusted(): void {
		if ( ! class_exists( '\\Peanut\\FormCore\\Update\\PackageVerifier' ) ) {
			$this->markTestSkipped( 'formflow-core not installed in this environment' );
		}
		$verifier = '\\Peanut\\FormCore\\Update\\PackageVerifier';
		$hosts    = array( 'peanutgraphic.com', 'github.com' );

		$this->assertTrue( $verifier::isTrustedPackageUrl( 'https://peanutgraphic.com/x.zip', $hosts ) );
		$this->assertFalse( $verifier::isTrustedPackageUrl( 'https://evilpeanutgraphic.com/x.zip', $hosts ) );
		$this->assertFalse( $verifier::isTrustedPackageUrl( 'http://peanutgraphic.com/x.zip', $hosts ), 'plaintext must not be trusted' );
	}
}
