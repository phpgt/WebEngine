<?php
namespace GT\WebEngine\Test\Redirection;

use GT\WebEngine\Redirection\RedirectException;
use GT\WebEngine\Redirection\StatusCodeValidator;
use PHPUnit\Framework\TestCase;

class StatusCodeValidatorTest extends TestCase {
	public function testValidate_validRange():void {
		$sut = new StatusCodeValidator();
		self::assertSame(301, $sut->validate(301));
		self::assertSame(307, $sut->validate(307));
		self::assertSame(308, $sut->validate(308));
	}

	public function testValidate_invalidLow():void {
		$sut = new StatusCodeValidator();
		self::expectException(RedirectException::class);
		self::expectExceptionMessage("Invalid HTTP status code in redirect file: 300");
		$sut->validate(300);
	}

	public function testValidate_invalidHigh():void {
		$sut = new StatusCodeValidator();
		self::expectException(RedirectException::class);
		self::expectExceptionMessage("Invalid HTTP status code in redirect file: 400");
		$sut->validate(400);
	}

	public function testValidate_nonNumeric():void {
		$sut = new StatusCodeValidator();
		self::expectException(RedirectException::class);
		self::expectExceptionMessage("Invalid HTTP status code in redirect file: abc");
		$sut->validate("abc");
	}
}
