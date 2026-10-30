<?php
require_once('AbstractProvider.php');

class DeepSeekProvider extends AbstractProvider {

    public function __construct() {
        $this->apiKey = FreshRSS_Context::$user_conf->DeepSeekApiKey ?? '';
        $this->model = trim(FreshRSS_Context::$user_conf->DeepSeekModel ?? '');
        $this->endpoint = 'https://api.deepseek.com/chat/completions';
    }

    public function setApiKey($apiKey) {
        $this->apiKey = $apiKey;
    }

    public function setModel($model) {
        $model = trim($model);
        if ($model !== '') {
            $this->model = $model;
        }
    }

    public function getName() {
        return 'deepseek';
    }

    protected function getHeaders() {
        return [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ];
    }

    public function translate($text, $sourceLang = 'auto', $targetLang = 'zh') {
        if (empty($this->apiKey) || $this->model === '' || empty(trim($text))) {
            return '';
        }

        $payload = [
            'model' => $this->model,
            'messages' => $this->buildTranslateMessages($text, $targetLang),
            'temperature' => self::$payloadDefaults['translate']['temperature'],
            'max_tokens' => self::$payloadDefaults['translate']['max_tokens'],
            'stream' => false
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
        if (empty($this->apiKey) || $this->model === '' || empty($texts)) {
            return null;
        }
        $payload = [
            'model' => $this->model,
            'messages' => $this->buildBatchMessages($this->buildBatchSegments($texts), $targetLang),
            'temperature' => self::$payloadDefaults['translate']['temperature'],
            'max_tokens' => 4000,
            'stream' => false
        ];

        return [
            'method' => 'POST',
            'url' => $this->getRequestUrl(),
            'headers' => $this->getHeaders(),
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ];
    }

    public function translateBatch(array $texts, $sourceLang = 'auto', $targetLang = 'zh') {
        if (empty($this->apiKey) || $this->model === '' || empty($texts)) {
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
        if (empty($this->apiKey)) {
            return mb_substr($text, 0, $maxChars);
        }

        $clean = $this->prepareText($text, $maxChars * 5);
        if (empty($clean)) {
            return '';
        }

        $payload = [
            'model' => $this->model,
            'messages' => $this->buildSummarizeMessages($clean, $targetLang, $maxChars),
            'temperature' => self::$payloadDefaults['summarize']['temperature'],
            'max_tokens' => self::$payloadDefaults['summarize']['max_tokens'],
            'stream' => false
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
            return ['ok' => false, 'message' => '未配置 DeepSeek API Key'];
        }
        if ($this->model === '') {
            return ['ok' => false, 'message' => '未配置 DeepSeek 模型'];
        }

        $payload = [
            'model' => $this->model,
            'messages' => [['role' => 'user', 'content' => 'ping']],
            'max_tokens' => 5,
            'temperature' => 0.0,
            'stream' => false
        ];

        $data = $this->makeRequest($payload, 10);
        if ($data === null) {
            return ['ok' => false, 'message' => '请求失败或认证失败'];
        }

        return ['ok' => true, 'message' => 'DeepSeek Key 验证通过'];
    }

    /**
     * 通过 GET https://api.deepseek.com/models 获取可用模型列表
     */
    public function listModels() {
        if (empty($this->apiKey)) {
            return ['ok' => false, 'models' => [], 'message' => '未配置 DeepSeek API Key'];
        }

        $data = $this->httpGet('https://api.deepseek.com/models', $this->getHeaders());
        return $this->parseModelList($data, 'data', 'id');
    }
}

