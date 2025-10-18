<?php
namespace GT\WebEngine\Dispatch;

use Gt\Config\Config;
use Gt\Http\Request;
use GT\Http\Response;
use Throwable;

readonly class Dispatcher {
	/**
	 * @param array<string, mixed> $globalGet
	 * @param array<string, mixed> $globalPost
	 * @param array<string, mixed> $globalFiles
	 * @param array<string, mixed> $globalServer
	 */
	public function __construct(
		private Config $config,
		private Request $request,
		protected array $globalGet,
		protected array $globalPost,
		protected array $globalFiles,
		protected array $globalServer,
	) {}

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
