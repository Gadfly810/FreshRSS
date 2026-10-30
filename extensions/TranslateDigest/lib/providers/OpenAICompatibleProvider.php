<?php
require_once(__DIR__ . '/AbstractProvider.php');

/**
 * OpenAI 兼容（OpenAI Compatible）提供商
 *
 * 适用于任何实现了 /v1/chat/completions 的服务：
 *   OpenAI 官方、Azure OpenAI 兼容网关、Ollama、LM Studio、vLLM、
 *   One API / New API 等中转服务。
 *
 * 配置：
 *   OpenAIApiKey   访问密钥（本地服务如 Ollama 可留空）
 *   OpenAIEndpoint 接口地址，可填 Base URL（https://api.openai.com/v1）
 *                  或完整地址（https://api.openai.com/v1/chat/completions）
 *   OpenAIModel    模型名，可留空后从模型列表中选择
 */
class OpenAICompatibleProvider extends AbstractProvider {

    const DEFAULT_ENDPOINT = 'https://api.openai.com/v1';

    public function __construct() {
        $this->apiKey = FreshRSS_Context::$user_conf->OpenAIApiKey ?? '';
        $this->model = trim(FreshRSS_Context::$user_conf->OpenAIModel ?? '');

        $configuredEndpoint = trim(FreshRSS_Context::$user_conf->OpenAIEndpoint ?? '');
        $this->endpoint = $this->normalizeEndpoint($configuredEndpoint);
    }

    public function setApiKey($apiKey) {
        $this->apiKey = $apiKey;
    }

    public function setEndpoint($endpoint) {
        $this->endpoint = $this->normalizeEndpoint(trim($endpoint));
    }

    public function setModel($model) {
        $model = trim($model);
        if ($model !== '') {
            $this->model = $model;
        }
    }

    /**
     * 允许填 Base URL 或完整地址，统一补全为 .../chat/completions
     */
    private function normalizeEndpoint($endpoint) {
        if ($endpoint === '') {
            return self::DEFAULT_ENDPOINT . '/chat/completions';
        }
        $endpoint = rtrim($endpoint, '/');
        if (substr($endpoint, -strlen('/chat/completions')) === '/chat/completions') {
            return $endpoint;
        }
        return $endpoint . '/chat/completions';
    }

    public function getName() {
        return 'openai';
    }

    protected function getHeaders() {
        $headers = ['Content-Type: application/json'];
        if (!empty($this->apiKey)) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }
        return $headers;
    }

    private function buildPayload($messages, $type) {
        $defaults = self::$payloadDefaults[$type] ?? self::$payloadDefaults['translate'];
        return [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $defaults['temperature'],
            'max_tokens' => $defaults['max_tokens'],
            'stream' => false
        ];
    }

    public function translate($text, $sourceLang = 'auto', $targetLang = 'zh') {
        if ($this->model === '' || empty(trim($text))) {
            return '';
        }

        $payload = $this->buildPayload($this->buildTranslateMessages($text, $targetLang), 'translate');

        $data = $this->makeRequest($payload);
        if ($data === null) {
            return '';
        }

        return $this->postProcessOutput($this->extractMessage($data));
    }

    public function supportsSummarize() {
        return true;
    }

    public function supportsBatch() {
        return true;
    }

    /**
     * 批量翻译：所有段落打包成一次请求（结构化 JSON 协议）
     */
    /**
     * 构造批量翻译请求（一次请求翻多段）
     * @return array|null
     */
    public function prepareBatchRequest(array $texts, $sourceLang = 'auto', $targetLang = 'zh') {
        if ($this->model === '' || empty($texts)) {
            return null;
        }
        $segments = $this->buildBatchSegments($texts);
        $payload = $this->buildPayload($this->buildBatchMessages($segments, $targetLang), 'translate');
        // 批量输出比单段长得多，放宽输出上限
        $payload['max_tokens'] = 4000;

        return [
            'method' => 'POST',
            'url' => $this->getRequestUrl(),
            'headers' => $this->getHeaders(),
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ];
    }

    public function translateBatch(array $texts, $sourceLang = 'auto', $targetLang = 'zh') {
        if ($this->model === '' || empty($texts)) {
            return array_fill_keys(array_keys($texts), '');
        }

        $request = $this->prepareBatchRequest($texts, $sourceLang, $targetLang);
        if ($request === null) {
            return parent::translateBatch($texts, $sourceLang, $targetLang);
        }

        $results = array_fill_keys(array_keys($texts), '');
        $data = $this->requestJson($request, 60);
        if ($data === null) {
            return $results;
        }

        $parsed = $this->parseBatchResponse($this->extractMessage($data));
        if ($parsed === null) {
            Logger::error($this->getName() . ' batch response parse failed, falling back to per-segment');
            return $results;
        }

        foreach ($this->mapBatchById($parsed, $texts) as $key => $value) {
            if ($value !== '') {
                $results[$key] = $this->postProcessOutput($value);
            }
        }
        return $results;
    }

    public function summarize($text, $targetLang = 'zh', $maxChars = 200) {
        $clean = $this->prepareText($text, $maxChars * 5);
        if (empty($clean)) {
            return '';
        }

        $payload = $this->buildPayload($this->buildSummarizeMessages($clean, $targetLang, $maxChars), 'summarize');

        $data = $this->makeRequest($payload);
        if ($data === null) {
            return mb_substr($clean, 0, $maxChars);
        }

        return mb_substr($this->postProcessOutput($this->extractMessage($data)), 0, $maxChars);
    }

    public function validateKey() {
        if ($this->model === '') {
            return ['ok' => false, 'message' => '未配置 OpenAI 模型'];
        }

        $isLocal = (bool)preg_match('#^https?://(127\.0\.0\.1|localhost|\[::1\])#i', $this->endpoint);
        if (empty($this->apiKey) && !$isLocal) {
            return ['ok' => false, 'message' => '未配置 OpenAI 兼容服务 API Key'];
        }

        $payload = [
            'model' => $this->model,
            'messages' => [['role' => 'user', 'content' => 'ping']],
            'max_tokens' => 5,
            'temperature' => 0.0,
            'stream' => false
        ];

        $data = $this->makeRequest($payload, 15);
        if ($data === null) {
            return ['ok' => false, 'message' => '请求失败或认证失败'];
        }

        return ['ok' => true, 'message' => 'OpenAI 兼容服务验证通过（模型: ' . $this->model . '）'];
    }

    /**
     * 通过 GET {base}/models 获取可用模型列表
     */
    public function listModels() {
        $base = $this->endpoint;
        $suffix = '/chat/completions';
        if (substr($base, -strlen($suffix)) === $suffix) {
            $base = substr($base, 0, -strlen($suffix));
        }

        $data = $this->httpGet(rtrim($base, '/') . '/models', $this->getHeaders());
        return $this->parseModelList($data, 'data', 'id');
    }
}
