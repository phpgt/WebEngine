<?php
namespace GT\WebEngine\Redirection;

class IniRedirectLoader implements RedirectLoader {
	public function load(string $file, RedirectMap $map):void {
		$fileHandle = fopen($file, 'r');
		if(!$fileHandle) {
			return;
		}

		$currentCode = StatusCodeValidator::DEFAULT_CODE; // default when no sections
		while(($line = fgets($fileHandle)) !== false) {
			$line = trim($line);
			if($this->isSkippableIniLine($line)) {
				continue;
			}
			// Section header?
			if($line !== '' && $line[0] === '[' && substr($line, -1) === ']') {
				$section = trim($line, '[] ');
				$currentCode = StatusCodeValidator::validate($section);
				continue;
			}

			if($keyValue = $this->splitIniKeyValue($line)) {
				[$old, $new] = $keyValue;
				$map->addRule($currentCode, $old, $new);
			}
		}

		fclose($fileHandle);
	}

	private function isSkippableIniLine(string $line):bool {
		$line = trim($line);
		return $line === '' || str_starts_with($line, ';') || str_starts_with($line, '#');
	}

	/** @return array{0:string,1:string}|null */
	private function splitIniKeyValue(string $line):?array {
		$eqPos = strpos($line, '=');
		if($eqPos === false) {
			return null;
		}
		$old = trim(substr($line, 0, $eqPos));
		$new = trim(substr($line, $eqPos + 1));
		if($old === '' || $new === '') {
			return null;
		}
		return [$old, $new];
	}
}
