<?php
namespace GT\WebEngine\Test\Dispatch;

use Gt\Http\Header\ResponseHeaders;
use GT\WebEngine\Dispatch\HeaderManager;
use PHPUnit\Framework\TestCase;

class HeaderManagerTest extends TestCase {
	public function testApplyWithHeader():void {
		$testHeaderArray = [
			"X-PHPGT-TEST" => uniqid(),
			"X-TIMESTAMP" => time(),
		];

		$callbackParameterCalls = [];
		$callback = function($name, $value) use(&$callbackParameterCalls) {
			array_push($callbackParameterCalls, [$name, $value]);
		};

		$responseHeaders = self::createMock(ResponseHeaders::class);
		$responseHeaders->expects(self::once())
			->method("asArray")
			->willReturn($testHeaderArray);

		$sut = new HeaderManager();
		$sut->applyWithHeader($responseHeaders, $callback);

		self::assertCount(count($testHeaderArray), $callbackParameterCalls);
		$index = 0;
		foreach($testHeaderArray as $key => $value) {
			$actualCallbackParameters = $callbackParameterCalls[$index];
			self::assertSame($key, $actualCallbackParameters[0]);
			self::assertSame($value, $actualCallbackParameters[1]);
			$index++;
		}
	}
}
