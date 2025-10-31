<?php
namespace GT\WebEngine\Test\Init;

use Closure;
use Gt\Http\ServerInfo;
use Gt\Http\Uri;
use Gt\Input\Input;
use GT\WebEngine\Init\RequestInit;
use GT\WebEngine\Dispatch\PathNormaliser;
use PHPUnit\Framework\TestCase;

class RequestInitTest extends TestCase {
	public function testConstructor_normalisesPath_andBuildsInputAndServerInfo():void {
		$pathNormaliser = self::createMock(PathNormaliser::class);

		$uri = self::createMock(Uri::class);
		$uri->method("getPath")
			->willReturn("https://example.test/section");

		$forceTrailing = true;
		$redirectCalled = false;
		$redirectArg = null;
		$redirect = function($u) use (&$redirectCalled, &$redirectArg) {
			$redirectCalled = true;
			$redirectArg = $u;
		};

		$pathNormaliser->expects(self::once())
			->method('normaliseTrailingSlash')
			->with($uri, $forceTrailing, self::isInstanceOf(Closure::class))
			->willReturnCallback(function(Uri $u, bool $force, Closure $redirectCallback) {
				// Call the provided redirect to simulate a normalisation.
				$redirectCallback($u);
			});

		$get = ['a' => '1'];
		$post = ['b' => '2'];
		$tmp = tempnam(sys_get_temp_dir(), 'upload');
		file_put_contents($tmp, 'x');
		$files = [
			'f' => [
				'name' => 'x.txt',
				'type' => 'text/plain',
				'tmp_name' => $tmp,
				'error' => 0,
				'size' => 1,
			],
		];
		$server = ['REQUEST_URI' => '/section'];

		$sut = new RequestInit(
			$pathNormaliser,
			$uri,
			$forceTrailing,
			$redirect,
			$get,
			$post,
			$files,
			$server,
		);

		// Assert redirect path normalisation collaboration occurred
		self::assertTrue($redirectCalled);
		self::assertSame($uri, $redirectArg);

		// Call getters to ensure full coverage and type expectations
		$input = $sut->getInput();
		$serverInfo = $sut->getServerInfo();
		self::assertInstanceOf(Input::class, $input);
		self::assertInstanceOf(ServerInfo::class, $serverInfo);
		self::assertSame('/section', $serverInfo->getRequestUri()->getPath());
	}
}
