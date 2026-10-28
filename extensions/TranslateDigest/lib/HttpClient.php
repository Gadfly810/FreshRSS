<?php
require_once(__DIR__ . '/Logger.php');

/**
 * 极简 HTTP 客户端
 *
 * FreshRSS 在 lib/lib_rss.php 的 unregister_unsafe_protocols() 里注销了除
 * file / php 之外的所有 stream wrapper，因此插件里直接用
 * file_get_contents('https://...') 会走到本地文件分支，报
 * "Failed to open stream: No such file or directory"，看起来像网络失败。
 *
 * 这里优先使用 cURL（不受 wrapper 注销影响）；只有在没有 cURL 扩展时才回退
 * 到 stream，并在请求期间临时恢复 http/https wrapper。
 */
class HttpClient {

    const DEFAULT_TIMEOUT = 20;

    /** 默认 User-Agent，避免部分服务直接拒绝空 UA */
    const USER_AGENT = 'FreshRSS-TranslateDigest/1.0';

    /**
     * 发送 POST 请求
     * @param string $url 请求地址
     * @param array $headers 请求头（"Name: value" 字符串数组）
     * @param string $body 请求体
     * @param int $timeout 超时（秒）
     * @return array{ok:bool,status:int,body:string,error:string}
     */
    public static function post($url, array $headers, $body, $timeout = self::DEFAULT_TIMEOUT) {
        return self::request('POST', $url, $headers, $body, $timeout);
    }

    /**
     * 发送 GET 请求
     * @param string $url 请求地址
     * @param array $headers 请求头（"Name: value" 字符串数组）
     * @param int $timeout 超时（秒）
     * @return array{ok:bool,status:int,body:string,error:string}
     */
    public static function get($url, array $headers = [], $timeout = self::DEFAULT_TIMEOUT) {
        return self::request('GET', $url, $headers, '', $timeout);
    }

    /**
     * 执行请求，返回统一结构；ok 为 false 时 error 里是失败原因
     */
    private static function request($method, $url, array $headers, $body, $timeout) {
        $timeout = (int)$timeout > 0 ? (int)$timeout : self::DEFAULT_TIMEOUT;

        if (function_exists('curl_init')) {
            return self::curlRequest($method, $url, $headers, $body, $timeout);
        }
        return self::streamRequest($method, $url, $headers, $body, $timeout);
    }

    /**
     * cURL 实现
     */
    private static function curlRequest($method, $url, array $headers, $body, $timeout) {
        $ch = curl_init();
        if ($ch === false) {
            return self::failure('curl_init failed');
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => array_merge($headers, ['Expect:']),
            CURLOPT_USERAGENT => self::USER_AGENT,
            // 让服务端返回压缩内容，cURL 自动解压
            CURLOPT_ENCODING => '',
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            return self::failure($error !== '' ? $error : 'curl request failed');
        }

        return ['ok' => true, 'status' => $status, 'body' => (string)$result, 'error' => ''];
    }

    /**
     * stream 回退实现
     * 请求期间临时恢复 http/https wrapper，结束后再注销回去
     */
    private static function streamRequest($method, $url, array $headers, $body, $timeout) {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        $restored = [];
        if ($scheme === 'http' || $scheme === 'https') {
            if (!in_array($scheme, stream_get_wrappers(), true)) {
                if (!@stream_wrapper_restore($scheme)) {
                    return self::failure('the ' . $scheme . ' stream wrapper is not available in this PHP build');
                }
                $restored[] = $scheme;
            }
        }

        $options = [
            'http' => [
                'method' => $method,
                'header' => array_merge($headers, ['User-Agent: ' . self::USER_AGENT]),
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 3,
            ],
        ];
        if ($method === 'POST') {
            $options['http']['content'] = $body;
        }
        $ctx = stream_context_create($options);

        $status = 0;
        $error = '';
        try {
            error_clear_last();
            $result = @file_get_contents($url, false, $ctx);
            if ($result === false) {
                $last = error_get_last();
                $error = $last['message'] ?? 'stream request failed';
            }
            $responseHeaders = function_exists('http_get_last_response_headers')
                ? (http_get_last_response_headers() ?? [])
                : ($http_response_header ?? []);
            foreach ($responseHeaders as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                    $status = (int)$m[1];
                }
            }
        } finally {
            foreach ($restored as $protocol) {
                @stream_wrapper_unregister($protocol);
            }
        }

        if ($result === false) {
            return self::failure($error);
        }

        return ['ok' => true, 'status' => $status, 'body' => (string)$result, 'error' => ''];
    }

    /**
     * 构造失败结果
     */
    private static function failure($message) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => (string)$message];
    }
}
