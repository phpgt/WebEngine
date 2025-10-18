<?php
namespace GT\WebEngine\Test;

use GT\Http\RequestFactory;
use GT\Http\Response;
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

		$sut = new Application(
			redirect: $redirect,
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

		$sut = new Application(
			timer: $timer,
		);
		$sut->start();
	}

	public function testStart_callsOutputBufferFunctions():void {
		$outputBuffer = self::createMock(OutputBuffer::class);
		$outputBuffer->expects(self::once())
			->method("start");
		$outputBuffer->expects(self::once())
			->method("debugOutput");

		$sut = new Application(
			outputBuffer: $outputBuffer,
		);
		$sut->start();
	}

	public function testStart_callsRequestFactoryFunctions():void {
		$requestFactory = self::createMock(RequestFactory::class);
		$requestFactory->expects(self::once())
			->method("createServerRequestFromGlobalState");

		$sut = new Application(
			requestFactory: $requestFactory,
		);
		$sut->start();
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
			->method("getStatusCode");
		$response->expects(self::once())
			->method("getHeaders");
		$response->expects(self::once())
			->method("getBody");

		$sut = new Application(
			dispatcherFactory: $dispatcherFactory,
		);
		$sut->start();
	}
}
