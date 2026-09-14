<?php
namespace GT\WebEngine\Debug;

use GT\Config\Config;
use GT\Http\ResponseStatusException\ResponseStatusException;
use Throwable;
use WeakMap;

/** Optional SDK bridge. Reporting must never prevent an application response. */
class SentryReporter {
	private bool $enabled = false;
	/** @var WeakMap<Throwable, bool> */
	private WeakMap $reported;

	public function __construct(Config $config) {
		$this->reported = new WeakMap();
		$dsn = trim($config->getString("sentry.dsn") ?? "");
		if(!$dsn || !function_exists("Sentry\\init") || !function_exists("Sentry\\captureException")) {
			return;
		}

		try {
			\Sentry\init(["dsn" => $dsn]);
			$this->enabled = true;
		}
		catch(Throwable) {
			error_log("WebEngine: Sentry initialization failed.");
		}
	}

	public function report(Throwable $throwable):void {
		if(!$this->enabled || isset($this->reported[$throwable]) || !function_exists("Sentry\\captureException")) {
			return;
		}
		if($throwable instanceof ResponseStatusException && $throwable->getHttpCode() < 500) {
			return;
		}

		$this->reported[$throwable] = true;
		try {
			\Sentry\captureException($throwable);
		}
		catch(Throwable) {
			error_log("WebEngine: Sentry exception reporting failed.");
		}
	}
}
