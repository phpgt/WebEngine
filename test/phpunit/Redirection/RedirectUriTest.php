<?php
namespace GT\WebEngine\Test\Redirection;

use GT\WebEngine\Redirection\RedirectUri;
use PHPUnit\Framework\TestCase;

class RedirectUriTest extends TestCase {
	public function testConstruct_defaults():void {
		$sut = new RedirectUri('/there');
		self::assertSame('/there', $sut->uri);
		self::assertSame(307, $sut->code);
	}

	public function testConstruct_customCode():void {
		$sut = new RedirectUri('/moved', 301);
		self::assertSame('/moved', $sut->uri);
		self::assertSame(301, $sut->code);
	}
}
