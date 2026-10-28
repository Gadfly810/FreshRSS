<?php
require_once(__DIR__ . '/ITranslationProvider.php');
require_once(__DIR__ . '/../Logger.php');
require_once(__DIR__ . '/../HttpClient.php');

/**
 * Google 翻译提供商（免费接口，无需 API Key）
 * 仅提供翻译；摘要走本地截断兜底
 */
class GoogleProvider implements ITranslationProvider {

    private $baseUrl = 'https://translate.googleapis.com/translate_a/single';

    public function getName() { return 'google'; }

    public function translate($text, $sourceLang = 'auto', $targetLang = 'zh') {
        $results = $this->requestTranslation([$text], $sourceLang, $targetLang);
        return $results[0] ?? '';
    }

    /**
     * 调用 Google 免费接口
     * translate_a/single 支持重复的 q 参数，一次请求返回多段译文；
     * 响应结构与单段请求一致时（拿不到逐段对应），返回空数组由调用方回落逐段
     * @param array $texts 文本列表（顺序下标）
     * @return array 与 $texts 同下标的译文数组
     */
    private function requestTranslation(array $texts, $sourceLang, $targetLang) {
        $texts = array_values($texts);
        if (empty($texts)) {
            return [];
        }

        $params = [
            'client' => 'gtx',
            'sl' => $sourceLang,
            'tl' => $targetLang,
            'dt' => 't',
        ];
        $query = http_build_query($params);
        foreach ($texts as $text) {
            $query .= '&q=' . urlencode((string)$text);
        }
        $url = $this->baseUrl . '?' . $query;

        try {
            $response = HttpClient::get($url, [], count($texts) > 1 ? 15 : 10);
        } catch (Throwable $e) {
            Logger::exception($e, 'google request');
            return [];
        }

        if (!$response['ok']) {
            Logger::error('google request failed: ' . $response['error'] . ' [' . $url . ']');
            return [];
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            Logger::error('google invalid JSON response (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 200));
            return [];
        }

        return $this->parseTranslationResponse($decoded, count($texts));
    }

    /**
     * 解析 translate_a/single 响应
     * 单段：[[["译文","原文",...],[...]], null, "en", ...]，需把同一次翻译的多个句子拼起来
     * 多段：[[[ ... ]], [[ ... ]], ...]，每个元素对应一个 q
     */
    private function parseTranslationResponse(array $response, $expectedCount) {
        // 多段：顶层元素数恰好等于段数，且每项都像单段结果
        if ($expectedCount > 1 && count($response) === $expectedCount && isset($response[0][0]) && is_array($response[0][0])) {
            $results = [];
            foreach ($response as $index => $item) {
                // 每个元素本身就是句子数组，包一层以复用 joinSentences
                $results[$index] = $this->joinSentences([$item]);
            }
            return $results;
        }

        // 单段（或服务端未按段返回）
        $single = $this->joinSentences($response);
        if ($expectedCount === 1) {
            return [$single];
        }
        return [];
    }

    /**
     * 把响应中的句子数组拼接成完整译文
     */
    private function joinSentences($response) {
        if (!is_array($response) || empty($response[0]) || !is_array($response[0])) {
            return '';
        }
        $parts = [];
        foreach ($response[0] as $sentence) {
            if (is_array($sentence) && isset($sentence[0]) && is_string($sentence[0])) {
                $parts[] = $sentence[0];
            }
        }
        return implode('', $parts);
    }

    public function supportsSummarize() { return true; }

    /**
     * Google 无摘要接口，使用本地截断（尽量在句末断开）
     */
    public function summarize($text, $targetLang = 'zh', $maxChars = 200) {
        $summary = mb_substr($text, 0, $maxChars);
        if (mb_strlen($text) > $maxChars) {
            $lastPeriod = mb_strrpos($summary, '。');
            if ($lastPeriod === false || $lastPeriod <= $maxChars / 2) {
                $lastPeriod = mb_strrpos($summary, '，');
            }
            if ($lastPeriod !== false && $lastPeriod > $maxChars / 2) {
                $summary = mb_substr($summary, 0, $lastPeriod + 1);
            }
        }
        return $summary;
    }

    public function validateKey() {
        return ['ok' => true, 'message' => 'Google 服务无需 API Key'];
    }

    public function supportsBatch() {
        return true;
    }

    /**
     * 批量翻译：用重复的 q 参数一次请求多段
     * 响应拿不到逐段对应时退化为逐段调用
     */
    public function translateBatch(array $texts, $sourceLang = 'auto', $targetLang = 'zh') {
        if (empty($texts)) {
            return [];
        }

        $orderedKeys = array_keys($texts);
        $orderedTexts = [];
        foreach ($orderedKeys as $key) {
            $orderedTexts[] = trim((string)$texts[$key]);
        }

        $translated = $this->requestTranslation($orderedTexts, $sourceLang, $targetLang);
        if (count($translated) === count($orderedTexts)) {
            $results = [];
            foreach ($orderedKeys as $index => $key) {
                $results[$key] = $translated[$index];
            }
            return $results;
        }

        Logger::error('google multi-q response could not be mapped per segment, falling back to per-segment');
        $results = [];
        foreach ($texts as $key => $text) {
            $results[$key] = $this->translate($text, $sourceLang, $targetLang);
        }
        return $results;
    }
}
