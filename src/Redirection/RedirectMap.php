<?php
namespace GT\WebEngine\Redirection;

class RedirectMap {
	/** @var array<int, array<string, string>> */
	private array $literal = [];
	/** @var array<int, array<int, array{pattern:string, replacement:string}>> */
	private array $regex = [];

	public function addRule(int $code, string $old, string $new):void {
		if($old === '' || $new === '') {
			return;
		}
		if(str_starts_with($old, '~')) {
			$this->regex[$code][] = [
				'pattern' => substr($old, 1),
				'replacement' => $new,
			];
		}
		else {
			$this->literal[$code][$old] = $new;
		}
	}

	public function isEmpty():bool {
		return empty($this->literal) && empty($this->regex);
	}

	public function match(string $oldUri):?RedirectUri {
		if($result = $this->matchLiteral($oldUri)) {
			return $result;
		}
		return $this->matchRegex($oldUri);
	}

	private function matchLiteral(string $oldUri):?RedirectUri {
		if(empty($this->literal)) {
			return null;
		}
		foreach($this->literal as $code => $pairs) {
			$newUri = $pairs[$oldUri] ?? null;
			if($newUri !== null) {
				return new RedirectUri($newUri, (int)$code);
			}
		}
		return null;
	}

	private function matchRegex(string $oldUri):?RedirectUri {
		if(empty($this->regex)) {
			return null;
		}
		foreach($this->regex as $code => $rules) {
			foreach($rules as $rule) {
				$pattern = $rule['pattern'];
				$replacement = $rule['replacement'];
				$matchResult = preg_match("~$pattern~", $oldUri);
				if($matchResult === false) {
					throw new RedirectException("Invalid regex pattern in redirect file: $pattern");
				}
				if($matchResult === 1) {
					$newUri = preg_replace("~$pattern~", $replacement, $oldUri, 1);
					if(is_string($newUri) && $newUri !== $oldUri) {
						return new RedirectUri($newUri, (int)$code);
					}
				}
			}
		}
		return null;
	}
}
