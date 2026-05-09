<?php
namespace GT\WebEngine\Redirection;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 * Reason: StatusCodeValidator provides a stateless validation utility via static
 * methods. Using static access here avoids unnecessary object plumbing while
 * keeping the loader pure and easy to test.
 */
class IniRedirectLoader implements RedirectLoader {
	public function load(string $file, RedirectMap $map):void {
		$fileHandle = fopen($file, 'r');
		if(!$fileHandle) {
			return;
		}

		$currentCode = StatusCodeValidator::DEFAULT_CODE; // default when no sections
		$lineNumber = 0;
		while(($line = fgets($fileHandle)) !== false) {
			$lineNumber++;
			$line = trim($line);
			if($this->isSkippableIniLine($line)) {
				continue;
			}
			// Section header?
			if($line !== '' && $line[0] === '[' && substr($line, -1) === ']') {
				$section = trim($line, '[] ');
				$statusCodeValidator = new StatusCodeValidator();
				$currentCode = $statusCodeValidator->validate($section);
				continue;
			}

			if($keyValue = $this->splitIniKeyValue($line)) {
				[$old, $new] = $keyValue;
				$map->addRule($currentCode, $old, $new, $this->formatSource($file, $lineNumber));
			}
		}

		fclose($fileHandle);
	}

	private function formatSource(string $file, int $lineNumber):string {
		$cwd = getcwd() ?: "";
		$file = str_replace($cwd, "", $file);
		$file = trim($file, "/");
		return "$file:$lineNumber";
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
