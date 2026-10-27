<?php
declare(strict_types=1);

/**
 * GitHub Gist backend.
 *
 * The snapshot lives in a single file inside one gist. GitHub has no PUT for
 * gists: updating a file is `PATCH /gists/{id}` with a partial `files` object.
 *
 * Rate limit: 30 authenticated requests/minute, which is far more than the
 * two manual buttons need. Never call this from a per-article hook.
 */
final class ConfigSyncGistBackend implements ConfigSyncBackend {
	public const FILENAME = 'freshrss-configsync.json';
	private const API = 'https://api.github.com/gists/';

	private string $lastError = '';

	public function __construct(
		private readonly string $token,
		private readonly string $gistId,
	) {
	}

	public function push(string $blob): bool {
		$this->lastError = '';

		try {
			$payload = json_encode([
				'files' => [
					self::FILENAME => ['content' => $blob],
				],
			], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		} catch (JsonException $e) {
			$this->lastError = 'cannot encode request body: ' . $e->getMessage();
			return false;
		}

		$http = new ConfigSyncHttp();
		$response = $http->request('PATCH', self::API . rawurlencode($this->gistId), $this->headers(), $payload);

		if ($response === null) {
			$this->lastError = $http->lastError();
			return false;
		}
		if ($response['status'] === 429) {
			$this->lastError = 'GitHub rate limit reached, retry later';
			return false;
		}
		if ($response['status'] !== 200) {
			$this->lastError = 'HTTP ' . $response['status'] . ': ' . self::excerpt($response['body']);
			return false;
		}

		return true;
	}

	public function pull(): ?string {
		$this->lastError = '';

		$http = new ConfigSyncHttp();
		$response = $http->request('GET', self::API . rawurlencode($this->gistId), $this->headers());

		if ($response === null) {
			$this->lastError = $http->lastError();
			return null;
		}
		if ($response['status'] === 429) {
			$this->lastError = 'GitHub rate limit reached, retry later';
			return null;
		}
		if ($response['status'] !== 200) {
			$this->lastError = 'HTTP ' . $response['status'] . ': ' . self::excerpt($response['body']);
			return null;
		}

		$data = json_decode($response['body'], true);
		if (!is_array($data)) {
			$this->lastError = 'invalid JSON from GitHub';
			return null;
		}

		$file = $data['files'][self::FILENAME] ?? null;
		if (!is_array($file)) {
			$this->lastError = 'file ' . self::FILENAME . ' not found in gist';
			return null;
		}

		// GitHub truncates large inline content; fall back to raw_url.
		if (!empty($file['truncated'])) {
			return $this->pullRaw($file['raw_url'] ?? null);
		}

		$content = $file['content'] ?? null;
		if (!is_string($content) || $content === '') {
			$this->lastError = 'empty file content in gist';
			return null;
		}

		return $content;
	}

	/**
	 * Fetch the untruncated file from raw_url.
	 *
	 * raw_url comes from the remote JSON, so it is untrusted input: it must
	 * point at gist.githubusercontent.com over https, otherwise we would let a
	 * compromised/forged response drive a request to an arbitrary host (SSRF).
	 * The token is sent because secret gists require authentication there too.
	 */
	private function pullRaw(mixed $rawUrl): ?string {
		if (!is_string($rawUrl) || $rawUrl === '') {
			$this->lastError = 'gist file is truncated and has no raw_url';
			return null;
		}

		$parts = parse_url($rawUrl);
		$host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
		$scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
		if ($scheme !== 'https' || $host !== 'gist.githubusercontent.com') {
			$this->lastError = 'refusing to fetch raw_url from untrusted host: ' . $host;
			return null;
		}

		$raw = new ConfigSyncHttp();
		$rawResponse = $raw->request('GET', $rawUrl, $this->headers());
		if ($rawResponse === null) {
			$this->lastError = 'cannot fetch raw file: ' . $raw->lastError();
			return null;
		}
		if ($rawResponse['status'] !== 200) {
			$this->lastError = 'cannot fetch raw file: HTTP ' . $rawResponse['status'];
			return null;
		}
		if (trim($rawResponse['body']) === '') {
			$this->lastError = 'empty raw file from gist';
			return null;
		}

		return $rawResponse['body'];
	}

	public function lastError(): string {
		return $this->lastError;
	}

	public function describe(): string {
		return 'gist ' . $this->gistId;
	}

	/**
	 * @return array<string,string>
	 */
	private function headers(): array {
		return [
			'Authorization' => 'Bearer ' . $this->token,
			'Accept' => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'Content-Type' => 'application/json',
		];
	}

	private static function excerpt(string $body): string {
		$body = trim(preg_replace('/\s+/', ' ', $body) ?? $body);
		return strlen($body) > 200 ? substr($body, 0, 200) . '…' : $body;
	}
}
