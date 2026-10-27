#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Cold-start configuration restore.
 *
 * This is the only place where a pull happens automatically; the extension
 * itself never does network I/O during init(). Called from
 * Docker/entrypoint.sh right after create-user.php, i.e. before Apache starts,
 * so the very first HTTP request already sees the restored configuration.
 *
 * Design notes (architecture.md §4.3 / §5.5):
 *  - The only reliable "this container is brand new" signal is that DATA_PATH
 *    started empty, hence the sentinel file.
 *  - The sentinel is touched even when the pull fails: the Gist API allows
 *    30 requests per minute and a restart loop must not burn through them.
 *    Recovery is a manual click on "Force pull" in the UI.
 *  - The exit code is non-zero on failure but entrypoint.sh deliberately uses
 *    `||`, so a failure never blocks the container from starting.
 */

require __DIR__ . '/_cli.php';

// 1) Cold start? DATA_PATH is fresh when the sentinel does not exist yet.
$marker = DATA_PATH . '/.configsync-pulled';
if (file_exists($marker)) {
	echo "ℹ️ ConfigSync: already pulled in this container; nothing to do.\n";
	done();
}

// 2) One pull per user. Each user has its own config.php and its own
//    user_conf, so they are restored independently.
$users = FreshRSS_user_Controller::listUsers();
if (count($users) === 0) {
	echo "ℹ️ ConfigSync: no user yet; nothing to do.\n";
	done();
}

$failed = [];
$pulled = 0;
foreach ($users as $username) {
	cliInitUser($username);   // → initUser() → enableByList() → ConfigSync::init()
	$ext = Minz_ExtensionManager::findExtension('ConfigSync');
	if (!($ext instanceof ConfigSyncExtension)) {
		fwrite(STDERR, "ConfigSync: extension is not available for {$username}\n");
		$failed[] = $username;
		continue;
	}
	try {
		$count = $ext->forcePull();
		$pulled++;
		echo "✅ ConfigSync: restored {$count} keys for {$username}.\n";
	} catch (Throwable $e) {
		fwrite(STDERR, "ConfigSync pull failed for {$username}: " . $e->getMessage() . "\n");
		$failed[] = $username;
	}
}

// 3) Touch the sentinel whatever happened, so this container does not retry.
@touch($marker);

if (count($failed) === 0) {
	echo "✅ ConfigSync: restored configuration for {$pulled} user(s).\n";
} else {
	echo '⚠️ ConfigSync: pull failed for ' . implode(', ', $failed) . "; use the extension page to retry.\n";
}

done(count($failed) === 0);
