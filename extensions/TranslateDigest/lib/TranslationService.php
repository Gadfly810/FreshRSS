<?php
require_once(__DIR__ . '/TextUtil.php');
require_once(__DIR__ . '/Logger.php');
require_once(__DIR__ . '/providers/ProviderFactory.php');

class TranslationService {

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
}
