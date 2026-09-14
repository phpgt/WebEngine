<?php
namespace GT\WebEngine\Debug;

use GT\Config\Config;
use GT\Http\ResponseStatusException\ResponseStatusException;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\ClientBuilder;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\State\Scope;
use Throwable;
use WeakMap;

/** Optional SDK bridge with explicit client and request dependencies. */
class SentryReporter {
	/** @var WeakMap<Throwable, bool> */
	private WeakMap $reported;

	public function __construct(
		private ClientInterface $client,
		private ServerRequestInterface $request,
	) {
		$this->reported = new WeakMap();
	}

	/** Must run before global protection: SDK option defaults read superglobals. */
	public static function create(Config $config, ServerRequestInterface $request):?self {
		$dsn = trim($config->getString("sentry.dsn") ?? "");
		if(!$dsn || !class_exists(ClientBuilder::class)) {
			return null;
		}

		try {
			$environment = trim($config->getString("sentry.environment") ?? "");
			$options = [
				"dsn" => $dsn,
				"default_integrations" => false,
			];
			if($environment !== "") {
				$options["environment"] = $environment;
			}
			$client = ClientBuilder::create($options)->getClient();
			if($client->getOptions()->getDsn() === null) {
				return null;
			}
			return new self($client, $request);
		}
		catch(Throwable) {
			error_log("WebEngine: Sentry initialization failed.");
			return null;
		}
	}

	public function report(Throwable $throwable):void {
		if(isset($this->reported[$throwable])) {
			return;
		}
		if($throwable instanceof ResponseStatusException && $throwable->getHttpCode() < 500) {
			return;
		}

		$this->reported[$throwable] = true;
		try {
			$scope = new Scope();
			$scope->addEventProcessor(function(Event $event):Event {
				// Explicitly exclude credentials, query values, cookies and payloads.
				$uri = $this->request->getUri()->withUserInfo("")->withQuery("")->withFragment("");
				$event->setRequest([
					"url" => (string)$uri,
					"method" => $this->request->getMethod(),
				]);
				return $event;
			});
			$this->client->captureException($throwable, $scope);
		}
		catch(Throwable) {
			error_log("WebEngine: Sentry exception reporting failed.");
		}
	}
}
