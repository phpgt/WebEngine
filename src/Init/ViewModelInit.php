<?php
namespace GT\WebEngine\Init;

use Closure;
use Gt\Dom\Document;
use GT\Dom\HTMLDocument;
use Gt\DomTemplate\BindableCache;
use Gt\DomTemplate\Binder;
use Gt\DomTemplate\DocumentBinder;
use Gt\DomTemplate\ElementBinder;
use Gt\DomTemplate\HTMLAttributeBinder;
use Gt\DomTemplate\HTMLAttributeCollection;
use Gt\DomTemplate\ListBinder;
use Gt\DomTemplate\ListElementCollection;
use Gt\DomTemplate\PlaceholderBinder;
use Gt\DomTemplate\TableBinder;
use Gt\Json\Schema\JsonDocument;
use GT\WebEngine\Logic\HTMLDocumentProcessor;
use GT\WebEngine\Logic\ViewModelProcessor;

class ViewModelInit {
	private ViewModelProcessor $viewModelProcessor;
	private bool $initialised = false;

	public function __construct(
		HTMLDocument|JsonDocument $model,
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
