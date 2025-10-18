<?php
namespace GT\WebEngine\Dispatch;

use Gt\Config\Config;
use Gt\Http\Request;

class DispatcherFactory {
	public function create(
		Config $config,
		Request $request,
		array $globalGet,
		array $globalPost,
		array $globalFiles,
		array $globalServer,
	):Dispatcher {
		return new Dispatcher(
			$config,
			$request,
			$globalGet,
			$globalPost,
			$globalFiles,
			$globalServer,
		);
	}
}
