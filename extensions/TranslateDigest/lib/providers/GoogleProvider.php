<?php
require_once(__DIR__ . '/ITranslationProvider.php');
require_once(__DIR__ . '/../Logger.php');
require_once(__DIR__ . '/../HttpClient.php');

/**
 * Google 翻译提供商（免费接口，无需 API Key）
 * 仅提供翻译；摘要走本地截断兜底
 *
 * 主接口为 Chrome 词典扩展使用的 translate_a/t（clients5），
 * 它对重复的 q 参数按段返回，且当前不像 translate_a/single 那样容易被限流；
 * 主接口不可用时对单段请求回退到 translate_a/single。
 */
class GoogleProvider implements ITranslationProvider {

    private const ENDPOINT_PRIMARY = 'https://clients5.google.com/translate_a/t';
    private const ENDPOINT_LEGACY = 'https://translate.googleapis.com/translate_a/single';

    /** 单次 GET 请求的 query 长度上限（实测约 16KB 被拒，取保守值） */
    private const MAX_QUERY_BYTES = 12000;

    public function getName() { return 'google'; }

    public function translate($text, $sourceLang = 'auto', $targetLang = 'zh') {
        $results = $this->requestTranslation([$text], $sourceLang, $targetLang);
        return $results[0] ?? '';
    }

    /**
     * 翻译一组文本，返回与输入同下标的译文数组；无法逐段对应时返回空数组由调用方回落
     * @param array $texts 文本列表（顺序下标）
     * @return array 与 $texts 同下标的译文数组
     */
    private function requestTranslation(array $texts, $sourceLang, $targetLang) {
        if (empty($texts)) {
            return [];
        }

        $results = [];
        $buffer = [];
        $keys = [];
        $bytes = 0;
        foreach ($texts as $key => $text) {
            $cost = $this->encodedCost($text);
            if ($cost > self::MAX_QUERY_BYTES) {
                // 单段本身就超过 URL 上限，只能切片翻译后拼接
                if (!$this->flushBuffer($buffer, $keys, $bytes, $results, $sourceLang, $targetLang)) {
                    return [];
                }
                $results[$key] = $this->translateLongText($text, $sourceLang, $targetLang);
                continue;
            }
            if (!empty($buffer) && $bytes + $cost > self::MAX_QUERY_BYTES) {
                if (!$this->flushBuffer($buffer, $keys, $bytes, $results, $sourceLang, $targetLang)) {
                    return [];
                }
            }
            $buffer[] = $text;
            $keys[] = $key;
            $bytes += $cost;
        }
        if (!$this->flushBuffer($buffer, $keys, $bytes, $results, $sourceLang, $targetLang)) {
            return [];
        }
        return $results;
    }

    /**
     * 发出当前缓冲的一批文本，结果按下标写入 $results
     * @return bool 是否成功（失败时调用方整体回落逐段）
     */
    private function flushBuffer(array &$buffer, array &$keys, &$bytes, array &$results, $sourceLang, $targetLang) {
        if (empty($buffer)) {
            return true;
        }
        $parsed = $this->requestChunk($buffer, $sourceLang, $targetLang);
        $ok = count($parsed) === count($keys);
        if ($ok) {
            foreach ($keys as $index => $key) {
                $results[$key] = $parsed[$index];
            }
        } else {
            Logger::error('google chunk could not be mapped per segment (' . count($keys) . ' segments)');
        }
        $buffer = [];
        $keys = [];
        $bytes = 0;
        return $ok;
    }

    /**
     * 单段过长时的兜底：按 URL 上限切片，逐片翻译后拼接
     */
    private function translateLongText($text, $sourceLang, $targetLang) {
        $text = (string)$text;
        $length = mb_strlen($text);
        $parts = [];
        $start = 0;
        while ($start < $length) {
            // 以 3000 字符为上限，再按编码后长度收缩
            $take = min(3000, $length - $start);
            while ($take > 1 && $this->encodedCost(mb_substr($text, $start, $take)) > self::MAX_QUERY_BYTES) {
                $take = (int)floor($take / 2);
            }
            $piece = mb_substr($text, $start, $take);
            $parsed = $this->requestChunk([$piece], $sourceLang, $targetLang);
            $parts[] = $parsed[0] ?? '';
            $start += $take;
        }
        return implode('', $parts);
    }

    /**
     * 文本在 URL 中占用的字节数（含 "&q="）
     */
    private function encodedCost($text) {
        return strlen(urlencode((string)$text)) + 3;
    }

    /**
     * 请求一个子批，主接口失败且只有一段时回退到旧接口
     */
    private function requestChunk(array $texts, $sourceLang, $targetLang) {
        $parsed = $this->fetchAndParse(self::ENDPOINT_PRIMARY, 'dict-chrome-ex', $texts, $sourceLang, $targetLang);
        if (count($parsed) === count($texts)) {
            return $parsed;
        }
        if (count($texts) === 1) {
            $legacy = $this->fetchAndParse(self::ENDPOINT_LEGACY, 'gtx', $texts, $sourceLang, $targetLang);
            if (count($legacy) === 1) {
                return $legacy;
            }
        }
        return [];
    }

    /**
     * 发出 GET 请求并解析响应
     * @param string $endpoint 接口地址
     * @param string $client client 参数值
     * @return array 与 $texts 同下标的译文数组，失败返回空数组
     */
    private function fetchAndParse($endpoint, $client, array $texts, $sourceLang, $targetLang) {
        $params = ['client' => $client, 'sl' => $sourceLang, 'tl' => $targetLang];
        if ($endpoint === self::ENDPOINT_LEGACY) {
            $params['dt'] = 't';
        }
        $query = http_build_query($params);
        foreach ($texts as $text) {
            $query .= '&q=' . urlencode((string)$text);
        }
        $url = $endpoint . '?' . $query;

        try {
            $response = HttpClient::get($url, [], count($texts) > 1 ? 15 : 10);
        } catch (Throwable $e) {
            Logger::exception($e, 'google request');
            return [];
        }

        if (!$response['ok']) {
            Logger::error('google request failed: ' . $response['error'] . ' [' . $endpoint . ']');
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
     * 解析响应
     * 新接口：[[译文, "en"], ...]，每个元素对应一个 q
     * 旧接口单段：[[["译文","原文",...], ...], null, "en", ...]，需把多个句子拼起来
     * 旧接口多段：[[[ ... ]], [[ ... ]], ...]，每个元素对应一个 q
     */
    private function parseTranslationResponse(array $response, $expectedCount) {
        $first = $response[0] ?? null;

        // 新接口扁平结构
        if (is_array($first) && isset($first[0]) && is_string($first[0])) {
            if (count($response) !== $expectedCount) {
                return [];
            }
            $results = [];
            foreach ($response as $index => $item) {
                $results[$index] = (string)($item[0] ?? '');
            }
            return $results;
        }

        // 旧接口多段：顶层元素数恰好等于段数，且每项都像单段结果
        if ($expectedCount > 1 && count($response) === $expectedCount && is_array($first) && isset($first[0]) && is_array($first[0])) {
            $results = [];
            foreach ($response as $index => $item) {
                // 每个元素本身就是句子数组，包一层以复用 joinSentences
                $results[$index] = $this->joinSentences([$item]);
            }
            return $results;
        }

        // 旧接口单段
        if ($expectedCount === 1) {
            $single = $this->joinSentences($response);
            return $single === '' ? [] : [$single];
        }
        return [];
    }

    /**
     * 把旧接口响应中的句子数组拼接成完整译文
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
