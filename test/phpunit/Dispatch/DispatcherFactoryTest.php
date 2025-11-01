<?php
namespace GT\WebEngine\Test\Dispatch;

use Gt\Config\Config;
use Gt\Http\Request;
use Gt\Http\ResponseStatusException\ClientError\HttpNotFound;
use Gt\Http\Uri;
use Gt\Session\FileHandler;
use GT\WebEngine\Dispatch\DispatcherFactory;
use PHPUnit\Framework\TestCase;

class DispatcherFactoryTest extends TestCase {
	public function testCreate():void {
		$config = self::createMock(Config::class);
		$config->method("getString")
			->willReturnMap([
				["app.namespace", "Example\\NS"],
				["app.class_dir", "/tmp/phpgt-webengine-test--dispatcher-factory--class"],
				["router.router_file", "/tmp/phpgt-webengine-test--dispatcher-factory--router"],
				["router.router_class", "TestRouter"],
				["router.default_content_type", "unit/test"],
				["session.name", "GT_Test_Session"],
				["session.handler", FileHandler::class],
				["session.path", "/"],
				["view.component_directory", "/tmp/phpgt-webengine-test--dispatcher-factory--component-dir"],
				["view.partial_directory", "/tmp/phpgt-webengine-test--dispatcher-factory--partial-dir"],
			]);
		$config->method("getInt")
			->willReturnMap([
				["router.redirect_response_code", 321],
			]);

		$requestUri = self::createMock(Uri::class);
		$requestUri->method("getPath")
			->willReturn("/test/");
		$request = self::createMock(Request::class);
		$request->method("getUri")
			->willReturn($requestUri);
		$request->method("getMethod")
			->willReturn("GET");
		$globals = [
			"_GET" => [],
			"_POST" => [],
			"_FILES" => [],
			"_SERVER" => [],
			"_COOKIE" => [],
		];
		$finishCallback = fn() => null;
		$errorStatus = 123;

		$sut = new DispatcherFactory();
		$dispatcher = $sut->create($config, $request, $globals, $finishCallback, $errorStatus);
		self::expectException(HttpNotFound::class);
		$dispatcher->generateResponse();
	}
}
