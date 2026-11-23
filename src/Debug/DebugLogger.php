<?php
namespace GT\WebEngine\Debug;

use GT\Config\Config;
use GT\Logger\Log;

/** Opt-in diagnostics; the destination's minimum level still applies. */
class DebugLogger {
	public function __construct(private readonly ?Config $config = null) {}

	/** @param array<string, mixed> $context */
	public function log(string $category, string $message, array $context = []):void {
		if(filter_var($this->config?->getString("logger.debug_" . $category), FILTER_VALIDATE_BOOLEAN)) {
			Log::debug($message, $context);
		}
	}
}
