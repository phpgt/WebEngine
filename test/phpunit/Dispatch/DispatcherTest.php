<?php
namespace GT\WebEngine\Test\Dispatch;

use Exception;
use Gt\Config\Config;
use Gt\Dom\Element;
use Gt\Dom\HTMLDocument;
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
use Gt\Http\Request;
use Gt\Http\Response;
use Gt\Http\ResponseStatusException\ClientError\HttpNotFound;
use Gt\Http\ResponseStatusException\ResponseStatusException;
use Gt\Http\ServerInfo;
use Gt\Http\StatusCode;
use Gt\Http\Stream;
use Gt\Http\Uri;
use Gt\Input\Input;
use Gt\Routing\Assembly;
use Gt\Routing\BaseRouter;
use Gt\Routing\Path\DynamicPath;
use Gt\Session\Session;
use GT\WebEngine\Dispatch\Dispatcher;
use GT\WebEngine\Dispatch\ErrorPageNotFoundException;
use GT\WebEngine\Dispatch\HeaderManager;
use GT\WebEngine\Init\RequestInit;
use GT\WebEngine\Init\RouterInit;
use GT\WebEngine\Init\SessionInit;
use GT\WebEngine\Init\ViewModelInit;
use GT\WebEngine\Logic\AppAutoloader;
use GT\WebEngine\Logic\LogicAssemblyComponentList;
use GT\WebEngine\Logic\LogicExecutor;
use GT\WebEngine\Logic\LogicStreamHandler;
use GT\WebEngine\Logic\ViewModelProcessor;
use GT\WebEngine\View\HTMLView;
use GT\WebEngine\View\ViewStreamer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DispatcherTest extends TestCase {
	public function testGenerateResponse_returnsPreparedRedirectResponse():void {
		$appAutoloader = $this->createMock(AppAutoloader::class);
		$appAutoloader->expects(self::once())
			->method("setup");
		$logicStreamHandler = $this->createMock(LogicStreamHandler::class);
		$logicStreamHandler->expects(self::once())
			->method("setup");

		$finishedResponse = null;
		$sut = new Dispatcher(
			$this->createConfig(),
			$this->createRequest("/redirect-me"),
			$this->createGlobals(),
			function(Response $response)use(&$finishedResponse):void {
				$finishedResponse = $response;
			},
			null,
			$appAutoloader,
			$logicStreamHandler,
		);

		$response = $sut->generateResponse();

		self::assertSame(303, $response->getStatusCode());
		self::assertSame("https://example.test/redirect-me/", $response->getHeaderLine("Location"));
		self::assertSame($response, $finishedResponse);
		self::assertNull($sut->getSessionInit());
	}

	public function testGenerateResponse_throwsNotFoundWhenNoDistinctFilesExist():void {
		$sut = $this->createDispatcher();

		$this->expectException(HttpNotFound::class);

		try {
			$sut->generateResponse();
		}
		finally {
			$response = $this->getPrivateProperty($sut, "response");
			self::assertSame(StatusCode::NOT_FOUND, $response->getStatusCode());
		}
	}

	public function testGenerateResponse_processesResponseAndDefaultsStatusToOk():void {
		$viewModel = new HTMLDocument("<!doctype html><body><main data-bind>Content</main></body>");
		$view = new HTMLView(new Stream());
		$viewAssembly = $this->createAssembly("/tmp/page.html");
		$logicAssembly = new Assembly();

		$processor = $this->createMock(ViewModelProcessor::class);
		$processor->expects(self::once())
			->method("processDynamicPath")
			->with(
				self::identicalTo($viewModel),
				self::isInstanceOf(DynamicPath::class),
			);
		$processor->expects(self::once())
			->method("processPartialContent")
			->with(self::identicalTo($viewModel))
			->willReturn(new LogicAssemblyComponentList());

		$viewStreamer = $this->createMock(ViewStreamer::class);
		$viewStreamer->expects(self::once())
			->method("stream")
			->with(
				self::identicalTo($view),
				self::identicalTo($viewModel),
			);

		$sut = $this->createDispatcher(
			input: new Input([], [], []),
			view: $view,
			viewModel: $viewModel,
			viewAssembly: $viewAssembly,
			logicAssembly: $logicAssembly,
			viewModelProcessor: $processor,
			viewStreamer: $viewStreamer,
		);

		$response = $sut->generateResponse();

		self::assertSame(StatusCode::OK, $response->getStatusCode());
		self::assertSame($this->getPrivateProperty($sut, "sessionInit"), $sut->getSessionInit());
	}

	public function testGenerateResponse_executesComponentAndPageLogicAndAppliesHeaders():void {
		$viewModel = new HTMLDocument(
			'<!doctype html><body>'
			. '<widget-one data-element="widget-one"></widget-one>'
			. '<main data-bind>Content</main>'
			. '</body>'
		);
		$component = $viewModel->querySelector("widget-one");
		self::assertInstanceOf(Element::class, $component);

		$componentAssembly = $this->createAssembly("/tmp/component.php");
		$componentList = new LogicAssemblyComponentList();
		$componentList->addAssemblyComponent($componentAssembly, $component);

		$view = new HTMLView(new Stream());
		$viewAssembly = $this->createAssembly("/tmp/page.html");
		$logicAssembly = $this->createAssembly("/tmp/page.php");

		$processor = $this->createMock(ViewModelProcessor::class);
		$processor->method("processDynamicPath");
		$processor->method("processPartialContent")
			->willReturn($componentList);

		$viewModelInit = $this->createViewModelInit($processor, true);

		$invocations = [];
		$logicExecutor = $this->createMock(LogicExecutor::class);
		$logicExecutor->method("invoke")
			->willReturnCallback(function(Assembly $assembly, string $name, array $extraArgs)use(&$invocations, $componentAssembly, $logicAssembly, $component):\Generator {
				$invocations[] = [
					"assembly" => $assembly === $componentAssembly ? "component" : "page",
					"name" => $name,
					"extraArgs" => $extraArgs,
				];
				if(false) {
					yield "never";
				}
			});

		$headerManager = $this->createMock(HeaderManager::class);
		$headerManager->expects(self::once())
			->method("applyWithHeader")
			->willReturnCallback(function($responseHeaders, $withHeader):Response {
				return $withHeader("X-Test", "applied");
			});

		$viewStreamer = $this->createMock(ViewStreamer::class);
		$viewStreamer->expects(self::once())
			->method("stream")
			->with(
				self::identicalTo($view),
				self::identicalTo($viewModel),
			);

		$sut = $this->createDispatcher(
			input: new Input(["do" => "save-item"], [], []),
			view: $view,
			viewModel: $viewModel,
			viewAssembly: $viewAssembly,
			logicAssembly: $logicAssembly,
			viewModelInit: $viewModelInit,
			logicExecutor: $logicExecutor,
			viewStreamer: $viewStreamer,
			headerManager: $headerManager,
		);

		$response = $sut->generateResponse();

		self::assertSame(StatusCode::OK, $response->getStatusCode());
		self::assertSame("applied", $response->getHeaderLine("X-Test"));
		self::assertSame([
			["assembly" => "component", "name" => "go_before"],
			["assembly" => "component", "name" => "do_save_item"],
			["assembly" => "component", "name" => "go"],
			["assembly" => "component", "name" => "go_after"],
			["assembly" => "page", "name" => "go_before"],
			["assembly" => "page", "name" => "do_save_item"],
			["assembly" => "page", "name" => "go"],
			["assembly" => "page", "name" => "go_after"],
		], array_map(fn(array $item):array => [
			"assembly" => $item["assembly"],
			"name" => $item["name"],
		], $invocations));

		$componentArgs = $invocations[0]["extraArgs"];
		self::assertArrayHasKey(Binder::class, $componentArgs);
		self::assertArrayHasKey(Element::class, $componentArgs);
		self::assertArrayHasKey("Gt\\DomTemplate\\Binder", $componentArgs);
		self::assertArrayHasKey("Gt\\Dom\\Element", $componentArgs);
		self::assertSame($component, $componentArgs[Element::class]);
		self::assertSame([], $invocations[4]["extraArgs"]);
	}

	public function testProcessResponse_rethrowsComponentThrowableOutsideErrorMode():void {
		$viewModel = new HTMLDocument('<!doctype html><body><widget-one data-element="widget-one"></widget-one></body>');
		$component = $viewModel->querySelector("widget-one");
		self::assertInstanceOf(Element::class, $component);

		$componentList = new LogicAssemblyComponentList();
		$componentList->addAssemblyComponent($this->createAssembly("/tmp/component.php"), $component);

		$processor = $this->createMock(ViewModelProcessor::class);
		$processor->method("processDynamicPath");
		$processor->method("processPartialContent")
			->willReturn($componentList);

		$logicExecutor = $this->createMock(LogicExecutor::class);
		$logicExecutor->method("invoke")
			->willThrowException(new Exception("component failed"));

		$sut = $this->createDispatcher(
			viewModel: $viewModel,
			viewAssembly: $this->createAssembly("/tmp/page.html"),
			logicAssembly: $this->createAssembly("/tmp/page.php"),
			viewModelProcessor: $processor,
			logicExecutor: $logicExecutor,
		);

		$this->expectExceptionMessage("component failed");
		$sut->processResponse();
	}

	public function testProcessResponse_rethrowsPageThrowableOutsideErrorMode():void {
		$processor = $this->createMock(ViewModelProcessor::class);
		$processor->method("processDynamicPath");
		$processor->method("processPartialContent")
			->willReturn(new LogicAssemblyComponentList());

		$pageAssembly = $this->createAssembly("/tmp/page.php");
		$logicExecutor = $this->createMock(LogicExecutor::class);
		$logicExecutor->method("invoke")
			->willReturnCallback(function(Assembly $assembly):\Generator {
				if($assembly->current() === "/tmp/page.php") {
					throw new Exception("page failed");
				}
				if(false) {
					yield "";
				}
			});

		$sut = $this->createDispatcher(
			viewAssembly: $this->createAssembly("/tmp/page.html"),
			logicAssembly: $pageAssembly,
			viewModelProcessor: $processor,
			logicExecutor: $logicExecutor,
		);

		$this->expectExceptionMessage("page failed");
		$sut->processResponse();
	}

	public function testProcessResponse_swallowsThrowablesDuringErrorModeAndBindsTrace():void {
		$viewModel = new HTMLDocument(
			'<!doctype html><body>'
			. '<widget-one data-element="widget-one"></widget-one>'
			. '<div data-bind:text></div>'
			. '<pre data-bind:text="trace"></pre>'
			. '</body>'
		);
		$component = $viewModel->querySelector("widget-one");
		self::assertInstanceOf(Element::class, $component);

		$componentAssembly = $this->createAssembly("/tmp/component.php");
		$logicAssembly = $this->createAssembly("/tmp/page.php");
		$componentList = new LogicAssemblyComponentList();
		$componentList->addAssemblyComponent($componentAssembly, $component);

		$processor = $this->createMock(ViewModelProcessor::class);
		$processor->method("processDynamicPath");
		$processor->method("processPartialContent")
			->willReturn($componentList);

		$viewModelInit = $this->createViewModelInit($processor, true);

		$logicExecutor = $this->createMock(LogicExecutor::class);
		$logicExecutor->method("invoke")
			->willReturnCallback(function(Assembly $assembly):\Generator {
				if($assembly->current() === "/tmp/component.php") {
					throw new Exception("component boom");
				}
				throw new Exception("page boom");
			});

		$viewStreamer = $this->createMock(ViewStreamer::class);
		$viewStreamer->expects(self::once())
			->method("stream");

		$throwable = $this->createExceptionWithTrace(
			"render failed",
			getcwd() . "/src/Page/go.php",
			123,
			[
			[
				"class" => "Example\\Handler",
				"function" => "go",
				"file" => getcwd() . "/src/Page/go.php",
				"line" => 55,
			],
			[
				"file" => "gt-logic-stream:///tmp/page.php",
				"line" => 99,
			],
			[
				"file" => getcwd() . "/src/Page/ignored.php",
				"line" => 999,
			],
		]);

		$sut = $this->createDispatcher(
			viewModel: $viewModel,
			viewAssembly: $this->createAssembly("/tmp/page.html"),
			logicAssembly: $logicAssembly,
			viewModelInit: $viewModelInit,
			logicExecutor: $logicExecutor,
			viewStreamer: $viewStreamer,
		);

		$sut->processResponse($throwable);

		$rendered = (string)$viewModel;
		self::assertStringContainsString("render failed", $rendered);
		self::assertStringContainsString('#0 Exception("render failed")', $rendered);
		self::assertStringContainsString('src/Page/go.php(123)', $rendered);
		self::assertStringContainsString('gt-logic-stream:///tmp/page.php(99)', $rendered);
		self::assertStringNotContainsString('ignored.php', $rendered);
	}

	public function testGenerateErrorResponse_throwsWhenNoErrorViewExists():void {
		$sut = $this->createDispatcher();
		$throwable = $this->createResponseStatusException(418);

		$this->expectException(ErrorPageNotFoundException::class);
		$this->expectExceptionCode(418);
		$sut->generateErrorResponse($throwable);
	}

	public function testGenerateErrorResponse_usesThrowableStatusAndReturnsResponse():void {
		$viewModel = new HTMLDocument('<!doctype html><body><div data-bind:text></div><pre data-bind:text="trace"></pre></body>');
		$processor = $this->createMock(ViewModelProcessor::class);
		$processor->method("processDynamicPath");
		$processor->method("processPartialContent")
			->willReturn(new LogicAssemblyComponentList());
		$viewModelInit = $this->createViewModelInit($processor, true);

		$viewStreamer = $this->createMock(ViewStreamer::class);
		$viewStreamer->expects(self::once())
			->method("stream");

		$throwable = $this->createResponseStatusException(
			418,
			"teapot",
			getcwd() . "/src/Error.php",
			88,
			[],
		);

		$sut = $this->createDispatcher(
			viewModel: $viewModel,
			viewAssembly: $this->createAssembly("/tmp/error.html"),
			logicAssembly: new Assembly(),
			viewModelInit: $viewModelInit,
			viewStreamer: $viewStreamer,
		);

		$response = $sut->generateErrorResponse($throwable);

		self::assertSame(418, $response->getStatusCode());
		self::assertStringContainsString("teapot", (string)$viewModel);
	}

	public function testGenerateBasicErrorResponse_forNotFoundIncludesHelpfulDevelopmentDetail():void {
		$sut = $this->createDispatcher(production: false);
		$this->setResponseStatus($sut, 404);

		$response = $sut->generateBasicErrorResponse(
			new HttpNotFound(),
			new Exception("inner"),
		);

		$body = (string)$response->getBody();
		self::assertSame(404, $response->getStatusCode());
		self::assertStringContainsString("The server could not find the requested resource.", $body);
		self::assertStringContainsString("/error-pages/404.html", $body);
	}

	public function testGenerateBasicErrorResponse_inDevelopmentIncludesTraceUntilInjectorFrame():void {
		$sut = $this->createDispatcher(production: false);
		$this->setResponseStatus($sut, 500);

		$throwable = $this->createExceptionWithTrace(
			"boom",
			"/tmp/app/Page.php",
			44,
			[
			[
				"file" => "/tmp/app/Before.php",
				"line" => 10,
				"function" => "before",
			],
			[
				"file" => "/tmp/vendor/phpgt/servicecontainer/src/Injector.php",
				"line" => 20,
				"function" => "invoke",
			],
			[
				"file" => "/tmp/app/After.php",
				"line" => 30,
				"function" => "after",
			],
		]);

		$response = $sut->generateBasicErrorResponse($throwable, new Exception("inner"));
		$body = (string)$response->getBody();

		self::assertSame(500, $response->getStatusCode());
		self::assertStringContainsString("/tmp/app/Page.php:44", $body);
		self::assertStringContainsString("#0", $body);
		self::assertStringContainsString("file:\t/tmp/app/Before.php", $body);
		self::assertStringNotContainsString("After.php", $body);
	}

	public function testGenerateBasicErrorResponse_inProductionOmitsDebugDetail():void {
		$sut = $this->createDispatcher(production: true);
		$this->setResponseStatus($sut, 500);

		$response = $sut->generateBasicErrorResponse(
			new Exception("boom"),
			new Exception("inner"),
		);

		$body = (string)$response->getBody();
		self::assertSame(500, $response->getStatusCode());
		self::assertStringContainsString("boom", $body);
		self::assertStringNotContainsString(__FILE__, $body);
		self::assertStringNotContainsString("#0", $body);
	}

	private function createDispatcher(
		bool $production = false,
		?Input $input = null,
		?HTMLView $view = null,
		?HTMLDocument $viewModel = null,
		?Assembly $viewAssembly = null,
		?Assembly $logicAssembly = null,
		?ViewModelProcessor $viewModelProcessor = null,
		?ViewModelInit $viewModelInit = null,
		?LogicExecutor $logicExecutor = null,
		?ViewStreamer $viewStreamer = null,
		?HeaderManager $headerManager = null,
	):Dispatcher {
		$input ??= new Input([], [], []);
		$view ??= new HTMLView(new Stream());
		$viewModel ??= new HTMLDocument("<!doctype html><body></body>");
		$viewAssembly ??= new Assembly();
		$logicAssembly ??= new Assembly();
		$viewModelProcessor ??= $this->createConfiguredMock(ViewModelProcessor::class, [
			"processPartialContent" => new LogicAssemblyComponentList(),
		]);

		$request = $this->createRequest("/page/");
		$requestInit = $this->createMock(RequestInit::class);
		$requestInit->method("getInput")
			->willReturn($input);
		$requestInit->method("getServerInfo")
			->willReturn(new ServerInfo([]));

		$routerInit = $this->createMock(RouterInit::class);
		$routerInit->method("getView")
			->willReturn($view);
		$routerInit->method("getViewModel")
			->willReturn($viewModel);
		$routerInit->method("getBaseRouter")
			->willReturn($this->createStub(BaseRouter::class));
		$routerInit->method("getDynamicPath")
			->willReturn(new DynamicPath("/page/", $viewAssembly, $logicAssembly));
		$routerInit->method("getViewAssembly")
			->willReturn($viewAssembly);
		$routerInit->method("getLogicAssembly")
			->willReturn($logicAssembly);

		$sessionInit = $this->createMock(SessionInit::class);
		$sessionInit->method("getSession")
			->willReturn($this->createStub(Session::class));

		$viewModelInit ??= $this->createViewModelInit($viewModelProcessor);

		$appAutoloader = $this->createMock(AppAutoloader::class);
		$appAutoloader->method("setup");
		$logicStreamHandler = $this->createMock(LogicStreamHandler::class);
		$logicStreamHandler->method("setup");
		$logicExecutor ??= $this->createMock(LogicExecutor::class);
		$logicExecutor->method("invoke")
			->willReturnCallback(fn():\Generator => $this->emptyGenerator());
		$viewStreamer ??= $this->createMock(ViewStreamer::class);
		$viewStreamer->method("stream");
		$headerManager ??= $this->createMock(HeaderManager::class);
		$headerManager->method("applyWithHeader")->willReturn(null);

		return new Dispatcher(
			$this->createConfig($production),
			$request,
			$this->createGlobals(),
			static fn() => null,
			null,
			$appAutoloader,
			$logicStreamHandler,
			$requestInit,
			$routerInit,
			$sessionInit,
			$viewModelInit,
			$logicExecutor,
			$viewStreamer,
			$headerManager,
		);
	}

	private function createConfig(bool $production = false):Config&MockObject {
		$config = $this->createMock(Config::class);
		$config->method("getString")
			->willReturnCallback(fn(string $key):string => [
				"app.namespace" => "Example\\App",
				"app.class_dir" => "/tmp/classes",
				"router.router_file" => "/tmp/router.php",
				"router.router_class" => "AppRouter",
				"router.default_content_type" => "text/html",
				"session.name" => "TESTSESSID",
				"session.handler" => "FileHandler",
				"session.path" => sys_get_temp_dir(),
				"view.component_directory" => "/tmp/components",
				"view.partial_directory" => "/tmp/partials",
				"app.error_page_dir" => "/error-pages",
			][$key] ?? "");
		$config->method("getBool")
			->willReturnCallback(fn(string $key):?bool => [
				"app.force_trailing_slash" => true,
				"session.use_trans_sid" => false,
				"session.use_cookies" => false,
				"app.production" => $production,
			][$key] ?? null);
		$config->method("getInt")
			->willReturnCallback(fn(string $key):int => [
				"router.redirect_response_code" => 303,
			][$key] ?? 0);
		return $config;
	}

	private function createRequest(string $path):Request&MockObject {
		$request = $this->createMock(Request::class);
		$request->method("getUri")
			->willReturn(new Uri("https://example.test" . $path));
		return $request;
	}

	/** @return array<string, array<string, string|array<string, string>>> */
	private function createGlobals():array {
		return [
			"_GET" => [],
			"_POST" => [],
			"_FILES" => [],
			"_SERVER" => [],
			"_COOKIE" => [],
		];
	}

	private function createAssembly(string ...$pathList):Assembly {
		$assembly = new Assembly();
		foreach($pathList as $path) {
			$assembly->add($path);
		}
		return $assembly;
	}

	private function createViewModelInit(
		ViewModelProcessor $processor,
		bool $initialiseBinders = false,
	):ViewModelInit&MockObject {
		$viewModelInit = $this->createMock(ViewModelInit::class);
		$viewModelInit->method("getViewModelProcessor")
			->willReturn($processor);
		$viewModelInit->method("initHTMLDocument")
			->willReturnCallback(function(
				DocumentBinder $documentBinder,
				HTMLAttributeBinder $htmlAttributeBinder,
				ListBinder $listBinder,
				TableBinder $tableBinder,
				ElementBinder $elementBinder,
				PlaceholderBinder $placeholderBinder,
				HTMLAttributeCollection $htmlAttributeCollection,
				ListElementCollection $listElementCollection,
				BindableCache $bindableCache,
			)use($initialiseBinders):void {
				if(!$initialiseBinders) {
					return;
				}

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
				$documentBinder->setDependencies(
					$elementBinder,
					$placeholderBinder,
					$tableBinder,
					$listBinder,
					$listElementCollection,
					$bindableCache,
				);
			});
		return $viewModelInit;
	}

	private function setResponseStatus(Dispatcher $dispatcher, int $statusCode):void {
		$response = $this->getPrivateProperty($dispatcher, "response");
		$this->setPrivateProperty($dispatcher, "response", $response->withStatus($statusCode));
	}

	private function getPrivateProperty(object $object, string $name):mixed {
		$reflection = new \ReflectionProperty($object, $name);
		return $reflection->getValue($object);
	}

	private function setPrivateProperty(object $object, string $name, mixed $value):void {
		$reflection = new \ReflectionProperty($object, $name);
		$reflection->setValue($object, $value);
	}

	/** @param array<int, array<string, mixed>> $trace */
	private function createExceptionWithTrace(
		string $message,
		string $file,
		int $line,
		array $trace,
	):Exception {
		$exception = new Exception($message);
		$this->setExceptionProperty($exception, "file", $file);
		$this->setExceptionProperty($exception, "line", $line);
		$this->setExceptionProperty($exception, "trace", $trace);
		return $exception;
	}

	/** @param array<int, array<string, mixed>> $trace */
	private function createResponseStatusException(
		int $statusCode,
		string $message = "",
		string $file = "",
		int $line = 0,
		array $trace = [],
	):ResponseStatusException {
		$exception = new class($statusCode, $message) extends ResponseStatusException {
			public function __construct(
				private readonly int $statusCode,
				string $message = "",
			) {
				parent::__construct($message);
			}

			public function getHttpCode():int {
				return $this->statusCode;
			}
		};

		if($file !== "") {
			$this->setExceptionProperty($exception, "file", $file);
		}
		if($line !== 0) {
			$this->setExceptionProperty($exception, "line", $line);
		}
		if($trace !== []) {
			$this->setExceptionProperty($exception, "trace", $trace);
		}

		return $exception;
	}

	private function setExceptionProperty(\Exception $exception, string $name, mixed $value):void {
		$reflection = new \ReflectionProperty(\Exception::class, $name);
		$reflection->setValue($exception, $value);
	}

	private function emptyGenerator():\Generator {
		if(false) {
			yield "";
		}
	}
}
