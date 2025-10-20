<?php
namespace Gt\WebEngine\Test\Debug;

use Gt\WebEngine\Debug\Timer;
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

		$sut = new Timer(timeGetter: $timeGetter);
		$sut->start();
		$sut->stop();
		self::assertSame(2.5, $sut->getDelta());
	}

	public function testStopMultipleTimesUsesLatestEndTime():void {
		$times = [10.0, 20.0, 40.0, 80.0, 160.0];
		$index = 0;
		$timeGetter = function() use (&$times, &$index) {
			$value = $times[$index] ?? end($times);
			$index++;
			return $value;
		};

		$sut = new Timer(timeGetter: $timeGetter);
		$sut->start();
		$sut->stop();
		self::assertSame(10.0, $sut->getDelta());

		$sut->start();
		$sut->stop();
		self::assertSame(40.0, $sut->getDelta());
	}
}
