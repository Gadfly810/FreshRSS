<?php
/**
 * 提供商工厂
 * 统一负责服务键到提供商类的映射与文件加载，
 * 避免 TranslationService / SummarizationService 各自维护一份 switch。
 */
require_once(__DIR__ . '/ITranslationProvider.php');
require_once(__DIR__ . '/AbstractProvider.php');
require_once(__DIR__ . '/GoogleProvider.php');
require_once(__DIR__ . '/DeepSeekProvider.php');
require_once(__DIR__ . '/GeminiProvider.php');
require_once(__DIR__ . '/DeepLProvider.php');
require_once(__DIR__ . '/OpenAICompatibleProvider.php');

class ProviderFactory {

    /** 服务键 => 提供商类名；'google' 同时作为未知键的默认值 */
    private static $map = [
        'deepseek' => 'DeepSeekProvider',
        'gemini'   => 'GeminiProvider',
        'deepl'    => 'DeepLProvider',
        'openai'   => 'OpenAICompatibleProvider',
        'google'   => 'GoogleProvider',
    ];

    /**
     * 按服务键创建提供商实例，未知键回落到 Google
     * @param string $key 服务键
     * @return ITranslationProvider
     */
    public static function create($key) {
        $class = self::$map[$key] ?? 'GoogleProvider';
        return new $class();
    }

    /**
     * 判断服务键是否受支持
     */
    public static function supports($key) {
        return isset(self::$map[$key]);
    }
}
