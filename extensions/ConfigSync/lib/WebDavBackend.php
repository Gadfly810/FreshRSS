<?php
declare(strict_types=1);

/**
 * WebDAV backend (Nextcloud, ownCloud, Synology, generic NAS).
 *
 * A WebDAV file store is just HTTP: PUT replaces the file, GET reads it.
 * Basic auth credentials come from the user configuration, which in turn is
 * injected from environment variables by Docker/config-user.custom.php.
 */
final class ConfigSyncWebDavBackend implements ConfigSyncBackend {
	private string $lastError = '';

	public function __construct(
		private readonly string $url,
		private readonly string $user,
		private readonly string $password,
	) {
	}

	public function push(string $blob): bool {
		$this->lastError = '';

		$http = new ConfigSyncHttp();
		$response = $http->request('PUT', $this->url, $this->headers('application/json'), $blob);

		if ($response === null) {
			$this->lastError = $http->lastError();
			return false;
		}
		// 201 Created / 204 No Content / 200 OK are all acceptable.
		if (!in_array($response['status'], [200, 201, 204], true)) {
			$this->lastError = $response['status'] === 409
				? 'HTTP 409: the parent collection does not exist on the server'
				: 'HTTP ' . $response['status'] . ': ' . self::excerpt($response['body']);
			return false;
		}

		return true;
	}

	public function pull(): ?string {
		$this->lastError = '';

		$http = new ConfigSyncHttp();
		$response = $http->request('GET', $this->url, $this->headers());

		if ($response === null) {
			$this->lastError = $http->lastError();
			return null;
		}
		if ($response['status'] !== 200) {
			$this->lastError = 'HTTP ' . $response['status'] . ': ' . self::excerpt($response['body']);
			return null;
		}
		if (trim($response['body']) === '') {
			$this->lastError = 'empty file on server';
			return null;
		}

		return $response['body'];
	}

	public function lastError(): string {
		return $this->lastError;
	}

	public function describe(): string {
		// Never echo userinfo or query strings: they routinely carry tokens
		// (https://user:app-password@host/... or pre-signed URLs) and this label
		// is rendered in the configuration page and in notifications.
		$parts = parse_url($this->url);
		if (!is_array($parts)) {
			return 'webdav';
		}
		$host = (string)($parts['host'] ?? '');
		$path = (string)($parts['path'] ?? '');
		$scheme = (string)($parts['scheme'] ?? '');
		$label = ($scheme !== '' ? $scheme . '://' : '') . $host . $path;
		return 'webdav ' . ($label !== '' ? $label : '(invalid URL)');
	}

	/**
	 * True when Basic credentials would travel in clear text.
	 */
	public function isInsecure(): bool {
		return strtolower((string)(parse_url($this->url, PHP_URL_SCHEME) ?? '')) !== 'https';
	}

	/**
	 * @return array<string,string>
	 */
	private function headers(string $contentType = ''): array {
		$headers = ['Accept' => 'application/json'];
		if ($contentType !== '') {
			$headers['Content-Type'] = $contentType;
		}
		if ($this->user !== '') {
			$headers['Authorization'] = 'Basic ' . base64_encode($this->user . ':' . $this->password);
		}
		return $headers;
	}

	private static function excerpt(string $body): string {
		$body = trim(preg_replace('/\s+/', ' ', $body) ?? $body);
		return strlen($body) > 200 ? substr($body, 0, 200) . '…' : $body;
	}
}
