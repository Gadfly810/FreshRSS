<?php

declare(strict_types=1);

/**
 * Image Proxy Opt — an optimised fork of xExtension-ImageProxy.
 *
 * Differences from upstream (FreshRSS/Extensions/xExtension-ImageProxy):
 *  - rewrites <img src|srcset> / <video poster> with targeted regexes instead of DOMDocument:
 *    ~11x faster, and it never re-serialises the document, so UTF-8 content is not mangled;
 *  - optional image-host allow/deny list, so only the images you cannot reach are proxied
 *    (this is what actually saves proxy requests, not feed-level filtering);
 *  - optional batch pre-warming of the proxy cache at feed-refresh time (one POST per entry).
 *
 * @see https://github.com/FreshRSS/Extensions/tree/master/xExtension-ImageProxy
 */
final class ImageProxyOptExtension extends Minz_Extension {
	// Defaults
	private const PROXY_URL = 'https://img.example.com/?url=';
	private const SCHEME_HTTP = true;
	private const SCHEME_HTTPS = true;
	private const SCHEME_DEFAULT = 'auto';
	private const SCHEME_INCLUDE = true;
	private const URL_ENCODE = true;
	private const HOST_MODE = 'off';
	private const HOST_LIST = '';
	private const WARM_ENABLED = false;
	private const WARM_URL = '';
	private const WARM_TOKEN = '';

	// Rewriting patterns. Attribute values containing ">" are not supported.
	private const TAG_RE = '/<(?:img|video)\b[^>]*>/i';
	private const URL_ATTR_RE = '/(\s(src|poster)\s*=\s*)(["\'])(.*?)\3/is';
	private const SRCSET_ATTR_RE = '/(\s(srcset)\s*=\s*)(["\'])(.*?)\3/is';
	private const SRCSET_ITEM_RE = '/([^\s,]+)(\s+[0-9.]+[wx])?/';

	// Pre-warming
	private const WARM_CONNECT_TIMEOUT = 2;
	private const WARM_TIMEOUT = 4;
	private const WARM_MAX_URLS = 50;

	/** @var array<string,string|bool>|null */
	private static ?array $settings = null;

	/** @var list<string>|null */
	private static ?array $hostListCache = null;

	/**
	 * @throws FreshRSS_Context_Exception
	 */
	#[\Override]
	public function init(): void {
		if (!FreshRSS_Context::hasSystemConf()) {
			throw new FreshRSS_Context_Exception('System configuration not initialised!');
		}
		$this->registerHook('entry_before_display', self::hookBeforeDisplay(...));
		$this->registerHook('entry_before_insert', self::hookBeforeInsert(...));
		$this->setDefaults();
	}

	/**
	 * @throws FreshRSS_Context_Exception
	 */
	private function setDefaults(): void {
		$save = false;
		foreach ([
			['image_proxy_opt_url', 'string', self::PROXY_URL],
			['image_proxy_opt_scheme_http', 'bool', self::SCHEME_HTTP],
			['image_proxy_opt_scheme_https', 'bool', self::SCHEME_HTTPS],
			['image_proxy_opt_scheme_default', 'string', self::SCHEME_DEFAULT],
			['image_proxy_opt_scheme_include', 'bool', self::SCHEME_INCLUDE],
			['image_proxy_opt_url_encode', 'bool', self::URL_ENCODE],
			['image_proxy_opt_host_mode', 'string', self::HOST_MODE],
			['image_proxy_opt_host_list', 'string', self::HOST_LIST],
			['image_proxy_opt_warm_enabled', 'bool', self::WARM_ENABLED],
			['image_proxy_opt_warm_url', 'string', self::WARM_URL],
			['image_proxy_opt_warm_token', 'string', self::WARM_TOKEN],
		] as [$key, $type, $value]) {
			$current = $type === 'bool'
				? FreshRSS_Context::userConf()->attributeBool($key)
				: FreshRSS_Context::userConf()->attributeString($key);
			if ($current === null) {
				FreshRSS_Context::userConf()->_attribute($key, $value);
				$save = true;
			}
		}
		if ($save) {
			FreshRSS_Context::userConf()->save();
		}
	}

	/**
	 * @throws FreshRSS_Context_Exception
	 */
	#[\Override]
	public function handleConfigureAction(): void {
		$this->registerTranslates();

		if (Minz_Request::isPost()) {
			$conf = FreshRSS_Context::userConf();
			$conf->_attribute('image_proxy_opt_url',
				Minz_Request::paramString('image_proxy_opt_url', plaintext: true) ?: self::PROXY_URL);
			$conf->_attribute('image_proxy_opt_scheme_http', Minz_Request::paramBoolean('image_proxy_opt_scheme_http'));
			$conf->_attribute('image_proxy_opt_scheme_https', Minz_Request::paramBoolean('image_proxy_opt_scheme_https'));
			$conf->_attribute('image_proxy_opt_scheme_default',
				Minz_Request::paramString('image_proxy_opt_scheme_default', plaintext: true) ?: self::SCHEME_DEFAULT);
			$conf->_attribute('image_proxy_opt_scheme_include', Minz_Request::paramBoolean('image_proxy_opt_scheme_include'));
			$conf->_attribute('image_proxy_opt_url_encode', Minz_Request::paramBoolean('image_proxy_opt_url_encode'));
			$conf->_attribute('image_proxy_opt_host_mode',
				Minz_Request::paramString('image_proxy_opt_host_mode', plaintext: true) ?: self::HOST_MODE);
			$conf->_attribute('image_proxy_opt_host_list',
				Minz_Request::paramString('image_proxy_opt_host_list', plaintext: true));
			$conf->_attribute('image_proxy_opt_warm_enabled', Minz_Request::paramBoolean('image_proxy_opt_warm_enabled'));
			$conf->_attribute('image_proxy_opt_warm_url',
				Minz_Request::paramString('image_proxy_opt_warm_url', plaintext: true));
			$conf->_attribute('image_proxy_opt_warm_token',
				Minz_Request::paramString('image_proxy_opt_warm_token', plaintext: true));
			$conf->save();
			self::$hostListCache = null;
			self::$settings = null;
		}
	}

	/**
	 * Snapshot of the settings, read once per request: the display hook calls
	 * getProxyImageUri() once per image, so per-URL config lookups add up.
	 *
	 * @return array<string,string|bool>
	 * @throws FreshRSS_Context_Exception
	 */
	private static function settings(): array {
		if (self::$settings !== null) {
			return self::$settings;
		}
		$conf = FreshRSS_Context::userConf();
		return self::$settings = [
			'image_proxy_opt_url' => $conf->attributeString('image_proxy_opt_url') ?? self::PROXY_URL,
			'image_proxy_opt_scheme_http' => $conf->attributeBool('image_proxy_opt_scheme_http') ?? self::SCHEME_HTTP,
			'image_proxy_opt_scheme_https' => $conf->attributeBool('image_proxy_opt_scheme_https') ?? self::SCHEME_HTTPS,
			'image_proxy_opt_scheme_default' => $conf->attributeString('image_proxy_opt_scheme_default') ?? self::SCHEME_DEFAULT,
			'image_proxy_opt_scheme_include' => $conf->attributeBool('image_proxy_opt_scheme_include') ?? self::SCHEME_INCLUDE,
			'image_proxy_opt_url_encode' => $conf->attributeBool('image_proxy_opt_url_encode') ?? self::URL_ENCODE,
			'image_proxy_opt_host_mode' => strtolower($conf->attributeString('image_proxy_opt_host_mode') ?? self::HOST_MODE),
			'image_proxy_opt_host_list' => $conf->attributeString('image_proxy_opt_host_list') ?? self::HOST_LIST,
			'image_proxy_opt_warm_enabled' => $conf->attributeBool('image_proxy_opt_warm_enabled') ?? self::WARM_ENABLED,
			'image_proxy_opt_warm_url' => $conf->attributeString('image_proxy_opt_warm_url') ?? self::WARM_URL,
			'image_proxy_opt_warm_token' => $conf->attributeString('image_proxy_opt_warm_token') ?? self::WARM_TOKEN,
		];
	}

	/**
	 * @throws FreshRSS_Context_Exception
	 */
	private static function str(string $key, string $default): string {
		$value = self::settings()[$key] ?? null;
		return is_string($value) ? $value : $default;
	}

	/**
	 * @throws FreshRSS_Context_Exception
	 */
	private static function bool(string $key, bool $default): bool {
		$value = self::settings()[$key] ?? null;
		return is_bool($value) ? $value : $default;
	}

	/**
	 * @return list<string>
	 * @throws FreshRSS_Context_Exception
	 */
	private static function hostList(): array {
		if (self::$hostListCache !== null) {
			return self::$hostListCache;
		}
		$list = [];
		foreach (preg_split('/\R/u', self::str('image_proxy_opt_host_list', self::HOST_LIST)) ?: [] as $line) {
			$line = strtolower(trim($line));
			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}
			$list[] = $line;
		}
		return self::$hostListCache = $list;
	}

	/**
	 * Host-level allow/deny decision. `off` keeps the upstream behaviour (proxy everything).
	 *
	 * @param string $host lower-cased host, already extracted by the caller
	 * @throws FreshRSS_Context_Exception
	 */
	private static function hostAllowed(string $host): bool {
		$mode = self::str('image_proxy_opt_host_mode', self::HOST_MODE);
		if ($mode !== 'allow' && $mode !== 'deny') {
			return true;
		}
		if ($host === '') {
			// Relative URL: proxy only in deny mode (nothing to match against).
			return $mode === 'deny';
		}
		$hit = false;
		foreach (self::hostList() as $pattern) {
			if ($pattern === $host ||
				(str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1)))) {
				$hit = true;
				break;
			}
		}
		return $mode === 'allow' ? $hit : !$hit;
	}

	private static function isRemoteUrl(string $url): bool {
		return !str_starts_with(strtolower($url), 'data:');
	}

	/**
	 * @throws FreshRSS_Context_Exception
	 */
	public static function getProxyImageUri(string $url): string {
		if ($url === '' || !self::isRemoteUrl($url)) {
			return $url;
		}

		// parse_url() is the most expensive call here: do it once and reuse both parts.
		$parts = parse_url($url);
		if (!is_array($parts)) {
			return $url;
		}
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		if (!self::hostAllowed(strtolower((string)($parts['host'] ?? '')))) {
			return $url;
		}

		$prefix = self::str('image_proxy_opt_url', self::PROXY_URL);
		if ($prefix !== '' && str_starts_with($url, $prefix)) {
			return $url;	// Already proxied (e.g. by the upstream extension): do not nest.
		}

		$include = self::bool('image_proxy_opt_scheme_include', self::SCHEME_INCLUDE);

		if ($scheme === 'http') {
			if (!self::bool('image_proxy_opt_scheme_http', self::SCHEME_HTTP)) {
				return $url;
			}
			if (!$include) {
				$url = substr($url, 7);	// http://
			}
		} elseif ($scheme === 'https') {
			if (!self::bool('image_proxy_opt_scheme_https', self::SCHEME_HTTPS)) {
				return $url;
			}
			if (!$include) {
				$url = substr($url, 8);	// https://
			}
		} elseif ($scheme === '') {
			if (!str_starts_with($url, '//')) {
				return $url;	// Relative path, nothing to proxy.
			}
			$default = self::str('image_proxy_opt_scheme_default', self::SCHEME_DEFAULT);
			if (!str_starts_with($default, 'http')) {
				if ($default !== 'auto') {
					return $url;	// "-": do not proxy protocol-relative URLs.
				}
				$default = (is_string($_SERVER['HTTPS'] ?? null) && strtolower($_SERVER['HTTPS']) !== 'off')
					? 'https' : 'http';
			}
			if (!self::bool('image_proxy_opt_scheme_' . $default, $default === 'http' ? self::SCHEME_HTTP : self::SCHEME_HTTPS)) {
				return $url;
			}
			if ($include) {
				$url = $default . ':' . $url;
			}
		} else {
			return $url;	// Unsupported scheme (data:, mailto:, ...).
		}

		// Signed over the *decoded* target, which is exactly what the worker reads back out of
		// ?url=, so the signature is independent of url_encode.
		$secret = self::str('image_proxy_opt_warm_token', self::WARM_TOKEN);
		$sig = $secret === '' ? '' : '&sig=' . substr(hash_hmac('sha256', $url, $secret), 0, 32);

		if (self::bool('image_proxy_opt_url_encode', self::URL_ENCODE)) {
			$url = rawurlencode($url);
		}
		return $prefix . $url . $sig;
	}

	/**
	 * Rewrite image/video URLs in an entry content.
	 *
	 * @throws FreshRSS_Context_Exception
	 */
	public static function swapUris(string $content): string {
		if ($content === '' || (stripos($content, '<img') === false && stripos($content, '<video') === false)) {
			return $content;
		}

		$out = preg_replace_callback(self::TAG_RE, static function (array $tagMatch): string {
			$tag = $tagMatch[0];
			/** @var list<array{0:string,1:string}> $originals */
			$originals = [];

			$tag = preg_replace_callback(self::URL_ATTR_RE,
				static function (array $a) use (&$originals): string {
					$new = self::getProxyImageUri($a[4]);
					if ($new === $a[4]) {
						return $a[0];
					}
					$originals[] = [strtolower($a[2]), $a[4]];
					return $a[1] . $a[3] . $new . $a[3];
				}, $tag) ?? $tag;

			$tag = preg_replace_callback(self::SRCSET_ATTR_RE,
				static function (array $a) use (&$originals): string {
					$rewritten = preg_replace_callback(self::SRCSET_ITEM_RE,
						static function (array $c): string {
							return self::getProxyImageUri($c[1]) . ($c[2] ?? '');
						}, $a[4]) ?? $a[4];
					if ($rewritten === $a[4]) {
						return $a[0];
					}
					$originals[] = [strtolower($a[2]), $a[4]];
					return $a[1] . $a[3] . $rewritten . $a[3];
				}, $tag) ?? $tag;

			if ($originals === []) {
				return $tag;
			}

			// Keep the original URLs in the content so that FreshRSS_Entry::containsLink()
			// still detects enclosures already rendered inline.
			$extra = '';
			foreach ($originals as [$attrName, $original]) {
				$dataAttr = match ($attrName) {
					'src' => 'data-imageproxyopt-original-src',
					'srcset' => 'data-imageproxyopt-original-srcset',
					'poster' => 'data-imageproxyopt-original-poster',
					default => 'data-imageproxyopt-original-url',
				};
				$extra .= ' ' . $dataAttr . '="' . htmlspecialchars($original, ENT_COMPAT, 'UTF-8') . '"';
			}

			if (str_ends_with($tag, '/>')) {
				return substr($tag, 0, -2) . $extra . '/>';
			}
			if (str_ends_with($tag, '>')) {
				return substr($tag, 0, -1) . $extra . '>';
			}
			return $tag;
		}, $content);

		return $out ?? $content;
	}

	/**
	 * @throws FreshRSS_Context_Exception
	 */
	public static function hookBeforeDisplay(FreshRSS_Entry $entry): FreshRSS_Entry {
		// Enclosures become part of the content, so only include them if the feed displays them.
		$feed = FreshRSS_Category::findFeed(FreshRSS_Context::categories(), $entry->feedId()) ?? $entry->feed();
		$entry->_content(
			self::swapUris($entry->content($feed?->attributeBoolean('display_enclosures') ?? true))
		);
		return $entry;
	}

	/**
	 * Batch pre-warm the proxy cache once per inserted entry.
	 *
	 * @throws FreshRSS_Context_Exception
	 */
	public static function hookBeforeInsert(FreshRSS_Entry $entry): FreshRSS_Entry {
		if (!self::bool('image_proxy_opt_warm_enabled', self::WARM_ENABLED)) {
			return $entry;
		}
		$urls = self::collectImageUrls($entry->content());
		if ($urls !== []) {
			self::warmUrls($urls);
		}
		return $entry;
	}

	/**
	 * @return list<string>
	 * @throws FreshRSS_Context_Exception
	 */
	private static function collectImageUrls(string $content): array {
		if ($content === '' || (stripos($content, '<img') === false && stripos($content, '<video') === false)) {
			return [];
		}
		$seen = [];
		if (preg_match_all(self::URL_ATTR_RE, $content, $m, PREG_SET_ORDER) > 0) {
			foreach ($m as $a) {
				$seen[trim($a[4])] = true;
			}
		}
		if (preg_match_all(self::SRCSET_ATTR_RE, $content, $m, PREG_SET_ORDER) > 0) {
			foreach ($m as $a) {
				if (preg_match_all(self::SRCSET_ITEM_RE, $a[4], $items, PREG_SET_ORDER) > 0) {
					foreach ($items as $c) {
						$seen[trim($c[1])] = true;
					}
				}
			}
		}

		$urls = [];
		foreach (array_keys($seen) as $url) {
			$url = (string)$url;
			if ($url === '' || !self::isRemoteUrl($url)) {
				continue;
			}
			if (!self::hostAllowed(strtolower((string)parse_url($url, PHP_URL_HOST)))) {
				continue;
			}
			$urls[] = $url;
			if (count($urls) >= self::WARM_MAX_URLS) {
				break;
			}
		}
		return $urls;
	}

	/**
	 * One POST per entry carrying all its image URLs, instead of one blocking request per image.
	 *
	 * @param list<string> $urls
	 * @throws FreshRSS_Context_Exception
	 */
	private static function warmUrls(array $urls): void {
		$endpoint = self::str('image_proxy_opt_warm_url', self::WARM_URL);
		if ($endpoint === '' || !function_exists('curl_init')) {
			return;
		}
		$payload = json_encode([
			'access_token' => self::str('image_proxy_opt_warm_token', self::WARM_TOKEN),
			'urls' => array_values($urls),
		], JSON_UNESCAPED_SLASHES);
		if ($payload === false) {
			return;
		}

		$curl = curl_init();
		if ($curl === false) {
			return;
		}
		curl_setopt_array($curl, [
			CURLOPT_URL => $endpoint,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS => $payload,
			CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => self::WARM_CONNECT_TIMEOUT,
			CURLOPT_TIMEOUT => self::WARM_TIMEOUT,
		]);
		curl_exec($curl);
		curl_close($curl);
	}
}
