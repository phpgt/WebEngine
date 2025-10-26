<?php
namespace GT\WebEngine\Test;

use Gt\Http\Request;
use Gt\Http\RequestFactory;
use Gt\Http\Response;
use Gt\Http\ServerRequest;
use Gt\Http\Uri;
use Gt\Logger\Log;
use Gt\ProtectedGlobal\Protection;
use GT\WebEngine\Application;
use GT\WebEngine\Debug\OutputBuffer;
use GT\WebEngine\Debug\Timer;
use GT\WebEngine\Dispatch\Dispatcher;
use GT\WebEngine\Dispatch\DispatcherFactory;
use GT\WebEngine\Redirection\Redirect;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase {
	public function testStart_callsRedirectExecute():void {
		$redirect = self::createMock(Redirect::class);
		$redirect->expects(self::once())
			->method("execute");

		$globalProtection = self::createMock(Protection::class);
		$serverRequest = self::createMock(ServerRequest::class);
		$serverRequest->method("getUri")
			->willReturn(self::createMock(Uri::class));
		$serverRequest->method("getHeaderLine")
			->with("accept")
			->willReturn("*/*");
		$serverRequest->method("getMethod")
			->willReturn("GET");
		$requestFactory = self::createMock(RequestFactory::class);
		$requestFactory->method("createServerRequestFromGlobalState")
			->willReturn($serverRequest);
		$dispatcher = self::createMock(Dispatcher::class);
		$response = self::createMock(Response::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getHeaders')->willReturn(['Content-Type' => ['text/html']]);
		$response->method('getBody')->willReturn(new \Gt\Http\Stream());
		$dispatcher->method('generateResponse')->willReturn($response);
		$dispatcherFactory = self::createMock(DispatcherFactory::class);
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
}
