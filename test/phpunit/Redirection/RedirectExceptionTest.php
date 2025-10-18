<?php
namespace GT\WebEngine\Test\Redirection;

use GT\WebEngine\Redirection\RedirectException;
use GT\WebEngine\WebEngineException;
use PHPUnit\Framework\TestCase;

class RedirectExceptionTest extends TestCase {
	public function testExtendsWebEngineException():void {
		$e = new RedirectException('x');
		self::assertInstanceOf(WebEngineException::class, $e);
	}
}
