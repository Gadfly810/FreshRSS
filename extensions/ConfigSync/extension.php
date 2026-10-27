<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/Crypto.php';
require_once __DIR__ . '/lib/Snapshot.php';
require_once __DIR__ . '/lib/SyncBackend.php';
require_once __DIR__ . '/lib/GistBackend.php';
require_once __DIR__ . '/lib/WebDavBackend.php';

/**
 * ConfigSync — encrypted user-configuration snapshot, pushed to / pulled from
 * an external store (GitHub Gist or WebDAV).
 *
 * Design rules (see architecture.md §5):
 *  - init() does no network I/O and touches no configuration: it only
 *    registers the controller and the views. It runs on every HTTP request
 *    and every CLI invocation.
 *  - The snapshot is the whole `user_conf->toArray()`, unfiltered.
 *  - Restore writes key by key, never a whole-object replacement.
 *  - The passphrase and the storage credentials come from environment
 *    variables injected into the user configuration; they are never taken
 *    from the snapshot itself.
 */
final class ConfigSyncExtension extends Minz_Extension {
	/**
	 * Keys that always keep their local value, even when the snapshot carries
	 * a different one.
	 *
	 * Two groups:
	 *  - storage settings (ConfigSync*): they describe *this* container's
	 *    environment. A snapshot taken elsewhere must not be able to point this
	 *    container at another gist, or to change its passphrase, otherwise a
	 *    single pull could lock it out of its own store.
	 *  - identity and access keys: the CLI pull loop (architecture.md §4.3)
	 *    applies the same snapshot to every user. Restoring these verbatim
	 *    would give user B the password hash, API token and admin flag of
	 *    user A.
	 */
	private const PROTECTED_KEYS = [
		'ConfigSyncBackend',
		'ConfigSyncGistToken',
		'ConfigSyncGistId',
		'ConfigSyncWebDavUrl',
		'ConfigSyncWebDavUser',
		'ConfigSyncWebDavPass',
		'ConfigSyncPassphrase',

		'passwordHash',
		'token',
		'apiPasswordHash',
		'feverKey',
		'is_admin',
		'enabled',
		'mail_login',
		'email_validation_token',
	];

	private const KEY_LAST_PUSH = 'ConfigSyncLastPushAt';
	private const KEY_LAST_PULL = 'ConfigSyncLastPullAt';

	private string $lastError = '';
	private string $lastMessage = '';

	#[\Override]
	public function init(): void {
		$this->registerController('configsync');
		$this->registerViews();
	}

	// ---------------------------------------------------------------- public API

	/**
	 * Read the live user configuration, encrypt it and upload it.
	 *
	 * @throws ConfigSyncException on any failure
	 */
	public function forcePush(): void {
		$this->resetMessages();

		$passphrase = $this->passphrase();
		if ($passphrase === '') {
			throw $this->fail('ConfigSyncPassphrase is empty');
		}

		$backend = $this->backend();
		if ($backend === null) {
			throw $this->fail($this->noBackendReason());
		}

		try {
			$blob = ConfigSyncSnapshot::build($this->userConf()->toArray(), $passphrase, $this->savedFrom());
		} catch (Throwable $e) {
			throw $this->fail('cannot build snapshot: ' . $e->getMessage());
		}

		if (!$backend->push($blob)) {
			throw $this->fail($backend->lastError());
		}

		if (!$this->stamp(self::KEY_LAST_PUSH)) {
			throw $this->fail('pushed, but cannot write ' . self::KEY_LAST_PUSH . ' to the user configuration');
		}

		$this->lastMessage = 'pushed ' . strlen($blob) . ' bytes to ' . $backend->describe();
	}

	/**
	 * Download the snapshot, decrypt it and merge it into the live user
	 * configuration, then save.
	 *
	 * Nothing is written unless the whole chain succeeds: a wrong passphrase or
	 * a tampered payload leaves the configuration untouched.
	 *
	 * Throws instead of returning a sentinel so that a CLI caller using the
	 * try/catch of architecture.md §4.3 cannot mistake a failure for success.
	 *
	 * @return int number of keys restored
	 * @throws ConfigSyncException on any failure
	 */
	public function forcePull(): int {
		$this->resetMessages();

		$passphrase = $this->passphrase();
		if ($passphrase === '') {
			throw $this->fail('ConfigSyncPassphrase is empty');
		}

		$backend = $this->backend();
		if ($backend === null) {
			throw $this->fail($this->noBackendReason());
		}

		$blob = $backend->pull();
		if ($blob === null) {
			throw $this->fail($backend->lastError() !== '' ? $backend->lastError() : 'empty response from ' . $backend->describe());
		}

		$envelope = ConfigSyncSnapshot::parse($blob);
		if ($envelope === null) {
			throw $this->fail('not a ConfigSync snapshot');
		}

		$data = ConfigSyncSnapshot::payload($envelope, $passphrase);
		if ($data === null) {
			// Wrong passphrase or tampered data: change nothing at all.
			throw $this->fail('cannot decrypt snapshot (wrong passphrase or corrupted payload)');
		}

		$userConf = $this->userConf();
		$current = $userConf->toArray();

		// Snapshot the local values we refuse to import. `null` here means the
		// key does not exist locally at all; writing null back unsets it, so a
		// value smuggled in by the snapshot is removed rather than kept.
		$local = [];
		foreach (self::PROTECTED_KEYS as $key) {
			$local[$key] = array_key_exists($key, $current) ? $current[$key] : null;
		}

		$count = ConfigSyncSnapshot::apply($data, $userConf);

		foreach ($local as $key => $value) {
			$userConf->{$key} = $value;
		}

		$stamp = gmdate('Y-m-d\TH:i:s\Z');
		$userConf->{self::KEY_LAST_PULL} = $stamp;

		if (!$userConf->save()) {
			throw $this->fail('cannot write user configuration');
		}
		// Minz_Configuration::save() suppresses the rename() error and returns
		// true anyway, so confirm the file really contains what we wrote.
		if (!$this->persisted(self::KEY_LAST_PULL, $stamp)) {
			throw $this->fail('user configuration was not written to disk');
		}

		$this->lastMessage = 'restored ' . $count . ' keys from ' . $backend->describe()
			. ' (saved at ' . (string)$envelope['_savedAt'] . ')';
		return $count;
	}

	public function getLastError(): string {
		return $this->lastError;
	}

	public function getLastMessage(): string {
		return $this->lastMessage;
	}

	/**
	 * Values for the configure page. Never returns the passphrase or tokens,
	 * only whether they are present.
	 *
	 * @return array<string,string>
	 */
	public function getStatus(): array {
		$conf = $this->userConf()->toArray();
		$backend = $this->backend();

		return [
			'backend' => (string)($conf['ConfigSyncBackend'] ?? '') !== ''
				? (string)$conf['ConfigSyncBackend']
				: 'gist',
			'target' => $backend !== null ? $backend->describe() : '—',
			'problem' => $backend !== null ? '' : $this->noBackendReason(),
			'insecure' => ($backend instanceof ConfigSyncWebDavBackend && $backend->isInsecure()) ? 'yes' : 'no',
			'gistId' => (string)($conf['ConfigSyncGistId'] ?? ''),
			'hasToken' => (string)($conf['ConfigSyncGistToken'] ?? '') !== '' ? 'yes' : 'no',
			'hasPassphrase' => $this->passphrase() !== '' ? 'yes' : 'no',
			'lastPush' => (string)($conf[self::KEY_LAST_PUSH] ?? ''),
			'lastPull' => (string)($conf[self::KEY_LAST_PULL] ?? ''),
		];
	}

	// ------------------------------------------------------------------ helpers

	private function resetMessages(): void {
		$this->lastError = '';
		$this->lastMessage = '';
	}

	private function fail(string $message): ConfigSyncException {
		$this->lastError = $message;
		return new ConfigSyncException($message);
	}

	/**
	 * @throws FreshRSS_Context_Exception
	 */
	private function userConf(): FreshRSS_UserConfiguration {
		if (!FreshRSS_Context::hasUserConf()) {
			throw new FreshRSS_Context_Exception('user configuration not initialised');
		}
		return FreshRSS_Context::userConf();
	}

	private function passphrase(): string {
		return trim((string)($this->userConf()->toArray()['ConfigSyncPassphrase'] ?? ''));
	}

	private function backend(): ?ConfigSyncBackend {
		return ConfigSyncBackendFactory::create($this->userConf()->toArray());
	}

	private function noBackendReason(): string {
		$reason = ConfigSyncBackendFactory::explain($this->userConf()->toArray());
		return $reason !== '' ? $reason : 'no usable storage backend configured';
	}

	private function savedFrom(): string {
		if (FreshRSS_Context::hasSystemConf()) {
			$baseUrl = (string)(FreshRSS_Context::systemConf()->attributeString('base_url') ?? '');
			if ($baseUrl !== '') {
				return $baseUrl;
			}
		}
		return (string)(gethostname() ?: 'freshrss');
	}

	private function stamp(string $key): bool {
		$userConf = $this->userConf();
		$stamp = gmdate('Y-m-d\TH:i:s\Z');
		$userConf->{$key} = $stamp;
		if (!$userConf->save()) {
			return false;
		}
		return $this->persisted($key, $stamp);
	}

	/**
	 * Read the user configuration file back from disk and check one key.
	 *
	 * Guards against a save() that reports success while the rename() silently
	 * failed (lib/Minz/Configuration.php:238 suppresses the error).
	 */
	private function persisted(string $key, string $expected): bool {
		$username = Minz_User::name() ?? '';
		if ($username === '') {
			// Not attached to a user (should not happen): nothing to verify.
			return true;
		}
		$filename = USERS_PATH . '/' . $username . '/config.php';
		clearstatcache(true, $filename);
		$data = @include $filename;
		if (!is_array($data)) {
			return false;
		}
		return (string)($data[$key] ?? '') === $expected;
	}
}

final class ConfigSyncException extends Exception {
}
