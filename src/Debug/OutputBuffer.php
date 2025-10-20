<?php
namespace Gt\WebEngine\Debug;

use Closure;

/**
 * The purpose of the debug output buffer is to capture any output that code
 * makes - for example, var_dump or echoing strings is useful for debugging
 * purposes but should never be visible to the page.
 *
 * If any code does output content, it will be captured by the output buffer and
 * either logged or sent to the browser's developer console.
 */
class OutputBuffer {
	private Closure $obStartHandler;
	private Closure $obGetCleanHandler;

	public function __construct(
		?Closure $obStartHandler = null,
		?Closure $obGetCleanHandler = null,
	) {
		$this->obStartHandler = $obStartHandler ?? fn() => ob_start();
		$this->obGetCleanHandler = $obGetCleanHandler ?? fn() => ob_get_clean();
	}

	public function start():void {
		($this->obStartHandler)();
	}

	public function getBuffer():string {
		$contents = ($this->obGetCleanHandler)();
		// ob_get_clean can return false; normalise to empty string.
		return is_string($contents) ? $contents : "";
	}

	public function debugOutput():void {
		if($buffer = $this->getBuffer()) {
			// TODO: Properly log to the console, or to the browser, depending on config.
			var_dump($buffer);
			echo("<<< OUTPUT BUFFER");
		}
	}
}
