<?php
namespace GT\WebEngine\Logic;

use Gt\Dom\HTMLDocument;
use Gt\DomTemplate\ComponentExpander;
use Gt\DomTemplate\PartialContent;
use Gt\DomTemplate\PartialContentDirectoryNotFoundException;
use Gt\DomTemplate\PartialExpander;
use Gt\Routing\Assembly;
use Gt\Routing\Path\DynamicPath;

class HTMLDocumentProcessor extends ViewModelProcessor {
	function processDynamicPath(
		HTMLDocument $model,
		DynamicPath $dynamicPath,
	):void {
		$dynamicUri = $dynamicPath->getUrl("page/");
		$dynamicUri = str_replace("/", "--", $dynamicUri);
		$dynamicUri = str_replace("@", "_", $dynamicUri);
		$model->body->classList->add("uri" . $dynamicUri);
		$bodyDirClass = "dir";
		foreach(explode("--", $dynamicUri) as $i => $pathPart) {
			if($i === 0) {
				continue;
			}
			$bodyDirClass .= "--$pathPart";
			$model->body->classList->add($bodyDirClass);
		}
	}

	function processPartialContent(
		HTMLDocument $model,
	):LogicAssemblyComponentList {
		$logicAssemblyComponentList = new LogicAssemblyComponentList();

		try {
// TODO: Handle other model types in sub-functions.
			$partial = new PartialContent(implode(DIRECTORY_SEPARATOR, [
				getcwd(),
				$this->componentDirectory,
			]));
			$componentExpander = new ComponentExpander(
				$model,
				$partial,
			);

			foreach($componentExpander->expand() as $componentElement) {
				$filePath = $this->componentDirectory;
				$filePath .= DIRECTORY_SEPARATOR;
				$filePath .= strtolower($componentElement->tagName);
				$filePath .= ".php";

				if(!is_file($filePath)) {
// TODO: Log that a component has been detected but there's no HTML file to load.
					continue;
				}

				$componentAssembly = new Assembly();
				$componentAssembly->add($filePath);
				$logicAssemblyComponentList->addAssemblyComponent(
					$componentAssembly,
					$componentElement,
				);
			}
		}
		catch(PartialContentDirectoryNotFoundException) {}

		try {
			$partial = new PartialContent(implode(DIRECTORY_SEPARATOR, [
				getcwd(),
				$this->partialDirectory,
			]));

			$partialExpander = new PartialExpander(
				$model,
				$partial,
			);
			$partialExpander->expand();
		}
		catch(PartialContentDirectoryNotFoundException) {}

		return $logicAssemblyComponentList;
	}
}
