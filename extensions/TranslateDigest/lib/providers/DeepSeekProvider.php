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
            'messages' => [
                ['role' => 'system', 'content' => self::$prompts['translate']],
                ['role' => 'user', 'content' => 'Translate into ' . $targetLang . ': ' . $text]
            ],
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
            'messages' => [
                ['role' => 'system', 'content' => self::$prompts['summarize']],
                ['role' => 'user', 'content' => 'Summarize in ' . $targetLang . ' within ' . $maxChars . ' characters: ' . $clean]
            ],
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
        if ($data === null) {
            return ['ok' => false, 'models' => [], 'message' => '请求模型列表失败'];
        }
        if (isset($data['error'])) {
            $msg = is_array($data['error']) ? ($data['error']['message'] ?? 'unknown') : (string)$data['error'];
            return ['ok' => false, 'models' => [], 'message' => $msg];
        }
        if (!isset($data['data']) || !is_array($data['data'])) {
            return ['ok' => false, 'models' => [], 'message' => '响应中没有模型列表'];
        }

        $models = [];
        foreach ($data['data'] as $item) {
            if (is_array($item) && !empty($item['id'])) {
                $models[] = $item['id'];
            }
        }
        sort($models);
        return ['ok' => true, 'models' => $models, 'message' => '共 ' . count($models) . ' 个模型'];
    }

    public function getDefaultModel() {
        return '';
    }
}

