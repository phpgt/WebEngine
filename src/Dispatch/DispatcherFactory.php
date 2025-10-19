<?php
namespace GT\WebEngine\Dispatch;

use GT\Config\Config;
use GT\Http\Request;

class DispatcherFactory {
	/**
	 * @param array<string, array<string, string|array<string, string>>> $globals
	 */
	public function create(
		Config $config,
		Request $request,
		array $globals
	):Dispatcher {
		return new Dispatcher(
			$config,
			$request,
			$globals
		);
	}
}
