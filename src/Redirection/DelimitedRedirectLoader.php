<?php
namespace GT\WebEngine\Redirection;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 * Reason: StatusCodeValidator exposes a simple, stateless validation API via
 * static methods. Injecting an instance would add indirection without benefit;
 * using static access here keeps the loader minimal and side‑effect free.
 */
readonly class DelimitedRedirectLoader implements RedirectLoader {
	public function __construct(private string $delimiter) {}

	public function load(string $file, RedirectMap $map):void {
		$fileHandle = fopen($file, 'r');
		if(!$fileHandle) {
			return;
		}

		while(($row = fgetcsv($fileHandle, 0, $this->delimiter, escape: '')) !== false) {
			if(count($row) < 2) {
				continue;
			}

			$statusCodeValidator = new StatusCodeValidator();
			$old = trim((string)$row[0]);
			$new = trim((string)$row[1]);
			$code = isset($row[2]) ? $statusCodeValidator->validate($row[2]) : StatusCodeValidator::DEFAULT_CODE;
			$map->addRule($code, $old, $new);
		}
		fclose($fileHandle);
	}
}
