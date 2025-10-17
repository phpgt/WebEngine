<?php
namespace Gt\WebEngine;

use Gt\WebEngine\Redirection\Redirect;

class Application {
	private Redirect $redirect;

	public function __construct(
		?Redirect $redirect = null,
	) {
		$this->redirect = $redirect ?? new Redirect();
	}

	public function start():void {
		$this->redirect->execute();
	}
}
