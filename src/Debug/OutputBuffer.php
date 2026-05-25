<?php
namespace GT\WebEngine\Debug;

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
	private string $buffer = "";
	private bool $bufferOpen = false;

	public function __construct(
		private bool $debugToJavaScript,
		?Closure $obStartHandler = null,
		?Closure $obGetCleanHandler = null,
	) {
		$this->obStartHandler = $obStartHandler ?? fn() => ob_start();
		$this->obGetCleanHandler = $obGetCleanHandler ?? fn() => ob_get_clean();
	}

	private function fillBuffer(string $buffer):string {
		$this->buffer .= $buffer;
		return $buffer;
	}

	public function start():void {
		$this->cleanBuffer();
		($this->obStartHandler)();
		$this->bufferOpen = true;
	}

	public function cleanBuffer():void {
		if(!$this->bufferOpen) {
			return;
		}

		$buffer = ($this->obGetCleanHandler)();
		$this->bufferOpen = false;

		// ob_get_clean can return false; normalise to empty string.
		$this->fillBuffer($buffer ?: "");
	}

	public function debugOutput():?string {
		$this->cleanBuffer();
		if($this->buffer) {
			if($this->debugToJavaScript) {
				$html = <<<HTML
				<script>
				console.group("PHP.GT/WebEngine");
				console.log(`%buffer%`);
				console.groupEnd();
				</script>

				HTML;
				$buffer = trim($this->buffer);
				$buffer = str_replace("`", "\\`", $buffer);
				return str_replace("%buffer%", $buffer, $html);
			}
		}

		return null;
	}
}
