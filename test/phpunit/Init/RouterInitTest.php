<?php
namespace GT\WebEngine\Test\Init;

use GT\Dom\HTMLDocument as GTHTMLDocument;
use GT\Http\Request;
use GT\Http\Response;
use GT\Http\Stream;
use GT\Http\Uri;
use GT\Routing\Assembly;
use GT\Routing\BaseRouter;
use GT\ServiceContainer\Container;
use GT\WebEngine\Init\RouterInit;
use GT\WebEngine\Dispatch\RouterFactory;
use GT\WebEngine\View\HTMLView;
use GT\WebEngine\View\NullView;
use PHPUnit\Framework\TestCase;

class RouterInitTest extends TestCase {
	public function testConstruct_usesRouterFactoryAndBuildsViewAndAssemblies():void {
			$request = self::createStub(Request::class);
			$request->method('getUri')->willReturn(new Uri('https://example.test/one'));
			$response = self::createStub(Response::class);
		$stream = new Stream();
		$response->method('getBody')->willReturn($stream);
		$container = new Container();

		$viewFile = "/tmp/phpgt-webengine-test--view-file-" . uniqid();
		touch($viewFile);
			$viewAssembly = self::createStub(Assembly::class);
		$viewAssembly->method("valid")
			->willReturnOnConsecutiveCalls(true, false);
		$viewAssembly->method("current")->willReturn($viewFile);
			$logicAssembly = self::createStub(Assembly::class);

		$baseRouter = self::createMock(BaseRouter::class);
		$baseRouter->expects(self::once())->method('route')->with($request);
  		$baseRouter->method('getViewClass')->willReturn(HTMLView::class);
		$baseRouter->method('getViewAssembly')->willReturn($viewAssembly);
		$baseRouter->method('getLogicAssembly')->willReturn($logicAssembly);

		$routerFactory = self::createMock(RouterFactory::class);
		$routerFactory->expects(self::once())
			->method("create")
			->with(
				$container,
				"App\\Ns",
				"/path/app-router.php",
				"AppRouter",
				"/path/default-router.php",
				"\\Default\\Router",
				307,
				"text/html",
				123,
			)
			->willReturn($baseRouter);

		$sut = new RouterInit(
			$request,
			$response,
			$container,
			"App\\Ns",
			"/path/app-router.php",
			"AppRouter",
			"/path/default-router.php",
			"\\Default\\Router",
			307,
			"text/html",
			123,
			$routerFactory,
		);

		self::assertSame($baseRouter, $sut->getBaseRouter());
		self::assertInstanceOf(HTMLView::class, $sut->getView());
		self::assertInstanceOf(GTHTMLDocument::class, $sut->getViewModel());
		self::assertSame($viewAssembly, $sut->getViewAssembly());
		self::assertSame($logicAssembly, $sut->getLogicAssembly());
		self::assertNotNull($sut->getDynamicPath());
	}

	public function testConstruct_legacyViewClassIsUsedDirectly():void {
			$request = self::createStub(Request::class);
			$request->method('getUri')->willReturn(new Uri('https://example.test/'));
			$response = self::createStub(Response::class);
		$response->method('getBody')->willReturn(new Stream());
		$container = new Container();

			$viewAssembly = self::createStub(Assembly::class);
			$logicAssembly = self::createStub(Assembly::class);

			$baseRouter = self::createStub(BaseRouter::class);
		$baseRouter->method("getViewClass")->willReturn(HTMLView::class);
		$baseRouter->method("getViewAssembly")->willReturn($viewAssembly);
		$baseRouter->method("getLogicAssembly")->willReturn($logicAssembly);

			$routerFactory = self::createStub(RouterFactory::class);
		$routerFactory->method('create')->willReturn($baseRouter);

		$sut = new RouterInit(
			$request,
			$response,
			$container,
			'Ns',
			'file.php', 'Cls', 'def.php', '\\Def', 307, 'text/html', null, $routerFactory
		);

		// The RouterInit should not remap namespaces; the exact class should be used
		self::assertInstanceOf(HTMLView::class, $sut->getView());
	}
}
