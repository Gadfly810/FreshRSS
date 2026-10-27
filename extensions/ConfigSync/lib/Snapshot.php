<?php
declare(strict_types=1);

/**
 * Snapshot envelope: build / parse / apply.
 *
 * The envelope is a JSON document whose only encrypted part is `payload`.
 * The payload is the full `user_conf->toArray()` serialised as JSON, with no
 * key filtering at all: whatever an extension writes into the user
 * configuration (top-level keys or the `extensions[...]` sub-array) is
 * carried over automatically.
 */
final class ConfigSyncSnapshot {
	public const FORMAT = 'freshrss-configsync';
	public const VERSION = 1;

	/** Refuse absurdly large blobs instead of feeding them to json_decode(). */
	private const MAX_BLOB_LENGTH = 4_000_000;

	/**
	 * Build the JSON blob to upload.
	 *
	 * The cleartext metadata (_format/_version/_savedAt/_savedFrom) is passed
	 * to the cipher as additional authenticated data: it stays readable, but
	 * any modification by the storage side makes decryption fail.
	 *
	 * @param array<string,mixed> $data the whole user configuration
	 * @throws ConfigSyncCryptoException
	 * @throws ConfigSyncSnapshotException
	 */
	public static function build(array $data, string $passphrase, string $savedFrom): string {
		$plaintext = self::encode($data);

		$meta = [
			'_format' => self::FORMAT,
			'_version' => self::VERSION,
			'_savedAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'_savedFrom' => $savedFrom,
		];

		$envelope = $meta + ConfigSyncCrypto::encrypt($plaintext, $passphrase, self::aad($meta));

		return self::encode($envelope);
	}

	/**
	 * Validate and decode an uploaded blob.
	 *
	 * @return array<string,mixed>|null null when the blob is not a ConfigSync snapshot
	 */
	public static function parse(string $blob): ?array {
		if (strlen($blob) > self::MAX_BLOB_LENGTH) {
			return null;
		}

		$blob = trim($blob);
		if ($blob === '') {
			return null;
		}

		try {
			$envelope = json_decode($blob, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			return null;
		}
		if (!is_array($envelope)) {
			return null;
		}
		if (($envelope['_format'] ?? null) !== self::FORMAT) {
			return null;
		}
		if (($envelope['_version'] ?? null) !== self::VERSION) {
			return null;
		}
		if (!is_string($envelope['_savedAt'] ?? null) || !is_string($envelope['_savedFrom'] ?? null)) {
			return null;
		}
		if (!is_string($envelope['payload'] ?? null)) {
			return null;
		}

		return $envelope;
	}

	/**
	 * Decrypt and decode the payload of a parsed envelope.
	 *
	 * @param array<string,mixed> $envelope
	 * @return array<string,mixed>|null null on wrong passphrase / tampered payload
	 */
	public static function payload(array $envelope, string $passphrase): ?array {
		$plaintext = ConfigSyncCrypto::decrypt($envelope, $passphrase, self::aad($envelope));
		if ($plaintext === null) {
			return null;
		}

		try {
			$data = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			return null;
		}

		return is_array($data) ? $data : null;
	}

	/**
	 * Write the snapshot values back into the live user configuration, one key
	 * at a time.
	 *
	 * Deliberately NOT a whole-object replacement: keys that exist in the
	 * current version but not in the snapshot keep their current value, so a
	 * snapshot taken on an older FreshRSS does not resurrect removed defaults
	 * or drop newly added ones.
	 *
	 * The caller is responsible for `save()`.
	 *
	 * `null` values are skipped on purpose: Minz_Configuration::__set() treats
	 * null as "unset this key", so writing them would delete local keys (and
	 * inflate the restored-keys counter) rather than restore a value.
	 *
	 * @param array<string,mixed> $data
	 * @return int number of keys written
	 */
	public static function apply(array $data, Minz_Configuration $userConf): int {
		$count = 0;
		foreach ($data as $key => $value) {
			if (!is_string($key) || $key === '' || $value === null) {
				continue;
			}
			$userConf->{$key} = $value;
			$count++;
		}
		return $count;
	}

	/**
	 * Additional authenticated data: the cleartext metadata, in a fixed order.
	 *
	 * @param array<string,mixed> $meta
	 */
	private static function aad(array $meta): string {
		return (string)($meta['_format'] ?? '')
			. "\n" . (string)($meta['_version'] ?? '')
			. "\n" . (string)($meta['_savedAt'] ?? '')
			. "\n" . (string)($meta['_savedFrom'] ?? '');
	}

	/**
	 * @param array<string,mixed>|array<int,mixed> $value
	 * @throws ConfigSyncSnapshotException
	 */
	private static function encode(array $value): string {
		try {
			return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		} catch (JsonException $e) {
			throw new ConfigSyncSnapshotException('cannot encode snapshot: ' . $e->getMessage());
		}
	}
}

final class ConfigSyncSnapshotException extends Exception {
}
