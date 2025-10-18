<?php
namespace GT\WebEngine;

use Gt\Http\Request;
use GT\Http\Response;
use Throwable;

readonly class Dispatcher {
	public function __construct(
		private Request $request,
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
