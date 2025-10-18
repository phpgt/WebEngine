<?php
namespace Gt\WebEngine\Test\Redirection;

use Gt\WebEngine\Redirection\RedirectException;
use Gt\WebEngine\Redirection\RedirectMap;
use Gt\WebEngine\Redirection\RedirectUri;
use PHPUnit\Framework\Attributes\IgnorePhpunitWarnings;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;

class RedirectMapTest extends TestCase {
	public function testAddRule_andMatch_literal():void {
		$map = new RedirectMap();
		self::assertTrue($map->isEmpty());
		$map->addRule(301, '/from', '/to');
		self::assertFalse($map->isEmpty());
		$r = $map->match('/from');
		self::assertInstanceOf(RedirectUri::class, $r);
		self::assertSame('/to', $r->uri);
		self::assertSame(301, $r->code);
		self::assertNull($map->match('/nope'));
	}

	public function testAddRule_andMatch_regex():void {
		$map = new RedirectMap();
		$map->addRule(307, '~^/blog/(.+)$', '/articles/$1');
		$r = $map->match('/blog/hello-world');
		self::assertInstanceOf(RedirectUri::class, $r);
		self::assertSame('/articles/hello-world', $r->uri);
		self::assertSame(307, $r->code);
	}

	public function testMatch_prefersLiteralOverRegex():void {
		$map = new RedirectMap();
		$map->addRule(308, '~^/page/(.+)$', '/regex/$1');
		$map->addRule(302, '/page/123', '/literal');
		$r = $map->match('/page/123');
		self::assertSame('/literal', $r->uri);
		self::assertSame(302, $r->code);
	}

	public function testMatch_invalidRegexThrows():void {
		$map = new RedirectMap();
		$map->addRule(307, '~^/broken/([)$', '/x');
		self::expectException(RedirectException::class);
		self::expectExceptionMessage('Invalid regex pattern in redirect file: ^/broken/([)$');

		set_error_handler(static fn(int $errno) => $errno === E_WARNING);
		try {
			$map->match('/broken/anything');
		}
		finally {
			restore_error_handler();
		}
	}

	public function testAddRule_ignoresEmpty():void {
		$map = new RedirectMap();
		$map->addRule(301, '', '/x');
		$map->addRule(301, '/x', '');
		self::assertTrue($map->isEmpty());
	}
}
