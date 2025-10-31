<?php
namespace GT\WebEngine\Test\Init;

use GT\WebEngine\Init\ViewModelInit;
use PHPUnit\Framework\TestCase;

class ViewModelInitTest extends TestCase {
	public function testInitHTMLDocument_wiresBindersAndIsIdempotent():void {
		$model = new \Gt\Dom\HTMLDocument('<main></main>');
		$sut = new ViewModelInit($model, '/components', '/partials');

		$documentBinder = self::createMock(\Gt\DomTemplate\DocumentBinder::class);
		$htmlAttributeBinder = self::createMock(\Gt\DomTemplate\HTMLAttributeBinder::class);
		$listBinder = self::createMock(\Gt\DomTemplate\ListBinder::class);
		$tableBinder = self::createMock(\Gt\DomTemplate\TableBinder::class);
		$elementBinder = self::createMock(\Gt\DomTemplate\ElementBinder::class);
		$placeholderBinder = self::createMock(\Gt\DomTemplate\PlaceholderBinder::class);
		$htmlAttributeCollection = self::createMock(\Gt\DomTemplate\HTMLAttributeCollection::class);
		$listElementCollection = self::createMock(\Gt\DomTemplate\ListElementCollection::class);
		$bindableCache = self::createMock(\Gt\DomTemplate\BindableCache::class);

		$htmlAttributeBinder->expects(self::once())
			->method('setDependencies')
			->with($listBinder, $tableBinder);
		$elementBinder->expects(self::once())
			->method('setDependencies')
			->with($htmlAttributeBinder, $htmlAttributeCollection, $placeholderBinder);
		$tableBinder->expects(self::once())
			->method('setDependencies')
			->with($listBinder, $listElementCollection, $elementBinder, $htmlAttributeBinder, $htmlAttributeCollection, $placeholderBinder);
		$listBinder->expects(self::once())
			->method('setDependencies')
			->with($elementBinder, $listElementCollection, $bindableCache, $tableBinder);
		$documentBinder->expects(self::once())
			->method('setDependencies')
			->with($elementBinder, $placeholderBinder, $tableBinder, $listBinder, $listElementCollection, $bindableCache);

		$sut->initHTMLDocument(
			$documentBinder,
			$htmlAttributeBinder,
			$listBinder,
			$tableBinder,
			$elementBinder,
			$placeholderBinder,
			$htmlAttributeCollection,
			$listElementCollection,
			$bindableCache,
		);

		// Call again to ensure idempotency (no further calls should be made)
		$sut->initHTMLDocument(
			$documentBinder,
			$htmlAttributeBinder,
			$listBinder,
			$tableBinder,
			$elementBinder,
			$placeholderBinder,
			$htmlAttributeCollection,
			$listElementCollection,
			$bindableCache,
		);

		self::assertInstanceOf(\GT\WebEngine\Logic\HTMLDocumentProcessor::class, $sut->getViewModelProcessor());
	}
}
