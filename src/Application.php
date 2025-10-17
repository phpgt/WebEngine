<?php
namespace Gt\WebEngine;

use Gt\WebEngine\Debug\Timer;
use Gt\WebEngine\Redirection\Redirect;

class Application {
	private Redirect $redirect;
	private Timer $timer;

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

// TODO: Test the timer! Then start some tests on Application (as much as possible in unit tests).
// Then, when tests are passing, begin to reconstruct the Lifecycle in a much more testable way.
	}
}
