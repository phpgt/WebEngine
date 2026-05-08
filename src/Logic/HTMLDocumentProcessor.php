<?php
namespace GT\WebEngine\Logic;

use GT\Dom\HTMLDocument;
use GT\DomTemplate\ComponentExpander;
use GT\DomTemplate\PartialContent;
use GT\DomTemplate\PartialContentDirectoryNotFoundException;
use GT\DomTemplate\PartialExpander;
use GT\Logger\Log;
use GT\Routing\Assembly;
use GT\Routing\Path\DynamicPath;
use GT\WebEngine\View\HeaderFooterPartialConflictException;

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
		?Assembly $viewAssembly = null,
	):LogicAssemblyComponentList {
		if($viewAssembly
		&& $this->containsPartialExtends($model)
		&& $this->containsHeaderOrFooterView($viewAssembly)) {
			throw new HeaderFooterPartialConflictException(
				"Header/footer view files cannot be combined with partial views."
			);
		}

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
					Log::debug(
						"Component detected without matching logic file",
						[
							"component" => strtolower($componentElement->tagName),
							"logic_file" => $filePath,
						],
					);
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

	private function containsHeaderOrFooterView(Assembly $viewAssembly):bool {
		foreach($viewAssembly as $viewFile) {
			$fileName = pathinfo($viewFile, PATHINFO_FILENAME);
			if($fileName === "_header" || $fileName === "_footer") {
				return true;
			}
		}

		return false;
	}

	private function containsPartialExtends(HTMLDocument $model):bool {
		return $this->containsPartialExtendsInNode($model->documentElement);
	}

	/** @return ?array<string, array<string, string>|string> */
	private function parseCommentIni(string $data):?array {
		set_error_handler(
			static fn() => true
		);

		try {
			$parsed = parse_ini_string($data, true);
		}
		finally {
			restore_error_handler();
		}

		return is_array($parsed)
			? $parsed
			: null;
	}

	private function containsPartialExtendsInNode(\DOMNode $node):bool {
		if($node->nodeType === XML_COMMENT_NODE) {
			$parsed = $this->parseCommentIni(trim($node->textContent));
			if(isset($parsed["extends"])) {
				return true;
			}
		}

		foreach($node->childNodes as $childNode) {
			if($this->containsPartialExtendsInNode($childNode)) {
				return true;
			}
		}

		return false;
	}
}
