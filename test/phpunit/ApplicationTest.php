<?php
namespace GT\WebEngine\Test;

use Exception;
use Gt\Config\Config;
use Gt\Config\ConfigFactory;
use Gt\Http\RequestFactory;
use Gt\Http\Response;
use Gt\Http\ServerRequest;
use Gt\Http\Uri;
use Gt\ProtectedGlobal\Protection;
use GT\WebEngine\Application;
use GT\WebEngine\Debug\OutputBuffer;
use GT\WebEngine\Debug\Timer;
use GT\WebEngine\Dispatch\Dispatcher;
use GT\WebEngine\Dispatch\DispatcherFactory;
use GT\WebEngine\Redirection\Redirect;
use PHPUnit\Framework\MockObject\Invocation;
use PHPUnit\Framework\MockObject\InvocationHandler;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase {
	public function testStart_callsRedirectExecute():void {
		$redirect = self::createMock(Redirect::class);
		$redirect->expects(self::once())
			->method("execute");

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
		$response->method('getBody')->willReturn(new \Gt\Http\Stream());
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
		$response->method('getBody')->willReturn(new \Gt\Http\Stream());
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
		$response->method('getBody')->willReturn(new \Gt\Http\Stream());
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
		$response->method('getBody')->willReturn(new \Gt\Http\Stream());
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
			->willReturn(new \Gt\Http\Stream());

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

	private function createTestConfig(array $mockedValues):Config {
		$config = self::createMock(Config::class);

		$configFile = "config.default.ini";
		$configContents = parse_ini_file($configFile, true);

		$map = [];
		foreach ($configContents as $section => $kvp) {
			foreach ($kvp as $key => $value) {
				$map["$section.$key"] = $value;
			}
		}

		$map = array_merge($map, $mockedValues);

		$config->method(self::anything())->willReturnCallback(function ($key) use ($map) {
			$type = null;
			$bt = debug_backtrace();
			if(isset($bt[1]) && ($bt[1]["args"][0] ?? null) instanceof Invocation) {
				$type = strtolower(substr($bt[1]["args"][0]->methodName(), 3));
			}

			return match($type) {
				"bool" => (bool)$map[$key],
				"int" => (int)$map[$key],
				"float" => (float)$map[$key],
				default => $map[$key],
			};
		});

		return $config;
	}
}
