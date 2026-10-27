<?php
declare(strict_types=1);

/**
 * Authenticated encryption of the snapshot payload.
 *
 * Everything except the payload is cleartext metadata, which is harmless:
 * it only describes how to derive the key (algorithm, iteration count, salt).
 */
final class ConfigSyncCrypto {
	public const CIPHER = 'AES-256-GCM';
	public const KDF_ALGO = 'PBKDF2-SHA256';
	public const KDF_ITERATIONS = 100000;

	private const KEY_LENGTH = 32;   // aes-256
	private const SALT_LENGTH = 16;
	private const IV_LENGTH = 12;    // GCM standard nonce size
	private const TAG_LENGTH = 16;

	/**
	 * Derive the raw 32-byte key from the passphrase and salt.
	 */
	public static function deriveKey(string $passphrase, string $salt): string {
		return hash_pbkdf2('sha256', $passphrase, $salt, self::KDF_ITERATIONS, self::KEY_LENGTH, true);
	}

	/**
	 * Encrypt a plaintext payload into the cleartext envelope fields.
	 *
	 * Salt and IV are regenerated on every call, so pushing the same
	 * configuration twice yields two different ciphertexts.
	 *
	 * @param string $aad additional authenticated data: the cleartext envelope
	 *                    metadata, so that tampering with it is detected even
	 *                    though it is not encrypted
	 * @return array{_kdf:array{algo:string,iterations:int,salt:string},_cipher:string,_iv:string,_tag:string,payload:string}
	 * @throws ConfigSyncCryptoException
	 */
	public static function encrypt(string $plaintext, string $passphrase, string $aad = ''): array {
		if ($passphrase === '') {
			throw new ConfigSyncCryptoException('empty passphrase');
		}

		$salt = random_bytes(self::SALT_LENGTH);
		$iv = random_bytes(self::IV_LENGTH);
		$key = self::deriveKey($passphrase, $salt);
		$tag = '';

		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			$aad,
			self::TAG_LENGTH
		);

		if (!is_string($ciphertext)) {
			throw new ConfigSyncCryptoException('openssl_encrypt() failed: ' . (string)openssl_error_string());
		}
		if (strlen($tag) !== self::TAG_LENGTH) {
			throw new ConfigSyncCryptoException('unexpected GCM tag length: ' . strlen($tag));
		}

		return [
			'_kdf' => [
				'algo' => self::KDF_ALGO,
				'iterations' => self::KDF_ITERATIONS,
				'salt' => base64_encode($salt),
			],
			'_cipher' => self::CIPHER,
			'_iv' => base64_encode($iv),
			'_tag' => base64_encode($tag),
			'payload' => base64_encode($ciphertext),
		];
	}

	/**
	 * Decrypt the payload of a snapshot envelope.
	 *
	 * Returns null when the envelope is not usable or the authentication tag
	 * does not verify (wrong passphrase / tampered data). Callers must treat
	 * null as "change nothing".
	 *
	 * @param array<string,mixed> $snapshot
	 * @param string $aad the same additional authenticated data that was used
	 *                    at encryption time; a mismatch makes decryption fail
	 */
	public static function decrypt(array $snapshot, string $passphrase, string $aad = ''): ?string {
		if ($passphrase === '') {
			return null;
		}
		if (($snapshot['_cipher'] ?? null) !== self::CIPHER) {
			return null;
		}

		$kdf = $snapshot['_kdf'] ?? null;
		if (!is_array($kdf)
			|| ($kdf['algo'] ?? null) !== self::KDF_ALGO
			|| (int)($kdf['iterations'] ?? 0) !== self::KDF_ITERATIONS
			|| !is_string($kdf['salt'] ?? null)) {
			return null;
		}

		$salt = is_string($kdf['salt'] ?? null) ? base64_decode($kdf['salt'], true) : false;
		$iv = is_string($snapshot['_iv'] ?? null) ? base64_decode($snapshot['_iv'], true) : false;
		$tag = is_string($snapshot['_tag'] ?? null) ? base64_decode($snapshot['_tag'], true) : false;
		$ciphertext = is_string($snapshot['payload'] ?? null) ? base64_decode($snapshot['payload'], true) : false;

		if (!is_string($salt) || strlen($salt) !== self::SALT_LENGTH
			|| !is_string($iv) || strlen($iv) !== self::IV_LENGTH
			|| !is_string($tag) || strlen($tag) !== self::TAG_LENGTH
			|| !is_string($ciphertext) || $ciphertext === '') {
			return null;
		}

		$key = self::deriveKey($passphrase, $salt);
		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			$aad
		);

		return is_string($plaintext) ? $plaintext : null;
	}
}

final class ConfigSyncCryptoException extends Exception {
}
