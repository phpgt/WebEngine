<?php
namespace GT\WebEngine\Logic;

use Generator;
use GT\Dom\HTMLDocument;
use GT\Routing\Path\DynamicPath;

abstract class ViewModelProcessor {
	public function __construct(
		protected string $componentDirectory,
		protected string $partialDirectory,
	) {}

	abstract function processDynamicPath(
		HTMLDocument $model,
		DynamicPath $dynamicPath,
	):void;

	abstract function processPartialContent(
		HTMLDocument $model,
	):LogicAssemblyComponentList;
}
