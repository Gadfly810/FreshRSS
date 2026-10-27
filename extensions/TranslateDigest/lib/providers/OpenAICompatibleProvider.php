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
 *   OpenAIModel    模型名，默认 gpt-4o-mini
 */
class OpenAICompatibleProvider extends AbstractProvider {

    const DEFAULT_ENDPOINT = 'https://api.openai.com/v1';

    public function __construct() {
        $this->apiKey = FreshRSS_Context::$user_conf->OpenAIApiKey ?? '';
        $configuredModel = trim(FreshRSS_Context::$user_conf->OpenAIModel ?? '');
        $this->model = $configuredModel !== '' ? $configuredModel : $this->getDefaultModel();

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
        if (empty(trim($text))) {
            return '';
        }

        $payload = $this->buildPayload([
            ['role' => 'system', 'content' => self::$prompts['translate']],
            ['role' => 'user', 'content' => 'Translate into ' . $targetLang . ': ' . $text]
        ], 'translate');

        $data = $this->makeRequest($payload);
        if ($data === null) {
            return '';
        }

        return $this->postProcessOutput($this->extractMessage($data));
    }

    public function supportsSummarize() {
        return true;
    }

    public function summarize($text, $targetLang = 'zh', $maxChars = 200) {
        $clean = $this->prepareText($text, $maxChars * 5);
        if (empty($clean)) {
            return '';
        }

        $payload = $this->buildPayload([
            ['role' => 'system', 'content' => self::$prompts['summarize']],
            ['role' => 'user', 'content' => 'Summarize in ' . $targetLang . ' within ' . $maxChars . ' characters: ' . $clean]
        ], 'summarize');

        $data = $this->makeRequest($payload);
        if ($data === null) {
            return mb_substr($clean, 0, $maxChars);
        }

        return mb_substr($this->postProcessOutput($this->extractMessage($data)), 0, $maxChars);
    }

    public function validateKey() {
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

    public function getDefaultModel() {
        return 'gpt-4o-mini';
    }
}
