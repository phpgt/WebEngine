<?php
namespace Gt\WebEngine\Redirection;

readonly class DelimitedRedirectLoader implements RedirectLoader {
	public function __construct(private string $delimiter) {}

	public function load(string $file, RedirectMap $map):void {
		$fileHandle = fopen($file, 'r');
		if(!$fileHandle) {
			return;
		}

		while(($row = fgetcsv($fileHandle, 0, $this->delimiter, escape: '\\')) !== false) {
			if(count($row) < 2) {
				continue;
			}
			$old = trim((string)$row[0]);
			$new = trim((string)$row[1]);
			$code = isset($row[2]) ? StatusCodeValidator::validate($row[2]) : StatusCodeValidator::DEFAULT_CODE;
			$map->addRule($code, $old, $new);
		}
		fclose($fileHandle);
	}
}
