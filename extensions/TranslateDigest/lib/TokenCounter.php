<?php

require_once(__DIR__ . '/Logger.php');

class TokenCounter {
    
    const STATS_KEY = 'TranslateTitlesCN_TokenStats';

    // 参与 token 统计的提供商
    private static $knownProviders = ['google', 'deepseek', 'qwen', 'gemini', 'deepl', 'openai'];

    private static $defaultStats = [
        'deepseek' => ['prompt' => 0, 'completion' => 0, 'total' => 0],
        'qwen'  => ['prompt' => 0, 'completion' => 0, 'total' => 0],
        'gemini'  => ['prompt' => 0, 'completion' => 0, 'total' => 0],
        // DeepL 按字符计费且响应不含 token 用量，仅占位
        'deepl'   => ['prompt' => 0, 'completion' => 0, 'total' => 0],
        'openai'  => ['prompt' => 0, 'completion' => 0, 'total' => 0],
    ];

    public static function recordTokens($tokenInfo, $provider, $type = 'translate') {
        if (empty($tokenInfo) || !in_array($provider, self::$knownProviders, true)) {
            return;
        }

        $promptTokens = (int)($tokenInfo['prompt_tokens'] ?? 0);
        $completionTokens = (int)($tokenInfo['completion_tokens'] ?? 0);
        $totalTokens = $promptTokens + $completionTokens;

        if ($totalTokens <= 0) {
            Logger::debug("Token count is 0 for $provider, skipping record");
            return;
        }

        // Update stats
        $stats = self::getStats();
        if (!isset($stats[$provider])) {
            $stats[$provider] = self::$defaultStats[$provider];
        }
        $stats[$provider]['prompt'] += $promptTokens;
        $stats[$provider]['completion'] += $completionTokens;
        $stats[$provider]['total'] += $totalTokens;
        self::saveStats($stats);

        Logger::info(sprintf(
            'Token recorded - Provider: %s | Type: %s | Tokens: %d (prompt: %d, completion: %d)',
            strtoupper($provider),
            $type,
            $totalTokens,
            $promptTokens,
            $completionTokens
        ));
    }

    public static function getTotalTokens($provider) {
        $stats = self::getStats();
        return $stats[$provider]['total'] ?? 0;
    }
    
    public static function getStats() {
        if (isset(FreshRSS_Context::$user_conf) && isset(FreshRSS_Context::$user_conf->{self::STATS_KEY})) {
            $stats = FreshRSS_Context::$user_conf->{self::STATS_KEY};
            if (is_array($stats)) {
                return array_merge(self::$defaultStats, $stats);
            }
        }
        return self::$defaultStats;
    }

    private static function saveStats($stats) {
        if (isset(FreshRSS_Context::$user_conf)) {
            FreshRSS_Context::$user_conf->{self::STATS_KEY} = $stats;
            FreshRSS_Context::$user_conf->save();
        }
    }
    
    public static function resetAllStats() {
        self::saveStats(self::$defaultStats);
        Logger::info("All token stats have been reset.");
    }
    
    public static function resetTotalTokens($provider) {
        $stats = self::getStats();
        if (isset($stats[$provider])) {
            $stats[$provider] = self::$defaultStats[$provider];
            self::saveStats($stats);
            Logger::info("Token stats reset for provider: $provider");
        }
    }

}
