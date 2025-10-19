<?php
namespace GT\WebEngine\Dispatch;

use GT\Config\Config;
use GT\Http\Request;
use GT\Http\Response;
use Gt\WebEngine\Logic\AppAutoloader;
use Throwable;

readonly class Dispatcher {
	/**
	 * @param array<string, array<string, string|array<string, string>>> $globals
	 */
	public function __construct(
		private Config $config,
		private Request $request,
		private array $globals,
		AppAutoloader $appAutoloader,
		LogicStreamHandler $logicStreamHandler,
	) {
	}

	public function generateResponse():Response {
		return new Response(request: $this->request);
	}

	public function generateErrorResponse(Throwable $throwable):Response {
		return new Response(request: $this->request);
	}

	public function generateBasicErrorResponse(
		Throwable $throwable,
		Throwable $previousThrowable,
	):Response {
		return new Response(request: $this->request);
	}
}
