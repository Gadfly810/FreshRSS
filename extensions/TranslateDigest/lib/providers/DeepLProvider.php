<?php
require_once(__DIR__ . '/AbstractProvider.php');

/**
 * DeepL / DeepLX 翻译提供商
 *
 * 官方 DeepL API（以及新版 DeepLX 的 /v2/translate）请求格式：
 *   POST {endpoint}
 *   Authorization: DeepL-Auth-Key <key>   （DeepLX 可不需要）
 *   {"text": ["..."], "target_lang": "ZH", "source_lang": "EN"}
 *   -> {"translations": [{"detected_source_language": "EN", "text": "..."}]}
 *
 * endpoint 留空时按 Key 后缀自动判断：
 *   Key 以 ":fx" 结尾（免费版）-> https://api-free.deepl.com/v2/translate
 *   其他（Pro 版）             -> https://api.deepl.com/v2/translate
 * 自建 DeepLX 可填 http://127.0.0.1:1188/translate（旧版扁平请求体已兼容）
 */
class DeepLProvider extends AbstractProvider {

    const OFFICIAL_FREE = 'https://api-free.deepl.com/v2/translate';
    const OFFICIAL_PRO  = 'https://api.deepl.com/v2/translate';

    public function __construct() {
        $this->apiKey = FreshRSS_Context::$user_conf->DeepLApiKey ?? '';
        $configuredEndpoint = trim(FreshRSS_Context::$user_conf->DeepLEndpoint ?? '');
        if ($configuredEndpoint !== '') {
            $this->endpoint = $configuredEndpoint;
        } elseif (substr($this->apiKey, -3) === ':fx') {
            $this->endpoint = self::OFFICIAL_FREE;
        } else {
            $this->endpoint = self::OFFICIAL_PRO;
        }
        $this->model = '';
    }

    public function setApiKey($apiKey) {
        $this->apiKey = $apiKey;
    }

    public function setEndpoint($endpoint) {
        $this->endpoint = trim($endpoint);
    }

    public function getName() {
        return 'deepl';
    }

    protected function getHeaders() {
        $headers = ['Content-Type: application/json'];
        if (!empty($this->apiKey)) {
            $headers[] = 'Authorization: DeepL-Auth-Key ' . $this->apiKey;
        }
        return $headers;
    }

    /**
     * DeepL 语言代码为大写；空/auto 表示自动检测（不传 source_lang）
     */
    private function mapLang($lang, $isTarget = false) {
        $lang = strtolower(trim((string)$lang));
        if ($lang === '' || $lang === 'auto') {
            return $isTarget ? 'EN' : '';
        }
        $map = ['zh' => 'ZH', 'en' => 'EN', 'ja' => 'JA', 'fr' => 'FR', 'de' => 'DE'];
        return $map[$lang] ?? strtoupper($lang);
    }

    /**
     * DeepLX 旧版接口 /translate 使用扁平请求体 {"text": "...", "source_lang": "AUTO"}
     * 与 DeepL 官方 /v2/translate 的 {"text": ["..."], ...} 不同
     */
    private function isLegacyDeepLX() {
        $path = parse_url($this->endpoint, PHP_URL_PATH);
        return is_string($path) && rtrim($path, '/') === '/translate';
    }

    public function translate($text, $sourceLang = 'auto', $targetLang = 'zh') {
        if (empty(trim($text))) {
            return '';
        }

        $target = $this->mapLang($targetLang, true);
        $src = $this->mapLang($sourceLang, false);

        if ($this->isLegacyDeepLX()) {
            $payload = [
                'text' => $text,
                'source_lang' => $src === '' ? 'AUTO' : $src,
                'target_lang' => $target
            ];
        } else {
            $payload = [
                'text' => [$text],
                'target_lang' => $target
            ];
            if ($src !== '') {
                $payload['source_lang'] = $src;
            }
        }

        $data = $this->makeRequest($payload);
        if ($data === null) {
            return '';
        }

        $output = $this->extractMessage($data);
        return $this->postProcessOutput($output);
    }

    protected function extractMessage($data) {
        if (isset($data['translations'][0]['text'])) {
            return $data['translations'][0]['text'];
        }
        // DeepLX 旧版接口返回 {"code":200,"data":"译文"}
        if (isset($data['data']) && is_string($data['data'])) {
            return $data['data'];
        }
        return '';
    }

    /**
     * 覆盖父类请求方法：DeepL 的错误响应使用 {"message": "..."} 格式（非 error.message），
     * 因此需要单独解析；同时开启 ignore_errors 以便读取 4xx/5xx 的响应体
     */
    protected function makeRequest($payload, $timeout = 20) {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => $this->getHeaders(),
                'content' => $json,
                'timeout' => $timeout,
                'ignore_errors' => true
            ]
        ];

        $ctx = stream_context_create($options);
        try {
            $res = @file_get_contents($this->endpoint, false, $ctx);
            if ($res === false) {
                Logger::error($this->getName() . ' request failed: ' . $this->endpoint);
                return null;
            }

            Logger::debug($this->getName() . ' raw response: ' . substr($res, 0, 500));

            $data = json_decode($res, true);
            if (!is_array($data)) {
                Logger::error($this->getName() . ' invalid JSON response: ' . substr($res, 0, 200));
                return null;
            }

            if (isset($data['code']) && (int)$data['code'] !== 200) {
                Logger::error($this->getName() . ' error code: ' . $data['code'] . ' ' . ($data['message'] ?? ''));
                return null;
            }
            if (isset($data['message'])) {
                Logger::error($this->getName() . ' error: ' . $data['message']);
                return null;
            }
            if (isset($data['error'])) {
                $err = $data['error'];
                Logger::error($this->getName() . ' error: ' . (is_array($err) ? ($err['message'] ?? 'unknown') : $err));
                return null;
            }

            $this->extractTokenInfo($data);
            return $data;
        } catch (Exception $e) {
            Logger::exception($e, $this->getName() . ' request');
            return null;
        }
    }

    /**
     * DeepL 仅提供翻译，不提供摘要；SummarizationService 会自动走截断兜底
     */
    public function supportsSummarize() {
        return false;
    }

    public function summarize($text, $targetLang = 'zh', $maxChars = 200) {
        return mb_substr($text, 0, $maxChars);
    }

    public function validateKey() {
        $isOfficial = ($this->endpoint === self::OFFICIAL_FREE || $this->endpoint === self::OFFICIAL_PRO);
        if (empty($this->apiKey) && $isOfficial) {
            return ['ok' => false, 'message' => '未配置 DeepL Auth Key'];
        }

        if ($this->isLegacyDeepLX()) {
            $payload = ['text' => 'ping', 'source_lang' => 'AUTO', 'target_lang' => 'EN'];
        } else {
            $payload = ['text' => ['ping'], 'target_lang' => 'EN'];
        }

        $data = $this->makeRequest($payload, 10);
        if ($data === null) {
            return ['ok' => false, 'message' => '请求失败或认证失败'];
        }

        if (empty($this->extractMessage($data))) {
            return ['ok' => false, 'message' => '响应中未包含翻译结果'];
        }

        return ['ok' => true, 'message' => empty($this->apiKey) ? 'DeepLX 服务可用（无需 Key）' : 'DeepL Key 验证通过'];
    }

    public function getDefaultModel() {
        return '';
    }
}
