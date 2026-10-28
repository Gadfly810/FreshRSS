<?php

return array(
	'imageproxyopt' => array(
		'proxy_url' => 'Proxy URL',
		'proxy_url_help' => 'Prefix used to rewrite an image URL. The image URL is appended as-is, e.g. https://img.example.com/?url=<url>.',
		'host_mode' => 'Image host filter',
		'host_mode_off' => 'Off — proxy every image (upstream behaviour)',
		'host_mode_allow' => 'Allow list — proxy only the listed hosts',
		'host_mode_deny' => 'Deny list — proxy everything except the listed hosts',
		'host_mode_help' => 'Proxying only the images you cannot reach keeps proxy requests (and cross-border latency) down.',
		'host_list' => 'Image host list',
		'host_list_help' => 'One host per line. Wildcards: *.ytimg.com. Lines starting with # are comments.',
		'scheme_http' => 'Proxy HTTP',
		'scheme_https' => 'Proxy HTTPS',
		'scheme_default' => 'Proxy protocol-relative URL',
		'scheme_default_help' => 'auto: use the current request scheme; -: leave protocol-relative URLs alone.',
		'scheme_include' => 'Include http*:// in URL',
		'url_encode' => 'Encode the URL',
		'warm_enabled' => 'Pre-warm the proxy cache on feed refresh',
		'warm_enabled_help' => 'Sends one batched POST per new entry instead of one blocking request per image. Requires a proxy endpoint accepting POST.',
		'warm_url' => 'Pre-warm endpoint',
		'warm_url_help' => 'POSTed with a JSON body {"access_token": "...", "urls": ["..."]}. Leave empty to disable.',
		'warm_token' => 'Pre-warm token',
		'warm_token_help' => 'Shared secret sent as access_token. Only sent to the endpoint above.',
	),
);
