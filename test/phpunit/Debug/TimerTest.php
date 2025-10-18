<?php
namespace GT\WebEngine\Test\Debug;

use GT\WebEngine\Debug\Timer;
use PHPUnit\Framework\TestCase;

class TimerTest extends TestCase {
	public function testDeltaWithInjectedTimeGetter():void {
		$times = [100.0, 102.5];
		$index = 0;
		$timeGetter = function() use (&$times, &$index) {
			$value = $times[$index] ?? end($times);
			$index++;
			return $value;
		};

		$sut = new Timer($timeGetter);
		$sut->stop();
		self::assertSame(2.5, $sut->getDelta());
	}

	public function testStopMultipleTimesUsesLatestEndTime():void {
		$times = [10.0, 15.0, 20.0];
		$index = 0;
		$timeGetter = function() use (&$times, &$index) {
			$value = $times[$index] ?? end($times);
			$index++;
			return $value;
		};

		$sut = new Timer($timeGetter);
		$sut->stop();
		self::assertSame(5.0, $sut->getDelta());

		$sut->stop();
		self::assertSame(10.0, $sut->getDelta());
	}
}
