<?php
interface ITranslationProvider {
    public function getName();
    public function translate($text, $sourceLang = 'auto', $targetLang = 'zh');
    public function supportsSummarize();
    public function summarize($text, $targetLang = 'zh', $maxChars = 200);
    public function validateKey();

    /**
     * 是否支持一次请求翻译多段文本（批量协议）
     * 返回 false 时 translateBatch() 退化为逐段调用
     */
    public function supportsBatch();

    /**
     * 批量翻译
     * @param array $texts 待翻译文本（下标任意，返回时保持同样的下标）
     * @param string $sourceLang 源语言
     * @param string $targetLang 目标语言
     * @return array 与 $texts 同下标的译文数组；失败项为 ''（由调用方回落原文）
     */
    public function translateBatch(array $texts, $sourceLang = 'auto', $targetLang = 'zh');

    /**
     * 是否遇到了短期内不会自愈的错误（额度耗尽、鉴权失败）
     * 调用方据此在本次运行内熔断该服务，直接走兜底
     */
    public function hasFatalError();

    /**
     * 最近一次业务错误原因，用于写日志说明为什么回落
     * @return string 空串表示没有错误
     */
    public function getLastErrorReason();
}
