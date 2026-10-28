<?php

return array(
	'imageproxyopt' => array(
		'proxy_url' => '代理地址前缀',
		'proxy_url_help' => '用于改写图片地址的前缀，原图地址会原样接在后面，例如 https://img.example.com/?url=<url>。',
		'host_mode' => '图源过滤模式',
		'host_mode_off' => '关闭 —— 代理全部图片（与上游行为一致）',
		'host_mode_allow' => '白名单 —— 只代理名单内的图源',
		'host_mode_deny' => '黑名单 —— 除名单内之外全部代理',
		'host_mode_help' => '只代理真正取不到的图源，可以显著减少代理请求数和跨境延迟。',
		'host_list' => '图源域名名单',
		'host_list_help' => '一行一个域名。支持通配：*.ytimg.com。以 # 开头的行是注释。',
		'scheme_http' => '代理 HTTP',
		'scheme_https' => '代理 HTTPS',
		'scheme_default' => '代理协议相对地址（//host/x）',
		'scheme_default_help' => 'auto：沿用当前请求的协议；-：不代理协议相对地址。',
		'scheme_include' => '地址中包含 http*://',
		'url_encode' => '对地址进行编码',
		'warm_enabled' => '抓取时预热代理缓存',
		'warm_enabled_help' => '每篇新文章只发一次批量 POST，而不是每张图一次阻塞请求。需要代理端支持 POST。',
		'warm_url' => '预热接口地址',
		'warm_url_help' => 'POST 一个 JSON：{"access_token": "...", "urls": ["..."]}。留空即关闭。',
		'warm_token' => '共享密钥',
		'warm_token_help' => '预热时作为 access_token 发送，同时用于给每个图片 URL 做 HMAC 签名。留空则两者都不启用，此时只有代理端的域名白名单在防护。',
	),
);
