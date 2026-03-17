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
		$str = str_replace(["-", "@"], " ", $str);
		$namespace = "";
		foreach(explode("\\", $str) as $part) {
			$part = ucwords($part);
			$namespace .= "\\";
			$namespace .= str_replace(" ", "", $part);
		}
		$namespace = trim($namespace, "\\");
		$namespace .= "Page";
		return $namespace;
	}
}
