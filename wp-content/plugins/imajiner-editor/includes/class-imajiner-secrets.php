<?php
/**
 * Encrypts API keys before they are stored in the database.
 *
 * Keys can't be hashed: the plugin has to send the original key to the AI
 * provider. They are encrypted with libsodium (XSalsa20-Poly1305) instead,
 * using a key derived from IMAJINER_EDITOR_ENCRYPTION_KEY when defined in
 * wp-config.php, or from the site's LOGGED_IN key and salt otherwise. A
 * database dump alone is then not enough to read them.
 *
 * Changing the encryption key or the salts makes stored keys unreadable; they
 * then have to be entered again.
 *
 * @package Imajiner_Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Secret storage helpers.
 */
class Imajiner_Secrets {

	/**
	 * Marks values encrypted by this class, and the scheme version.
	 */
	const PREFIX = 'imj1:';

	/**
	 * Encrypts a secret for storage.
	 *
	 * @param string $plaintext Secret.
	 * @return string Encrypted value.
	 */
	public static function encrypt( $plaintext ) {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypts a stored secret.
	 *
	 * @param string $stored Value from encrypt().
	 * @return string|false Secret, or false if it can't be decrypted (e.g. the salts changed).
	 */
	public static function decrypt( $stored ) {
		if ( ! is_string( $stored ) || 0 !== strpos( $stored, self::PREFIX ) ) {
			return false;
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return false;
		}

		try {
			return sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::key()
			);
		} catch ( Exception $error ) {
			return false;
		}
	}

	/**
	 * Shows only the end of a secret, e.g. "••••3f9a".
	 *
	 * @param string $secret Secret.
	 * @return string
	 */
	public static function mask( $secret ) {
		return '••••' . substr( $secret, -4 );
	}

	/**
	 * Encryption key, derived so it is never the raw constant or salt.
	 *
	 * @return string 32-byte key.
	 */
	private static function key() {
		$material = defined( 'IMAJINER_EDITOR_ENCRYPTION_KEY' ) && IMAJINER_EDITOR_ENCRYPTION_KEY
			? IMAJINER_EDITOR_ENCRYPTION_KEY
			: wp_salt( 'logged_in' );

		return sodium_crypto_generichash( 'imajiner-editor-api-keys|' . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
