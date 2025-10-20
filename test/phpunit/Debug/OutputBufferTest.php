<?php
namespace Gt\WebEngine\Test\Debug;

use Gt\WebEngine\Debug\OutputBuffer;
use PHPUnit\Framework\TestCase;

class OutputBufferTest extends TestCase {
	public function testEchoIsCaptured():void {
		$sut = new OutputBuffer();
		$sut->start();
		echo "Hello world";
		$buffer = $sut->getBuffer();
		self::assertSame("Hello world", $buffer);
	}

	public function testVarDumpIsCaptured():void {
		$sut = new OutputBuffer();
		$sut->start();
		$var = ["a" => 1, "b" => 2];
		var_dump($var);
		$buffer = $sut->getBuffer();
// var_dump format can vary; assert key parts present rather than exact formatting
		self::assertNotSame("", $buffer);
		self::assertStringContainsString("array", $buffer);
		self::assertStringContainsString("int(1)", $buffer);
		self::assertStringContainsString("int(2)", $buffer);
	}
}
