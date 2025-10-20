<?php
namespace Gt\WebEngine\Test\Redirection;

use Gt\WebEngine\Redirection\RedirectException;
use Gt\WebEngine\WebEngineException;
use PHPUnit\Framework\TestCase;

class RedirectExceptionTest extends TestCase {
	public function testExtendsWebEngineException():void {
		$e = new RedirectException('x');
		self::assertInstanceOf(WebEngineException::class, $e);
	}
}
