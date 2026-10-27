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
     * 需要保留的行内标签（其余标签直接丢弃）
     */
    private static $inlineTags = ['a', 'b', 'strong', 'i', 'em', 'u', 's', 'del', 'ins', 'code', 'kbd', 'var', 'sub', 'sup', 'mark', 'small', 'q', 'cite', 'abbr', 'time'];

    /**
     * 从 HTML 中提取段落级文本块，并保留行内标签
     * 行内标签被换成 <a1>…</a1> 形式的占位符，翻译后再用 restoreInlineTags() 还原，
     * 这样译文仍能保留链接、粗体、代码等格式
     * @param string $html 原始 HTML
     * @return array 每项为 ['text' => 含占位符的文本, 'tags' => [id => ['open' => .., 'close' => ..]]]
     */
    public static function extractRichBlocks($html) {
        $html = (string)$html;

        // 去掉脚本与样式内容
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);

        // 块级标签边界与 <br> 转成换行符
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</(p|div|li|ul|ol|dl|dt|dd|h[1-6]|blockquote|tr|td|th|section|article|figure|figcaption|pre|table)\s*>#i', "\n", $html);
        $html = preg_replace('#<(p|div|li|ul|ol|dl|dt|dd|h[1-6]|blockquote|tr|td|th|section|article|figure|figcaption|pre|table)\b[^>]*>#i', "\n", $html);
        $html = str_replace(["\r\n", "\r"], "\n", $html);

        $blocks = [];
        $previousText = null;
        foreach (explode("\n", $html) as $line) {
            $block = self::protectInlineTags($line);
            $block['text'] = preg_replace('/\s+/u', ' ', $block['text']);
            $block['text'] = trim($block['text']);
            if ($block['text'] === '' || mb_strlen($block['text']) < 2) {
                continue;
            }
            if ($previousText !== null && $previousText === $block['text']) {
                continue;
            }
            $previousText = $block['text'];
            $blocks[] = $block;
        }

        return $blocks;
    }

    /**
     * 把一段 HTML 中的行内标签换成占位符，返回纯文本与标签映射
     * @param string $html 单块 HTML
     * @return array ['text' => string, 'tags' => array]
     */
    private static function protectInlineTags($html) {
        $tags = [];
        $nextId = 0;

        $text = preg_replace_callback(
            '#<(/?)([a-zA-Z][a-zA-Z0-9]*)((?:\s[^>]*)?)\s*/?>#',
            function ($match) use (&$tags, &$nextId) {
                $isClosing = $match[1] === '/';
                $name = strtolower($match[2]);
                if (!in_array($name, self::$inlineTags, true)) {
                    return '';
                }

                if ($isClosing) {
                    // 找最近的同名未闭合标签
                    for ($id = $nextId; $id >= 1; $id--) {
                        if (isset($tags[$id]) && $tags[$id]['name'] === $name && !$tags[$id]['closed']) {
                            $tags[$id]['closed'] = true;
                            return '</a' . $id . '>';
                        }
                    }
                    return '';
                }

                $nextId++;
                $tags[$nextId] = [
                    'name' => $name,
                    'open' => $match[0],
                    'close' => '</' . $name . '>',
                    'closed' => false,
                ];
                return '<a' . $nextId . '>';
            },
            (string)$html
        );

        // 未闭合的标签也补上关闭标签，保证还原时成对
        foreach ($tags as $id => $tag) {
            if (!$tag['closed']) {
                $text .= '</a' . $id . '>';
                $tags[$id]['closed'] = true;
            }
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return ['text' => $text, 'tags' => $tags];
    }

    /**
     * 把译文中的占位符还原成真实 HTML 标签
     * 译文其余部分会被转义；占位符缺失或不成对时丢弃该标签，避免产生残缺 HTML
     * @param string $text 译文（含 <a1> 占位符）
     * @param array $tags extractRichBlocks() 返回的标签映射
     * @return string 可插入页面的 HTML
     */
    public static function restoreInlineTags($text, array $tags) {
        $escaped = self::escapeHtml((string)$text);
        if (empty($tags)) {
            return $escaped;
        }

        // 先把占位符换成临时标记，便于统计开闭是否成对
        $pairs = [];
        foreach ($tags as $id => $tag) {
            $openToken = "\x01O{$id}\x02";
            $closeToken = "\x01C{$id}\x02";
            $escaped = str_replace(
                ['&lt;a' . $id . '&gt;', '&lt;/a' . $id . '&gt;'],
                [$openToken, $closeToken],
                $escaped
            );
            $pairs[$id] = ['open' => $openToken, 'close' => $closeToken, 'tag' => $tag];
        }

        foreach ($pairs as $id => $pair) {
            $openCount = substr_count($escaped, $pair['open']);
            $closeCount = substr_count($escaped, $pair['close']);
            if ($openCount === 0 && $closeCount === 0) {
                continue;
            }
            if ($openCount !== $closeCount || $openCount > 1) {
                // 不成对或重复：丢弃该标签，只留文字
                $escaped = str_replace([$pair['open'], $pair['close']], '', $escaped);
                continue;
            }
            $escaped = str_replace([$pair['open'], $pair['close']], [$pair['tag']['open'], $pair['tag']['close']], $escaped);
        }

        // 清掉模型遗留的占位符残骸
        return preg_replace('/&lt;\/?a\d+&gt;/', '', $escaped);
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
     * 在 splitChunks() 基础上保证每个块内的占位符成对，避免标签被切到两个块
     * @param string $text 含占位符的文本
     * @param int $limit 单块最大字符数
     * @return array 文本块列表
     */
    public static function splitChunksBalanced($text, $limit) {
        $chunks = self::splitChunks($text, $limit);
        if (count($chunks) <= 1) {
            return $chunks;
        }

        $balanced = [];
        $buffer = '';
        foreach ($chunks as $chunk) {
            $buffer = $buffer === '' ? $chunk : $buffer . ' ' . $chunk;
            if (self::hasBalancedPlaceholders($buffer)) {
                $balanced[] = $buffer;
                $buffer = '';
            }
        }
        if ($buffer !== '') {
            $balanced[] = $buffer;
        }
        return $balanced;
    }

    /**
     * 判断文本中的 <aN> 占位符是否成对出现
     */
    private static function hasBalancedPlaceholders($text) {
        if (!preg_match_all('#</?a(\d+)>#', (string)$text, $matches)) {
            return true;
        }
        $counts = array_count_values($matches[1]);
        foreach ($counts as $count) {
            if ($count % 2 !== 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * 安全转义HTML
     */
    public static function escapeHtml($text) {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
