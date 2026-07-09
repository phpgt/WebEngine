<?php
namespace GT\WebEngine\Test\Dispatch;

use Exception;
use GT\Config\Config;
use GT\Dom\Element;
use GT\Dom\HTMLDocument;
use GT\DomTemplate\BindableCache;
use GT\DomTemplate\Binder;
use GT\DomTemplate\DocumentBinder;
use GT\DomTemplate\ElementBinder;
use GT\DomTemplate\HTMLAttributeBinder;
use GT\DomTemplate\HTMLAttributeCollection;
use GT\DomTemplate\ListBinder;
use GT\DomTemplate\ListElementCollection;
use GT\DomTemplate\PlaceholderBinder;
use GT\DomTemplate\TableBinder;
use GT\Http\Request;
use GT\Http\Response;
use GT\Http\ResponseStatusException\ClientError\HttpNotFound;
use GT\Http\ResponseStatusException\ResponseStatusException;
use GT\Http\ServerInfo;
use GT\Http\StatusCode;
use GT\Http\Stream;
use GT\Http\Uri;
use GT\Input\Input;
use GT\Json\Schema\JSONDocument;
use GT\Logger\LogConfig;
use GT\Routing\Assembly;
use GT\Routing\BaseRouter;
use GT\Routing\Path\DynamicPath;
use GT\Session\Session;
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
use GT\WebEngine\Test\Fixture\TestLogHandler;
use GT\WebEngine\View\HTMLView;
use GT\WebEngine\View\JSONView;
use GT\WebEngine\View\NullView;
use GT\WebEngine\View\ViewStreamer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DispatcherTest extends TestCase {
	protected function tearDown():void {
		$this->resetLoggerState();
		parent::tearDown();
	}

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

	public function testGenerateResponse_streamsJsonDocumentMutatedByPostLogic():void {
		$stream = new Stream();
		$view = new JSONView($stream);
		$viewModel = new JSONDocument();
		$logicAssembly = $this->createAssembly("/tmp/api.php");

		$viewModelInit = $this->createMock(ViewModelInit::class);
		$viewModelInit->expects(self::once())
			->method("getViewModelProcessor")
			->willReturn(null);
		$viewModelInit->expects(self::never())
			->method("initHTMLDocument");

		$logicExecutor = $this->createMock(LogicExecutor::class);
		$logicExecutor->method("invoke")
			->willReturnCallback(function(Assembly $assembly, string $name)use($logicAssembly, $viewModel):\Generator {
				self::assertSame($logicAssembly, $assembly);
				if($name !== "go") {
					return;
				}

				$viewModel->set("hello", "Greg");
				yield "/tmp/api.php::go()";
			});

		$sut = $this->createDispatcher(
			request: $this->createRequest("/hello", "POST"),
			view: $view,
			viewModel: $viewModel,
			logicAssembly: $logicAssembly,
			viewModelInit: $viewModelInit,
			logicExecutor: $logicExecutor,
			viewStreamer: new ViewStreamer(),
		);

		$response = $sut->generateResponse();

		self::assertSame(StatusCode::OK, $response->getStatusCode());
		self::assertSame("application/json", $response->getHeaderLine("Content-Type"));
		self::assertSame("{\"hello\":\"Greg\"}\n", (string)$stream);
		self::assertSame("/tmp/api:go", $response->getHeaderLine("X-Logic-Execution"));
	}

	public function testGenerateResponse_jsonDocumentErrorFinishesResponseAndInterruptsLogic():void {
		$stream = new Stream();
		$view = new JSONView($stream);
		$viewModel = new JSONDocument();
		$logicAssembly = $this->createAssembly("/tmp/api.php");
		$finishedResponse = null;

		$viewModelInit = $this->createMock(ViewModelInit::class);
		$viewModelInit->method("getViewModelProcessor")
			->willReturn(null);

		$logicExecutor = $this->createMock(LogicExecutor::class);
		$logicExecutor->method("invoke")
			->willReturnCallback(function(Assembly $assembly, string $name)use($logicAssembly, $viewModel):\Generator {
				self::assertSame($logicAssembly, $assembly);
				if($name !== "go") {
					return;
				}

				$viewModel->error("missing parameter: name", StatusCode::UNPROCESSABLE_ENTITY);
				$viewModel->set("hello", "Greg");
				yield "/tmp/api.php::go()";
			});

		$sut = $this->createDispatcher(
			request: $this->createRequest("/hello", "POST"),
			view: $view,
			viewModel: $viewModel,
			logicAssembly: $logicAssembly,
			viewModelInit: $viewModelInit,
			logicExecutor: $logicExecutor,
			viewStreamer: new ViewStreamer(),
			finishCallback: function(Response $response)use(&$finishedResponse):void {
				$finishedResponse = $response;
			},
		);

		$response = $sut->generateResponse();

		self::assertSame(StatusCode::UNPROCESSABLE_ENTITY, $response->getStatusCode());
		self::assertSame("application/json", $response->getHeaderLine("Content-Type"));
		self::assertSame("{\"error\":\"missing parameter: name\"}\n", (string)$stream);
		self::assertSame($response, $finishedResponse);
		self::assertSame("", $response->getHeaderLine("X-Logic-Execution"));
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
				yield ($assembly === $componentAssembly ? "/tmp/component.php" : "/tmp/page.php") . "::$name()";
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
		self::assertSame(
			"/tmp/component:go_before;"
			. "/tmp/component:do_save_item;"
			. "/tmp/component:go;"
			. "/tmp/component:go_after;"
			. "/tmp/page:go_before;"
			. "/tmp/page:do_save_item;"
			. "/tmp/page:go;"
			. "/tmp/page:go_after",
			$response->getHeaderLine("X-Logic-Execution"),
		);
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
		self::assertArrayHasKey("GT\\DomTemplate\\Binder", $componentArgs);
		self::assertArrayHasKey("GT\\Dom\\Element", $componentArgs);
		self::assertSame($component, $componentArgs[Element::class]);
		self::assertSame([], $invocations[4]["extraArgs"]);
	}

	public function testGenerateResponse_componentFormDoActionDoesNotRunPageDoAction():void {
		$html = <<<HTML
		<!doctype html>
		<body>
			<form method="post"><button name="do" value="save-item">Save</button></form>
		<widget-one>
			<form method="post">
				<input type="hidden" name="__component" value="widget-one">
				<button name="do" value="save-item">Save</button>
			</form>
		</widget-one>
		</body>
		HTML;

		$viewModel = new HTMLDocument($html);
		$component = $viewModel->querySelector("widget-one");
		self::assertInstanceOf(Element::class, $component);

		$componentAssembly = $this->createAssembly("/tmp/component.php");
		$componentList = new LogicAssemblyComponentList();
		$componentList->addAssemblyComponent($componentAssembly, $component);

		$processor = $this->createMock(ViewModelProcessor::class);
		$processor->method("processDynamicPath");
		$processor->method("processPartialContent")
			->willReturn($componentList);

		$invocations = [];
		$logicAssembly = $this->createAssembly("/tmp/page.php");
		$logicExecutor = $this->createMock(LogicExecutor::class);
		$logicExecutor->method("invoke")
			->willReturnCallback(function(Assembly $assembly, string $name)use(&$invocations, $componentAssembly):\Generator {
				$invocations[] = [
					"assembly" => $assembly === $componentAssembly ? "component" : "page",
					"name" => $name,
				];
				yield ($assembly === $componentAssembly ? "/tmp/component.php" : "/tmp/page.php") . "::$name()";
			});

		$sut = $this->createDispatcher(
			input: new Input([], [
				"__component" => "widget-one",
				"do" => "save-item",
			]),
			viewModel: $viewModel,
			logicAssembly: $logicAssembly,
			viewModelInit: $this->createViewModelInit($processor, true),
			logicExecutor: $logicExecutor,
		);

		$response = $sut->generateResponse();

		self::assertSame(StatusCode::OK, $response->getStatusCode());
		self::assertSame([
			["assembly" => "component", "name" => "go_before"],
			["assembly" => "component", "name" => "do_save_item"],
			["assembly" => "component", "name" => "go"],
			["assembly" => "component", "name" => "go_after"],
			["assembly" => "page", "name" => "go_before"],
			["assembly" => "page", "name" => "go"],
			["assembly" => "page", "name" => "go_after"],
		], $invocations);
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

	public function testGenerateResponse_stopsProcessingAfterRedirectFromLogic():void {
		$processor = $this->createMock(ViewModelProcessor::class);
		$processor->method("processDynamicPath");
		$processor->method("processPartialContent")
			->willReturn(new LogicAssemblyComponentList());

		$viewStreamer = $this->createMock(ViewStreamer::class);
		$viewStreamer->expects(self::never())
			->method("stream");

		$invocations = [];
		$response = null;
		$logicExecutor = $this->createMock(LogicExecutor::class);
		$logicExecutor->method("invoke")
			->willReturnCallback(function(Assembly $assembly, string $name)use(&$invocations, &$response):\Generator {
				$invocations[] = [$assembly->current(), $name];
				if($assembly->current() === "/tmp/page.php" && $name === "go") {
					$response->redirect("./?new-state");
				}
				if(false) {
					yield "";
				}
			});

		$sut = $this->createDispatcher(
			viewAssembly: $this->createAssembly("/tmp/page.html"),
			logicAssembly: $this->createAssembly("/tmp/page.php"),
			viewModelProcessor: $processor,
			logicExecutor: $logicExecutor,
			viewStreamer: $viewStreamer,
		);
		$response = $this->getPrivateProperty($sut, "response");
		self::assertInstanceOf(Response::class, $response);

		$generatedResponse = $sut->generateResponse();

		self::assertSame(StatusCode::SEE_OTHER, $generatedResponse->getStatusCode());
		self::assertSame("./?new-state", $generatedResponse->getHeaderLine("Location"));
		self::assertSame([
			["/tmp/page.php", "go_before"],
			["/tmp/page.php", "go"],
		], $invocations);
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
		$this->resetLoggerState();
		LogConfig::addHandler(new TestLogHandler());
		$sut = $this->createDispatcher();
		$throwable = $this->createResponseStatusException(418);

		try {
			$sut->generateErrorResponse($throwable);
			self::fail("Expected missing error view to throw.");
		}
		catch(ErrorPageNotFoundException $exception) {
			self::assertSame(418, $exception->getCode());
		}

		self::assertCount(1, TestLogHandler::$records);
		self::assertSame("WARNING", TestLogHandler::$records[0]["level"]);
		self::assertSame("Error page template not found for HTTP 418", TestLogHandler::$records[0]["message"]);
		self::assertSame("/page/", TestLogHandler::$records[0]["context"]["uri"]);
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

	public function testGenerateErrorResponse_doesNotVerifyCsrfTokenAgainForPostRequest():void {
		$token = "CSRF_spent-token";
		$sessionState = [
			"tokenList" => [$token => time()],
		];
		$viewModel = new HTMLDocument('<!doctype html><body><h1 data-bind:text></h1></body>');
		$processor = $this->createMock(ViewModelProcessor::class);
		$processor->method("processDynamicPath");
		$processor->method("processPartialContent")
			->willReturn(new LogicAssemblyComponentList());
		$viewModelInit = $this->createViewModelInit($processor, true);

		$viewStreamer = $this->createMock(ViewStreamer::class);
		$viewStreamer->expects(self::once())
			->method("stream");

		$sut = $this->createDispatcher(
			request: $this->createRequest("/page/", "POST"),
			globals: $this->createGlobals(post: ["csrf-token" => $token]),
			viewModel: $viewModel,
			viewAssembly: $this->createAssembly("/tmp/error.html"),
			logicAssembly: new Assembly(),
			viewModelInit: $viewModelInit,
			viewStreamer: $viewStreamer,
			sessionInit: $this->createSessionInitWithState($sessionState),
		);

		$response = $sut->generateErrorResponse(new Exception("post failed"));

		self::assertSame(StatusCode::INTERNAL_SERVER_ERROR, $response->getStatusCode());
		self::assertStringContainsString("post failed", (string)$viewModel);
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

	public function testGenerateBasicErrorResponse_forJsonViewReturnsJson():void {
		$sut = $this->createDispatcher(
			production: true,
			view: new JSONView(new Stream()),
			viewModel: new JSONDocument(),
		);
		$this->setResponseStatus($sut, 500);

		$response = $sut->generateBasicErrorResponse(
			new Exception("missing parameter: name"),
			new Exception("inner"),
		);

		self::assertSame(500, $response->getStatusCode());
		self::assertSame("application/json", $response->getHeaderLine("Content-Type"));
		self::assertSame(
			[
				"error" => "missing parameter: name",
				"status" => 500,
				"type" => Exception::class,
			],
			json_decode((string)$response->getBody(), true),
		);
		self::assertStringEndsWith("\n", (string)$response->getBody());
	}

	public function testGenerateBasicErrorResponse_forJsonViewUsesStatusTextWhenThrowableMessageIsEmpty():void {
		$sut = $this->createDispatcher(
			production: true,
			view: new JSONView(new Stream()),
			viewModel: new JSONDocument(),
		);
		$this->setResponseStatus($sut, 500);

		$response = $sut->generateBasicErrorResponse(
			new Exception(),
			new Exception("inner"),
		);

		self::assertSame(
			"Internal Server Error",
			json_decode((string)$response->getBody(), true)["error"],
		);
	}

	public function testGenerateBasicErrorResponse_defaultsToThrowableHttpCodeWhenResponseStatusUnset():void {
		$sut = $this->createDispatcher(production: false);

		$throwable = new class("CSRF Token not found") extends ResponseStatusException {
			public function getHttpCode():int {
				return StatusCode::FORBIDDEN;
			}
		};

		$response = $sut->generateBasicErrorResponse(
			$throwable,
			new Exception("inner"),
		);

		$body = (string)$response->getBody();
		self::assertSame(StatusCode::FORBIDDEN, $response->getStatusCode());
		self::assertStringContainsString("Error 403", $body);
		self::assertStringContainsString("CSRF Token not found", $body);
	}

	public function testGenerateResponse_injectsCsrfTokenIntoPostForms():void {
		$viewModel = new HTMLDocument(
			'<!doctype html><html><head></head><body>'
			. '<form method="post"><button>Save</button></form>'
			. '<form method="get"><button>Search</button></form>'
			. '</body></html>'
		);
		$sessionState = [];
		$viewStreamer = $this->createMock(ViewStreamer::class);
		$viewStreamer->expects(self::once())
			->method("stream")
			->willReturnCallback(function(HTMLView $view, HTMLDocument $document)use(&$sessionState):void {
				$postForm = $document->querySelector("form[method='post']");
				self::assertInstanceOf(Element::class, $postForm);
				$tokenInput = $postForm->querySelector("input[name='csrf-token'][type='hidden']");
				self::assertInstanceOf(Element::class, $tokenInput);

				$metaToken = $document->querySelector("meta[name='csrf-token']");
				self::assertInstanceOf(Element::class, $metaToken);
				self::assertSame(
					$tokenInput->getAttribute("value"),
					$metaToken->getAttribute("content"),
				);

				$getForm = $document->querySelector("form[method='get']");
				self::assertInstanceOf(Element::class, $getForm);
				self::assertNull($getForm->querySelector("input[name='csrf-token']"));
				self::assertArrayHasKey($tokenInput->getAttribute("value"), $sessionState["tokenList"]);
			});

		$sut = $this->createDispatcher(
			viewModel: $viewModel,
			viewAssembly: $this->createAssembly("/tmp/page.html"),
			sessionInit: $this->createSessionInitWithState($sessionState),
			viewStreamer: $viewStreamer,
		);

		$sut->generateResponse();
	}

	public function testGenerateResponse_verifiesValidCsrfTokenBeforeLogic():void {
		$token = "CSRF_valid-token";
		$sessionState = [
			"tokenList" => [$token => null],
		];
		$logicExecutor = $this->createMock(LogicExecutor::class);
		$logicExecutor->expects(self::exactly(3))
			->method("invoke")
			->willReturnCallback(fn():\Generator => $this->emptyGenerator());

		$sut = $this->createDispatcher(
			request: $this->createRequest("/page/", "POST"),
			globals: $this->createGlobals(post: ["csrf-token" => $token]),
			viewAssembly: $this->createAssembly("/tmp/page.html"),
			logicAssembly: $this->createAssembly("/tmp/page.php"),
			logicExecutor: $logicExecutor,
			sessionInit: $this->createSessionInitWithState($sessionState),
		);

		$response = $sut->generateResponse();

		self::assertSame(StatusCode::OK, $response->getStatusCode());
		self::assertIsInt($sessionState["tokenList"][$token]);
	}

	public function testGenerateResponse_doesNotConsumeCsrfTokenWhenLogicThrows():void {
		$token = "CSRF_valid-token";
		$sessionState = [
			"tokenList" => [$token => null],
		];
		$logicExecutor = $this->createMock(LogicExecutor::class);
		$logicExecutor->method("invoke")
			->willReturnCallback(function(Assembly $assembly, string $name):\Generator {
				if($name === "go") {
					throw new Exception("post failed");
				}

				if(false) {
					yield "";
				}
			});

		$sut = $this->createDispatcher(
			request: $this->createRequest("/page/", "POST"),
			globals: $this->createGlobals(post: ["csrf-token" => $token]),
			viewAssembly: $this->createAssembly("/tmp/page.html"),
			logicAssembly: $this->createAssembly("/tmp/page.php"),
			logicExecutor: $logicExecutor,
			sessionInit: $this->createSessionInitWithState($sessionState),
		);

		try {
			$sut->generateResponse();
			self::fail("Expected page logic to throw.");
		}
		catch(Exception $exception) {
			self::assertSame("post failed", $exception->getMessage());
		}

		self::assertNull($sessionState["tokenList"][$token]);
	}

	public function testGenerateResponse_throwsForbiddenOnInvalidCsrfToken():void {
		$this->resetLoggerState();
		LogConfig::addHandler(new TestLogHandler());
		$logicExecutor = $this->createMock(LogicExecutor::class);
		$logicExecutor->expects(self::never())
			->method("invoke");
		$sessionState = [
			"tokenList" => ["CSRF_other-token" => null],
		];

		$sut = $this->createDispatcher(
			request: $this->createRequest("/page/", "POST"),
			globals: $this->createGlobals(post: ["csrf-token" => "CSRF_invalid-token"]),
			viewAssembly: $this->createAssembly("/tmp/page.html"),
			logicAssembly: $this->createAssembly("/tmp/page.php"),
			logicExecutor: $logicExecutor,
			sessionInit: $this->createSessionInitWithState($sessionState),
		);

		try {
			$sut->generateResponse();
			self::fail("Expected invalid CSRF token to abort the request.");
		}
		catch(ResponseStatusException $exception) {
			self::assertSame(StatusCode::FORBIDDEN, $exception->getHttpCode());
			self::assertSame(StatusCode::FORBIDDEN, $exception->getCode());
			self::assertStringContainsString("CSRF", $exception->getMessage());
		}

		self::assertCount(1, TestLogHandler::$records);
		self::assertSame("WARNING", TestLogHandler::$records[0]["level"]);
		self::assertStringContainsString("CSRF verification failed", TestLogHandler::$records[0]["message"]);
		self::assertSame("/page/", TestLogHandler::$records[0]["context"]["uri"]);
	}

	private function createDispatcher(
		bool $production = false,
		?Config $config = null,
		?Request $request = null,
		?array $globals = null,
		?Input $input = null,
		NullView|JSONView|HTMLView|null $view = null,
		HTMLDocument|JSONDocument|null $viewModel = null,
		?Assembly $viewAssembly = null,
		?Assembly $logicAssembly = null,
		?ViewModelProcessor $viewModelProcessor = null,
		?ViewModelInit $viewModelInit = null,
		?LogicExecutor $logicExecutor = null,
		?ViewStreamer $viewStreamer = null,
		?HeaderManager $headerManager = null,
		?SessionInit $sessionInit = null,
		?\Closure $finishCallback = null,
	):Dispatcher {
		$config ??= $this->createConfig($production);
		$request ??= $this->createRequest("/page/");
		$globals ??= $this->createGlobals();
		$input ??= new Input(
			$globals["_GET"],
			$globals["_POST"],
			$globals["_FILES"],
			requestMethod: $request->getMethod(),
		);
		$view ??= new HTMLView(new Stream());
		$viewModel ??= new HTMLDocument("<!doctype html><body></body>");
		$viewAssembly ??= new Assembly();
		$logicAssembly ??= new Assembly();
		$viewModelProcessor ??= $this->createConfiguredMock(ViewModelProcessor::class, [
			"processPartialContent" => new LogicAssemblyComponentList(),
		]);
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

		if(!$sessionInit) {
			$sessionInit = $this->createMock(SessionInit::class);
			$sessionInit->method("getSession")
				->willReturn($this->createStub(Session::class));
		}

		$viewModelInit ??= $this->createViewModelInit($viewModelProcessor);

		$appAutoloader = $this->createMock(AppAutoloader::class);
		$appAutoloader->method("setup");
		$logicStreamHandler = $this->createMock(LogicStreamHandler::class);
		$logicStreamHandler->method("setup");
		$logicExecutor ??= $this->createMock(LogicExecutor::class);
		$logicExecutor->method("invoke")
			->willReturnCallback(fn():\Generator => $this->emptyGenerator());
		if(!$viewStreamer) {
			$viewStreamer = $this->createMock(ViewStreamer::class);
			$viewStreamer->method("stream");
		}
		$headerManager ??= $this->createMock(HeaderManager::class);
		$headerManager->method("applyWithHeader")->willReturn(null);

		return new Dispatcher(
			$config,
			$request,
			$globals,
			$finishCallback ?? static fn() => null,
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
				"security.csrf_ignore_path" => "",
				"security.csrf_token_sharing" => "per-page",
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
				"security.csrf_max_tokens" => 100,
				"security.csrf_token_length" => 10,
			][$key] ?? 0);
		return $config;
	}

	private function createRequest(string $path, string $method = "GET"):Request&MockObject {
		$request = $this->createMock(Request::class);
		$request->method("getUri")
			->willReturn(new Uri("https://example.test" . $path));
		$request->method("getMethod")
			->willReturn($method);
		return $request;
	}

	/** @return array<string, array<string, string|array<string, string>>> */
	private function createGlobals(
		array $get = [],
		array $post = [],
		array $files = [],
		array $server = [],
		array $cookie = [],
	):array {
		return [
			"_GET" => $get,
			"_POST" => $post,
			"_FILES" => $files,
			"_SERVER" => $server,
			"_COOKIE" => $cookie,
		];
	}

	/** @param array<string, mixed> $sessionState */
	private function createSessionInitWithState(array &$sessionState):SessionInit&MockObject {
		$session = $this->createMock(Session::class);
		$session->method("get")
			->willReturnCallback(function(string $key)use(&$sessionState):mixed {
				return $sessionState[$key] ?? null;
			});
		$session->method("set")
			->willReturnCallback(function(string $key, mixed $value)use(&$sessionState):void {
				$sessionState[$key] = $value;
			});

		$sessionInit = $this->createMock(SessionInit::class);
		$sessionInit->method("getSession")
			->willReturn($session);
		return $sessionInit;
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

	private function resetLoggerState():void {
		TestLogHandler::$records = [];
		$this->setStaticProperty(LogConfig::class, "handlers", []);
		$this->setStaticProperty(LogConfig::class, "handlerMinLevels", []);
		$this->setStaticProperty(LogConfig::class, "handlerMaxLevels", []);
		$this->setStaticProperty(LogConfig::class, "defaultHandlerLevel", "DEBUG");
	}

	private function setStaticProperty(string $className, string $propertyName, mixed $value):void {
		$reflection = new \ReflectionProperty($className, $propertyName);
		$reflection->setValue(null, $value);
	}

	private function emptyGenerator():\Generator {
		if(false) {
			yield "";
		}
	}
}
