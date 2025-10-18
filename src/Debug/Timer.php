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
	private Closure $deltaLogCallback;
	private Closure $timeGetter;

	public function __construct(
		private float $slowDelta = 0.1,
		private float $verySlowDelta = 0.5,
		?Closure $deltaLogCallback = null,
		?Closure $timeGetter = null,
	) {
		if($deltaLogCallback) {
			$this->deltaLogCallback = $deltaLogCallback;
		}

		$this->timeGetter = $timeGetter ?? fn() => microtime(true);
		$this->startTime = ($this->timeGetter)();
	}

	public function stop():void {
		$this->endTime = ($this->timeGetter)();
	}

	public function getDelta():float {
		return $this->endTime - $this->startTime;
	}

	public function logDelta():void {
		if(!isset($this->deltaLogCallback)) {
			return;
		}

		$delta = $this->getDelta();
		if($delta > $this->verySlowDelta) {
			$message = "VERY SLOW";
		}
		elseif($delta > $this->slowDelta) {
			$message = "SLOW";
		}
		else {
			return;
		}

		$this->deltaLogCallback->call($this, "Timer ended with $message delta time: $delta seconds. https://www.php.gt/webengine/slow-delta");
	}

}
