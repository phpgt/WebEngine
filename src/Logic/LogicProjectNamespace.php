<?php
namespace GT\WebEngine\Logic;

use Stringable;

class LogicProjectNamespace implements Stringable {
	public function __construct(
		private string $path,
		private string $namespacePrefix
	) {
	}

	public function __toString():string {
		$str = str_replace("/", "\\", $this->path);
		$str = $this->namespacePrefix . "\\" . $str;
		$str = strtok($str, ".");
		$namespace = "";
		foreach(explode("\\", $str) as $part) {
			$namespace .= "\\";
			$namespace .= $this->pathPartToClassPart($part);
		}
		$namespace = trim($namespace, "\\");
		$namespace .= "Page";
		return $namespace;
	}

	private function pathPartToClassPart(string $part):string {
		$dynamicPrefix = "";
		while(str_starts_with($part, "@")) {
			$dynamicPrefix .= "_";
			$part = substr($part, 1);
		}

		$part = str_replace("-", " ", $part);
		$part = ucwords($part);
		$part = str_replace(" ", "", $part);
		return $dynamicPrefix . $part;
	}
}
