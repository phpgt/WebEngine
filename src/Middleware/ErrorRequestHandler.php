<?php
namespace GT\WebEngine\Middleware;

use GT\Config\Config;
use GT\Dom\HTMLDocument;
use GT\DomTemplate\DocumentBinder;
use GT\Http\ResponseStatusException\ResponseStatusException;
use GT\Http\Uri;
use GT\ServiceContainer\Container;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class ErrorRequestHandler extends RequestHandler {
	public function __construct(
		Config $config,
		callable $finishCallback,
		private Throwable $throwable,
		protected Container $serviceContainer,
		protected array $getArray,
		protected array $postArray,
		protected array $filesArray,
		protected array $serverArray,
	) {
		parent::__construct(
			$config,
			$finishCallback,
			$this->getArray,
			$this->postArray,
			$this->filesArray,
			$this->serverArray,
		);
	}

	public function handle(
		ServerRequestInterface $request
	):ResponseInterface {
		$errorCode = 500;
		if($this->throwable instanceof ResponseStatusException) {
			$errorCode = $this->throwable->getHttpCode();
		}

		$this->originalUri = $request->getUri();
		$errorUri = new Uri("/_$errorCode");
		$errorRequest = $request->withUri($errorUri);
		$this->completeRequestHandling($errorRequest, true);
		$this->response = $this->response->withStatus($errorCode);
		return $this->response;
	}
}
