<?php
declare(strict_types=1);

/**
 * System configuration seed, copied to DATA_PATH/config.custom.php by
 * Docker/entrypoint.sh on every cold start (only when absent).
 *
 * Read by cli/do-install.php:100-107 and merged *after* `'salt' =>
 * generateSalt()`, so the salt below wins over the freshly generated one.
 * That is the whole point: FreshRSS_Feed::hash() = sha1($salt . $params), so a
 * new salt on every restart would make feed deduplication unstable.
 *
 * Everything here comes from the environment; no secret is committed.
 */

$salt = getenv('FRESHRSS_SALT');

return [
	// Must stay constant across deployments (architecture.md §4.4 / §7.2).
	'salt' => is_string($salt) && $salt !== '' ? $salt : 'CHANGE-ME-fixed-random-string',

	'base_url' => (string)(getenv('FRESHRSS_BASE_URL') ?: ''),

	'title' => (string)(getenv('FRESHRSS_TITLE') ?: 'FreshRSS'),

	'auth_type' => 'form',
];
