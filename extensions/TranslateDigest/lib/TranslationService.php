<?php
require_once(__DIR__ . '/TextUtil.php');
require_once(__DIR__ . '/Logger.php');
require_once(__DIR__ . '/providers/ProviderFactory.php');

class TranslationService {

    /** 单次批量请求的最大段数 */
    const BATCH_MAX_SEGMENTS = 20;
    /** 单次批量请求的最大字符数 */
    const BATCH_MAX_CHARS = 10000;

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

        $translated = null;
        $actualService = $serviceName;

        Logger::info('Translation start - Service: ' . strtoupper($serviceName) . ' | Target: ' . $this->targetLang);
        Logger::debug('Text to translate: ' . mb_substr($text, 0, 50));

        try {
            $translated = $this->provider->translate($text, 'auto', $this->targetLang);

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
        return $translated;
    }

    /**
     * 回落到 Google 翻译，失败时返回原文
     */
    private function fallbackToGoogle($text) {
        $fallback = ProviderFactory::create('google');
        $result = $fallback->translate($text, 'auto', $this->targetLang) ?: $text;
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
            $pending[$key] = ['text' => $text, 'cache' => $cacheKey];
        }

        if (empty($pending)) {
            Logger::debug('Batch translation - Service: ' . strtoupper($serviceName) . ' | Cache: ALL MEMORY HIT | Segments: ' . count($texts));
            return $results;
        }

        Logger::info('Batch translation start - Service: ' . strtoupper($serviceName)
            . ' | Target: ' . $this->targetLang
            . ' | Segments: ' . count($pending)
            . ' | Batch support: ' . ($this->provider->supportsBatch() ? 'YES' : 'NO'));

        $batches = $this->groupIntoBatches($pending);

        foreach ($batches as $batch) {
            $batchTexts = [];
            foreach ($batch as $key => $item) {
                $batchTexts[$key] = $item['text'];
            }

            $translated = $this->callProviderBatch($batchTexts);

            foreach ($batch as $key => $item) {
                $result = $translated[$key] ?? '';
                if (trim((string)$result) === '') {
                    $result = $this->fallbackToGoogle($item['text']);
                }
                self::$cache[$item['cache']] = $result;
                $results[$key] = $result;
            }
        }

        Logger::info('Batch translation done - Service: ' . strtoupper($serviceName) . ' | Segments: ' . count($pending));
        return $results;
    }

    /**
     * 调用提供商的批量翻译，异常时返回空数组
     */
    private function callProviderBatch(array $batchTexts) {
        try {
            return $this->provider->translateBatch($batchTexts, 'auto', $this->targetLang) ?: [];
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
