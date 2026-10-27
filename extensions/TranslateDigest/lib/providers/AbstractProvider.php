<?php
/**
 * 抽象翻译提供商基类
 * 提取公共的 HTTP 请求和文本处理逻辑
 */
require_once(__DIR__ . '/ITranslationProvider.php');
require_once(__DIR__ . '/../Logger.php');
require_once(__DIR__ . '/../TextUtil.php');

abstract class AbstractProvider implements ITranslationProvider {
    
    protected $apiKey;
    protected $model;
    protected $endpoint;

    // Prompt 模板
    protected static $prompts = [
        'translate' => 'You are a professional translation engine. Output ONLY the translated text without explanation.',
        'batch' => 'Act as a professional machine translation engine. Output a raw JSON array strictly. No Markdown fences, no conversational filler.',
        'summarize' => 'Summarize the article, and provide three parts: summary, abstract, and opinions. The opinion section should be listed as a list, using Markdown to reply.'
    ];

    // 请求参数默认值
    protected static $payloadDefaults = [
        'translate' => ['temperature' => 0.2, 'max_tokens' => 800],
        'summarize' => ['temperature' => 0.1, 'max_tokens' => 600]
    ];

    /**
     * 获取请求头
     */
    protected abstract function getHeaders();

    /**
     * 获取实际请求地址
     * 默认直接用 $this->endpoint；需要把凭据拼进 URL 的提供商（如 Gemini）覆盖此方法
     */
    protected function getRequestUrl() {
        return $this->endpoint;
    }

    /**
     * 执行 HTTP POST 请求
     * @param array $payload 请求负载
     * @param int $timeout 超时时间（秒）
     * @return array|null 响应数据
     */
    protected function makeRequest($payload, $timeout = 20) {
        $url = $this->getRequestUrl();
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
            $res = @file_get_contents($url, false, $ctx);
            if ($res === false) {
                Logger::error($this->getName() . ' request failed: ' . $url);
                return null;
            }

            Logger::debug($this->getName() . ' raw response: ' . substr($res, 0, 500));

            $data = json_decode($res, true);
            if (!is_array($data)) {
                Logger::error($this->getName() . ' invalid JSON response: ' . substr($res, 0, 200));
                return null;
            }

            if ($this->isErrorResponse($data)) {
                return null;
            }

            return $data;
        } catch (Exception $e) {
            Logger::exception($e, $this->getName() . ' request');
            return null;
        }
    }

    /**
     * 判断响应是否为错误，并记录日志
     * 默认处理 OpenAI 风格的 {"error": {"message": ...}}；
     * 使用其它错误格式的提供商（如 DeepL）覆盖此方法
     */
    protected function isErrorResponse($data) {
        if (!isset($data['error'])) {
            return false;
        }
        $err = $data['error'];
        Logger::error($this->getName() . ' error: ' . (is_array($err) ? ($err['message'] ?? 'unknown') : (string)$err));
        return true;
    }

    /**
     * 执行 HTTP GET 请求
     * @param string $url 请求地址
     * @param array $headers 请求头（字符串数组）
     * @param int $timeout 超时时间（秒）
     * @return array|null 响应数据
     */
    protected function httpGet($url, $headers = [], $timeout = 15) {
        $options = [
            'http' => [
                'method' => 'GET',
                'header' => $headers,
                'timeout' => $timeout,
                'ignore_errors' => true
            ]
        ];

        $ctx = stream_context_create($options);
        try {
            $res = @file_get_contents($url, false, $ctx);
            if ($res === false) {
                Logger::error($this->getName() . ' GET request failed: ' . $url);
                return null;
            }
            Logger::debug($this->getName() . ' GET raw response: ' . substr($res, 0, 500));
            return json_decode($res, true);
        } catch (Exception $e) {
            Logger::exception($e, $this->getName() . ' GET request');
            return null;
        }
    }

    /**
     * 获取该服务可用的模型列表
     * 默认实现：不支持
     * @return array ['ok' => bool, 'models' => string[], 'message' => string]
     */
    public function listModels() {
        return ['ok' => false, 'models' => [], 'message' => '该服务不支持获取模型列表'];
    }

    /**
     * 从 {"data": [{"id": ...}]} 或 {"models": [{"name": ...}]} 中解析模型名
     * @param array $data 响应数据
     * @param string $listKey 列表字段名（data / models）
     * @param string $nameKey 名称字段名（id / name）
     * @param string $prefix 需要从名称中去掉的前缀（如 models/）
     * @return array ['ok' => bool, 'models' => string[], 'message' => string]
     */
    protected function parseModelList($data, $listKey, $nameKey, $prefix = '') {
        if ($data === null) {
            return ['ok' => false, 'models' => [], 'message' => '请求模型列表失败'];
        }
        if (isset($data['error'])) {
            $err = $data['error'];
            $msg = is_array($err) ? ($err['message'] ?? 'unknown') : (string)$err;
            return ['ok' => false, 'models' => [], 'message' => $msg];
        }
        if (!isset($data[$listKey]) || !is_array($data[$listKey])) {
            return ['ok' => false, 'models' => [], 'message' => '响应中没有模型列表'];
        }

        $models = [];
        foreach ($data[$listKey] as $item) {
            if (!is_array($item) || empty($item[$nameKey])) {
                continue;
            }
            $models[] = $prefix === '' ? $item[$nameKey] : str_replace($prefix, '', $item[$nameKey]);
        }
        sort($models);
        return ['ok' => true, 'models' => $models, 'message' => '共 ' . count($models) . ' 个模型'];
    }

    /**
     * 构造翻译消息列表
     */
    protected function buildTranslateMessages($text, $targetLang) {
        return [
            ['role' => 'system', 'content' => self::$prompts['translate']],
            ['role' => 'user', 'content' => 'Translate into ' . $targetLang . ': ' . $text]
        ];
    }

    /**
     * 是否支持一次请求翻译多段文本
     * 默认不支持，translateBatch() 会逐段调用 translate()
     */
    public function supportsBatch() {
        return false;
    }

    /**
     * 批量翻译默认实现：逐段调用 translate()
     * @param array $texts 待翻译文本（保持原下标）
     * @return array 与 $texts 同下标的译文数组
     */
    public function translateBatch(array $texts, $sourceLang = 'auto', $targetLang = 'zh') {
        $results = [];
        foreach ($texts as $key => $text) {
            $results[$key] = $this->translate($text, $sourceLang, $targetLang);
        }
        return $results;
    }

    /**
     * 构造批量翻译的消息列表（结构化 JSON 协议）
     * 输入 [{"id":1,"text":"..."}]，要求模型输出同结构的译文数组
     * @param array $segments 形如 [['id' => 1, 'text' => '...'], ...]
     * @param string $targetLang 目标语言
     * @return array 消息列表
     */
    protected function buildBatchMessages(array $segments, $targetLang) {
        return [
            ['role' => 'system', 'content' => self::$prompts['batch']],
            ['role' => 'user', 'content' => $this->buildBatchInstruction($segments, $targetLang)]
        ];
    }

    /**
     * 构造批量翻译的单段 prompt（供 Gemini 这类只用单条文本的提供商使用）
     */
    protected function buildBatchPrompt(array $segments, $targetLang) {
        return self::$prompts['batch'] . "\n\n" . $this->buildBatchInstruction($segments, $targetLang);
    }

    /**
     * 批量翻译的指令正文
     */
    private function buildBatchInstruction(array $segments, $targetLang) {
        return 'Translate the "text" field of each object in the JSON array below into ' . $targetLang . ".\n"
            . "Rules:\n"
            . "1. Keep the \"id\" field and the array order unchanged.\n"
            . "2. Preserve all HTML-like tags, entities and placeholders exactly as they appear.\n"
            . "3. Do not translate text inside <code>, <pre>, backticks, or placeholders like {1} or [[1]].\n"
            . "4. If a text is already in the target language, return it unchanged.\n"
            . "5. On any error, return [].\n"
            . "Input: " . json_encode($segments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\nOutput: ";
    }

    /**
     * 解析批量翻译响应，容忍 Markdown 围栏与前后废话
     * @param string $content 模型原始输出
     * @return array|null 以 id 为键的译文数组；无法解析时返回 null
     */
    protected function parseBatchResponse($content) {
        $content = trim((string)$content);
        if ($content === '') {
            return null;
        }

        // 去掉 Markdown 代码围栏
        $content = preg_replace('/^```[a-zA-Z]*\s*/', '', $content);
        $content = preg_replace('/\s*```\s*$/', '', $content);

        // 截取首个 [ / { 到最后一个 ] / }
        $start = strpos($content, '[');
        $startObj = strpos($content, '{');
        if ($start === false || ($startObj !== false && $startObj < $start)) {
            $start = $startObj;
        }
        $end = max((int)strrpos($content, ']'), (int)strrpos($content, '}'));
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $json = substr($content, $start, $end - $start + 1);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            Logger::error($this->getName() . ' batch response is not valid JSON: ' . substr($json, 0, 200));
            return null;
        }

        // 兼容 {"translations": [...]} 包装
        if (isset($decoded['translations']) && is_array($decoded['translations'])) {
            $decoded = $decoded['translations'];
        }

        $result = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = $item['id'] ?? null;
            if ($id === null) {
                continue;
            }
            $text = $item['text'] ?? ($item['translation'] ?? null);
            if ($text === null) {
                continue;
            }
            $result[(int)$id] = (string)$text;
        }

        return empty($result) ? null : $result;
    }

    /**
     * 构造摘要消息列表
     */
    protected function buildSummarizeMessages($text, $targetLang, $maxChars) {
        return [
            ['role' => 'system', 'content' => self::$prompts['summarize']],
            ['role' => 'user', 'content' => 'Summarize in ' . $targetLang . ' within ' . $maxChars . ' characters: ' . $text]
        ];
    }

    /**
     * 获取翻译 Prompt（供需要单段 prompt 的提供商使用，如 Gemini）
     */
    protected function getTranslatePrompt($text, $targetLang) {
        return self::$prompts['translate'] . "\n\nTranslate into " . $targetLang . ': ' . $text;
    }

    /**
     * 获取摘要 Prompt（供需要单段 prompt 的提供商使用，如 Gemini）
     */
    protected function getSummarizePrompt($text, $targetLang, $maxChars) {
        return self::$prompts['summarize'] . "\n\nSummarize in " . $targetLang . ' within ' . $maxChars . ' characters: ' . $text;
    }

    /**
     * 准备文本
     */
    protected function prepareText($text, $limit = 4000) {
        return TextUtil::prepare($text, $limit);
    }

    /**
     * 后处理输出
     */
    protected function postProcessOutput($text) {
        return TextUtil::postProcess($text);
    }

    /**
     * 提取消息内容
     */
    protected function extractMessage($data) {
        return $data['choices'][0]['message']['content'] ?? '';
    }
}
