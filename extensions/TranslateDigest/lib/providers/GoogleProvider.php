<?php
require_once(__DIR__ . '/ITranslationProvider.php');

/**
 * Google 翻译提供商（免费接口，无需 API Key）
 * 仅提供翻译；摘要走本地截断兜底
 */
class GoogleProvider implements ITranslationProvider {

    private $baseUrl = 'https://translate.googleapis.com/translate_a/single';

    public function getName() { return 'google'; }

    public function translate($text, $sourceLang = 'auto', $targetLang = 'zh') {
        $queryParams = http_build_query([
            'client' => 'gtx',
            'sl' => $sourceLang,
            'tl' => $targetLang,
            'dt' => 't',
            'q' => $text,
        ]);
        $url = $this->baseUrl . '?' . $queryParams;
        $options = ['http' => ['method' => 'GET', 'timeout' => 5]];
        $context = stream_context_create($options);
        try {
            $result = @file_get_contents($url, false, $context);
            if ($result === false) {
                return '';
            }
            $response = json_decode($result, true);
            return !empty($response[0][0][0]) ? $response[0][0][0] : '';
        } catch (Exception $e) {
            return '';
        }
    }

    public function supportsSummarize() { return true; }

    /**
     * Google 无摘要接口，使用本地截断（尽量在句末断开）
     */
    public function summarize($text, $targetLang = 'zh', $maxChars = 200) {
        $summary = mb_substr($text, 0, $maxChars);
        if (mb_strlen($text) > $maxChars) {
            $lastPeriod = mb_strrpos($summary, '。');
            if ($lastPeriod === false || $lastPeriod <= $maxChars / 2) {
                $lastPeriod = mb_strrpos($summary, '，');
            }
            if ($lastPeriod !== false && $lastPeriod > $maxChars / 2) {
                $summary = mb_substr($summary, 0, $lastPeriod + 1);
            }
        }
        return $summary;
    }

    public function validateKey() {
        return ['ok' => true, 'message' => 'Google 服务无需 API Key'];
    }
}
