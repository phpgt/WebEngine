<?php
namespace GT\WebEngine\Init;

use GT\Dom\HTMLDocument;
use GT\DomTemplate\BindableCache;
use GT\DomTemplate\DocumentBinder;
use GT\DomTemplate\ElementBinder;
use GT\DomTemplate\HTMLAttributeBinder;
use GT\DomTemplate\HTMLAttributeCollection;
use GT\DomTemplate\ListBinder;
use GT\DomTemplate\ListElementCollection;
use GT\DomTemplate\PlaceholderBinder;
use GT\DomTemplate\TableBinder;
use GT\Json\Schema\JSONDocument;
use GT\WebEngine\Logic\HTMLDocumentProcessor;
use GT\WebEngine\Logic\ViewModelProcessor;

class ViewModelInit {
	private ViewModelProcessor $viewModelProcessor;
	private bool $initialised = false;

	public function __construct(
		HTMLDocument|JSONDocument $model,
		string $componentDirectory,
		string $partialDirectory,
	) {
		if($model instanceof HTMLDocument) {
			$this->viewModelProcessor = new HTMLDocumentProcessor(
				$componentDirectory,
				$partialDirectory,
			);
		}
// TODO: Handle other view model types.
	}

	public function initHTMLDocument(
		DocumentBinder $documentBinder,
		HTMLAttributeBinder $htmlAttributeBinder,
		ListBinder $listBinder,
		TableBinder $tableBinder,
		ElementBinder $elementBinder,
		PlaceholderBinder $placeholderBinder,
		HTMLAttributeCollection $attrCollection,
		ListElementCollection $elementCollection,
		BindableCache $bindableCache,
	):void {
		if($this->initialised) {
			return;
		}

		$this->initialised = true;

		$htmlAttributeBinder->setDependencies(
			$listBinder,
			$tableBinder,
		);
		$elementBinder->setDependencies(
			$htmlAttributeBinder,
			$attrCollection,
			$placeholderBinder,
		);
		$tableBinder->setDependencies(
			$listBinder,
			$elementCollection,
			$elementBinder,
			$htmlAttributeBinder,
			$attrCollection,
			$placeholderBinder,
		);
		$listBinder->setDependencies(
			$elementBinder,
			$elementCollection,
			$bindableCache,
			$tableBinder,
		);
		$documentBinder->setDependencies(
			$elementBinder,
			$placeholderBinder,
			$tableBinder,
			$listBinder,
			$elementCollection,
			$bindableCache,
		);
	}

	public function getViewModelProcessor():?ViewModelProcessor {
		return $this->viewModelProcessor ?? null;
	}
}
