<?php
namespace GT\WebEngine\Test;

use GT\Dom\HTMLDocument;
use GT\DomTemplate\ComponentExpander;
use GT\DomTemplate\PartialContent;
use GT\DomTemplate\PartialContentDirectoryNotFoundException;
use GT\DomTemplate\PartialExpander;
use Gt\Http\Request;
use Gt\Http\Stream;
use Gt\Http\Uri;
use GT\WebEngine\Logic\HTMLDocumentProcessor;
use GT\Routing\RouterConfig;
use GT\WebEngine\AmbiguousPathException;
use GT\WebEngine\DefaultRouter;
use Gt\ServiceContainer\Container;
use GT\WebEngine\View\HeaderFooterPartialConflictException;
use GT\WebEngine\View\HTMLView;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . "/router.default.php";

class DefaultRouterTest extends TestCase {
	private string $tmpDir;
	private string $cwd;

	protected function setUp():void {
		parent::setUp();
		$this->cwd = getcwd();
		$this->tmpDir = sys_get_temp_dir() . "/phpgt-webengine-test--DefaultRouter-" . uniqid();
		mkdir($this->tmpDir . "/page/admin", recursive: true);
	}

	protected function tearDown():void {
		chdir($this->cwd);
		$this->removeDirectory($this->tmpDir);
		parent::tearDown();
	}

	public function testRoute_pageRequest_includesHeadersAndFootersInNestedOrder():void {
		file_put_contents($this->tmpDir . "/page/_header.html", "<html><body><header>site</header>");
		file_put_contents($this->tmpDir . "/page/admin/_header.html", "<nav>admin</nav>");
		file_put_contents($this->tmpDir . "/page/admin/users.html", "<main>users</main>");
		file_put_contents($this->tmpDir . "/page/admin/_footer.html", "<footer>admin</footer>");
		file_put_contents($this->tmpDir . "/page/_footer.html", "<footer>site</footer></body></html>");

		chdir($this->tmpDir);

		$request = self::createMock(Request::class);
		$request->method("getMethod")->willReturn("GET");
		$request->method("getHeaderLine")
			->with("accept")
			->willReturn("text/html");
		$request->method("getUri")->willReturn(new Uri("https://example.test/admin/users"));

		$sut = new DefaultRouter(new RouterConfig(307, "text/html"));
		$container = new Container();
		$container->set($request);
		$sut->setContainer($container);
		$sut->route($request);

		self::assertSame(
			[
				"page/_header.html",
				"page/admin/_header.html",
				"page/admin/users.html",
				"page/admin/_footer.html",
				"page/_footer.html",
			],
			iterator_to_array($sut->getViewAssembly()),
		);
	}

	public function testRoute_pageRequest_withHeadersFootersAndPartials_throwsLogicException():void {
		class_exists(HTMLDocument::class);
		class_exists(ComponentExpander::class);
		class_exists(PartialContent::class);
		class_exists(PartialContentDirectoryNotFoundException::class);
		class_exists(PartialExpander::class);

		file_put_contents($this->tmpDir . "/page/_header.html", "<html><body><header>site</header>");
		file_put_contents($this->tmpDir . "/page/admin/_header.html", "<nav>admin</nav>");
		file_put_contents($this->tmpDir . "/page/admin/users.html", "<!-- extends=layout --><main>users</main>");
		file_put_contents($this->tmpDir . "/page/admin/_footer.html", "<footer>admin</footer>");
		file_put_contents($this->tmpDir . "/page/_footer.html", "<footer>site</footer></body></html>");
		mkdir($this->tmpDir . "/page/_partial", recursive: true);
		file_put_contents(
			$this->tmpDir . "/page/_partial/layout.html",
			"<!doctype html><html><body><section data-partial></section></body></html>",
		);

		chdir($this->tmpDir);

		$request = self::createMock(Request::class);
		$request->method("getMethod")->willReturn("GET");
		$request->method("getHeaderLine")
			->with("accept")
			->willReturn("text/html");
		$request->method("getUri")->willReturn(new Uri("https://example.test/admin/users"));

		$sut = new DefaultRouter(new RouterConfig(307, "text/html"));
		$container = new Container();
		$container->set($request);
		$sut->setContainer($container);
		$sut->route($request);

		$view = new HTMLView(new Stream());
		foreach($sut->getViewAssembly() as $viewFile) {
			$view->addViewFile($viewFile);
		}
		$viewModel = $view->createViewModel();

		$processor = new HTMLDocumentProcessor("components", "page/_partial");
		$this->expectException(HeaderFooterPartialConflictException::class);
		$this->expectExceptionMessage(
			"Header/footer view files cannot be combined with partial views."
		);
		$processor->processPartialContent($viewModel, $sut->getViewAssembly());
	}

	public function testRoute_pageRequest_withDirectAndIndexView_throwsAmbiguousPathException():void {
		mkdir($this->tmpDir . "/page/contact", recursive: true);
		file_put_contents($this->tmpDir . "/page/contact.html", "<main>contact</main>");
		file_put_contents($this->tmpDir . "/page/contact/index.html", "<main>contact index</main>");

		chdir($this->tmpDir);

		$request = self::createMock(Request::class);
		$request->method("getMethod")->willReturn("GET");
		$request->method("getHeaderLine")
			->with("accept")
			->willReturn("text/html");
		$request->method("getUri")->willReturn(new Uri("https://example.test/contact"));

		$sut = new DefaultRouter(new RouterConfig(307, "text/html"));
		$container = new Container();
		$container->set($request);
		$sut->setContainer($container);

		$this->expectException(AmbiguousPathException::class);
		$this->expectExceptionMessage(
			"Ambiguous route for '/contact': both 'page/contact.html' and "
			. "'page/contact/index.html' match."
		);
		$sut->route($request);
	}

	private function removeDirectory(string $dir):void {
		if(!is_dir($dir)) {
			return;
		}

		foreach(scandir($dir) ?: [] as $file) {
			if($file === "." || $file === "..") {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $file;
			if(is_dir($path)) {
				$this->removeDirectory($path);
				continue;
			}

			unlink($path);
		}

		rmdir($dir);
	}
}
