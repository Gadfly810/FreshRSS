<?php
require_once('TranslationService.php');
require_once('SummarizationService.php');
require_once('TextUtil.php');
require_once('Logger.php');

class TranslateController {
    
    public function processEntry($entry) {
        try {
            $feedId = $entry->feed()->id();
            
            // 从 FreshRSS_Context 读取配置
            $translateMap = json_decode(FreshRSS_Context::$user_conf->TranslateTitles ?? '{}', true) ?: [];
            $summarizeMap = json_decode(FreshRSS_Context::$user_conf->SummarizeContents ?? '{}', true) ?: [];
            $contentMap = json_decode(FreshRSS_Context::$user_conf->TranslateContents ?? '{}', true) ?: [];
            
            // 构建配置数组
            $config = [
                'TranslateDefaultService' => FreshRSS_Context::$user_conf->TranslateDefaultService ?? 'google',
                'TranslateTargetLang' => FreshRSS_Context::$user_conf->TranslateTargetLang ?? 'zh',
                'TranslateSkipIfSame' => (FreshRSS_Context::$user_conf->TranslateSkipIfSame ?? '0') === '1',
                'TokenMaxChars' => FreshRSS_Context::$user_conf->TokenMaxChars ?? '4000',
                'TranslateTitles' => $translateMap,
                'SummarizeContents' => $summarizeMap,
                'TranslateContents' => $contentMap,
            ];
            
            // 检查此订阅源是否启用翻译
            $translationEnabled = isset($config['TranslateTitles'][$feedId]) && $config['TranslateTitles'][$feedId] === '1';
            $summarizeEnabled = isset($config['SummarizeContents'][$feedId]) && $config['SummarizeContents'][$feedId] === '1';
            $contentTranslationEnabled = isset($config['TranslateContents'][$feedId]) && $config['TranslateContents'][$feedId] === '1';
            
            $defaultService = strtoupper($config['TranslateDefaultService']);
            Logger::info("Processing entry - Feed: $feedId | Service: $defaultService | Translate: " . ($translationEnabled ? 'YES' : 'NO') . " | Summarize: " . ($summarizeEnabled ? 'YES' : 'NO') . " | TranslateContent: " . ($contentTranslationEnabled ? 'YES' : 'NO'));
            
            if (!$translationEnabled && !$summarizeEnabled && !$contentTranslationEnabled) {
                Logger::debug("Feed $feedId: all disabled, skipping");
                return $entry;
            }

            // 翻译标题
            if ($translationEnabled) {
                $entry = $this->translateTitle($entry, $config, $feedId);
            }

            // 正文只取一次（标题修改不影响正文）
            $originalContent = $entry->content();

            // 生成两个块，最后一次性拼接到原文前，避免互相污染
            $blocks = '';
            if ($contentTranslationEnabled) {
                $blocks .= $this->buildContentTranslationBlock($originalContent, $config, $feedId);
            }
            if ($summarizeEnabled) {
                $blocks .= $this->buildSummaryBlock($originalContent, $config, $feedId);
            }

            if ($blocks !== '') {
                $entry->_content($blocks . $originalContent);
            }
            
            return $entry;
        } catch (Exception $e) {
            Logger::exception($e, 'Entry processing');
            return $entry;
        }
    }

    /**
     * 翻译标题
     */
    private function translateTitle($entry, $config, $feedId) {
        $targetLang = $config['TranslateTargetLang'];
        $skipIfSame = $config['TranslateSkipIfSame'];
        $serviceKey = $config['TranslateDefaultService'];

        $title = $entry->title();
        if (empty($title)) {
            return $entry;
        }
        
        Logger::debug("Original title: $title (feedId: $feedId)");
        
        // 检查是否同语言
        if ($skipIfSame && $this->isTargetLang($title, $targetLang)) {
            Logger::debug("Skipping translation - same language detected: $title");
            return $entry;
        }

            try {
                $translationService = new TranslationService($serviceKey, $targetLang);
                $usedService = null;
                $translatedTitle = $translationService->translate($title, $usedService);

                if ($translatedTitle && $translatedTitle !== $title) {
                    // 在标题中添加使用的翻译引擎标识
                    $serviceTag = '[' . strtoupper($usedService) . ']';
                    $displayTitle = $translatedTitle . ' - ' . $title . ' - ' . $serviceTag;
                    $entry->_title($displayTitle);
                    Logger::debug("Translated title: " . mb_substr($displayTitle, 0, 50));
                } else {
                    Logger::debug("Translation returned empty or same: $translatedTitle");
                }
            } catch (Exception $e) {
                Logger::exception($e, 'Title translation for: ' . $title);
            }

        return $entry;
    }

    /**
     * 生成摘要块 HTML（失败返回空串）
     */
    private function buildSummaryBlock($content, $config, $feedId) {
        try {
            if (empty($content)) {
                Logger::debug("Feed $feedId: no content to summarize");
                return '';
            }

            $serviceKey = $config['TranslateDefaultService'];
            $targetLang = $config['TranslateTargetLang'];
            $maxChars = (int)$config['TokenMaxChars'];

            Logger::debug("Summarizing content for feed $feedId (service: $serviceKey, lang: $targetLang, maxChars: $maxChars)");

            $summaryService = new SummarizationService($serviceKey, $targetLang, $maxChars);
            $summary = $summaryService->summarize($content);

            if (empty($summary)) {
                Logger::debug('Summary service returned empty result');
                return '';
            }

            // 摘要若已为目标语言则无需再翻译
            if ($targetLang !== 'en' && !$this->isTargetLang($summary, $targetLang)) {
                try {
                    $translationService = new TranslationService($serviceKey, $targetLang);
                    $translatedSummary = $translationService->translate($summary);
                    if (!empty($translatedSummary)) {
                        $summary = $translatedSummary;
                        Logger::debug("Summary translated to $targetLang");
                    }
                } catch (Exception $e) {
                    Logger::debug('Failed to translate summary: ' . $e->getMessage());
                }
            }

            $safeSummary = TextUtil::escapeHtml($summary);
            Logger::debug('Added summary: ' . mb_substr($summary, 0, 40));
            return '<blockquote style="margin:0 0 1em;color:#555;">'
                . '<p><em>✨ AI 摘要：</em> ' . $safeSummary . '</p>'
                . '</blockquote>';
        } catch (Exception $e) {
            Logger::exception($e, 'Summary generation for feed: ' . $feedId);
            return '';
        }
    }

    /**
     * 生成正文翻译块 HTML（双语显示：译文块在前，原文完整保留在后）
     * 长文本按段落切块，每块再按句末标点分段翻译后拼接
     */
    private function buildContentTranslationBlock($content, $config, $feedId) {
        try {
            if (empty($content)) {
                Logger::debug("Feed $feedId: no content to translate");
                return '';
            }

            $serviceKey = $config['TranslateDefaultService'];
            $targetLang = $config['TranslateTargetLang'];
            $skipIfSame = $config['TranslateSkipIfSame'];
            $maxChars = (int)$config['TokenMaxChars'];

            $blocks = TextUtil::extractBlocks($content);
            if (empty($blocks)) {
                Logger::debug("Feed $feedId: no text block extracted from content");
                return '';
            }

            Logger::debug("Translating content for feed $feedId (service: $serviceKey, lang: $targetLang, maxChars: $maxChars, blocks: " . count($blocks) . ')');

            $translationService = new TranslationService($serviceKey, $targetLang);
            $translatedBlocks = [];
            $translatedCount = 0;

            foreach ($blocks as $block) {
                // 已是目标语言则原样保留
                if ($skipIfSame && $this->isTargetLang($block, $targetLang)) {
                    $translatedBlocks[] = $block;
                    continue;
                }

                $chunks = TextUtil::splitChunks($block, $maxChars);
                $translatedChunks = [];
                foreach ($chunks as $chunk) {
                    try {
                        $translated = $translationService->translate($chunk);
                    } catch (Exception $e) {
                        Logger::exception($e, 'Content chunk translation');
                        $translated = null;
                    }
                    // 翻译失败或返回空时保留原文，避免丢内容
                    $translatedChunks[] = empty($translated) ? $chunk : $translated;
                }

                $translatedBlock = trim(implode(' ', $translatedChunks));
                if ($translatedBlock !== '') {
                    $translatedBlocks[] = $translatedBlock;
                    $translatedCount++;
                }
            }

            if ($translatedCount === 0 || empty($translatedBlocks)) {
                Logger::debug("Feed $feedId: content translation produced nothing, skipping");
                return '';
            }

            $paragraphs = '';
            foreach ($translatedBlocks as $block) {
                $paragraphs .= '<p style="margin:0 0 0.6em;">' . TextUtil::escapeHtml($block) . '</p>';
            }

            Logger::debug('Added content translation: ' . mb_substr(implode(' ', $translatedBlocks), 0, 40));
            return '<div style="margin:0 0 1em;padding:0.8em 1em;border-left:4px solid #2b7de9;'
                . 'background:#f2f7ff;border-radius:3px;color:#1a3d6d;">'
                . '<p style="margin:0 0 0.6em;"><em>🌐 译文（' . strtoupper($targetLang) . '）：</em></p>'
                . $paragraphs
                . '</div>';
        } catch (Exception $e) {
            Logger::exception($e, 'Content translation for feed: ' . $feedId);
            return '';
        }
    }

    /**
     * 检测文本是否为目标语言
     */
    private function isTargetLang($text, $targetLang) {
        $text = trim($text);
        if (empty($text)) {
            return false;
        }

        $ratio = 0.6;
        switch ($targetLang) {
            case 'zh':
                return $this->charRatio('/[\x{4e00}-\x{9fa5}]/u', $text) > $ratio;
            case 'ja':
                return $this->charRatio('/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $text) > 0.3;
            case 'en':
                // 非 ASCII 字符少于 10% 视为英文
                return $this->charRatio('/[^\x00-\x7F]/u', $text) < 0.1;
            case 'fr':
            case 'de':
                // 拉丁字母为主且不含中文/日文假名
                return $this->charRatio('/[\x{4e00}-\x{9fa5}\x{3040}-\x{30FF}]/u', $text) < 0.05;
            default:
                return false;
        }
    }

    /**
     * 计算匹配字符数占总字符数的比例
     */
    private function charRatio($pattern, $text) {
        $len = mb_strlen($text);
        if ($len === 0) {
            return 0.0;
        }
        return preg_match_all($pattern, $text) / $len;
    }
}
