<?php
namespace Gt\WebEngine\Test\Redirection;

use Gt\WebEngine\Redirection\RedirectUri;
use PHPUnit\Framework\TestCase;

class RedirectUriTest extends TestCase {
	public function testConstruct_defaults():void {
		$ru = new RedirectUri('/there');
		self::assertSame('/there', $ru->uri);
		self::assertSame(307, $ru->code);
	}

	public function testConstruct_customCode():void {
		$ru = new RedirectUri('/moved', 301);
		self::assertSame('/moved', $ru->uri);
		self::assertSame(301, $ru->code);
	}
}
