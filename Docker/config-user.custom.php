<?php
declare(strict_types=1);

/**
 * User configuration seed, copied to DATA_PATH/config-user.custom.php by
 * Docker/entrypoint.sh on every cold start (only when absent).
 *
 * Read by FreshRSS_user_Controller::createUser() (app/Controllers/
 * userController.php:353-364) and merged into every new user's config.php.
 *
 * Two things only:
 *  1. enable ConfigSync, so that cli/configsync-pull.php finds an enabled
 *     extension to call;
 *  2. give it its storage credentials and passphrase.
 *
 * Nothing else belongs here — not even the enable state of other extensions.
 * TranslateDigest's keys (DeepSeekApiKey, TranslateTitles, TranslateTargetLang,
 * extensions_enabled['TranslateDigest'], ...) are all restored from the
 * encrypted snapshot. That is what breaks the circular dependency: the
 * environment only has to keep ConfigSync itself alive.
 *
 * The ConfigSync* keys are in ConfigSyncExtension::PROTECTED_KEYS, so a pull
 * never overwrites them with values coming from another machine.
 *
 * No secret is committed: every value is read from the environment
 * (architecture.md §6).
 */

$getenv = static function (string $name): string {
	$value = getenv($name);
	return is_string($value) ? $value : '';
};

return [
	// The only extension enabled before the first pull.
	'extensions_enabled' => ['ConfigSync' => true],

	// Storage backend. Must not live in config.php alone: a cold start would
	// wipe it and the sync feature would be unable to restore itself.
	'ConfigSyncBackend' => $getenv('SYNC_BACKEND') !== '' ? $getenv('SYNC_BACKEND') : 'gist',
	'ConfigSyncGistToken' => $getenv('SYNC_GIST_TOKEN'),
	'ConfigSyncGistId' => $getenv('SYNC_GIST_ID'),
	'ConfigSyncWebDavUrl' => $getenv('SYNC_WEBDAV_URL'),
	'ConfigSyncWebDavUser' => $getenv('SYNC_WEBDAV_USER'),
	'ConfigSyncWebDavPass' => $getenv('SYNC_WEBDAV_PASS'),

	// The one and only source of the encryption passphrase. Losing it means the
	// snapshot can never be decrypted again (architecture.md §5.3).
	'ConfigSyncPassphrase' => $getenv('SYNC_PASSPHRASE'),
];
