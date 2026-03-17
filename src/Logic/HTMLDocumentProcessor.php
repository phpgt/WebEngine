<?php
namespace GT\WebEngine\Logic;

use GT\Dom\HTMLDocument;
use GT\DomTemplate\ComponentExpander;
use GT\DomTemplate\PartialContent;
use GT\DomTemplate\PartialContentDirectoryNotFoundException;
use GT\DomTemplate\PartialExpander;
use GT\Routing\Assembly;
use GT\Routing\Path\DynamicPath;

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
		$componentList = new LogicAssemblyComponentList();

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
				$componentList->addAssemblyComponent(
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

		return $componentList;
	}
}
