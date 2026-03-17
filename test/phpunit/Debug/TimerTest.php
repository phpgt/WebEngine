<?php
namespace GT\WebEngine\Test\Debug;

use GT\WebEngine\Debug\Timer;
use PHPUnit\Framework\TestCase;

class TimerTest extends TestCase {
	public function testStart_stop_getDelta_usesInjectedTimeGetter(): void {
		$times = [100.0, 100.25];
		$timeGetter = function() use (&$times) { return array_shift($times); };
		$sut = new Timer(timeGetter: $timeGetter);
		$sut->start();
		$sut->stop();
		self::assertSame(0.25, $sut->getDelta());
	}

	public function testLogDelta_noCallback_returnsEarly_withoutError(): void {
		$times = [1.0, 1.2];
		$timeGetter = function() use (&$times) { return array_shift($times); };
		$sut = new Timer(timeGetter: $timeGetter);
		$sut->start();
		$sut->stop();
		// No assertions besides not throwing — ensures early return path when no callback set.
		$sut->logDelta();
		self::assertTrue(true);
	}

	public function testLogDelta_belowThreshold_doesNotCallCallback(): void {
		$called = false;
		$cb = function(string $msg) use (&$called) { $called = true; };
		$times = [10.0, 10.04]; // delta 0.04 — below default slow (0.1)
		$timeGetter = function() use (&$times) { return array_shift($times); };
		$sut = new Timer(deltaLogCallback: $cb, timeGetter: $timeGetter);
		$sut->start();
		$sut->stop();
		$sut->logDelta();
		self::assertFalse($called, 'Callback should not be invoked when delta is below slow threshold');
	}

	public function testLogDelta_slowThreshold_callsCallbackWithSLOW(): void {
		$captured = null;
		$cb = function(string $msg) use (&$captured) { $captured = $msg; };
		$times = [0.0, 0.2]; // delta 0.2 > slow (0.1) but < very slow (0.5)
		$timeGetter = function() use (&$times) { return array_shift($times); };
		$sut = new Timer(slowDelta: 0.1, verySlowDelta: 0.5, deltaLogCallback: $cb, timeGetter: $timeGetter);
		$sut->start();
		$sut->stop();
		$sut->logDelta();
		self::assertNotNull($captured);
		self::assertStringContainsString('SLOW', $captured);
		self::assertStringContainsString('https://www.php.gt/webengine/slow-delta', $captured);
	}

	public function testLogDelta_verySlowThreshold_callsCallbackWithVERY_SLOW(): void {
		$captured = null;
		$cb = function(string $msg) use (&$captured) { $captured = $msg; };
		$times = [5.0, 5.75]; // delta 0.75 > verySlow (0.5)
		$timeGetter = function() use (&$times) { return array_shift($times); };
		$sut = new Timer(slowDelta: 0.1, verySlowDelta: 0.5, deltaLogCallback: $cb, timeGetter: $timeGetter);
		$sut->start();
		$sut->stop();
		$sut->logDelta();
		self::assertNotNull($captured);
		self::assertStringContainsString('VERY SLOW', $captured);
	}
}
