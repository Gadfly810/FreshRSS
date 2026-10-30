<?php
require_once(__DIR__ . '/Logger.php');

/**
 * 译文持久化缓存
 *
 * 进程内缓存（TranslationService::$cache）随一次请求/一次 actualize 结束就失效，
 * 而订阅源里有大量跨抓取不变的文本：页脚、署名、「阅读更多」、模板化的导语等。
 * 这里把译文落盘到用户数据目录，命中时完全不发请求。
 *
 * 存储格式：{"<md5>": "译文", ...}，按写入顺序保存，超出上限时保留最新的条目。
 */
class TranslationCache {

    const FILE_NAME = 'TranslateDigest.cache.json';

    /** 最多保留的条目数 */
    const MAX_ENTRIES = 5000;

    /** @var array<string,string>|null 已加载的条目 */
    private static $entries = null;

    /** @var bool 是否有未落盘的改动 */
    private static $dirty = false;

    /** @var bool 是否已注册 shutdown 落盘 */
    private static $shutdownRegistered = false;

    /** @var string|null 缓存文件路径，null 表示不可用 */
    private static $path = null;

    /** @var bool 是否已尝试解析过路径 */
    private static $pathResolved = false;

    /**
     * 读取一条缓存
     * @param string $key 缓存键
     * @return string|null 未命中返回 null
     */
    public static function get($key) {
        $entries = self::load();
        if ($entries === null || !isset($entries[$key])) {
            return null;
        }
        return $entries[$key];
    }

    /**
     * 写入一条缓存（不立即落盘，进程结束时统一写）
     * @param string $key 缓存键
     * @param string $value 译文
     */
    public static function set($key, $value) {
        $entries = self::load();
        if ($entries === null) {
            return;
        }
        $value = (string)$value;
        if ($value === '') {
            return;
        }
        if (isset($entries[$key]) && $entries[$key] === $value) {
            return;
        }
        // 重新插入到末尾，近似 LRU
        unset($entries[$key]);
        $entries[$key] = $value;
        self::$entries = $entries;
        self::$dirty = true;
    }

    /**
     * 把缓存写入磁盘
     */
    public static function flush() {
        if (!self::$dirty || self::$entries === null) {
            return;
        }
        $path = self::path();
        if ($path === null) {
            return;
        }

        $entries = self::$entries;
        if (count($entries) > self::MAX_ENTRIES) {
            $entries = array_slice($entries, -self::MAX_ENTRIES, null, true);
            self::$entries = $entries;
        }

        $json = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            Logger::error('Translation cache encode failed');
            return;
        }

        // 先写临时文件再改名，避免并发写入读到半截内容
        $temp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temp, $json, LOCK_EX) === false) {
            Logger::error('Translation cache write failed: ' . $temp);
            return;
        }
        if (!@rename($temp, $path)) {
            @unlink($temp);
            Logger::error('Translation cache rename failed: ' . $path);
            return;
        }
        self::$dirty = false;
    }

    /**
     * 清空缓存文件（卸载扩展时调用）
     */
    public static function clear() {
        $path = self::path();
        self::$entries = [];
        self::$dirty = false;
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * 加载缓存条目，不可用时返回 null
     * @return array<string,string>|null
     */
    private static function load() {
        if (self::$entries !== null) {
            return self::$entries;
        }
        $path = self::path();
        if ($path === null) {
            return null;
        }

        $entries = [];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $entries = $decoded;
                } else {
                    Logger::error('Translation cache is corrupted, starting over: ' . $path);
                }
            }
        }
        self::$entries = $entries;

        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([__CLASS__, 'flush']);
        }

        return self::$entries;
    }

    /**
     * 解析当前用户的缓存文件路径，无法确定时返回 null
     * @return string|null
     */
    private static function path() {
        if (self::$pathResolved) {
            return self::$path;
        }
        self::$pathResolved = true;

        if (!defined('DATA_PATH') || !class_exists('Minz_User')) {
            self::$path = null;
            return null;
        }
        $user = Minz_User::name();
        if ($user === null || $user === '') {
            self::$path = null;
            return null;
        }
        self::$path = DATA_PATH . '/users/' . $user . '/' . self::FILE_NAME;
        return self::$path;
    }
}
