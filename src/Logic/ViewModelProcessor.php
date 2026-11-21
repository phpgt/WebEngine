<?php
namespace GT\WebEngine\Logic;

use GT\Dom\HTMLDocument;
use GT\WebEngine\Debug\DebugLogger;
use GT\Routing\Assembly;
use GT\Routing\Path\DynamicPath;

abstract class ViewModelProcessor {
	protected DebugLogger $debugLogger;
	public function __construct(
		protected string $componentDirectory,
		protected string $partialDirectory,
		?DebugLogger $debugLogger = null,
	) {
		$this->debugLogger = $debugLogger ?? new DebugLogger();
	}

	abstract function processDynamicPath(
		HTMLDocument $model,
		DynamicPath $dynamicPath,
	):void;

	abstract function processPartialContent(
		HTMLDocument $model,
		?Assembly $viewAssembly = null,
	):LogicAssemblyComponentList;
}
