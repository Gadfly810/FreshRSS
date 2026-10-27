<?php
/**
 * 文本处理工具类
 */
class TextUtil {
    
    /**
     * 准备文本：清理HTML、规范化空白、去重标点
     * @param string $text 原始文本
     * @param int $limit 字符限制
     * @return string 处理后的文本
     */
    public static function prepare($text, $limit = 4000) {
        // 移除HTML标签
        $text = strip_tags($text);
        
        // 解码 HTML 实体（&nbsp; → 空格，&amp; → &，等等）
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // 规范化空白字符（包括换行、制表符等）
        $text = preg_replace('/\s+/u', ' ', $text);
        
        // 去除行首行尾空白
        $text = trim($text);
        
        // 去重重复标点
        $text = preg_replace('/([。！？!?])\1+/u', '$1', $text);
        
        // 修复标点后的多余空格（"word ." → "word."）
        $text = preg_replace('/\s+([.,;:!?。，；：！？])/u', '$1', $text);
        
        // 移除作者署名等常见模式（可选，提高摘要质量）
        $text = preg_replace('/^(Authored by|By)\s+[^,]+,?\s*/iu', '', $text);
        
        // 截断到限制长度
        $text = mb_substr($text, 0, $limit);
        
        return $text;
    }

    /**
     * 后处理：移除包裹的引号
     */
    public static function postProcess($text) {
        $text = trim($text);
        
        // 移除包裹的双引号
        $text = preg_replace('/^"(.+)"$/u', '$1', $text);
        
        // 移除包裹的单引号
        $text = preg_replace("/^'(.+)'$/u", '$1', $text);
        
        return $text;
    }

    /**
     * 从 HTML 中提取段落级文本块
     * 块级标签与 <br> 当作换行，去标签、解实体、规范化空白，丢弃空行与相邻重复行
     * @param string $html 原始 HTML
     * @return array 文本块列表
     */
    public static function extractBlocks($html) {
        $html = (string)$html;

        // 去掉脚本与样式内容
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);

        // 块级标签边界与 <br> 转成换行符
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</(p|div|li|ul|ol|dl|dt|dd|h[1-6]|blockquote|tr|td|th|section|article|figure|figcaption|pre|table)\s*>#i', "\n", $html);
        $html = preg_replace('#<(p|div|li|ul|ol|dl|dt|dd|h[1-6]|blockquote|tr|td|th|section|article|figure|figcaption|pre|table)\b[^>]*>#i', "\n", $html);

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $blocks = [];
        foreach (explode("\n", $text) as $line) {
            $line = preg_replace('/\s+/u', ' ', $line);
            $line = trim($line);
            if ($line === '' || mb_strlen($line) < 2) {
                continue;
            }
            if (!empty($blocks) && end($blocks) === $line) {
                continue;
            }
            $blocks[] = $line;
        }

        return $blocks;
    }

    /**
     * 按句末标点把长文本切成不超过 $limit 的块，用于分段翻译
     * @param string $text 文本
     * @param int $limit 单块最大字符数
     * @return array 文本块列表
     */
    public static function splitChunks($text, $limit) {
        $text = trim((string)$text);
        if ($text === '') {
            return [];
        }
        $limit = (int)$limit;
        if ($limit < 50) {
            $limit = 50;
        }
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        // 先按句末标点切句（保留标点）
        $sentences = preg_split('/(?<=[。！？；.!?;])\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($sentences)) {
            $sentences = [$text];
        }

        $chunks = [];
        $current = '';
        foreach ($sentences as $sentence) {
            if (mb_strlen($sentence) > $limit) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                // 单句超长：硬切
                $len = mb_strlen($sentence);
                for ($i = 0; $i < $len; $i += $limit) {
                    $chunks[] = mb_substr($sentence, $i, $limit);
                }
                continue;
            }
            if ($current !== '' && mb_strlen($current) + mb_strlen($sentence) > $limit) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= $sentence;
        }
        if (trim($current) !== '') {
            $chunks[] = $current;
        }

        $result = [];
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk !== '') {
                $result[] = $chunk;
            }
        }
        return $result;
    }

    /**
     * 安全转义HTML
     */
    public static function escapeHtml($text) {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
