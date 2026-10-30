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
     * 非内容元素：其中的文本不参与翻译（对齐 kiss-translator 的 NON_CONTENT_SELECTOR）
     * 只影响文本提取，原始 HTML 仍会完整保留
     */
    private static $nonContentTags = 'script|style|noscript|template|svg|canvas|iframe|object|video|audio|select|textarea|button';

    /**
     * 从 HTML 中提取段落级文本块，并保留行内标签
     * 行内标签被换成 <a1>…</a1> 形式的占位符，翻译后再用 restoreInlineTags() 还原，
     * 这样译文仍能保留链接、粗体、代码等格式
     * @param string $html 原始 HTML
     * @return array 每项为 ['text' => 含占位符的文本, 'tags' => [id => ['open' => .., 'close' => ..]]]
     */
    public static function extractRichBlocks($html) {
        $html = (string)$html;

        // 去掉脚本、样式等非内容元素的整块内容
        $html = preg_replace('#<(' . self::$nonContentTags . ')\b[^>]*>.*?</\1>#is', ' ', $html);
        $html = preg_replace('#<(script|style)\b[^>]*/>#is', ' ', $html);

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
     * 顶层块级标签集合（用于把正文切成可独立对照的片段）
     */
    private static $blockTags = ['p', 'div', 'li', 'ul', 'ol', 'dl', 'dt', 'dd', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'tr', 'td', 'th', 'section', 'article', 'figure', 'figcaption', 'pre', 'table', 'hr', 'header', 'footer', 'main', 'aside', 'nav', 'details', 'summary'];

    /**
     * 块级边界的匹配式：优先匹配白名单标签（常见情况，速度快），
     * 其次匹配带 style="display:block" 这类显式块级提示的任意标签
     */
    private static function blockBoundaryPattern() {
        static $pattern = null;
        if ($pattern === null) {
            $pattern = '#<(/?)(?:(' . implode('|', self::$blockTags) . ')\b|([a-zA-Z][a-zA-Z0-9]*)\b[^>]*style\s*=\s*["\'][^"\']*display\s*:\s*(?:block|flex|grid|list-item|table)\b[^"\']*["\'])[^>]*>#i';
        }
        return $pattern;
    }

    /** 递归下降的最大层数，防止异常嵌套造成无限递归 */
    private const MAX_SPLIT_DEPTH = 8;

    /**
     * 把 HTML 按顶层块级标签切成片段，便于在每段原文后插入对应译文
     * 片段保留原始 HTML（含图片等非文本内容），未被块级标签包裹的零散内容原样保留
     * @param string $html 原始 HTML
     * @return array 原始 HTML 片段列表（按文档顺序）
     */
    public static function splitTopLevelBlocks($html) {
        $html = (string)$html;
        if (trim($html) === '') {
            return [];
        }
        if (!preg_match_all(self::blockBoundaryPattern(), $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [$html];
        }

        $voidTags = ['hr' => true];
        $segments = [];
        $depth = 0;
        $start = 0;
        $length = strlen($html);

        foreach ($matches as $match) {
            $rawTag = $match[0][0];
            $offset = $match[0][1];
            $closing = $match[1][0] === '/';
            $name = strtolower(isset($match[2]) && $match[2][0] !== '' ? $match[2][0] : $match[3][0]);
            $selfClosing = isset($voidTags[$name]) || substr(rtrim($rawTag), -2) === '/>';

            if (!$closing) {
                if ($depth === 0) {
                    // 块级标签前的零散行内内容单独成段，避免被丢掉
                    if ($offset > $start) {
                        $segments[] = substr($html, $start, $offset - $start);
                    }
                    $start = $offset;
                }
                if (!$selfClosing) {
                    $depth++;
                }
            } else {
                $depth = max(0, $depth - 1);
            }

            if ($depth === 0) {
                $end = $offset + strlen($rawTag);
                $segments[] = substr($html, $start, $end - $start);
                $start = $end;
            }
        }

        if ($start < $length) {
            $tail = substr($html, $start);
            if (trim($tail) === '' && !empty($segments)) {
                $segments[count($segments) - 1] .= $tail;
            } else {
                $segments[] = $tail;
            }
        }

        return $segments;
    }

    /**
     * 把正文拆成可逐段对照的叶子片段，同时返回每段的文本块（一次遍历，不重复提取）
     * 一直下降到“容器内不再有多个顶层块”为止，因此 <div> 包住多段、
     * 自定义容器 <x-article> 包住多段、<figure> 内含 figcaption 等都能逐段对照
     * @param string $html 原始 HTML
     * @return array 每项为 ['html' => 原始片段, 'blocks' => extractRichBlocks() 结果]
     */
    public static function splitForBilingual($html) {
        $leaves = [];
        self::collectLeaves((string)$html, 0, $leaves);
        return $leaves;
    }

    private static function collectLeaves($html, $depth, array &$leaves) {
        if (trim($html) === '') {
            return;
        }

        $segments = self::splitTopLevelBlocks($html);
        if (empty($segments)) {
            $segments = [$html];
        }

        foreach ($segments as $segment) {
            if (trim($segment) === '') {
                $leaves[] = ['html' => $segment, 'blocks' => []];
                continue;
            }
            $children = $depth < self::MAX_SPLIT_DEPTH ? self::splitContainerChildren($segment) : null;
            if ($children === null) {
                $leaves[] = ['html' => $segment, 'blocks' => self::extractRichBlocks($segment)];
                continue;
            }
            foreach ($children as $child) {
                self::collectLeaves($child, $depth + 1, $leaves);
            }
        }
    }

    /**
     * 把一个容器元素拆成「开始标签 + 直接子块 + 结束标签」
     * 拆不开（不是单元素包裹、或子块只有 1 个）时返回 null
     */
    private static function splitContainerChildren($segment) {
        if (!preg_match('#^\s*<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>#', $segment, $match)) {
            return null;
        }
        $openTag = $match[0];
        $closeTag = '</' . strtolower($match[1]) . '>';
        $innerStart = strlen($openTag);
        $innerEnd = strrpos($segment, $closeTag);
        if ($innerEnd === false || $innerEnd < $innerStart) {
            return null;
        }
        $inner = substr($segment, $innerStart, $innerEnd - $innerStart);
        $suffix = substr($segment, $innerEnd);
        $children = self::splitTopLevelBlocks($inner);
        if (count($children) <= 1) {
            return null;
        }
        array_unshift($children, $openTag);
        $children[] = $suffix;
        return $children;
    }

    /**
     * 把译文插到片段的块级元素内部末尾（对齐 kiss-translator 的插入位置，
     * 译文与原文同属一个段落块，不会在两者之间多出一个段间距）
     * 片段不是完整元素时直接追加在后面
     * @param string $segment 原文片段
     * @param string $translationHtml 译文 HTML
     * @return string
     */
    public static function insertTranslationInside($segment, $translationHtml) {
        if ($translationHtml === '') {
            return $segment;
        }
        if (!preg_match('#^\s*<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>#', $segment, $match)) {
            return $segment . $translationHtml;
        }
        $closeTag = '</' . strtolower($match[1]) . '>';
        $position = strrpos($segment, $closeTag);
        if ($position === false) {
            return $segment . $translationHtml;
        }
        return substr($segment, 0, $position) . $translationHtml . substr($segment, $position);
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
        $text = self::decodeNewlines((string)$text);
        $escaped = self::escapeHtml($text);
        // 模型常用 <br> 代替换行，转义后把它们恢复成真实换行，最后统一渲染为 <br>
        $escaped = preg_replace('#&lt;br\s*/?&gt;#i', "\n", $escaped);

        if (!empty($tags)) {
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
            $escaped = preg_replace('/&lt;\/?a\d+&gt;/', '', $escaped);
        }

        // 换行统一渲染为 <br>（<p> 内换行否则会变成空白）
        return str_replace("\n", '<br>', $escaped);
    }

    /**
     * 换行保护：规范化换行并转成 <br>，避免发送时被模型当成空白丢掉
     * @param string $text 文本
     * @return string
     */
    public static function encodeNewlines($text) {
        $text = str_replace(["\r\n", "\r"], "\n", (string)$text);
        return str_replace("\n", '<br>', $text);
    }

    /**
     * 换行保护：把 <br> 及其变体还原成换行符
     * @param string $text 文本
     * @return string
     */
    public static function decodeNewlines($text) {
        return preg_replace('#<br\s*/?>#i', "\n", (string)$text);
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
