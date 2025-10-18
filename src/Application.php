<?php
namespace GT\WebEngine;

use GT\Config\Config;
use GT\Config\ConfigFactory;
use GT\Http\Request;
use GT\Http\Response;
use Gt\Http\ServerRequest;
use GT\Http\Stream;
use GT\ProtectedGlobal\Protection;
use GT\WebEngine\Debug\OutputBuffer;
use GT\WebEngine\Debug\Timer;
use GT\WebEngine\Redirection\Redirect;
use GT\Http\RequestFactory;
use ErrorException;
use Throwable;

/**
 * The fundamental purpose of any PHP framework is to provide a mechanism for
 * generating an HTTP response from an incoming HTTP request. This functionality
 * is what's wrapped into the WebEngine Application class here.
 *
 * The heavy lifting of converting Request to Response is performed in the
 * Dispatcher's generateResponse() method.
 */
class Application {
	private Redirect $redirect;
	private Timer $timer;
	private OutputBuffer $outputBuffer;
	/** @var array<string, array<string, string>> */
	private array $globals;
	private Config $config;
	private Dispatcher $dispatcher;
	private bool $finished = false;

	public function __construct(
		?Redirect $redirect = null,
	) {
		$this->config = $this->loadConfig();
		$this->redirect = $redirect ?? new Redirect();
		register_shutdown_function($this->handleShutdown(...));
	}

	public function start():void {
// Before we start, we check if the current URI should be redirected. If it
// should, we won't go any further into the lifecycle.
		$this->redirect->execute();
// The first thing done within the WebEngine lifecycle is start a timer.
// This timer is only used again at the end of the call, when finish() is
// called - at which point the entire duration of the request is logged out (and
// slow requests are highlighted as a NOTICE).
		$this->timer = new Timer(
			$this->config->getFloat("app.slow_delta"),
			$this->config->getFloat("app.very_slow_delta"),
		);

// Starting the output buffer is done before any logic is executed, so any calls
// to any area of code will not accidentally send output to the web browser.
		$this->outputBuffer = new OutputBuffer();

// PHP.GT provides object-oriented interfaces to all values stored in $_SERVER,
// $_FILES, $_GET, and $_POST - to enforce good encapsulation and safe variable
// usage, the globals are protected against accidental misuse.
		$this->globals = $this->protectGlobals();

		$requestFactory = new RequestFactory();

		/** @var ServerRequest $request */
		$request = $requestFactory->createServerRequestFromGlobalState(
			$this->globals["server"],
			$this->globals["files"],
			$this->globals["get"],
			$this->globals["post"],
		);

		$this->dispatcher = new Dispatcher($request);

		try {
			$response = $this->dispatcher->generateResponse();
		}
		catch(Throwable $throwable) {
			$this->logError($throwable);
			$response = $this->dispatcher->generateErrorResponse($throwable);
		}

		$this->finish($response);
	}

	private function finish(
		Response $response,
	):void {
		if($this->finished) {
			return;
		}
		$this->finished = true;

		$this->outputHeaders(
			$response->getStatusCode(),
			$response->getHeaders(),
		);

// If there's any content in the output buffer, render it to the developer console/log instead of the page.
		$this->outputBuffer->debugOutput();

		/** @var Stream $responseBody */
		$responseBody = $response->getBody();
		$this->outputResponseBody($responseBody);

		$this->timer->stop();
		$this->timer->logDelta();
	}

	/**
	 * @return array<string, array<string, mixed>>
	 * @SuppressWarnings("PHPMD.Superglobals")
	 */
	private function protectGlobals():array {
		$originalGlobals = [
			"server" => $_SERVER,
			"files" => $_FILES,
			"get" => $_GET,
			"post" => $_POST,
			"env" => $_ENV,
			"cookie" => $_COOKIE
		];

		$protection = new Protection();
		$protection->overrideInternals(
			$protection->removeGlobals($originalGlobals, [
				"_ENV" => explode(",", $this->config->getString("app.globals_whitelist_env") ?? ""),
				"_SERVER" => explode(",", $this->config->getString("app.globals_whitelist_server") ?? ""),
				"_GET" => explode(",", $this->config->getString("app.globals_whitelist_get") ?? ""),
				"_POST" => explode(",", $this->config->getString("app.globals_whitelist_post") ?? ""),
				"_FILES" => explode(",", $this->config->getString("app.globals_whitelist_files") ?? ""),
				"_COOKIES" => explode(",", $this->config->getString("app.globals_whitelist_cookies") ?? ""),
			])
		);

		return $originalGlobals;
	}

	private function loadConfig():Config {
		$configFactory = new ConfigFactory();
		return $configFactory->createForProject(
			getcwd(),
			"vendor/phpgt/webengine/config.default.ini"
		);
	}

	private function handleShutdown():void {
		$error = error_get_last();
		if(!$error) {
			return;
		}

		$fatalErrors = [
			E_ERROR,
			E_PARSE,
			E_CORE_ERROR,
			E_COMPILE_ERROR,
			E_USER_ERROR,
		];

		if(!in_array($error["type"], $fatalErrors)) {
			return;
		}

		if($this->finished) {
			return;
		}


		$throwable = new ErrorException(
			$error["message"],
			0,
			$error["type"],
			$error["file"],
			$error["line"],
		);
		$this->logError($throwable);

		if(!isset($this->dispatcher)) {
			return;
		}

		try {
			$response = $this->dispatcher->generateErrorResponse($throwable);
			$this->finish($response);
		}
		catch(Throwable $innerThrowable) {
			$this->dispatcher->generateBasicErrorResponse($innerThrowable, $throwable);
		}
	}

	private function logError(Throwable $throwable):void {
		// TODO: implement
	}

	/** @param array<string, array<string>> $headers */
	private function outputHeaders(int $statusCode, array $headers):void {
		// TODO: implement
	}

	private function outputResponseBody(Stream $responseBody):void {
		// TODO: implement
	}
}
