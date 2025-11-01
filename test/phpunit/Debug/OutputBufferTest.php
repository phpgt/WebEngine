<?php
namespace GT\WebEngine\Test\Debug;

use GT\WebEngine\Debug\OutputBuffer;
use PHPUnit\Framework\TestCase;

class OutputBufferTest extends TestCase {
	public function testStart_invokesObStartHandler(): void {
		$called = false;
		$obStart = function() use (&$called) { $called = true; };
		$sut = new OutputBuffer(debugToJavaScript: true, obStartHandler: $obStart);
		$sut->start();
		self::assertTrue($called, 'Expected injected ob_start handler to be invoked');
	}

	public function testDebugOutput_emptyBuffer_returnsNull(): void {
		$obStart = function() { /* no-op */ };
		$obGetCleanValues = [""]; // empty string simulates no output
		$obGetClean = function() use (&$obGetCleanValues) { return array_shift($obGetCleanValues); };
		$sut = new OutputBuffer(true, $obStart, $obGetClean);
		$sut->start();
		self::assertNull($sut->debugOutput());
	}

	public function testDebugOutput_whenDebugToJavaScriptFalse_returnsNullDespiteBuffer(): void {
		$obStart = function() {};
		$obGetCleanValues = ["Some output"];
		$obGetClean = function() use (&$obGetCleanValues) { return array_shift($obGetCleanValues); };
		$sut = new OutputBuffer(false, $obStart, $obGetClean);
		$sut->start();
		self::assertNull($sut->debugOutput());
	}

	public function testDebugOutput_returnsScriptWithEscapedBackticks_andTrimApplied(): void {
		$obStart = function() {};
		$raw = "  Hello `there`  \n"; // leading/trailing whitespace and a backtick
		$obGetCleanValues = [$raw];
		$obGetClean = function() use (&$obGetCleanValues) { return array_shift($obGetCleanValues); };
		$sut = new OutputBuffer(true, $obStart, $obGetClean);
		$sut->start();
		$html = $sut->debugOutput();
		self::assertNotNull($html);
		self::assertStringContainsString('<script>', $html);
		self::assertStringContainsString('console.group("PHP.GT/WebEngine")', $html);
		$expectedBuffer = str_replace('`', '\\`', trim($raw));
		self::assertStringContainsString($expectedBuffer, $html);
	}

	public function testCleanBuffer_accumulatesAndNormalisesNull(): void {
		$obStart = function() {};
		$sequence = ["one", null, "two"]; // null should normalise to empty string
		$obGetClean = function() use (&$sequence) { return array_shift($sequence); };
		$sut = new OutputBuffer(true, $obStart, $obGetClean);
		$sut->start();
		// Manually accumulate twice, then let debugOutput() call a final clean
		$sut->cleanBuffer(); // "one"
		$sut->cleanBuffer(); // null -> ""
		$html = $sut->debugOutput(); // "two" + emit script
		self::assertNotNull($html);
		self::assertStringContainsString('onetwo', $html);
	}

	public function testFillBuffer_privateMethod_appendsAndReturnsValue(): void {
		$sut = new OutputBuffer(true, function(){}, function(){ return ''; });
		$ref = new \ReflectionClass($sut);
		$m = $ref->getMethod('fillBuffer');
		$m->setAccessible(true);
		$ret1 = $m->invoke($sut, 'A');
		$ret2 = $m->invoke($sut, 'B');
		self::assertSame('A', $ret1);
		self::assertSame('B', $ret2);
		// Now force debugOutput() to flush accumulated buffer
		$html = $sut->debugOutput();
		self::assertNotNull($html);
		self::assertStringContainsString('AB', $html);
	}
}
