<?php
namespace GT\WebEngine\Test\Redirection;

use GT\WebEngine\Redirection\RedirectException;
use GT\WebEngine\Redirection\RedirectMap;
use GT\WebEngine\Redirection\RedirectUri;
use PHPUnit\Framework\TestCase;

class RedirectMapTest extends TestCase {
	public function testAddRule_andMatch_literal():void {
		$sut = new RedirectMap();
		self::assertTrue($sut->isEmpty());
		$sut->addRule(301, '/from', '/to');
		self::assertFalse($sut->isEmpty());
		$r = $sut->match('/from');
		self::assertInstanceOf(RedirectUri::class, $r);
		self::assertSame('/to', $r->uri);
		self::assertSame(301, $r->code);
		self::assertNull($sut->match('/nope'));
	}

	public function testAddRule_andMatch_regex():void {
		$sut = new RedirectMap();
		$sut->addRule(307, '~^/blog/(.+)$', '/articles/$1');
		$r = $sut->match('/blog/hello-world');
		self::assertInstanceOf(RedirectUri::class, $r);
		self::assertSame('/articles/hello-world', $r->uri);
		self::assertSame(307, $r->code);
	}

	public function testMatch_prefersLiteralOverRegex():void {
		$sut = new RedirectMap();
		$sut->addRule(308, '~^/page/(.+)$', '/regex/$1');
		$sut->addRule(302, '/page/123', '/literal');
		$r = $sut->match('/page/123');
		self::assertSame('/literal', $r->uri);
		self::assertSame(302, $r->code);
	}

	public function testMatch_invalidRegexThrows():void {
		$sut = new RedirectMap();
		$sut->addRule(307, '~^/broken/([)$', '/x');
		self::expectException(RedirectException::class);
		self::expectExceptionMessage('Invalid regex pattern in redirect file: ^/broken/([)$');

		set_error_handler(static fn(int $errno) => $errno === E_WARNING);
		try {
			$sut->match('/broken/anything');
		}
		finally {
			restore_error_handler();
		}
	}

	public function testAddRule_ignoresEmpty():void {
		$sut = new RedirectMap();
		$sut->addRule(301, '', '/x');
		$sut->addRule(301, '/x', '');
		self::assertTrue($sut->isEmpty());
	}

	public function testRegexMatchButReplacementSame_returnsNull():void {
		$sut = new RedirectMap();
		// Pattern matches '/keep' and replacement yields the same URI string
		// Provide the regex in the same format loaders do: leading '~' only
		$sut->addRule(301, '~^/keep$', '/keep');
		self::assertNull($sut->match('/keep'));
	}
}
