<?php
namespace GT\WebEngine\Test\Init;

use Gt\Dom\HTMLDocument as GtHTMLDocument;
use Gt\Http\Request;
use Gt\Http\Response;
use Gt\Http\Stream;
use Gt\Http\Uri;
use Gt\Routing\Assembly;
use Gt\Routing\BaseRouter;
use Gt\ServiceContainer\Container;
use GT\WebEngine\Init\RouterInit;
use GT\WebEngine\Dispatch\RouterFactory;
use GT\WebEngine\View\HTMLView;
use GT\WebEngine\View\NullView;
use PHPUnit\Framework\TestCase;

class RouterInitTest extends TestCase {
	public function testConstruct_usesRouterFactoryAndBuildsViewAndAssemblies():void {
		$request = self::createMock(Request::class);
		$request->method('getUri')->willReturn(new Uri('https://example.test/one'));
		$response = self::createMock(Response::class);
		$stream = new Stream();
		$response->method('getBody')->willReturn($stream);
		$container = new Container();

		$viewFile = "/tmp/phpgt-webengine-test--view-file-" . uniqid();
		touch($viewFile);
		$viewAssembly = self::createMock(Assembly::class);
		$viewAssembly->method("valid")
			->willReturnOnConsecutiveCalls(true, false);
		$viewAssembly->method("current")->willReturn($viewFile);
		$logicAssembly = self::createMock(Assembly::class);

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
		self::assertInstanceOf(GtHTMLDocument::class, $sut->getViewModel());
		self::assertSame($viewAssembly, $sut->getViewAssembly());
		self::assertSame($logicAssembly, $sut->getLogicAssembly());
		self::assertNotNull($sut->getDynamicPath());
	}

	public function testConstruct_legacyGtViewClassIsUsedDirectly():void {
		$request = self::createMock(Request::class);
		$request->method('getUri')->willReturn(new Uri('https://example.test/'));
		$response = self::createMock(Response::class);
		$response->method('getBody')->willReturn(new Stream());
		$container = new Container();

		$viewAssembly = self::createMock(Assembly::class);
		$logicAssembly = self::createMock(Assembly::class);

		$baseRouter = self::createMock(BaseRouter::class);
		$baseRouter->method("getViewClass")->willReturn(HTMLView::class);
		$baseRouter->method("getViewAssembly")->willReturn($viewAssembly);
		$baseRouter->method("getLogicAssembly")->willReturn($logicAssembly);

		$routerFactory = self::createMock(RouterFactory::class);
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
