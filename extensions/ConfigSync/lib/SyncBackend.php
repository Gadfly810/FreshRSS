<?php
declare(strict_types=1);

/**
 * Storage backend contract.
 *
 * A backend stores one opaque blob (the encrypted snapshot) and returns it
 * back unchanged. It must never interpret, decrypt or validate the blob.
 */
interface ConfigSyncBackend {
	/**
	 * Store/replace the blob. Returns true on success.
	 */
	public function push(string $blob): bool;

	/**
	 * Fetch the blob. Returns null when it does not exist or the request failed.
	 */
	public function pull(): ?string;

	/**
	 * Human readable reason of the last failure ('' when nothing failed).
	 */
	public function lastError(): string;

	/**
	 * Short label for the UI, e.g. "gist abc123".
	 */
	public function describe(): string;
}

/**
 * Minimal HTTP client based on stream contexts.
 *
 * Deliberately not curl: the extension must work whether or not php-curl is
 * present (allow_url_fopen is enough), and the built-in FreshRSS helper
 * `FreshRSS_http_Util::httpGet()` whitelists curl options and cannot send PUT
 * or PATCH bodies.
 */
final class ConfigSyncHttp {
	private const TIMEOUT = 20;
	private const CONNECT_TIMEOUT = 10;

	private string $lastError = '';

	/**
	 * @param array<string,string> $headers
	 * @return array{status:int,body:string}|null null on transport failure
	 */
	public function request(string $method, string $url, array $headers = [], ?string $body = null): ?array {
		$this->lastError = '';

		$headerLines = [];
		foreach ($headers as $name => $value) {
			$headerLines[] = $name . ': ' . $value;
		}
		$headerLines[] = 'Connection: close';

		$options = [
			'method' => strtoupper($method),
			'header' => implode("\r\n", $headerLines),
			'timeout' => self::TIMEOUT,
			'connect_timeout' => self::CONNECT_TIMEOUT,
			'ignore_errors' => true,      // we want the body of 4xx/5xx too
			// Redirects are NOT followed: PHP's http wrapper keeps the request
			// headers across a redirect, which would hand the GitHub token or the
			// WebDAV Basic credentials to whatever host the redirect points at,
			// and it downgrades PUT/PATCH to GET, dropping the body.
			'follow_location' => 0,
			'user_agent' => 'FreshRSS-ConfigSync/0.1',
		];
		if ($body !== null) {
			$options['content'] = $body;
		}

		$context = stream_context_create(['http' => $options]);

		// FreshRSS calls unregister_unsafe_protocols() at bootstrap
		// (lib/lib_rss.php), which removes every wrapper except file and php.
		// Without this the https:// URL is not recognised as a URL at all: PHP
		// falls back to plain file access and fails with the misleading
		// "Failed to open stream: No such file or directory". Restore the
		// wrappers for the duration of this request and remove them again
		// afterwards, so the hardening the application asked for stays in place.
		$restored = [];
		$scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
		if ($scheme === 'https' || $scheme === 'http') {
			if (!in_array($scheme, stream_get_wrappers(), true)) {
				if (@stream_wrapper_restore($scheme)) {
					$restored[] = $scheme;
				} else {
					$this->lastError = 'the ' . $scheme . ' stream wrapper is not available in this PHP build';
					return null;
				}
			}
		}

		try {
			// error_get_last() is process-global: clear it so that a stale warning
			// from an unrelated call cannot be reported as this request's failure.
			error_clear_last();
			$response = @file_get_contents($url, false, $context);
			$error = error_get_last();
		} finally {
			foreach ($restored as $protocol) {
				@stream_wrapper_unregister($protocol);
			}
		}

		$responseHeaders = function_exists('http_get_last_response_headers')
			? (http_get_last_response_headers() ?? [])
			: ($http_response_header ?? []);

		$status = 0;
		foreach ($responseHeaders as $line) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
				$status = (int)$m[1];   // last one wins (after redirects)
			}
		}

		if ($response === false) {
			$this->lastError = 'transport failure: ' . ($error['message'] ?? 'unknown error');
			return null;
		}

		if ($status >= 300 && $status < 400) {
			$this->lastError = 'unexpected redirect (HTTP ' . $status . '): refusing to follow it with credentials attached';
			return null;
		}

		return ['status' => $status, 'body' => $response];
	}

	public function lastError(): string {
		return $this->lastError;
	}
}

/**
 * Build the configured backend from the user configuration values.
 */
final class ConfigSyncBackendFactory {
	private const KNOWN = ['', 'gist', 'webdav'];

	/**
	 * @param array<string,mixed> $config flat map of ConfigSync* keys
	 */
	public static function create(array $config): ?ConfigSyncBackend {
		$name = strtolower(trim((string)($config['ConfigSyncBackend'] ?? '')));

		if (!in_array($name, self::KNOWN, true)) {
			return null;
		}

		if ($name === '' || $name === 'gist') {
			$token = trim((string)($config['ConfigSyncGistToken'] ?? ''));
			$id = trim((string)($config['ConfigSyncGistId'] ?? ''));
			return $token !== '' && $id !== '' ? new ConfigSyncGistBackend($token, $id) : null;
		}

		$url = trim((string)($config['ConfigSyncWebDavUrl'] ?? ''));
		if ($url === '') {
			return null;
		}
		return new ConfigSyncWebDavBackend(
			$url,
			trim((string)($config['ConfigSyncWebDavUser'] ?? '')),
			(string)($config['ConfigSyncWebDavPass'] ?? '')
		);
	}

	/**
	 * Why create() returned null, for the configure page.
	 *
	 * @param array<string,mixed> $config flat map of ConfigSync* keys
	 */
	public static function explain(array $config): string {
		$name = strtolower(trim((string)($config['ConfigSyncBackend'] ?? '')));
		if (!in_array($name, self::KNOWN, true)) {
			return 'unknown backend "' . $name . '" (expected gist or webdav)';
		}
		if ($name === '' || $name === 'gist') {
			if (trim((string)($config['ConfigSyncGistToken'] ?? '')) === '') {
				return 'ConfigSyncGistToken is empty';
			}
			if (trim((string)($config['ConfigSyncGistId'] ?? '')) === '') {
				return 'ConfigSyncGistId is empty';
			}
			return '';
		}
		return trim((string)($config['ConfigSyncWebDavUrl'] ?? '')) === '' ? 'ConfigSyncWebDavUrl is empty' : '';
	}
}
