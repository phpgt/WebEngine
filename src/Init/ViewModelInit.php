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
use GT\WebEngine\Logic\HTMLDocumentProcessor;
use GT\WebEngine\Logic\ViewModelProcessor;

class ViewModelInit {
	private ViewModelProcessor $viewModelProcessor;

	public function __construct(
		HTMLDocument $model,
		string $componentDirectory,
		string $partialDirectory,
	) {
		if($model instanceof HTMLDocument) {
			$this->viewModelProcessor = new HTMLDocumentProcessor(
				$componentDirectory,
				$partialDirectory,
			);
		}
		else {
// TODO: Handle other view model types.
		}
	}

	public function initHTMLDocument(
		DocumentBinder $binder,
		HTMLAttributeBinder $htmlAttributeBinder,
		ListBinder $listBinder,
		TableBinder $tableBinder,
		ElementBinder $elementBinder,
		PlaceholderBinder $placeholderBinder,
		HTMLAttributeCollection $htmlAttributeCollection,
		ListElementCollection $listElementCollection,
		BindableCache $bindableCache,
	):void {
		$htmlAttributeBinder->setDependencies(
			$listBinder,
			$tableBinder,
		);
		$elementBinder->setDependencies(
			$htmlAttributeBinder,
			$htmlAttributeCollection,
			$placeholderBinder,
		);
		$tableBinder->setDependencies(
			$listBinder,
			$listElementCollection,
			$elementBinder,
			$htmlAttributeBinder,
			$htmlAttributeCollection,
			$placeholderBinder,
		);
		$listBinder->setDependencies(
			$elementBinder,
			$listElementCollection,
			$bindableCache,
			$tableBinder,
		);
		$binder->setDependencies(
			$elementBinder,
			$placeholderBinder,
			$tableBinder,
			$listBinder,
			$listElementCollection,
			$bindableCache,
		);
	}

	public function getViewModelProcessor():ViewModelProcessor {
		return $this->viewModelProcessor;
	}
}
