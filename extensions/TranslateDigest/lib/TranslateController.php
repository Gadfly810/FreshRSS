<?php
require_once('TranslationService.php');
require_once('SummarizationService.php');
require_once('TextUtil.php');
require_once('Logger.php');

class TranslateController {

    /** 译文块样式：对齐 kiss-translator 的「普通下划线」译文风格（DEFAULT_COLOR = #209CEE） */
    private const TRANSLATION_BLOCK_STYLE = 'margin:0.15em 0 1em;'
        . 'text-decoration-line:underline;text-decoration-style:solid;text-decoration-color:#209CEE;'
        . 'text-decoration-thickness:1px;text-underline-offset:0.3em;'
        . '-webkit-text-decoration-line:underline;-webkit-text-decoration-style:solid;'
        . '-webkit-text-decoration-color:#209CEE;-webkit-text-underline-offset:0.3em;';
    
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

            // 摘要块放在最前；正文按「原文 - 译文」逐段对照
            $newContent = $originalContent;
            if ($contentTranslationEnabled) {
                $newContent = $this->buildInterleavedContent($originalContent, $config, $feedId);
            }

            $blocks = '';
            if ($summarizeEnabled) {
                $blocks .= $this->buildSummaryBlock($originalContent, $config, $feedId);
            }

            if ($blocks !== '' || $newContent !== $originalContent) {
                $entry->_content($blocks . $newContent);
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
     * 生成对照式双语正文：每段原文之后紧跟该段译文
     * 原文保留原始 HTML（图片、列表等不被破坏），译文只保留文字与行内标签
     */
    private function buildInterleavedContent($content, $config, $feedId) {
        try {
            if (empty($content)) {
                Logger::debug("Feed $feedId: no content to translate");
                return $content;
            }

            $serviceKey = $config['TranslateDefaultService'];
            $targetLang = $config['TranslateTargetLang'];
            $skipIfSame = $config['TranslateSkipIfSame'];
            $maxChars = (int)$config['TokenMaxChars'];

            $segments = TextUtil::splitTopLevelBlocks($content);
            if (empty($segments)) {
                Logger::debug("Feed $feedId: no segment split from content");
                return $content;
            }

            // 先把所有待翻译的 chunk 收集起来，统一批量翻译（一次请求多段）
            $plan = [];       // segIndex => [ ['tags' => .., 'keys' => [..]] ]
            $pending = [];    // "segIndex:blockIndex:chunkIndex" => 原文
            foreach ($segments as $segIndex => $segmentHtml) {
                $plan[$segIndex] = [];
                foreach (TextUtil::extractRichBlocks($segmentHtml) as $blockIndex => $block) {
                    $keys = [];
                    // 已是目标语言则不再翻译
                    if (!($skipIfSame && $this->isTargetLang($block['text'], $targetLang))) {
                        foreach (TextUtil::splitChunksBalanced($block['text'], $maxChars) as $chunkIndex => $chunk) {
                            $key = $segIndex . ':' . $blockIndex . ':' . $chunkIndex;
                            $pending[$key] = $chunk;
                            $keys[] = $key;
                        }
                    }
                    $plan[$segIndex][] = ['tags' => $block['tags'], 'keys' => $keys];
                }
            }

            if (empty($pending)) {
                Logger::debug("Feed $feedId: nothing to translate in content");
                return $content;
            }

            Logger::debug("Translating content for feed $feedId (service: $serviceKey, lang: $targetLang, maxChars: $maxChars, segments: " . count($segments) . ', chunks: ' . count($pending) . ')');

            $translationService = new TranslationService($serviceKey, $targetLang);
            try {
                $translated = $translationService->translateBatch($pending);
            } catch (Exception $e) {
                Logger::exception($e, 'Content batch translation');
                $translated = [];
            }

            $output = '';
            $translatedBlocks = 0;
            foreach ($segments as $segIndex => $segmentHtml) {
                // 原文原样输出
                $output .= $segmentHtml;
                foreach ($plan[$segIndex] as $block) {
                    $pieces = [];
                    foreach ($block['keys'] as $key) {
                        $piece = trim((string)($translated[$key] ?? ''));
                        if ($piece !== '') {
                            $pieces[] = $piece;
                        }
                    }
                    if (empty($pieces)) {
                        continue;
                    }
                    // 还原行内标签（链接、粗体、代码等），其余文本会被转义
                    $output .= '<div class="td-translation" style="' . self::TRANSLATION_BLOCK_STYLE . '">'
                        . TextUtil::restoreInlineTags(implode(' ', $pieces), $block['tags'])
                        . '</div>';
                    $translatedBlocks++;
                }
            }

            if ($translatedBlocks === 0) {
                Logger::debug("Feed $feedId: content translation produced nothing, skipping");
                return $content;
            }

            Logger::debug("Added bilingual content for feed $feedId: $translatedBlocks segment(s)");
            return $output;
        } catch (Exception $e) {
            Logger::exception($e, 'Content translation for feed: ' . $feedId);
            return $content;
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
