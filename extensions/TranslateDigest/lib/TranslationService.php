<?php
require_once(__DIR__ . '/TextUtil.php');
require_once(__DIR__ . '/Logger.php');
require_once(__DIR__ . '/HttpClient.php');
require_once(__DIR__ . '/TranslationCache.php');
require_once(__DIR__ . '/providers/ProviderFactory.php');

class TranslationService {

    /** 单次批量请求的最大段数 */
    const BATCH_MAX_SEGMENTS = 20;
    /** 单次批量请求的最大字符数 */
    const BATCH_MAX_CHARS = 10000;
    /** 批量请求超时（秒），并行时多批一起等 */
    const BATCH_TIMEOUT = 60;

    private $provider;
    private $targetLang;
    private static $cache = [];

    public function __construct($serviceKey, $targetLang = null) {
        $this->targetLang = $targetLang ?: (FreshRSS_Context::$user_conf->TranslateTargetLang ?? 'zh');
        $this->provider = ProviderFactory::create($serviceKey);
    }

    public function translate($text, &$usedService = null) {
        $text = trim($text);
        if (empty($text)) {
            return '';
        }

        $serviceName = $this->provider->getName();
        $cacheKey = md5('translate|' . $serviceName . '|' . $this->targetLang . '|' . $text);

        if (isset(self::$cache[$cacheKey])) {
            Logger::debug('Translation result - Service: ' . strtoupper($serviceName) . ' | Cache: MEMORY HIT | Text: ' . mb_substr($text, 0, 40));
            $usedService = $serviceName;
            return self::$cache[$cacheKey];
        }

        $persisted = TranslationCache::get($cacheKey);
        if ($persisted !== null) {
            Logger::debug('Translation result - Service: ' . strtoupper($serviceName) . ' | Cache: DISK HIT | Text: ' . mb_substr($text, 0, 40));
            self::$cache[$cacheKey] = $persisted;
            $usedService = $serviceName;
            return $persisted;
        }

        $translated = null;
        $actualService = $serviceName;

        Logger::info('Translation start - Service: ' . strtoupper($serviceName) . ' | Target: ' . $this->targetLang);
        Logger::debug('Text to translate: ' . mb_substr($text, 0, 50));

        try {
            $translated = $this->provider->translate(TextUtil::encodeNewlines($text), 'auto', $this->targetLang);
            $translated = TextUtil::decodeNewlines($translated);

            if (empty($translated)) {
                if ($serviceName !== 'google') {
                    Logger::info('Translation API call to ' . strtoupper($serviceName) . ' returned empty');
                    $translated = $this->fallbackToGoogle($text);
                    $actualService = 'google';
                } else {
                    $translated = $text;
                }
            } else {
                Logger::info('Translation API call - Service: ' . strtoupper($serviceName) . ' | Result: ' . mb_substr($translated, 0, 50));
            }
        } catch (Exception $e) {
            Logger::exception($e, 'Translation with ' . strtoupper($serviceName));

            if ($serviceName !== 'google') {
                try {
                    $translated = $this->fallbackToGoogle($text);
                    $actualService = 'google';
                    Logger::info('Translation fallback to Google due to error - Service: GOOGLE');
                } catch (Exception $fallbackErr) {
                    Logger::exception($fallbackErr, 'Fallback translation to Google');
                    $translated = $text;
                }
            } else {
                $translated = $text;
                Logger::error('Google translation failed');
            }
        }

        $usedService = $actualService;
        self::$cache[$cacheKey] = $translated;
        TranslationCache::set($cacheKey, $translated);
        return $translated;
    }

    /**
     * 回落到 Google 翻译，失败时返回原文
     */
    private function fallbackToGoogle($text) {
        $fallback = ProviderFactory::create('google');
        $result = $fallback->translate(TextUtil::encodeNewlines($text), 'auto', $this->targetLang);
        $result = TextUtil::decodeNewlines($result) ?: $text;
        Logger::info('Translation fallback - Service: GOOGLE | Result: ' . mb_substr($result, 0, 50));
        return $result;
    }

    /**
     * 批量翻译
     * 先查内存缓存，剩余文本按段数/字符数分组，每组一次请求；
     * 组内失败项回落 Google，仍失败则返回原文
     * @param array $texts 待翻译文本（下标任意）
     * @return array 与 $texts 同下标的译文数组
     */
    public function translateBatch(array $texts) {
        if (empty($texts)) {
            return [];
        }

        $serviceName = $this->provider->getName();
        $results = [];
        $pending = [];
        $aliases = [];        // cacheKey => 与代表项文本相同的其他 key
        $representatives = []; // cacheKey => 实际送翻的 key

        foreach ($texts as $key => $text) {
            $text = trim((string)$text);
            if ($text === '') {
                $results[$key] = '';
                continue;
            }
            $cacheKey = md5('translate|' . $serviceName . '|' . $this->targetLang . '|' . $text);
            if (isset(self::$cache[$cacheKey])) {
                $results[$key] = self::$cache[$cacheKey];
                continue;
            }
            $persisted = TranslationCache::get($cacheKey);
            if ($persisted !== null) {
                self::$cache[$cacheKey] = $persisted;
                $results[$key] = $persisted;
                continue;
            }
            // 同一段文本（页脚、模板文字等）只送一次，结果回填给所有重复项
            if (isset($representatives[$cacheKey])) {
                $aliases[$cacheKey][] = $key;
                continue;
            }
            $representatives[$cacheKey] = $key;
            $pending[$key] = ['text' => $text, 'cache' => $cacheKey];
        }

        if (empty($pending)) {
            Logger::debug('Batch translation - Service: ' . strtoupper($serviceName) . ' | Cache: ALL HIT | Segments: ' . count($texts));
            return $results;
        }

        Logger::info('Batch translation start - Service: ' . strtoupper($serviceName)
            . ' | Target: ' . $this->targetLang
            . ' | Segments: ' . count($pending)
            . ' | Batch support: ' . ($this->provider->supportsBatch() ? 'YES' : 'NO'));

        $batches = $this->groupIntoBatches($pending);
        $translations = $this->runBatches($batches);

        foreach ($batches as $batch) {
            foreach ($batch as $key => $item) {
                $result = $translations[$key] ?? '';
                if (trim((string)$result) === '') {
                    $result = $this->fallbackToGoogle($item['text']);
                }
                self::$cache[$item['cache']] = $result;
                TranslationCache::set($item['cache'], $result);
                $results[$key] = $result;
            }
        }

        // 把重复段落的译文回填到各自的 key
        foreach ($aliases as $cacheKey => $keys) {
            if (!isset(self::$cache[$cacheKey])) {
                continue;
            }
            foreach ($keys as $key) {
                $results[$key] = self::$cache[$cacheKey];
            }
        }

        Logger::info('Batch translation done - Service: ' . strtoupper($serviceName) . ' | Segments: ' . count($pending));
        return $results;
    }

    /**
     * 执行各批请求
     * 能一次性构造出请求时用 curl_multi 并行发出（总耗时≈最慢的一批）；
     * 否则退回串行，串行路径自带重试
     * @param array $batches groupIntoBatches() 的结果
     * @return array key => 译文（失败项缺省，由调用方兜底）
     */
    private function runBatches(array $batches) {
        $translations = [];
        if (count($batches) < 2) {
            foreach ($batches as $batch) {
                $translations += $this->callProviderBatch($this->extractTexts($batch));
            }
            return $translations;
        }

        $requests = [];
        $batchTexts = [];
        foreach ($batches as $index => $batch) {
            $texts = $this->extractTexts($batch);
            $request = $this->provider->prepareBatchRequest($texts, 'auto', $this->targetLang);
            if ($request === null) {
                // 有一批无法用单次请求完成，整体退回串行
                $translations = [];
                foreach ($batches as $serialBatch) {
                    $translations += $this->callProviderBatch($this->extractTexts($serialBatch));
                }
                return $translations;
            }
            $requests[$index] = $request;
            $batchTexts[$index] = $texts;
        }

        $responses = HttpClient::requestMulti($requests, self::BATCH_TIMEOUT);
        if ($responses === null) {
            $translations = [];
            foreach ($batches as $serialBatch) {
                $translations += $this->callProviderBatch($this->extractTexts($serialBatch));
            }
            return $translations;
        }

        Logger::info('Batch translation parallel - Service: ' . strtoupper($this->provider->getName())
            . ' | Requests: ' . count($responses));

        foreach ($batchTexts as $index => $texts) {
            $response = $responses[$index] ?? null;
            if ($response === null || empty($response['ok'])) {
                $translations += $this->callProviderBatch($texts);
                continue;
            }
            $data = json_decode($response['body'], true);
            if (!is_array($data)) {
                Logger::error('Parallel batch response is not JSON (HTTP ' . $response['status'] . '), retrying serially');
                $translations += $this->callProviderBatch($texts);
                continue;
            }
            foreach ($this->provider->parseBatchResponseData($data, $texts) as $key => $text) {
                $translations[$key] = TextUtil::decodeNewlines($text);
            }
        }
        return $translations;
    }

    /**
     * 取出待翻译文本并做换行保护（与串行路径一致）
     */
    private function extractTexts(array $batch) {
        $texts = [];
        foreach ($batch as $key => $item) {
            $texts[$key] = TextUtil::encodeNewlines($item['text']);
        }
        return $texts;
    }

    /**
     * 调用提供商的批量翻译，异常时返回空数组
     */
    private function callProviderBatch(array $batchTexts) {
        try {
            $encoded = [];
            foreach ($batchTexts as $key => $text) {
                $encoded[$key] = TextUtil::encodeNewlines($text);
            }
            $translated = $this->provider->translateBatch($encoded, 'auto', $this->targetLang) ?: [];
            foreach ($translated as $key => $text) {
                $translated[$key] = TextUtil::decodeNewlines($text);
            }
            return $translated;
        } catch (Throwable $e) {
            Logger::exception($e, 'Batch translation with ' . strtoupper($this->provider->getName()));
            return [];
        }
    }

    /**
     * 按段数与字符数上限把待翻译文本分组
     */
    private function groupIntoBatches(array $pending) {
        $batches = [];
        $current = [];
        $currentChars = 0;

        foreach ($pending as $key => $item) {
            $length = mb_strlen($item['text']);
            if (!empty($current) && (count($current) >= self::BATCH_MAX_SEGMENTS || $currentChars + $length > self::BATCH_MAX_CHARS)) {
                $batches[] = $current;
                $current = [];
                $currentChars = 0;
            }
            $current[$key] = $item;
            $currentChars += $length;
        }

        if (!empty($current)) {
            $batches[] = $current;
        }
        return $batches;
    }
}
