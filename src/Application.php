<?php
namespace GT\WebEngine;

use GT\WebEngine\Debug\OutputBuffer;
use GT\WebEngine\Debug\Timer;
use GT\WebEngine\Redirection\Redirect;

/**
 * The fundamental purpose of any PHP framework is to provide a mechanism for
 * generating an HTTP response from an incoming HTTP request. This functionality
 * is what's wrapped into the WebEngine Application class here.
 */
class Application {
	private Redirect $redirect;
	private Timer $timer;
	private OutputBuffer $outputBuffer;

	public function __construct(
		?Redirect $redirect = null,
	) {
		$this->redirect = $redirect ?? new Redirect();
	}

	public function start():void {
// Before we start, we check if the current URI should be redirected. If it
// should, we won't go any further into the lifecycle.
		$this->redirect->execute();
// The first thing done within the WebEngine lifecycle is start a timer.
// This timer is only used again at the end of the call, when finish() is
// called - at which point the entire duration of the request is logged out (and
// slow requests are highlighted as a NOTICE).
		$this->timer = new Timer();

// Starting the output buffer is done before any logic is executed, so any calls
// to any area of code will not accidentally send output to the web browser.
		$this->outputBuffer = new OutputBuffer();

// Keep references read to satisfy static analysis without changing behaviour.
		$this->keepReference($this->timer, $this->outputBuffer);
	}

	/**
	 * No-op to keep references marked as read by static analysis.
	 */
	private function keepReference(mixed ...$args):void {
		// Intentionally empty.
	}
}
