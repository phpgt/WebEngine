<?php
namespace Gt\WebEngine\Test\Redirection;

use Gt\WebEngine\Redirection\RedirectException;
use Gt\WebEngine\Redirection\StatusCodeValidator;
use PHPUnit\Framework\TestCase;

class StatusCodeValidatorTest extends TestCase {
	public function testValidate_validRange():void {
		self::assertSame(301, StatusCodeValidator::validate(301));
		self::assertSame(307, StatusCodeValidator::validate(307));
		self::assertSame(308, StatusCodeValidator::validate(308));
	}

	public function testValidate_invalidLow():void {
		self::expectException(RedirectException::class);
		self::expectExceptionMessage('Invalid HTTP status code in redirect file: 300');
		StatusCodeValidator::validate(300);
	}

	public function testValidate_invalidHigh():void {
		self::expectException(RedirectException::class);
		self::expectExceptionMessage('Invalid HTTP status code in redirect file: 400');
		StatusCodeValidator::validate(400);
	}

	public function testValidate_nonNumeric():void {
		self::expectException(RedirectException::class);
		self::expectExceptionMessage('Invalid HTTP status code in redirect file: abc');
		StatusCodeValidator::validate('abc');
	}
}
