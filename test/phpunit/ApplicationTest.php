<?php
namespace GT\WebEngine\Test;

use Closure;
use Exception;
use GT\Csrf\HTMLDocumentProtector;
use GT\Config\Config;
use GT\Config\ConfigFactory;
use GT\Http\RequestFactory;
use GT\Http\Response;
use GT\Http\ResponseStatusException\ClientError\HttpNotFound;
use GT\Http\ResponseStatusException\Redirection\HttpNotModified;
use GT\Http\ServerRequest;
use GT\Http\StatusCode;
use GT\Http\Stream;
use GT\Http\Uri;
use GT\Logger\LogConfig;
use GT\ProtectedGlobal\Protection;
use GT\WebEngine\Application;
use GT\WebEngine\Debug\OutputBuffer;
use GT\WebEngine\Debug\Timer;
use GT\WebEngine\Dispatch\Dispatcher;
use GT\WebEngine\Dispatch\DispatcherFactory;
use GT\WebEngine\Init\SessionInit;
use GT\WebEngine\Redirection\Redirect;
use GT\WebEngine\Redirection\RedirectUri;
use GT\WebEngine\Test\Fixture\TestLogHandler;
use PHPUnit\Framework\MockObject\Invocation;
use PHPUnit\Framework\MockObject\InvocationHandler;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase {
	protected function tearDown():void {
		$this->resetApplicationLoggerState();
		parent::tearDown();
	}

	public function testDefaultConfig_usesHtmlRoutingAndDoesNotLogNotModifiedResponses():void {
		$configFile = dirname(__DIR__, 2) . "/config.default.ini";
		$config = parse_ini_file($configFile, true, INI_SCANNER_RAW);

		self::assertSame("text/html", $config["router"]["default_content_type"]);
		self::assertSame("false", $config["logger"]["log_not_modified"]);
	}

	public function testStart_callsRedirectExecute():void {
		$redirect = self::createMock(Redirect::class);
		$redirect->expects(self::once())
			->method("execute")
			->willReturn(null);

		$globalProtection = self::createStub(Protection::class);
		$serverRequest = self::createStub(ServerRequest::class);
		$serverRequest->method("getUri")
			->willReturn(self::createStub(Uri::class));
		$serverRequest->method("getHeaderLine")
			->willReturnCallback(
				fn(string $name):string => strtolower($name) === "accept" ? "*/*" : ""
			);
		$serverRequest->method("getMethod")
			->willReturn("GET");
		$requestFactory = self::createStub(RequestFactory::class);
		$requestFactory->method("createServerRequestFromGlobalState")
			->willReturn($serverRequest);
		$dispatcher = self::createStub(Dispatcher::class);

		$response = self::createStub(Response::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getHeaders')->willReturn(['Content-Type' => ['text/html']]);
		$response->method('getBody')->willReturn(new \GT\Http\Stream());
		$dispatcher->method('generateResponse')->willReturn($response);

		$dispatcherFactory = self::createStub(DispatcherFactory::class);
		$dispatcherFactory->method('create')->willReturn($dispatcher);

		// Avoid warnings by ensuring server params contain REMOTE_ADDR
		$serverRequest->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);

		$sut = new Application(
			redirect: $redirect,
			requestFactory: $requestFactory,
			dispatcherFactory: $dispatcherFactory,
			globalProtection: $globalProtection,
		);
		$sut->start();
	}

	public function testStart_callsTimerFunctions():void {
		$timer = self::createMock(Timer::class);
		$timer->expects(self::once())
			->method("start");
		$timer->expects(self::once())
			->method("stop");
		$timer->expects(self::once())
			->method("logDelta");

		$dispatcher = self::createStub(Dispatcher::class);
		$response = self::createStub(Response::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getHeaders')->willReturn(['Content-Type' => ['text/html']]);
		$response->method('getBody')->willReturn(new \GT\Http\Stream());
		$dispatcher->method('generateResponse')->willReturn($response);
		$dispatcherFactory = self::createStub(DispatcherFactory::class);
		$dispatcherFactory->method('create')->willReturn($dispatcher);

		$requestFactory = self::createStub(RequestFactory::class);
		$serverRequest = self::createStub(ServerRequest::class);
		$serverRequest->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);
		$serverRequest->method('getUri')->willReturn(self::createStub(Uri::class));
		$serverRequest->method('getHeaderLine')
			->willReturnCallback(
				fn(string $name):string => strtolower($name) === "accept" ? "*/*" : ""
			);
		$serverRequest->method('getMethod')->willReturn('GET');
		$requestFactory->method('createServerRequestFromGlobalState')->willReturn($serverRequest);

		$sut = new Application(
			timer: $timer,
			requestFactory: $requestFactory,
			dispatcherFactory: $dispatcherFactory,
		);
		$sut->start();
	}

	public function testStart_callsOutputBufferFunctions():void {
		$outputBuffer = self::createMock(OutputBuffer::class);
		$outputBuffer->expects(self::once())
			->method("start");
		$outputBuffer->expects(self::once())
			->method("debugOutput");

		$globalProtection = self::createMock(Protection::class);

		$dispatcher = self::createMock(Dispatcher::class);
		$response = self::createMock(Response::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getHeaders')->willReturn(['Content-Type' => ['text/html']]);
		$response->method('getBody')->willReturn(new \GT\Http\Stream());
		$dispatcher->method('generateResponse')->willReturn($response);
		$dispatcherFactory = self::createMock(DispatcherFactory::class);
		$dispatcherFactory->method('create')->willReturn($dispatcher);

		$requestFactory = self::createMock(RequestFactory::class);
		$serverRequest = self::createMock(ServerRequest::class);
		$serverRequest->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);
		$serverRequest->method('getUri')->willReturn(self::createMock(Uri::class));
		$serverRequest->method('getHeaderLine')->with('accept')->willReturn('*/*');
		$serverRequest->method('getMethod')->willReturn('GET');
		$requestFactory->method('createServerRequestFromGlobalState')->willReturn($serverRequest);

		$sut = new Application(
			outputBuffer: $outputBuffer,
			requestFactory: $requestFactory,
			dispatcherFactory: $dispatcherFactory,
			globalProtection: $globalProtection,
		);
		$sut->start();
		self::addToAssertionCount(1);
	}

	public function testStart_callsRequestFactoryFunctions():void {
		$requestFactory = self::createMock(RequestFactory::class);
		$requestFactory->expects(self::once())
			->method("createServerRequestFromGlobalState");

		$dispatcher = self::createMock(Dispatcher::class);
		$response = self::createMock(Response::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getHeaders')->willReturn(['Content-Type' => ['text/html']]);
		$response->method('getBody')->willReturn(new \GT\Http\Stream());
		$dispatcher->method('generateResponse')->willReturn($response);
		$dispatcherFactory = self::createMock(DispatcherFactory::class);
		$dispatcherFactory->method('create')->willReturn($dispatcher);

		$serverRequest = self::createMock(ServerRequest::class);
		$serverRequest->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);
		$serverRequest->method('getUri')->willReturn(self::createMock(Uri::class));
		$serverRequest->method('getHeaderLine')->with('accept')->willReturn('*/*');
		$serverRequest->method('getMethod')->willReturn('GET');
		$requestFactory->method('createServerRequestFromGlobalState')->willReturn($serverRequest);

		$sut = new Application(
			requestFactory: $requestFactory,
			dispatcherFactory: $dispatcherFactory,
		);
		$sut->start();
		self::addToAssertionCount(1);
	}

	/**
	 * This test is really important because it shows that all of the
	 * components that make up the request/response can be injected into the
	 * application, so everything can be meticulously tested in detail.
	 */
	public function testStart_callsDispatcherFactoryFunctions():void {
		$response = self::createMock(Response::class);
		$dispatcher = self::createMock(Dispatcher::class);

		$dispatcherFactory = self::createMock(DispatcherFactory::class);
		$dispatcherFactory->expects(self::once())
			->method("create")
			->willReturn($dispatcher);

		$dispatcher->expects(self::once())
			->method("generateResponse")
			->willReturn($response);

		$response->expects(self::once())
			->method("getStatusCode")
			->willReturn(200);
		$response->expects(self::once())
			->method("getHeaders")
			->willReturn(['Content-Type' => ['text/html']]);
		$response->expects(self::once())
			->method("getBody")
			->willReturn(new \GT\Http\Stream());

		$globalProtection = self::createMock(Protection::class);

		$sut = new Application(
			dispatcherFactory: $dispatcherFactory,
			globalProtection: $globalProtection,
		);
		$sut->start();
		self::addToAssertionCount(1);
	}

	public function testStart_callErrorScriptOnThrowable():void {
		$php = <<<PHP
		<?php
		echo "exception message is " . \$throwable->getMessage();
		PHP;

		$tmpFile = tempnam(sys_get_temp_dir(), "webengine-test-");
		file_put_contents($tmpFile, $php);

		$config = $this->createTestConfig([
			"app.error_script" => $tmpFile,
		]);

		$dispatcher = self::createMock(Dispatcher::class);

		$dispatcherFactory = self::createMock(DispatcherFactory::class);
		$dispatcherFactory->expects(self::once())
			->method("create")
			->willReturn($dispatcher);

		$sut = new Application(
			config: $config,
			dispatcherFactory: $dispatcherFactory,
		);

		$dispatcher->expects(self::once())
			->method("generateResponse")
			->willThrowException(new Exception("testing"));

		ob_start();
		$sut->start();
		$output = ob_get_clean();
		ob_end_clean();

		self::assertStringContainsString("exception message is testing", $output);
	}

	public function testStart_protectsGlobalsUsingConfiguredWhitelists():void {
		$config = $this->createTestConfig([
			"app.globals_whitelist_env" => "ENV_OK",
			"app.globals_whitelist_server" => "SERVER_OK,SERVER_OK_2",
			"app.globals_whitelist_get" => "GET_OK",
			"app.globals_whitelist_post" => "POST_OK",
			"app.globals_whitelist_files" => "FILES_OK",
			"app.globals_whitelist_cookies" => "COOKIE_OK",
		]);
		$globals = [
			"_SERVER" => ["SERVER_OK" => "a", "SERVER_OK_2" => "b"],
			"_FILES" => ["FILES_OK" => ["name" => "file.txt"]],
			"_GET" => ["GET_OK" => "search"],
			"_POST" => ["POST_OK" => "token"],
			"_ENV" => ["ENV_OK" => "1"],
			"_COOKIE" => ["COOKIE_OK" => "cookie"],
		];

		$globalProtection = self::createMock(Protection::class);
		$globalProtection->expects(self::once())
			->method("removeGlobals")
			->with(
				[
					"server" => $globals["_SERVER"],
					"files" => $globals["_FILES"],
					"get" => $globals["_GET"],
					"post" => $globals["_POST"],
					"env" => $globals["_ENV"],
					"cookie" => $globals["_COOKIE"],
				],
				[
					"_ENV" => ["ENV_OK"],
					"_SERVER" => ["SERVER_OK", "SERVER_OK_2"],
					"_GET" => ["GET_OK"],
					"_POST" => ["POST_OK"],
					"_FILES" => ["FILES_OK"],
					"_COOKIE" => ["COOKIE_OK"],
				]
			)
			->willReturn(["server" => "protected"]);
		$globalProtection->expects(self::once())
			->method("overrideInternals")
			->with(["server" => "protected"]);

		$requestFactory = self::createMock(RequestFactory::class);
		$requestFactory->expects(self::once())
			->method("createServerRequestFromGlobalState")
			->with(
				$globals["_SERVER"],
				$globals["_FILES"],
				$globals["_GET"],
				$globals["_POST"],
			)
			->willReturn($this->createServerRequest());

		$dispatcher = self::createMock(Dispatcher::class);
		$dispatcher->expects(self::once())
			->method("generateResponse")
			->willReturn($this->createResponse());

		$dispatcherFactory = self::createMock(DispatcherFactory::class);
		$dispatcherFactory->expects(self::once())
			->method("create")
			->willReturn($dispatcher);

		$sut = new Application(
			config: $config,
			requestFactory: $requestFactory,
			dispatcherFactory: $dispatcherFactory,
			globals: $globals,
			globalProtection: $globalProtection,
		);

		$sut->start();
	}

	public function testStart_rebuildsDispatcherWithErrorStatusAndSessionInit():void {
		$config = $this->createTestConfig(["app.error_script" => ""]);
		$request = $this->createServerRequest("/missing-page");
		$requestFactory = self::createStub(RequestFactory::class);
		$requestFactory->method("createServerRequestFromGlobalState")
			->willReturn($request);

		$sessionInit = self::createMock(SessionInit::class);
		$firstDispatcher = self::createMock(Dispatcher::class);
		$firstDispatcher->expects(self::once())
			->method("generateResponse")
			->willThrowException(new HttpNotFound());
		$firstDispatcher->expects(self::once())
			->method("getSessionInit")
			->willReturn($sessionInit);

		$errorResponse = $this->createResponse(404, "<body>not found</body>");
		$secondDispatcher = self::createMock(Dispatcher::class);
		$secondDispatcher->expects(self::once())
			->method("generateErrorResponse")
			->with(self::isInstanceOf(HttpNotFound::class))
			->willReturn($errorResponse);

		$dispatcherFactory = self::createMock(DispatcherFactory::class);
		$createCalls = [];
		$dispatcherFactory->expects(self::exactly(2))
			->method("create")
			->willReturnCallback(function(
				Config $passedConfig,
				ServerRequest $passedRequest,
				array $passedGlobals,
				Closure $finishCallback,
				?int $errorStatus = null,
				?SessionInit $passedSessionInit = null,
			) use ($config, $request, $sessionInit, &$createCalls, $firstDispatcher, $secondDispatcher) {
				$createCalls[] = [
					"config" => $passedConfig,
					"request" => $passedRequest,
					"errorStatus" => $errorStatus,
					"sessionInit" => $passedSessionInit,
					"globalsKeys" => array_keys($passedGlobals),
					"finishCallback" => $finishCallback,
				];

				return count($createCalls) === 1
					? $firstDispatcher
					: $secondDispatcher;
			});

		$sut = new Application(
			config: $config,
			requestFactory: $requestFactory,
			dispatcherFactory: $dispatcherFactory,
			globalProtection: self::createStub(Protection::class),
		);

		$sut->start();

		self::assertSame($config, $createCalls[0]["config"]);
		self::assertSame($request, $createCalls[0]["request"]);
		self::assertNull($createCalls[0]["errorStatus"]);
		self::assertNull($createCalls[0]["sessionInit"]);
		self::assertSame(["_SERVER", "_FILES", "_GET", "_POST", "_ENV", "_COOKIE"], $createCalls[0]["globalsKeys"]);
		self::assertSame($config, $createCalls[1]["config"]);
		self::assertSame($request, $createCalls[1]["request"]);
		self::assertSame(404, $createCalls[1]["errorStatus"]);
		self::assertSame($sessionInit, $createCalls[1]["sessionInit"]);
	}

	public function testStart_returnsNotModifiedWithoutErrorDispatcher():void {
		$this->resetApplicationLoggerState();
		LogConfig::addHandler(new TestLogHandler());
		$this->setApplicationLoggerConfigured(true);

		$config = $this->createTestConfig(["app.error_script" => ""]);
		$requestFactory = self::createStub(RequestFactory::class);
		$requestFactory->method("createServerRequestFromGlobalState")
			->willReturn($this->createServerRequest("/live"));

		$dispatcher = self::createMock(Dispatcher::class);
		$dispatcher->expects(self::once())
			->method("generateResponse")
			->willThrowException(new HttpNotModified());
		$dispatcher->expects(self::never())
			->method("generateErrorResponse");

		$dispatcherFactory = self::createMock(DispatcherFactory::class);
		$dispatcherFactory->expects(self::once())
			->method("create")
			->willReturn($dispatcher);

		$sut = new Application(
			config: $config,
			requestFactory: $requestFactory,
			dispatcherFactory: $dispatcherFactory,
			globalProtection: self::createStub(Protection::class),
		);

		$sut->start();

		self::assertCount(0, TestLogHandler::$records);
	}

	public function testHandleThrowable_returnsNotModifiedResponseWithoutLoggingError():void {
		$this->resetApplicationLoggerState();
		LogConfig::addHandler(new TestLogHandler());
		$this->setApplicationLoggerConfigured(true);

		$request = $this->createServerRequest("/cached");
		$sut = new Application(
			config: $this->createTestConfig(["app.error_script" => ""]),
			requestFactory: $this->createRequestFactory($request),
			dispatcherFactory: self::createStub(DispatcherFactory::class),
			globalProtection: self::createStub(Protection::class),
		);
		$this->setPrivateProperty($sut, "request", $request);

		$response = $this->invokePrivateMethod($sut, "handleThrowable", new HttpNotModified());

		self::assertInstanceOf(Response::class, $response);
		self::assertSame(StatusCode::NOT_MODIFIED, $response->getStatusCode());
		self::assertSame([], TestLogHandler::$records);
	}

	public function testHandleThrowable_runsErrorScriptWithRestoredGlobalsAndReturnsNull():void {
		$php = <<<'PHP'
		<?php
		echo $_POST["message"] . "|" . $GLOBALS["POST"]["message"];
		PHP;

		$tmpFile = tempnam(sys_get_temp_dir(), "webengine-test-");
		file_put_contents($tmpFile, $php);

		$sut = new Application(
			config: $this->createTestConfig([
				"app.error_script" => $tmpFile,
			]),
			requestFactory: $this->createRequestFactory(),
			dispatcherFactory: self::createStub(DispatcherFactory::class),
			globalProtection: self::createStub(Protection::class),
			globals: [
				"_SERVER" => [],
				"_FILES" => [],
				"_GET" => [],
				"_POST" => ["message" => "restored"],
				"_ENV" => [],
				"_COOKIE" => [],
			],
		);

		$_POST = ["message" => "wrong"];
		$GLOBALS["POST"] = ["message" => "wrong"];

		ob_start();
		$response = $this->invokePrivateMethod($sut, "handleThrowable", new Exception("boom"));
		$output = ob_get_clean();
		unlink($tmpFile);

		self::assertNull($response);
		self::assertSame("restored|restored", $output);
	}

	public function testHandleThrowable_rebuildsDispatcherAndReturnsGeneratedErrorResponse():void {
		$this->resetApplicationLoggerState();
		LogConfig::addHandler(new TestLogHandler());
		$this->setApplicationLoggerConfigured(true);

		$config = $this->createTestConfig(["app.error_script" => ""]);
		$request = $this->createServerRequest("/broken");
		$sessionInit = self::createStub(SessionInit::class);
		$throwable = new Exception("page failed");
		$errorResponse = $this->createResponse(500, "<body>error</body>");

		$firstDispatcher = self::createMock(Dispatcher::class);
		$firstDispatcher->expects(self::once())
			->method("getSessionInit")
			->willReturn($sessionInit);

		$secondDispatcher = self::createMock(Dispatcher::class);
		$secondDispatcher->expects(self::once())
			->method("generateErrorResponse")
			->with(self::identicalTo($throwable))
			->willReturn($errorResponse);

		$dispatcherFactory = self::createMock(DispatcherFactory::class);
		$dispatcherFactory->expects(self::once())
			->method("create")
			->with(
				self::identicalTo($config),
				self::identicalTo($request),
				self::isArray(),
				self::isInstanceOf(Closure::class),
				500,
				self::identicalTo($sessionInit),
			)
			->willReturn($secondDispatcher);

		$sut = new Application(
			config: $config,
			requestFactory: $this->createRequestFactory($request),
			dispatcherFactory: $dispatcherFactory,
			globalProtection: self::createStub(Protection::class),
		);
		$this->setPrivateProperty($sut, "request", $request);
		$this->setPrivateProperty($sut, "dispatcher", $firstDispatcher);

		$response = $this->invokePrivateMethod($sut, "handleThrowable", $throwable);

		self::assertSame($errorResponse, $response);
		self::assertCount(1, TestLogHandler::$records);
		self::assertStringContainsString("page failed", TestLogHandler::$records[0]["message"]);
	}

	public function testHandleThrowable_fallsBackToBasicErrorResponseWhenErrorResponseFails():void {
		$this->resetApplicationLoggerState();
		LogConfig::addHandler(new TestLogHandler());
		$this->setApplicationLoggerConfigured(true);

		$request = $this->createServerRequest("/broken-error");
		$throwable = new Exception("page failed");
		$innerThrowable = new Exception("error page failed");
		$fallbackResponse = $this->createResponse(500, "<body>fallback</body>");

		$firstDispatcher = self::createMock(Dispatcher::class);
		$firstDispatcher->expects(self::once())
			->method("getSessionInit")
			->willReturn(null);

		$secondDispatcher = self::createMock(Dispatcher::class);
		$secondDispatcher->expects(self::once())
			->method("generateErrorResponse")
			->with(self::identicalTo($throwable))
			->willThrowException($innerThrowable);
		$secondDispatcher->expects(self::once())
			->method("generateBasicErrorResponse")
			->with(
				self::identicalTo($throwable),
				self::identicalTo($innerThrowable),
			)
			->willReturn($fallbackResponse);

		$dispatcherFactory = self::createStub(DispatcherFactory::class);
		$dispatcherFactory->method("create")
			->willReturn($secondDispatcher);

		$sut = new Application(
			config: $this->createTestConfig(["app.error_script" => ""]),
			requestFactory: $this->createRequestFactory($request),
			dispatcherFactory: $dispatcherFactory,
			globalProtection: self::createStub(Protection::class),
		);
		$this->setPrivateProperty($sut, "request", $request);
		$this->setPrivateProperty($sut, "dispatcher", $firstDispatcher);

		$response = $this->invokePrivateMethod($sut, "handleThrowable", $throwable);

		self::assertSame($fallbackResponse, $response);
		self::assertCount(2, TestLogHandler::$records);
		self::assertStringContainsString("Failed to render framework error response", TestLogHandler::$records[1]["message"]);
		self::assertSame("Exception", TestLogHandler::$records[1]["context"]["original_error"]);
		self::assertSame("/broken-error", TestLogHandler::$records[1]["context"]["uri"]);
	}

	public function testStart_fallsBackToBasicErrorResponseWhenErrorPageRenderingFails():void {
		$this->resetApplicationLoggerState();
		LogConfig::addHandler(new TestLogHandler());
		$this->setApplicationLoggerConfigured(true);

		$config = $this->createTestConfig([
			"app.error_script" => "",
			"logger.log_all_requests" => false,
		]);
		$requestFactory = self::createStub(RequestFactory::class);
		$requestFactory->method("createServerRequestFromGlobalState")
			->willReturn($this->createServerRequest("/broken-error"));

		$originalThrowable = new Exception("page failed");
		$innerThrowable = new Exception("error page failed");
		$fallbackResponse = $this->createResponse(500, "<body>fallback</body>");

		$firstDispatcher = self::createMock(Dispatcher::class);
		$firstDispatcher->expects(self::once())
			->method("generateResponse")
			->willThrowException($originalThrowable);
		$firstDispatcher->method("getSessionInit")
			->willReturn(null);

		$secondDispatcher = self::createMock(Dispatcher::class);
		$secondDispatcher->expects(self::once())
			->method("generateErrorResponse")
			->with(self::identicalTo($originalThrowable))
			->willThrowException($innerThrowable);
		$secondDispatcher->expects(self::once())
			->method("generateBasicErrorResponse")
			->with(
				self::identicalTo($originalThrowable),
				self::identicalTo($innerThrowable),
			)
			->willReturn($fallbackResponse);

		$dispatcherFactory = self::createMock(DispatcherFactory::class);
		$dispatcherFactory->expects(self::exactly(2))
			->method("create")
			->willReturnOnConsecutiveCalls($firstDispatcher, $secondDispatcher);

		$sut = new Application(
			config: $config,
			requestFactory: $requestFactory,
			dispatcherFactory: $dispatcherFactory,
			globalProtection: self::createStub(Protection::class),
		);

		ob_start();
		$sut->start();
		ob_get_clean();

		self::assertCount(2, TestLogHandler::$records);
		self::assertSame("ERROR", TestLogHandler::$records[0]["level"]);
		self::assertStringContainsString("page failed", TestLogHandler::$records[0]["message"]);
		self::assertSame("ERROR", TestLogHandler::$records[1]["level"]);
		self::assertStringContainsString("Failed to render framework error response", TestLogHandler::$records[1]["message"]);
		self::assertSame("Exception", TestLogHandler::$records[1]["context"]["original_error"]);
		self::assertSame("/broken-error", TestLogHandler::$records[1]["context"]["uri"]);
	}

	public function testConstructor_logsRedirectConfigurationErrors():void {
		$this->resetApplicationLoggerState();
		LogConfig::addHandler(new TestLogHandler());
		$this->setApplicationLoggerConfigured(true);

		$cwd = getcwd();
		$tmpDir = sys_get_temp_dir() . "/webengine-redirect-" . uniqid();
		mkdir($tmpDir);
		file_put_contents($tmpDir . "/redirect.ini", "/from=/to");
		file_put_contents($tmpDir . "/redirect.csv", "/from,/to\n");
		chdir($tmpDir);

		try {
			new Application(
				config: $this->createTestConfig([]),
				requestFactory: $this->createRequestFactory(),
				dispatcherFactory: self::createStub(DispatcherFactory::class),
				globalProtection: self::createStub(Protection::class),
			);
			self::fail("Expected invalid redirect configuration to throw.");
		}
		catch(\GT\WebEngine\Redirection\RedirectException $exception) {
			self::assertSame("Multiple redirect files in project root", $exception->getMessage());
		}
		finally {
			chdir($cwd);
			unlink($tmpDir . "/redirect.ini");
			unlink($tmpDir . "/redirect.csv");
			rmdir($tmpDir);
		}

		self::assertCount(1, TestLogHandler::$records);
		self::assertSame("ERROR", TestLogHandler::$records[0]["level"]);
		self::assertStringContainsString("Redirect configuration error", TestLogHandler::$records[0]["message"]);
	}

	public function testStart_restoresGlobalsBeforeExecutingErrorScript():void {
		$php = <<<'PHP'
		<?php
		echo $_GET["restored"] . "|" . $_SERVER["RESTORED"] . "|" . $GLOBALS["GET"]["restored"];
		PHP;

		$tmpFile = tempnam(sys_get_temp_dir(), "webengine-test-");
		file_put_contents($tmpFile, $php);

		$config = $this->createTestConfig([
			"app.error_script" => $tmpFile,
		]);
		$globals = [
			"_SERVER" => ["RESTORED" => "server"],
			"_FILES" => [],
			"_GET" => ["restored" => "query"],
			"_POST" => [],
			"_ENV" => [],
			"_COOKIE" => [],
		];

		$_GET = ["restored" => "wrong"];
		$_SERVER["RESTORED"] = "wrong";
		$GLOBALS["GET"] = ["restored" => "wrong"];

		$dispatcher = self::createMock(Dispatcher::class);
		$dispatcher->expects(self::once())
			->method("generateResponse")
			->willThrowException(new Exception("testing"));

		$dispatcherFactory = self::createMock(DispatcherFactory::class);
		$dispatcherFactory->expects(self::once())
			->method("create")
			->willReturn($dispatcher);

		$sut = new Application(
			config: $config,
			dispatcherFactory: $dispatcherFactory,
			requestFactory: $this->createRequestFactory(),
			globals: $globals,
		);

		$initialOutputBufferLevel = ob_get_level();
		ob_start();
		$sut->start();
		$output = "";
		while(ob_get_level() > $initialOutputBufferLevel) {
			$output = ob_get_clean() . $output;
		}

		self::assertSame("query|server|query", $output);
	}

	public function testRestoreGlobals_restoresSuperglobalAliases():void {
		$globals = [
			"_SERVER" => ["RESTORED" => "server"],
			"_FILES" => ["upload" => ["name" => "file.txt"]],
			"_GET" => ["query" => "value"],
			"_POST" => ["token" => "abc"],
			"_ENV" => ["APP_ENV" => "test"],
			"_COOKIE" => ["session" => "cookie"],
		];

		$sut = new Application(
			config: $this->createTestConfig([]),
			requestFactory: $this->createRequestFactory(),
			dispatcherFactory: self::createStub(DispatcherFactory::class),
			globals: $globals,
			globalProtection: self::createStub(Protection::class),
		);

		$_GET = [];
		$_POST = [];
		$_SERVER = [];
		$_COOKIE = [];
		$_FILES = [];
		$_ENV = [];
		$GLOBALS["GET"] = [];
		$GLOBALS["POST"] = [];
		$GLOBALS["SERVER"] = [];
		$GLOBALS["COOKIE"] = [];
		$GLOBALS["FILES"] = [];
		$GLOBALS["ENV"] = [];

		$sut->restoreGlobals();

		self::assertSame($globals["_GET"], $_GET);
		self::assertSame($globals["_POST"], $_POST);
		self::assertSame($globals["_SERVER"], $_SERVER);
		self::assertSame($globals["_COOKIE"], $_COOKIE);
		self::assertSame($globals["_FILES"], $_FILES);
		self::assertSame($globals["_ENV"], $_ENV);
		self::assertSame($globals["_GET"], $GLOBALS["GET"]);
		self::assertSame($globals["_POST"], $GLOBALS["POST"]);
		self::assertSame($globals["_SERVER"], $GLOBALS["SERVER"]);
		self::assertSame($globals["_COOKIE"], $GLOBALS["COOKIE"]);
		self::assertSame($globals["_FILES"], $GLOBALS["FILES"]);
		self::assertSame($globals["_ENV"], $GLOBALS["ENV"]);
	}

	public function testFinish_injectsDebugScriptAndRunsOnlyOnce():void {
		$config = $this->createTestConfig([
			"logger.log_all_requests" => false,
			"app.render_buffer_size" => 8192,
		]);
		$timer = self::createMock(Timer::class);
		$timer->expects(self::once())->method("stop");
		$timer->expects(self::once())->method("logDelta");
		$outputBuffer = self::createMock(OutputBuffer::class);
		$outputBuffer->expects(self::once())
			->method("debugOutput")
			->willReturn("<script>debug()</script>");

		$stream = new Stream();
		$stream->write("<html><body>Hello</body></html>");

		$response = self::createMock(Response::class);
		$response->expects(self::atLeastOnce())->method("getStatusCode")->willReturn(200);
		$response->expects(self::once())->method("getHeaders")->willReturn([]);
		$response->expects(self::once())->method("getBody")->willReturn($stream);

		$sut = new Application(
			config: $config,
			timer: $timer,
			outputBuffer: $outputBuffer,
			requestFactory: $this->createRequestFactory(),
			dispatcherFactory: self::createStub(DispatcherFactory::class),
			globalProtection: self::createStub(Protection::class),
		);

		ob_start();
		$this->invokePrivateMethod($sut, "finish", $response);
		$this->invokePrivateMethod($sut, "finish", $response);
		$output = ob_get_clean();

		self::assertSame("<html><body>Hello<script>debug()</script></body></html>", $output);
	}

	public function testBuildLogContext_omitsObjectParsedBodyAndKeepsQueryContext():void {
		$sut = new Application(
			config: $this->createTestConfig([]),
			requestFactory: $this->createRequestFactory(),
			dispatcherFactory: self::createStub(DispatcherFactory::class),
			globalProtection: self::createStub(Protection::class),
		);

		$context = $this->invokePrivateMethod(
			$sut,
			"buildLogContext",
			"/api",
			["page" => "2"],
			(object)["token" => "should-not-be-logged"],
			"10.0.0.5",
		);

		self::assertSame("10.0.0.5:", $context["id"]);
		self::assertSame("/api", $context["uri"]);
		self::assertSame(["page" => "2"], $context["query"]);
		self::assertArrayNotHasKey("post", $context);
	}

	public function testBuildLogContext_filtersConfiguredCsrfTokenNameOnly():void {
		$sut = new Application(
			config: $this->createTestConfig([]),
			requestFactory: $this->createRequestFactory(),
			dispatcherFactory: self::createStub(DispatcherFactory::class),
			globalProtection: self::createStub(Protection::class),
		);

		$context = $this->invokePrivateMethod(
			$sut,
			"buildLogContext",
			"/send",
			[],
			[
				HTMLDocumentProtector::TOKEN_NAME => "CSRF_secret",
				"token" => "business-token",
			],
		);

		self::assertSame(["token" => "business-token"], $context["post"]);
	}

	public function testStart_logsAllRequestsWithRequestContext():void {
		$this->resetApplicationLoggerState();
		TestLogHandler::$records = [];
		LogConfig::addHandler(new TestLogHandler());
		$this->setApplicationLoggerConfigured(true);

		$config = $this->createTestConfig([
			"logger.log_all_requests" => true,
		]);
		$request = $this->createServerRequest(
			"/search",
			["q" => "php"],
			["token" => "abc"],
			["REMOTE_ADDR" => "127.0.0.1"],
		);
		$requestFactory = self::createStub(RequestFactory::class);
		$requestFactory->method("createServerRequestFromGlobalState")
			->willReturn($request);

		$dispatcher = self::createMock(Dispatcher::class);
		$dispatcher->expects(self::once())
			->method("generateResponse")
			->willReturn($this->createResponse(204));

		$dispatcherFactory = self::createStub(DispatcherFactory::class);
		$dispatcherFactory->method("create")
			->willReturn($dispatcher);

		$sut = new Application(
			config: $config,
			requestFactory: $requestFactory,
			dispatcherFactory: $dispatcherFactory,
			globalProtection: self::createStub(Protection::class),
		);

		$sut->start();

		self::assertCount(1, TestLogHandler::$records);
		self::assertSame("INFO", TestLogHandler::$records[0]["level"]);
		self::assertSame("HTTP 204", TestLogHandler::$records[0]["message"]);
		self::assertSame("/search", TestLogHandler::$records[0]["context"]["uri"]);
		self::assertSame(["q" => "php"], TestLogHandler::$records[0]["context"]["query"]);
		self::assertSame(["token" => "abc"], TestLogHandler::$records[0]["context"]["post"]);
		self::assertSame("127.0.0.1:", TestLogHandler::$records[0]["context"]["id"]);
	}

	public function testStart_filtersCsrfTokenFromLoggedPostContext():void {
		$this->resetApplicationLoggerState();
		TestLogHandler::$records = [];
		LogConfig::addHandler(new TestLogHandler());
		$this->setApplicationLoggerConfigured(true);

		$config = $this->createTestConfig([
			"logger.log_all_requests" => true,
		]);
		$request = $this->createServerRequest(
			"/send",
			post: [
				"message" => "Hello",
				"csrf-token" => "CSRF_secret",
				"do" => "send",
			],
		);
		$requestFactory = self::createStub(RequestFactory::class);
		$requestFactory->method("createServerRequestFromGlobalState")
			->willReturn($request);

		$dispatcher = self::createMock(Dispatcher::class);
		$dispatcher->expects(self::once())
			->method("generateResponse")
			->willReturn($this->createResponse(204));

		$dispatcherFactory = self::createStub(DispatcherFactory::class);
		$dispatcherFactory->method("create")
			->willReturn($dispatcher);

		$sut = new Application(
			config: $config,
			requestFactory: $requestFactory,
			dispatcherFactory: $dispatcherFactory,
			globalProtection: self::createStub(Protection::class),
		);

		$sut->start();

		self::assertSame([
			"message" => "Hello",
			"do" => "send",
		], TestLogHandler::$records[0]["context"]["post"]);
	}

	public function testStart_doesNotLogNotModifiedByDefault():void {
		$this->resetApplicationLoggerState();
		TestLogHandler::$records = [];
		LogConfig::addHandler(new TestLogHandler());
		$this->setApplicationLoggerConfigured(true);

		$config = $this->createTestConfig([
			"logger.log_all_requests" => true,
			"logger.log_not_modified" => false,
		]);
		$requestFactory = self::createStub(RequestFactory::class);
		$requestFactory->method("createServerRequestFromGlobalState")
			->willReturn($this->createServerRequest("/live"));

		$dispatcher = self::createMock(Dispatcher::class);
		$dispatcher->expects(self::once())
			->method("generateResponse")
			->willReturn($this->createResponse(StatusCode::NOT_MODIFIED));

		$dispatcherFactory = self::createStub(DispatcherFactory::class);
		$dispatcherFactory->method("create")
			->willReturn($dispatcher);

		$sut = new Application(
			config: $config,
			requestFactory: $requestFactory,
			dispatcherFactory: $dispatcherFactory,
			globalProtection: self::createStub(Protection::class),
		);

		$sut->start();

		self::assertSame([], TestLogHandler::$records);
	}

	public function testStart_logsNotModifiedWhenConfigured():void {
		$this->resetApplicationLoggerState();
		TestLogHandler::$records = [];
		LogConfig::addHandler(new TestLogHandler());
		$this->setApplicationLoggerConfigured(true);

		$config = $this->createTestConfig([
			"logger.log_all_requests" => true,
			"logger.log_not_modified" => true,
		]);
		$requestFactory = self::createStub(RequestFactory::class);
		$requestFactory->method("createServerRequestFromGlobalState")
			->willReturn($this->createServerRequest("/live"));

		$dispatcher = self::createMock(Dispatcher::class);
		$dispatcher->expects(self::once())
			->method("generateResponse")
			->willReturn($this->createResponse(StatusCode::NOT_MODIFIED));

		$dispatcherFactory = self::createStub(DispatcherFactory::class);
		$dispatcherFactory->method("create")
			->willReturn($dispatcher);

		$sut = new Application(
			config: $config,
			requestFactory: $requestFactory,
			dispatcherFactory: $dispatcherFactory,
			globalProtection: self::createStub(Protection::class),
		);

		$sut->start();

		self::assertCount(1, TestLogHandler::$records);
		self::assertSame("HTTP 304", TestLogHandler::$records[0]["message"]);
		self::assertSame("/live", TestLogHandler::$records[0]["context"]["uri"]);
	}

	public function testStart_logsSlowRequestsAsDebugWithRequestContext():void {
		$this->resetApplicationLoggerState();
		TestLogHandler::$records = [];
		LogConfig::addHandler(new TestLogHandler());
		$this->setApplicationLoggerConfigured(true);

		$config = $this->createTestConfig([
			"logger.log_all_requests" => false,
			"app.slow_delta" => -1,
			"app.very_slow_delta" => -1,
		]);
		$request = $this->createServerRequest(
			"/slow",
			["page" => "1"],
			[],
			["REMOTE_ADDR" => "127.0.0.1"],
		);
		$requestFactory = self::createStub(RequestFactory::class);
		$requestFactory->method("createServerRequestFromGlobalState")
			->willReturn($request);

		$dispatcher = self::createMock(Dispatcher::class);
		$dispatcher->expects(self::once())
			->method("generateResponse")
			->willReturn($this->createResponse(200));

		$dispatcherFactory = self::createStub(DispatcherFactory::class);
		$dispatcherFactory->method("create")
			->willReturn($dispatcher);

		$sut = new Application(
			config: $config,
			requestFactory: $requestFactory,
			dispatcherFactory: $dispatcherFactory,
			globalProtection: self::createStub(Protection::class),
		);

		$sut->start();

		self::assertCount(1, TestLogHandler::$records);
		self::assertSame("NOTICE", TestLogHandler::$records[0]["level"]);
		self::assertStringContainsString("VERY SLOW", TestLogHandler::$records[0]["message"]);
		self::assertSame("/slow", TestLogHandler::$records[0]["context"]["uri"]);
		self::assertSame(["page" => "1"], TestLogHandler::$records[0]["context"]["query"]);
		self::assertSame("127.0.0.1:", TestLogHandler::$records[0]["context"]["id"]);
	}

	public function testStart_doesNotLogRedirectResponsesWhenLogRedirectsIsFalse():void {
		$this->resetApplicationLoggerState();
		TestLogHandler::$records = [];
		LogConfig::addHandler(new TestLogHandler());
		$this->setApplicationLoggerConfigured(true);

		$config = $this->createTestConfig([
			"logger.log_all_requests" => false,
			"logger.log_redirects" => false,
		]);
		$request = $this->createServerRequest("/redirect-me");
		$requestFactory = self::createStub(RequestFactory::class);
		$requestFactory->method("createServerRequestFromGlobalState")
			->willReturn($request);

		$dispatcher = self::createMock(Dispatcher::class);
		$dispatcher->expects(self::once())
			->method("generateResponse")
			->willReturn($this->createResponse(303, headers: ["Location" => ["https://example.test/redirect-me/"]]));

		$dispatcherFactory = self::createStub(DispatcherFactory::class);
		$dispatcherFactory->method("create")
			->willReturn($dispatcher);

		$sut = new Application(
			config: $config,
			requestFactory: $requestFactory,
			dispatcherFactory: $dispatcherFactory,
			globalProtection: self::createStub(Protection::class),
		);

		$sut->start();

		self::assertSame([], TestLogHandler::$records);
	}

	public function testStart_logsRedirectResponsesWhenLogRedirectsIsTrue():void {
		$this->resetApplicationLoggerState();
		TestLogHandler::$records = [];
		LogConfig::addHandler(new TestLogHandler());
		$this->setApplicationLoggerConfigured(true);

		$config = $this->createTestConfig([
			"logger.log_all_requests" => false,
			"logger.log_redirects" => true,
		]);
		$request = $this->createServerRequest("/redirect-me");
		$requestFactory = self::createStub(RequestFactory::class);
		$requestFactory->method("createServerRequestFromGlobalState")
			->willReturn($request);

		$dispatcher = self::createMock(Dispatcher::class);
		$dispatcher->expects(self::once())
			->method("generateResponse")
			->willReturn($this->createResponse(303, headers: ["Location" => ["https://example.test/redirect-me/"]]));

		$dispatcherFactory = self::createStub(DispatcherFactory::class);
		$dispatcherFactory->method("create")
			->willReturn($dispatcher);

		$sut = new Application(
			config: $config,
			requestFactory: $requestFactory,
			dispatcherFactory: $dispatcherFactory,
			globalProtection: self::createStub(Protection::class),
		);

		$sut->start();

		self::assertCount(1, TestLogHandler::$records);
		self::assertSame("INFO", TestLogHandler::$records[0]["level"]);
		self::assertSame("HTTP 303", TestLogHandler::$records[0]["message"]);
		self::assertSame("/redirect-me", TestLogHandler::$records[0]["context"]["uri"]);
		self::assertSame("https://example.test/redirect-me/", TestLogHandler::$records[0]["context"]["location"]);
	}

	public function testStart_logsRedirectResponsesWhenLogAllRequestsIsTrue():void {
		$this->resetApplicationLoggerState();
		TestLogHandler::$records = [];
		LogConfig::addHandler(new TestLogHandler());
		$this->setApplicationLoggerConfigured(true);

		$config = $this->createTestConfig([
			"logger.log_all_requests" => true,
			"logger.log_redirects" => false,
		]);
		$request = $this->createServerRequest(
			"/message",
			post: [
				"message" => "Hello from Flux",
				"csrf-token" => "CSRF_secret",
				"do" => "send",
			],
		);
		$requestFactory = self::createStub(RequestFactory::class);
		$requestFactory->method("createServerRequestFromGlobalState")
			->willReturn($request);

		$dispatcher = self::createMock(Dispatcher::class);
		$dispatcher->expects(self::once())
			->method("generateResponse")
			->willReturn($this->createResponse(303, headers: ["Location" => ["https://example.test/message/"]]));

		$dispatcherFactory = self::createStub(DispatcherFactory::class);
		$dispatcherFactory->method("create")
			->willReturn($dispatcher);

		$sut = new Application(
			config: $config,
			requestFactory: $requestFactory,
			dispatcherFactory: $dispatcherFactory,
			globalProtection: self::createStub(Protection::class),
		);

		$sut->start();

		self::assertCount(1, TestLogHandler::$records);
		self::assertSame("HTTP 303", TestLogHandler::$records[0]["message"]);
		self::assertSame("/message", TestLogHandler::$records[0]["context"]["uri"]);
		self::assertSame([
			"message" => "Hello from Flux",
			"do" => "send",
		], TestLogHandler::$records[0]["context"]["post"]);
		self::assertSame("https://example.test/message/", TestLogHandler::$records[0]["context"]["location"]);
	}

	public function testStart_logsPreDispatchRedirectsWhenLogRedirectsIsTrue():void {
		$this->resetApplicationLoggerState();
		TestLogHandler::$records = [];
		LogConfig::addHandler(new TestLogHandler());
		$this->setApplicationLoggerConfigured(true);

		$redirect = self::createMock(Redirect::class);
		$redirect->expects(self::once())
			->method("execute")
			->with("/legacy")
			->willReturn(new RedirectUri("/new-home", 301));

		$sut = new Application(
			redirect: $redirect,
			config: $this->createTestConfig([
				"logger.log_redirects" => true,
			]),
			requestFactory: $this->createRequestFactory(),
			dispatcherFactory: self::createStub(DispatcherFactory::class),
			globalProtection: self::createStub(Protection::class),
			globals: [
				"_SERVER" => [
					"REQUEST_URI" => "/legacy",
					"REMOTE_ADDR" => "127.0.0.1",
				],
				"_FILES" => [],
				"_GET" => [],
				"_POST" => [],
				"_ENV" => [],
				"_COOKIE" => [],
			],
		);

		$sut->start();

		self::assertCount(1, TestLogHandler::$records);
		self::assertSame("INFO", TestLogHandler::$records[0]["level"]);
		self::assertSame("HTTP 301", TestLogHandler::$records[0]["message"]);
		self::assertSame("/legacy", TestLogHandler::$records[0]["context"]["uri"]);
		self::assertSame("/new-home", TestLogHandler::$records[0]["context"]["location"]);
	}

	public function testStart_matchesPreDispatchRedirectsUsingDecodedPathWithoutQuery():void {
		$this->resetApplicationLoggerState();
		TestLogHandler::$records = [];
		LogConfig::addHandler(new TestLogHandler());
		$this->setApplicationLoggerConfigured(true);

		$redirect = self::createMock(Redirect::class);
		$redirect->expects(self::once())
			->method("execute")
			->with("/old page")
			->willReturn(new RedirectUri("/new-page", 302));

		$dispatcherFactory = self::createMock(DispatcherFactory::class);
		$dispatcherFactory->expects(self::never())
			->method("create");

		$sut = new Application(
			redirect: $redirect,
			config: $this->createTestConfig([
				"logger.log_redirects" => true,
			]),
			requestFactory: $this->createRequestFactory(),
			dispatcherFactory: $dispatcherFactory,
			globalProtection: self::createStub(Protection::class),
			globals: [
				"_SERVER" => [
					"REQUEST_URI" => "/old%20page?ignored=1",
					"REMOTE_ADDR" => "127.0.0.1",
				],
				"_FILES" => [],
				"_GET" => ["ignored" => "1"],
				"_POST" => [HTMLDocumentProtector::TOKEN_NAME => "CSRF_secret"],
				"_ENV" => [],
				"_COOKIE" => [],
			],
		);

		$sut->start();

		self::assertCount(1, TestLogHandler::$records);
		self::assertSame("/old page", TestLogHandler::$records[0]["context"]["uri"]);
		self::assertSame(["ignored" => "1"], TestLogHandler::$records[0]["context"]["query"]);
		self::assertArrayNotHasKey("post", TestLogHandler::$records[0]["context"]);
		self::assertSame("/new-page", TestLogHandler::$records[0]["context"]["location"]);
	}

	public function testGetRequestedPath_defaultsToSlashWhenRequestUriHasNoPath():void {
		$sut = new Application(
			config: $this->createTestConfig([]),
			requestFactory: $this->createRequestFactory(),
			dispatcherFactory: self::createStub(DispatcherFactory::class),
			globalProtection: self::createStub(Protection::class),
			globals: [
				"_SERVER" => ["REQUEST_URI" => "?only=query"],
				"_FILES" => [],
				"_GET" => [],
				"_POST" => [],
				"_ENV" => [],
				"_COOKIE" => [],
			],
		);

		self::assertSame("/", $this->invokePrivateMethod($sut, "getRequestedPath"));
	}

	public function testLogRedirect_doesNothingWhenRedirectLoggingDisabled():void {
		$this->resetApplicationLoggerState();
		LogConfig::addHandler(new TestLogHandler());
		$this->setApplicationLoggerConfigured(true);

		$sut = new Application(
			config: $this->createTestConfig([
				"logger.log_redirects" => false,
			]),
			requestFactory: $this->createRequestFactory(),
			dispatcherFactory: self::createStub(DispatcherFactory::class),
			globalProtection: self::createStub(Protection::class),
		);

		$this->invokePrivateMethod($sut, "logRedirect", new RedirectUri("/new", 302), "/old");

		self::assertSame([], TestLogHandler::$records);
	}

	public function testShouldLogRequest_doesNotLogNotFoundEvenWhenAllRequestsEnabled():void {
		$sut = new Application(
			config: $this->createTestConfig([
				"logger.log_all_requests" => true,
			]),
			requestFactory: $this->createRequestFactory(),
			dispatcherFactory: self::createStub(DispatcherFactory::class),
			globalProtection: self::createStub(Protection::class),
		);

		$shouldLog = $this->invokePrivateMethod($sut, "shouldLogRequest", $this->createResponse(404));

		self::assertFalse($shouldLog);
	}

	public function testLogError_doesNotLogClientErrors():void {
		$this->resetApplicationLoggerState();
		LogConfig::addHandler(new TestLogHandler());
		$this->setApplicationLoggerConfigured(true);

		$sut = new Application(
			config: $this->createTestConfig([]),
			requestFactory: $this->createRequestFactory(),
			dispatcherFactory: self::createStub(DispatcherFactory::class),
			globalProtection: self::createStub(Protection::class),
		);

		$this->invokePrivateMethod($sut, "logError", new HttpNotFound());

		self::assertSame([], TestLogHandler::$records);
	}

	public function testLogError_logs404WhenConfigured():void {
		$this->resetApplicationLoggerState();
		LogConfig::addHandler(new TestLogHandler());
		$this->setApplicationLoggerConfigured(true);

		$request = $this->createServerRequest(
			"/missing-page",
			[],
			[],
			["REMOTE_ADDR" => "127.0.0.1"],
		);
		$requestFactory = self::createStub(RequestFactory::class);
		$requestFactory->method("createServerRequestFromGlobalState")
			->willReturn($request);

		$sut = new Application(
			config: $this->createTestConfig([
				"logger.log_404_to_error_log" => true,
			]),
			requestFactory: $requestFactory,
			dispatcherFactory: self::createStub(DispatcherFactory::class),
			globalProtection: self::createStub(Protection::class),
		);
		$this->setPrivateProperty($sut, "request", $request);

		$this->invokePrivateMethod($sut, "logError", new HttpNotFound());

		self::assertCount(1, TestLogHandler::$records);
		self::assertSame("ERROR", TestLogHandler::$records[0]["level"]);
		self::assertSame("HTTP 404", TestLogHandler::$records[0]["message"]);
		self::assertSame("/missing-page", TestLogHandler::$records[0]["context"]["uri"]);
	}

	public function testLogError_usesConfiguredLoggerHandlers():void {
		$this->resetApplicationLoggerState();
		LogConfig::addHandler(new TestLogHandler());
		$this->setApplicationLoggerConfigured(true);

		$sut = new Application(
			config: $this->createTestConfig([]),
			requestFactory: $this->createRequestFactory(),
			dispatcherFactory: self::createStub(DispatcherFactory::class),
			globalProtection: self::createStub(Protection::class),
		);

		$this->invokePrivateMethod($sut, "logError", new Exception("boom"));

		self::assertCount(1, TestLogHandler::$records);
		self::assertSame("ERROR", TestLogHandler::$records[0]["level"]);
		self::assertStringContainsString("boom", TestLogHandler::$records[0]["message"]);
	}

	public function testGetStderrMinimumLogLevel_fallsBackToErrorForInvalidConfig():void {
		$sut = new Application(
			config: $this->createTestConfig([
				"logger.stderr_level" => "banana",
			]),
			requestFactory: $this->createRequestFactory(),
			dispatcherFactory: self::createStub(DispatcherFactory::class),
			globalProtection: self::createStub(Protection::class),
		);

		$level = $this->invokePrivateMethod($sut, "getStderrMinimumLogLevel");

		self::assertSame("ERROR", $level);
	}

	public function testGetMinimumLogLevel_fallsBackToDebugForInvalidConfig():void {
		$sut = new Application(
			config: $this->createTestConfig([
				"logger.level" => "banana",
			]),
			requestFactory: $this->createRequestFactory(),
			dispatcherFactory: self::createStub(DispatcherFactory::class),
			globalProtection: self::createStub(Protection::class),
		);

		$level = $this->invokePrivateMethod($sut, "getMinimumLogLevel");

		self::assertSame("DEBUG", $level);
	}

	public function testConfigureLoggerStreams_registersSplitStdoutAndStderrHandlers():void {
		$this->resetApplicationLoggerState();

		new Application(
			config: $this->createTestConfig([
				"logger.stderr_level" => "WARNING",
			]),
			requestFactory: $this->createRequestFactory(),
			dispatcherFactory: self::createStub(DispatcherFactory::class),
			globalProtection: self::createStub(Protection::class),
		);

		$handlers = $this->getStaticProperty(LogConfig::class, "handlers");
		$minLevels = $this->getStaticProperty(LogConfig::class, "handlerMinLevels");
		$maxLevels = $this->getStaticProperty(LogConfig::class, "handlerMaxLevels");

		self::assertCount(2, $handlers);
		self::assertSame(["DEBUG", "WARNING"], $minLevels);
		self::assertSame(["NOTICE", "EMERGENCY"], $maxLevels);
		self::assertTrue($this->getStaticProperty(Application::class, "loggerConfigured"));
	}

	public function testConfigureLoggerStreams_respectsConfiguredMinimumLogLevel_caseInsensitive():void {
		$this->resetApplicationLoggerState();

		new Application(
			config: $this->createTestConfig([
				"logger.level" => "warning",
				"logger.stderr_level" => "ERROR",
			]),
			requestFactory: $this->createRequestFactory(),
			dispatcherFactory: self::createStub(DispatcherFactory::class),
			globalProtection: self::createStub(Protection::class),
		);

		$minLevels = $this->getStaticProperty(LogConfig::class, "handlerMinLevels");
		$maxLevels = $this->getStaticProperty(LogConfig::class, "handlerMaxLevels");
		$defaultHandlerLevel = $this->getStaticProperty(LogConfig::class, "defaultHandlerLevel");

		self::assertSame(["WARNING", "ERROR"], $minLevels);
		self::assertSame(["WARNING", "EMERGENCY"], $maxLevels);
		self::assertSame("WARNING", $defaultHandlerLevel);
	}

	public function testStart_doesNotLogInfoBelowConfiguredMinimumLogLevel():void {
		$this->resetApplicationLoggerState();

		new Application(
			config: $this->createTestConfig([
				"logger.level" => "warning",
			]),
			requestFactory: $this->createRequestFactory(),
			dispatcherFactory: self::createStub(DispatcherFactory::class),
			globalProtection: self::createStub(Protection::class),
		);
		$this->setStaticProperty(LogConfig::class, "handlers", []);
		$this->setStaticProperty(LogConfig::class, "handlerMinLevels", []);
		$this->setStaticProperty(LogConfig::class, "handlerMaxLevels", []);
		LogConfig::addHandler(new TestLogHandler());

		$config = $this->createTestConfig([
			"logger.level" => "warning",
			"logger.log_all_requests" => true,
		]);
		$request = $this->createServerRequest("/asset");
		$requestFactory = self::createStub(RequestFactory::class);
		$requestFactory->method("createServerRequestFromGlobalState")
			->willReturn($request);

		$dispatcher = self::createMock(Dispatcher::class);
		$dispatcher->expects(self::once())
			->method("generateResponse")
			->willReturn($this->createResponse(200));

		$dispatcherFactory = self::createStub(DispatcherFactory::class);
		$dispatcherFactory->method("create")
			->willReturn($dispatcher);

		$sut = new Application(
			config: $config,
			requestFactory: $requestFactory,
			dispatcherFactory: $dispatcherFactory,
			globalProtection: self::createStub(Protection::class),
		);

		$sut->start();

		self::assertSame([], TestLogHandler::$records);
	}

	public function testStart_doesNotLogNoticeBelowConfiguredMinimumLogLevel():void {
		$this->resetApplicationLoggerState();

		new Application(
			config: $this->createTestConfig([
				"logger.level" => "warning",
			]),
			requestFactory: $this->createRequestFactory(),
			dispatcherFactory: self::createStub(DispatcherFactory::class),
			globalProtection: self::createStub(Protection::class),
		);
		$this->setStaticProperty(LogConfig::class, "handlers", []);
		$this->setStaticProperty(LogConfig::class, "handlerMinLevels", []);
		$this->setStaticProperty(LogConfig::class, "handlerMaxLevels", []);
		LogConfig::addHandler(new TestLogHandler());

		$config = $this->createTestConfig([
			"logger.level" => "warning",
			"logger.log_all_requests" => false,
			"app.slow_delta" => -1,
			"app.very_slow_delta" => -1,
		]);
		$request = $this->createServerRequest("/slow");
		$requestFactory = self::createStub(RequestFactory::class);
		$requestFactory->method("createServerRequestFromGlobalState")
			->willReturn($request);

		$dispatcher = self::createMock(Dispatcher::class);
		$dispatcher->expects(self::once())
			->method("generateResponse")
			->willReturn($this->createResponse(200));

		$dispatcherFactory = self::createStub(DispatcherFactory::class);
		$dispatcherFactory->method("create")
			->willReturn($dispatcher);

		$sut = new Application(
			config: $config,
			requestFactory: $requestFactory,
			dispatcherFactory: $dispatcherFactory,
			globalProtection: self::createStub(Protection::class),
		);

		$sut->start();

		self::assertSame([], TestLogHandler::$records);
	}

	private function createTestConfig(array $mockedValues):Config {
		$config = self::createStub(Config::class);

		$configFile = dirname(__DIR__, 2) . "/config.default.ini";
		$configContents = parse_ini_file($configFile, true);

		$map = [];
		foreach($configContents as $section => $kvp) {
			foreach($kvp as $key => $value) {
				$map["$section.$key"] = $value;
			}
		}

		$map = array_merge($map, $mockedValues);

		$config->method(self::anything())->willReturnCallback(function($key) use ($map) {
			$type = null;
			$bt = debug_backtrace();
			if(isset($bt[1]) && ($bt[1]["args"][0] ?? null) instanceof Invocation) {
				$type = strtolower(substr($bt[1]["args"][0]->methodName(), 3));
			}

			return match ($type) {
				"bool" => (bool)$map[$key],
				"int" => (int)$map[$key],
				"float" => (float)$map[$key],
				default => $map[$key],
			};
		});

		return $config;
	}

	private function createRequestFactory(?ServerRequest $request = null):RequestFactory {
		$requestFactory = self::createStub(RequestFactory::class);
		$requestFactory->method("createServerRequestFromGlobalState")
			->willReturn($request ?? $this->createServerRequest());
		return $requestFactory;
	}

	private function createServerRequest(
		string $path = "/",
		array $query = [],
		array $post = [],
		array $serverParams = ["REMOTE_ADDR" => "127.0.0.1"],
	):ServerRequest {
		$request = self::createStub(ServerRequest::class);
		$request->method("getUri")
			->willReturn(new Uri("https://example.test" . $path));
		$request->method("getHeaderLine")
			->willReturnCallback(
				fn(string $name):string => strtolower($name) === "accept" ? "*/*" : ""
			);
		$request->method("getMethod")
			->willReturn("GET");
		$request->method("getServerParams")
			->willReturn($serverParams);
		$request->method("getQueryParams")
			->willReturn($query);
		$request->method("getParsedBody")
			->willReturn($post);
		return $request;
	}

	private function createResponse(int $statusCode = 200, string $body = "", array $headers = []):Response {
		$response = self::createStub(Response::class);
		$stream = new Stream();
		$stream->write($body);
		$response->method("getStatusCode")->willReturn($statusCode);
		$response->method("getHeaders")->willReturn($headers);
		$response->method("getBody")->willReturn($stream);
		$response->method("hasHeader")
			->willReturnCallback(fn(string $name):bool => array_key_exists($name, $headers));
		$response->method("getHeaderLine")
			->willReturnCallback(function(string $name) use ($headers):string {
				if(!isset($headers[$name])) {
					return "";
				}

				return implode(";", $headers[$name]);
			});
		return $response;
	}

	private function invokePrivateMethod(object $object, string $method, mixed ...$args):mixed {
		$reflectionMethod = new \ReflectionMethod($object, $method);
		return $reflectionMethod->invokeArgs($object, $args);
	}

	private function resetApplicationLoggerState():void {
		$this->setStaticProperty(Application::class, "loggerConfigured", false);
		$this->setStaticProperty(LogConfig::class, "handlers", []);
		$this->setStaticProperty(LogConfig::class, "handlerMinLevels", []);
		$this->setStaticProperty(LogConfig::class, "handlerMaxLevels", []);
		$this->setStaticProperty(LogConfig::class, "defaultHandlerLevel", "DEBUG");
		TestLogHandler::$records = [];
	}

	private function setApplicationLoggerConfigured(bool $configured):void {
		$this->setStaticProperty(Application::class, "loggerConfigured", $configured);
	}

	private function setStaticProperty(string $className, string $propertyName, mixed $value):void {
		$property = new \ReflectionProperty($className, $propertyName);
		$property->setValue(null, $value);
	}

	private function setPrivateProperty(object $object, string $propertyName, mixed $value):void {
		$property = new \ReflectionProperty($object, $propertyName);
		$property->setValue($object, $value);
	}

	private function getStaticProperty(string $className, string $propertyName):mixed {
		$property = new \ReflectionProperty($className, $propertyName);
		return $property->getValue();
	}
}
