<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Data encryption for sensitive fields.
 *
 * Two stored formats, both starting with ENCRYPTED_PREFIX:
 *
 *  - V2 (written since the authenticated-encryption fix): XChaCha20-Poly1305
 *    AEAD via ext-sodium (or WordPress's bundled sodium_compat), framed as
 *    '$PB_ENC$v2:' . base64(nonce . ciphertext), with fixed associated data.
 *    Tampering or a wrong key fails decryption. The key is an HKDF-SHA256
 *    subkey over the full AUTH_KEY + SECURE_AUTH_KEY material.
 *
 *  - LEGACY (read only): AES-256-CBC, '$PB_ENC$' . base64(iv . ciphertext),
 *    key = PBKDF2-SHA256(AUTH_KEY . SECURE_AUTH_KEY, 10000 iterations). No MAC,
 *    so it is malleable. Kept readable so existing data keeps decrypting;
 *    encrypt() upgrades a legacy value to V2 when one is passed back in, and
 *    needs_reencrypt() lets callers migrate lazily. The V2 marker 'v2:' cannot
 *    collide with legacy output, whose base64 alphabet has no ':'.
 *
 * FALLBACK KEY: when AUTH_KEY is missing, the WordPress default, or shorter
 * than 32 bytes, the only key available is derived from md5(site_url . table
 * prefix . ABSPATH), which anyone holding the database can reconstruct. That
 * key is used ONLY to decrypt legacy data written under it; new values are
 * NOT encrypted with it (encrypt() returns them unchanged, logs, fires
 * 'peanut_booker_encryption_failed', and admins see an error notice).
 *
 * @package Peanut_Booker
 * @since   1.3.0
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Encryption class for protecting sensitive data at rest.
 */
class Peanut_Booker_Encryption {

	/**
	 * Legacy cipher (read only).
	 */
	const CIPHER = 'aes-256-cbc';

	/**
	 * Prefix for encrypted values to identify them (both formats).
	 */
	const ENCRYPTED_PREFIX = '$PB_ENC$';

	/**
	 * Prefix for V2 (authenticated) values.
	 */
	const V2_PREFIX = '$PB_ENC$v2:';

	/**
	 * HKDF info string for the V2 subkey.
	 */
	const V2_INFO = 'peanut-booker/v2/data-at-rest';

	/**
	 * Associated data bound into every V2 ciphertext.
	 */
	const V2_AD = 'peanut-booker/v2';

	/**
	 * Minimum AUTH_KEY length accepted as a real key.
	 */
	const MIN_KEY_LENGTH = 32;

	/**
	 * Keyring derived from the site's constants, cached per request.
	 *
	 * @var array|null
	 */
	private static $default_keyring = null;

	/**
	 * Flag to track if we've logged a warning about weak encryption keys.
	 *
	 * @var bool
	 */
	private static $weak_key_warning_logged = false;

	/**
	 * Derive the keys for a given set of key inputs.
	 *
	 * Pure function of its arguments, so it is testable without WordPress.
	 *
	 * @param string|null $auth_key          AUTH_KEY value, or null when undefined.
	 * @param string|null $secure_auth_key   SECURE_AUTH_KEY value, or null when undefined.
	 * @param string      $fallback_material site_url . table prefix . ABSPATH (legacy fallback input).
	 * @return array{legacy:string, v2:?string, fallback:bool}
	 */
	public static function derive_keyring( $auth_key, $secure_auth_key, $fallback_material ) {
		$fallback = self::is_fallback_key( $auth_key );

		if ( $fallback ) {
			// Legacy fallback derivation, preserved verbatim so old data decrypts.
			$salt = 'pb_fallback_' . md5( (string) $fallback_material );
			$v2   = null;
		} else {
			$secure = is_string( $secure_auth_key ) ? $secure_auth_key : '';
			$salt   = $auth_key . $secure;
			// Length-prefixed so the two constants cannot be shifted into each other.
			$v2 = hash_hkdf(
				'sha256',
				'auth_key:' . strlen( $auth_key ) . ':' . $auth_key . '|secure_auth_key:' . strlen( $secure ) . ':' . $secure,
				32,
				self::V2_INFO
			);
		}

		return array(
			'legacy'   => hash_pbkdf2( 'sha256', $salt, 'peanut-booker-encryption', 10000, 32, true ),
			'v2'       => $v2,
			'fallback' => $fallback,
		);
	}

	/**
	 * Whether AUTH_KEY is unusable (missing, WordPress default, or too short).
	 *
	 * @param string|null $auth_key AUTH_KEY value.
	 * @return bool
	 */
	private static function is_fallback_key( $auth_key ) {
		return ! is_string( $auth_key )
			|| 'put your unique phrase here' === $auth_key
			|| strlen( $auth_key ) < self::MIN_KEY_LENGTH;
	}

	/**
	 * Keyring for this site, from wp-config constants.
	 *
	 * @return array{legacy:string, v2:?string, fallback:bool}
	 */
	private static function site_keyring() {
		if ( null !== self::$default_keyring ) {
			return self::$default_keyring;
		}

		$auth_key        = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : null;
		$secure_auth_key = defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY : null;

		$fallback_material = '';
		if ( self::is_fallback_key( $auth_key ) ) {
			$site_url          = function_exists( 'get_site_url' ) ? get_site_url() : '';
			$db_prefix         = $GLOBALS['wpdb']->prefix ?? 'wp_';
			$fallback_material = $site_url . $db_prefix . ABSPATH;
			self::warn_fallback_key();
		}

		self::$default_keyring = self::derive_keyring( $auth_key, $secure_auth_key, $fallback_material );

		return self::$default_keyring;
	}

	/**
	 * Log and surface an admin error when only the guessable fallback key exists.
	 */
	private static function warn_fallback_key() {
		if ( self::$weak_key_warning_logged ) {
			return;
		}
		self::$weak_key_warning_logged = true;

		error_log( 'Peanut Booker SECURITY ERROR: AUTH_KEY is missing, default, or too short. ' .
			'New sensitive values (event address, ZIP, phone) will be stored UNENCRYPTED until ' .
			'WordPress security keys are configured in wp-config.php. Existing encrypted values remain readable. ' .
			'Visit: https://api.wordpress.org/secret-key/1.1/salt/' );

		if ( function_exists( 'add_action' ) ) {
			add_action( 'admin_notices', function() {
				if ( current_user_can( 'manage_options' ) ) {
					echo '<div class="notice notice-error"><p>';
					echo '<strong>Peanut Booker Security Error:</strong> ';
					echo 'WordPress AUTH_KEY is not properly configured, so Peanut Booker cannot encrypt new ';
					echo 'sensitive data (event addresses, ZIP codes, phone numbers) and is storing it unencrypted. ';
					echo 'Add security keys to wp-config.php. Existing encrypted data remains readable. ';
					echo 'Warning: changing AUTH_KEY or SECURE_AUTH_KEY later makes values encrypted under the old keys unreadable. ';
					echo '<a href="https://api.wordpress.org/secret-key/1.1/salt/" target="_blank" rel="noopener noreferrer">Generate keys here</a>.';
					echo '</p></div>';
				}
			} );
		}
	}

	/**
	 * Whether new values can be encrypted (a real, non-fallback key exists).
	 *
	 * @param array|null $keyring Keyring from derive_keyring(); defaults to the site's.
	 * @return bool
	 */
	public static function can_encrypt( $keyring = null ) {
		$keyring = $keyring ?? self::site_keyring();
		return ! $keyring['fallback'];
	}

	/**
	 * Whether V2 can be written (sodium or WordPress's sodium_compat is loaded).
	 *
	 * @return bool
	 */
	private static function v2_available() {
		return function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' );
	}

	/**
	 * Encrypt a value.
	 *
	 * Writes V2. A value that is already V2 is returned unchanged; a legacy
	 * value is decrypted and re-encrypted as V2. With only the fallback key
	 * available, the value is returned unencrypted (see class docblock).
	 *
	 * @param string     $value   The plaintext value to encrypt.
	 * @param array|null $keyring Keyring from derive_keyring(); defaults to the site's.
	 * @return string The encrypted value.
	 */
	public static function encrypt( $value, $keyring = null ) {
		if ( empty( $value ) || ! is_string( $value ) ) {
			return $value;
		}

		$keyring = $keyring ?? self::site_keyring();

		if ( self::is_encrypted( $value ) ) {
			if ( ! self::needs_reencrypt( $value, $keyring ) ) {
				return $value;
			}
			// Lazy migration: legacy CBC -> V2. Leave it untouched if it will not decrypt.
			$plaintext = self::decrypt_legacy( $value, $keyring['legacy'] );
			if ( null === $plaintext ) {
				return $value;
			}
			$value = $plaintext;
		}

		if ( $keyring['fallback'] ) {
			// SECURITY: refuse to encrypt under a guessable key; see class docblock.
			do_action( 'peanut_booker_encryption_failed', 'encrypt', 'fallback_key' );
			return $value;
		}

		if ( ! self::v2_available() ) {
			// Degraded path: no sodium at all. Legacy CBC under a real key beats plaintext.
			error_log( 'Peanut Booker SECURITY WARNING: sodium unavailable; writing legacy AES-256-CBC.' );
			return self::encrypt_legacy( $value, $keyring['legacy'] );
		}

		$nonce      = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $value, self::V2_AD, $nonce, $keyring['v2'] );

		return self::V2_PREFIX . base64_encode( $nonce . $ciphertext );
	}

	/**
	 * Decrypt a value (V2 or legacy).
	 *
	 * Fails closed: on any failure (tampering, wrong key, corruption) the stored
	 * value is returned unchanged, never partially decrypted plaintext.
	 *
	 * @param string     $value   The encrypted value.
	 * @param array|null $keyring Keyring from derive_keyring(); defaults to the site's.
	 * @return string The decrypted plaintext value.
	 */
	public static function decrypt( $value, $keyring = null ) {
		if ( empty( $value ) || ! is_string( $value ) ) {
			return $value;
		}

		if ( ! self::is_encrypted( $value ) ) {
			return $value;
		}

		$keyring = $keyring ?? self::site_keyring();

		$plaintext = self::is_v2( $value )
			? self::decrypt_v2( $value, $keyring['v2'] )
			: self::decrypt_legacy( $value, $keyring['legacy'] );

		if ( null === $plaintext ) {
			error_log( 'Peanut Booker SECURITY WARNING: Decryption failed. ' .
				'This could indicate key mismatch, data corruption, or tampering.' );
			do_action( 'peanut_booker_encryption_failed', 'decrypt', self::is_v2( $value ) ? 'v2' : 'legacy' );
			return $value;
		}

		return $plaintext;
	}

	/**
	 * True when a stored value should be rewritten: it is legacy CBC and this
	 * site can write V2. Callers can decrypt + encrypt + save to migrate.
	 *
	 * @param mixed      $stored  Stored value.
	 * @param array|null $keyring Keyring from derive_keyring(); defaults to the site's.
	 * @return bool
	 */
	public static function needs_reencrypt( $stored, $keyring = null ) {
		if ( ! self::is_encrypted( $stored ) || self::is_v2( $stored ) ) {
			return false;
		}
		$keyring = $keyring ?? self::site_keyring();
		return ! $keyring['fallback'];
	}

	/**
	 * Check if a value is encrypted (either format).
	 *
	 * @param string $value Value to check.
	 * @return bool True if encrypted.
	 */
	public static function is_encrypted( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}
		return strpos( $value, self::ENCRYPTED_PREFIX ) === 0;
	}

	/**
	 * Check if a value is in the V2 format.
	 *
	 * @param mixed $value Value to check.
	 * @return bool
	 */
	private static function is_v2( $value ) {
		return is_string( $value ) && strpos( $value, self::V2_PREFIX ) === 0;
	}

	/**
	 * @return string|null Plaintext, or null on any failure.
	 */
	private static function decrypt_v2( $value, $key ) {
		if ( null === $key || ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' ) ) {
			return null;
		}

		$data      = base64_decode( substr( $value, strlen( self::V2_PREFIX ) ), true );
		$nonce_len = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

		if ( false === $data || strlen( $data ) < $nonce_len + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES ) {
			return null;
		}

		try {
			$plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
				substr( $data, $nonce_len ),
				self::V2_AD,
				substr( $data, 0, $nonce_len ),
				$key
			);
		} catch ( \SodiumException $e ) {
			return null;
		}

		return false === $plaintext ? null : $plaintext;
	}

	/**
	 * Write the legacy format (only when sodium is unavailable).
	 */
	private static function encrypt_legacy( $value, $key ) {
		$iv        = random_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$encrypted = openssl_encrypt( $value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $encrypted ) {
			error_log( 'Peanut Booker SECURITY ERROR: Encryption failed. OpenSSL error: ' . openssl_error_string() );
			do_action( 'peanut_booker_encryption_failed', 'encrypt', openssl_error_string() );
			return $value;
		}

		return self::ENCRYPTED_PREFIX . base64_encode( $iv . $encrypted );
	}

	/**
	 * @return string|null Plaintext, or null on any failure.
	 */
	private static function decrypt_legacy( $value, $key ) {
		$data = base64_decode( substr( $value, strlen( self::ENCRYPTED_PREFIX ) ), true );
		if ( false === $data ) {
			return null;
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		if ( strlen( $data ) <= $iv_length ) {
			return null;
		}

		$decrypted = openssl_decrypt( substr( $data, $iv_length ), self::CIPHER, $key, OPENSSL_RAW_DATA, substr( $data, 0, $iv_length ) );

		return false === $decrypted ? null : $decrypted;
	}

	/**
	 * Encrypt multiple fields in an array.
	 *
	 * @param array $data   Data array.
	 * @param array $fields Field names to encrypt.
	 * @return array Data with specified fields encrypted.
	 */
	public static function encrypt_fields( $data, $fields ) {
		foreach ( $fields as $field ) {
			if ( isset( $data[ $field ] ) && ! empty( $data[ $field ] ) ) {
				$data[ $field ] = self::encrypt( $data[ $field ] );
			}
		}
		return $data;
	}

	/**
	 * Decrypt multiple fields in an object or array.
	 *
	 * @param object|array $data   Data object or array.
	 * @param array        $fields Field names to decrypt.
	 * @return object|array Data with specified fields decrypted.
	 */
	public static function decrypt_fields( $data, $fields ) {
		$is_object = is_object( $data );

		foreach ( $fields as $field ) {
			if ( $is_object && isset( $data->$field ) && ! empty( $data->$field ) ) {
				$data->$field = self::decrypt( $data->$field );
			} elseif ( ! $is_object && isset( $data[ $field ] ) && ! empty( $data[ $field ] ) ) {
				$data[ $field ] = self::decrypt( $data[ $field ] );
			}
		}

		return $data;
	}

	/**
	 * Get list of sensitive booking fields that should be encrypted.
	 *
	 * @return array Field names.
	 */
	public static function get_booking_encrypted_fields() {
		return array(
			'event_address',
			'event_zip',
		);
	}

	/**
	 * Get list of sensitive customer fields that should be encrypted.
	 *
	 * @return array Field names.
	 */
	public static function get_customer_encrypted_fields() {
		return array(
			'phone',
			'billing_phone',
		);
	}

	/**
	 * Encrypt booking data before storage.
	 *
	 * @param array $data Booking data.
	 * @return array Encrypted booking data.
	 */
	public static function encrypt_booking_data( $data ) {
		return self::encrypt_fields( $data, self::get_booking_encrypted_fields() );
	}

	/**
	 * Decrypt booking data after retrieval.
	 *
	 * @param object|array $booking Booking data.
	 * @return object|array Decrypted booking data.
	 */
	public static function decrypt_booking_data( $booking ) {
		return self::decrypt_fields( $booking, self::get_booking_encrypted_fields() );
	}
}
