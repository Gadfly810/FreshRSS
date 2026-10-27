<?php
declare(strict_types=1);

/**
 * Controller behind the two buttons of configure.phtml.
 *
 * Both actions are POST-only, authenticated and CSRF-checked by the global
 * `FreshRSS::initAuth()` (app/FreshRSS.php:74-80) before this controller runs.
 * After doing the work they redirect back to the extension configuration page
 * so a page refresh does not repeat the operation.
 */
final class FreshExtension_configsync_Controller extends Minz_ActionController {
	public function firstAction(): void {
		// Cheap guard so that a direct hit on /i/?c=configsync is rejected
		// before the action body runs, not in the middle of it.
		if (!FreshRSS_Auth::hasAccess()) {
			Minz_Error::error(403);
		}
	}

	public function pushAction(): void {
		$this->runSync(true);
	}

	public function pullAction(): void {
		$this->runSync(false);
	}

	private function runSync(bool $push): void {
		if (!Minz_Request::isPost()) {
			Minz_Error::error(405);
		}

		$extension = Minz_ExtensionManager::findExtension('ConfigSync');
		if (!($extension instanceof ConfigSyncExtension)) {
			Minz_Request::bad($this->escape('ConfigSync extension not found or not enabled'), $this->backUrl());
		}

		try {
			if ($push) {
				$extension->forcePush();
			} else {
				$extension->forcePull();
			}
			$message = $extension->getLastMessage();
		} catch (ConfigSyncException $e) {
			// The message may embed server-controlled text (GitHub error bodies,
			// the snapshot's _savedAt, a WebDAV URL). layout.phtml prints the
			// notification verbatim, so escape it here.
			Minz_Request::bad($this->escape($e->getMessage()), $this->backUrl());
			return;
		}

		Minz_Request::good($this->escape($message), $this->backUrl());
	}

	private function escape(string $message): string {
		return (string)Minz_Helper::htmlspecialchars_utf8($message);
	}

	/** @return array<string,mixed> */
	private function backUrl(): array {
		return [
			'c' => 'extension',
			'a' => 'configure',
			'params' => ['e' => 'ConfigSync'],
		];
	}
}
