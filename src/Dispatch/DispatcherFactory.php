<?php
namespace GT\WebEngine\Dispatch;

use Gt\Config\Config;
use Gt\Http\Request;

class DispatcherFactory {
	/**
	 * @param array<string, mixed> $globalGet
	 * @param array<string, mixed> $globalPost
	 * @param array<string, mixed> $globalFiles
	 * @param array<string, mixed> $globalServer
	 */
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
