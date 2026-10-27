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
    public function translateBatch(array $texts, $sourceLang = 'auto', $targetLang = 'zh') {
        if (empty($this->apiKey) || $this->model === '' || empty($texts)) {
            return array_fill_keys(array_keys($texts), '');
        }

        $segments = [];
        $keys = [];
        $id = 0;
        foreach ($texts as $key => $text) {
            $text = trim((string)$text);
            if ($text === '') {
                continue;
            }
            $id++;
            $segments[] = ['id' => $id, 'text' => $text];
            $keys[$id] = $key;
        }
        if (empty($segments)) {
            return array_fill_keys(array_keys($texts), '');
        }

        $payload = [
            'model' => $this->model,
            'messages' => $this->buildBatchMessages($segments, $targetLang),
            'temperature' => self::$payloadDefaults['translate']['temperature'],
            'max_tokens' => 4000,
            'stream' => false
        ];

        $data = $this->makeRequest($payload, 60);
        if ($data === null) {
            return array_fill_keys(array_keys($texts), '');
        }

        $parsed = $this->parseBatchResponse($this->extractMessage($data));
        $results = array_fill_keys(array_keys($texts), '');
        if ($parsed === null) {
            Logger::error($this->getName() . ' batch response parse failed, falling back to per-segment');
            return $results;
        }

        foreach ($keys as $segmentId => $key) {
            if (isset($parsed[$segmentId])) {
                $results[$key] = $this->postProcessOutput($parsed[$segmentId]);
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

