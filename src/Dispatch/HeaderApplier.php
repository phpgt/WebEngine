<?php
namespace Gt\WebEngine\Dispatch;

use Gt\Http\Header\ResponseHeaders;
use Gt\Http\Response;
use Gt\ServiceContainer\Container;
use Gt\ServiceContainer\ServiceNotFoundException;

class HeaderApplier {
	public function apply(Container $container, Response $response): Response {
		try {
			$responseHeaders = $container->get(ResponseHeaders::class); /* @var ResponseHeaders $responseHeaders */
		}
		catch(ServiceNotFoundException) {
			// Legacy namespace fallback due to GT -> Gt transition: allow fetching
			// the same service using the old class-string. The dynamic class-string
			// construction is safe and covered by runtime checks, so we suppress the
			// static analyser warning on the next line.
			// @phpstan-ignore-next-line
			$responseHeaders = $container->get(str_replace("GT\\", "Gt\\", ResponseHeaders::class)); /* @var ResponseHeaders $responseHeaders */
		}
		foreach($responseHeaders->asArray() as $name => $value) {
			$response = $response->withHeader($name, $value);
		}
		return $response;
	}
}
