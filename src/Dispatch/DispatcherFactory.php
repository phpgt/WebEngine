<?php
namespace GT\WebEngine\Dispatch;

use Closure;
use Gt\Config\Config;
use Gt\Http\Request;

class DispatcherFactory {
	/**
	 * @param array<string, array<string, string|array<string, string>>> $globals
	 */
	public function create(
		Config $config,
		Request $request,
		array $globals,
		Closure $finishCallback,
		?int $errorStatus = null,
	):Dispatcher {
		return new Dispatcher(
			$config,
			$request,
			$globals,
			$finishCallback,
			$errorStatus,
		);
	}
}
