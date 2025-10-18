<?php
namespace GT\WebEngine\Debug;

use Closure;

/**
 * Debug timer for measuring elapsed time within the request lifecycle.
 *
 * Behavior:
 * - On construction, records the start time.
 * - Call stop() to record the end time.
 * - Call getDelta() to retrieve the elapsed time in seconds (float).
 *
 * Testability:
 * - Accepts an optional Closure $timeGetter to supply the current time.
 * - By default, this calls microtime(true).
 */
class Timer {
	private float $startTime;
	private float $endTime;
	private Closure $timeGetter;

	public function __construct(?Closure $timeGetter = null) {
		$this->timeGetter = $timeGetter ?? fn() => microtime(true);
		$this->startTime = ($this->timeGetter)();
	}

	public function stop():void {
		$this->endTime = ($this->timeGetter)();
	}

	public function getDelta():float {
		return $this->endTime - $this->startTime;
	}
}
