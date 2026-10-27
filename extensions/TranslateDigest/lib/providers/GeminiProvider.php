<?php
require_once('AbstractProvider.php');

class GeminiProvider extends AbstractProvider {

    const DEFAULT_ENDPOINT = 'https://generativelanguage.googleapis.com';

    /** 用户配置的原始端点（未补全） */
    private $configuredEndpoint = '';

    public function __construct() {
        $this->apiKey = FreshRSS_Context::$user_conf->GeminiApiKey ?? '';
        $this->model = trim(FreshRSS_Context::$user_conf->GeminiModel ?? '');
        $this->configuredEndpoint = trim(FreshRSS_Context::$user_conf->GeminiEndpoint ?? '');
        $this->endpoint = $this->buildEndpoint($this->configuredEndpoint, $this->model);
    }

    public function setEndpoint($endpoint) {
        $this->configuredEndpoint = trim($endpoint);
        $this->endpoint = $this->buildEndpoint($this->configuredEndpoint, $this->model);
    }

    public function setModel($model) {
        $model = trim($model);
        if ($model !== '') {
            $this->model = $model;
            $this->endpoint = $this->buildEndpoint($this->configuredEndpoint, $model);
        }
    }

    /**
     * 允许填 Base URL 或完整地址，统一补全为 .../v1beta/models/{model}:generateContent
     * 完整地址中若含 {model} 占位符会被替换
     */
    private function buildEndpoint($endpoint, $model) {
        if ($endpoint === '') {
            $endpoint = self::DEFAULT_ENDPOINT;
        }
        if (strpos($endpoint, '{model}') !== false) {
            return str_replace('{model}', $model, $endpoint);
        }
        if (strpos($endpoint, ':generateContent') !== false) {
            return $endpoint;
        }
        $endpoint = rtrim($endpoint, '/');
        if (substr($endpoint, -strlen('/models')) === '/models') {
            return $endpoint . '/' . $model . ':generateContent';
        }
        if (preg_match('#/v1(beta|alpha)?$#', $endpoint)) {
            return $endpoint . '/models/' . $model . ':generateContent';
        }
        return $endpoint . '/v1beta/models/' . $model . ':generateContent';
    }

    /**
     * 获取模型列表用的 Base URL（去掉版本段，调用方自行拼接 /v1beta/models）
     */
    private function getBaseUrl() {
        $endpoint = $this->endpoint;
        $pos = strpos($endpoint, '/models/');
        if ($pos === false) {
            $pos = strpos($endpoint, '/models');
        }
        $base = $pos !== false ? substr($endpoint, 0, $pos) : rtrim($endpoint, '/');
        return preg_replace('#/v1(beta|alpha)[0-9]*$#', '', $base);
    }

    public function setApiKey($apiKey) {
        $this->apiKey = $apiKey;
    }

    public function getName() {
        return 'gemini';
    }

    protected function getHeaders() {
        return [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $this->apiKey
        ];
    }

    public function translate($text, $sourceLang = 'auto', $targetLang = 'zh') {
        if (empty($this->apiKey) || $this->model === '' || empty(trim($text))) {
            return '';
        }

        $prompt = $this->getTranslatePrompt($text, $targetLang);
        
        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'temperature' => self::$payloadDefaults['translate']['temperature'],
                'maxOutputTokens' => self::$payloadDefaults['translate']['max_tokens']
            ]
        ];

        $data = $this->makeRequest($payload);
        if ($data === null) {
            return '';
        }

        $output = $this->extractMessage($data);
        return $this->postProcessOutput($output);
    }

    public function supportsSummarize() {
        return true;
    }

    public function summarize($text, $targetLang = 'zh', $maxChars = 200) {
        if (empty($this->apiKey)) {
            return mb_substr($text, 0, $maxChars);
        }

        $clean = $this->prepareText($text, $maxChars * 5);
        if (empty($clean)) {
            return '';
        }

        $prompt = $this->getSummarizePrompt($clean, $targetLang, $maxChars);
        
        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'temperature' => self::$payloadDefaults['summarize']['temperature'],
                'maxOutputTokens' => self::$payloadDefaults['summarize']['max_tokens']
            ]
        ];

        $data = $this->makeRequest($payload);
        if ($data === null) {
            return mb_substr($clean, 0, $maxChars);
        }

        $output = $this->extractMessage($data);
        $output = $this->postProcessOutput($output);
        return mb_substr($output, 0, $maxChars);
    }

    public function validateKey() {
        if (empty($this->apiKey)) {
            return ['ok' => false, 'message' => '未配置 Gemini API Key'];
        }
        if ($this->model === '') {
            return ['ok' => false, 'message' => '未配置 Gemini 模型'];
        }

        $payload = [
            'contents' => [
                ['parts' => [['text' => 'ping']]]
            ],
            'generationConfig' => [
                'maxOutputTokens' => 5,
                'temperature' => 0.0
            ]
        ];

        $data = $this->makeRequest($payload, 10);
        if ($data === null) {
            return ['ok' => false, 'message' => '请求失败或认证失败'];
        }

        return ['ok' => true, 'message' => 'Gemini Key 验证通过'];
    }

    /**
     * 通过 GET {base}/v1beta/models 获取可用模型列表
     */
    public function listModels() {
        if (empty($this->apiKey)) {
            return ['ok' => false, 'models' => [], 'message' => '未配置 Gemini API Key'];
        }

        $url = $this->getBaseUrl() . '/v1beta/models?key=' . urlencode($this->apiKey);
        $data = $this->httpGet($url, ['Content-Type: application/json']);
        if ($data === null) {
            return ['ok' => false, 'models' => [], 'message' => '请求模型列表失败'];
        }
        if (isset($data['error'])) {
            $msg = is_array($data['error']) ? ($data['error']['message'] ?? 'unknown') : (string)$data['error'];
            return ['ok' => false, 'models' => [], 'message' => $msg];
        }
        if (!isset($data['models']) || !is_array($data['models'])) {
            return ['ok' => false, 'models' => [], 'message' => '响应中没有模型列表'];
        }

        $models = [];
        foreach ($data['models'] as $item) {
            $name = is_array($item) ? ($item['name'] ?? '') : '';
            if ($name === '') {
                continue;
            }
            // 只保留支持 generateContent 的模型
            if (isset($item['supportedGenerationMethods']) && is_array($item['supportedGenerationMethods'])
                && !in_array('generateContent', $item['supportedGenerationMethods'], true)) {
                continue;
            }
            $models[] = preg_replace('#^models/#', '', $name);
        }
        sort($models);
        return ['ok' => true, 'models' => $models, 'message' => '共 ' . count($models) . ' 个模型'];
    }

    public function getDefaultModel() {
        return '';
    }

    /**
     * 覆盖父类的 makeRequest 方法，因为 Gemini API 使用 API key 作为 URL 参数
     */
    protected function makeRequest($payload, $timeout = 20) {
        $url = $this->endpoint . '?key=' . $this->apiKey;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $this->getHeaders()),
                'content' => $json,
                'timeout' => $timeout
            ]
        ];
        
        $ctx = stream_context_create($options);
        try {
            $res = @file_get_contents($url, false, $ctx);
            if ($res === false) {
                Logger::error($this->getName() . ' request failed');
                return null;
            }
            
            $data = json_decode($res, true);
            
            Logger::debug($this->getName() . ' raw response: ' . substr($res, 0, 500));
            
            if (isset($data['error'])) {
                Logger::error($this->getName() . ' error: ' . ($data['error']['message'] ?? 'unknown'));
                return null;
            }
            
            // Gemini API 使用不同的 token 统计结构
            $this->extractTokenInfo($data);
            
            Logger::debug($this->getName() . ' extracted token info: ' . print_r($this->lastTokenInfo, true));
            
            return $data;
        } catch (Exception $e) {
            Logger::exception($e, $this->getName() . ' request');
            return null;
        }
    }

    /**
     * 覆盖 token 信息提取方法，适配 Gemini API 响应格式
     */
    protected function extractTokenInfo($data) {
        if (isset($data['usageMetadata'])) {
            $this->lastTokenInfo = [
                'prompt_tokens' => $data['usageMetadata']['promptTokenCount'] ?? 0,
                'completion_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
                'total_tokens' => $data['usageMetadata']['totalTokenCount'] ?? 0
            ];
            Logger::debug($this->getName() . ' tokens - prompt: ' . $this->lastTokenInfo['prompt_tokens'] 
                . ', completion: ' . $this->lastTokenInfo['completion_tokens'] 
                . ', total: ' . $this->lastTokenInfo['total_tokens']);
        }
    }

    /**
     * 覆盖消息提取方法，适配 Gemini API 响应格式
     */
    protected function extractMessage($data) {
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }
}
