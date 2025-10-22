<?php
namespace Gt\WebEngine;

use Closure;
use Throwable;
use ErrorException;
use Gt\WebEngine\Debug\OutputBuffer;
use Gt\WebEngine\Debug\Timer;
use Gt\WebEngine\Redirection\Redirect;
use Gt\WebEngine\Dispatch\Dispatcher;
use Gt\WebEngine\Dispatch\DispatcherFactory;
use Gt\Config\Config;
use Gt\Config\ConfigFactory;
use Gt\Http\RequestFactory;
use Gt\Http\Response;
use Gt\Http\ServerRequest;
use Gt\Http\Stream;
use Gt\ProtectedGlobal\Protection;

/**
 * The fundamental purpose of any PHP framework is to provide a mechanism for
 * generating an HTTP response from an incoming HTTP request. This functionality
 * is what's wrapped into the WebEngine Application class here.
 *
 * The heavy lifting of converting Request to Response is performed in the
 * Dispatcher's generateResponse() method.
 *
  * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class Application {
	private Redirect $redirect;
	private Timer $timer;
	private OutputBuffer $outputBuffer;
	private RequestFactory $requestFactory;
	/** @var array<string, array<string, string|array<string, string>>> */
	private array $globals;
	private Config $config;
	private DispatcherFactory $dispatcherFactory;
	private Dispatcher $dispatcher;
	private bool $finished = false;

	/**
	 * @param null|array<string, array<string, string>> $globals
	 * @SuppressWarnings("PHPMD.Superglobals")
	 */
	public function __construct(
		?Redirect $redirect = null,
		?Config $config = null,
		?Timer $timer = null,
		?OutputBuffer $outputBuffer = null,
		?RequestFactory $requestFactory = null,
		?DispatcherFactory $dispatcherFactory = null,
		?array $globals = null,
		?Closure $handleShutdown = null,
	) {
		$this->gtCompatibility();
		$this->redirect = $redirect ?? new Redirect();
		$this->config = $config ?? $this->loadConfig();
		$this->timer = $timer ?? new Timer(
			$this->config->getFloat("app.slow_delta"),
			$this->config->getFloat("app.very_slow_delta"),
		);
		$this->outputBuffer = $outputBuffer ?? new OutputBuffer();
		$this->requestFactory = $requestFactory ?? new RequestFactory();
		$this->dispatcherFactory = $dispatcherFactory ?? new DispatcherFactory();
		$this->globals = array_merge([
			"_SERVER" => [],
			"_FILES" => [],
			"_GET" => [],
			"_POST" => [],
			"_ENV" => [],
			"_COOKIE" => [],
		], $globals ?? $GLOBALS);
		register_shutdown_function($handleShutdown ?? $this->handleShutdown(...));
	}

	public function start():void {
// Before we start, we check if the current URI should be redirected. If it
// should, we won't go any further into the lifecycle.
		$this->redirect->execute();
		
// The first thing done within the WebEngine lifecycle is start a timer.
// This timer is only used again at the end of the call, when finish() is
// called - at which point the entire duration of the request is logged out (and
// slow requests are highlighted as a NOTICE).
		$this->timer->start();

// Starting the output buffer is done before any logic is executed, so any calls
// to any area of code will not accidentally send output to the web browser.
		$this->outputBuffer->start();

// PHP.GT provides object-oriented interfaces to all values stored in $_SERVER,
// $_FILES, $_GET, and $_POST - to enforce good encapsulation and safe variable
// usage, the globals are protected against accidental misuse.
		$this->protectGlobals();

// The RequestFactory takes the necessary global arrays to construct a
// ServerRequest object. The $_SERVER array contains metadata about the request,
// such as headers and server variables. $_FILES contains any uploaded files,
// $_GET contains query parameters from the URL, and $_POST contains form data.
// These arrays are optional and will default to empty arrays if not provided,
// ensuring the ServerRequest can always be constructed safely.
		/** @var ServerRequest $request */
		$request = $this->requestFactory->createServerRequestFromGlobalState(
			$this->globals["_SERVER"] ?? [],
			$this->globals["_FILES"] ?? [],
			$this->globals["_GET"] ?? [],
			$this->globals["_POST"] ?? [],
		);

// The Dispatcher is a core component responsible for:
// 1. Executing the application's routing logic to match the incoming request
// 2. Running any middleware defined for the matched route
// 3. Executing the appropriate page logic functions
// 4. Generating and returning the HTTP response
//
// It's critical to store the Dispatcher as a class property because if an error
// occurs during request processing, the error handling system needs access to
// the same Dispatcher instance that has the original request context,
// configuration, and other dependencies required to properly generate and
// display error pages. This ensures errors can be handled consistently using
// the application's error templates and logging mechanisms.
		$this->dispatcher = $this->dispatcherFactory->create(
			$this->config,
			$request,
			$this->globals,
			$this->finish(...),
		);

		try {
			$response = $this->dispatcher->generateResponse();
		}
		catch(Throwable $throwable) {
			var_dump($throwable);
			die("ERRRRRRRRRRRRRRRRRRR");
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
	 * Registers a namespace compatibility autoloader to bridge the
	 * Gt -> GT namespace transition.
	 *
	 * As part of the PHP.GT rebranding for WebEngine v5, all references to
	 * "GT" are being standardised to uppercase. However, the framework
	 * consists of 40+ repositories that cannot all be refactored
	 * simultaneously. This compatibility layer allows new code to reference
	 * classes using the GT\ namespace while the underlying packages still
	 * define classes with the Gt\ namespace.
	 */
	private function gtCompatibility():void {
		spl_autoload_register(function(string $class):void {
			if(str_starts_with($class, 'GT\\')) {
				$legacyClass = 'Gt' . substr($class, 2);
				// Trigger autoloading for the legacy class
				spl_autoload_call($legacyClass);
				// Only create alias if it was loaded and target doesn't already exist
				if((class_exists($legacyClass, false) || interface_exists($legacyClass, false) || trait_exists($legacyClass, false))
					&& !class_exists($class, false) && !interface_exists($class, false) && !trait_exists($class, false)) {
					class_alias($legacyClass, $class);
				}
			}
		}, true, true);
	}

	private function protectGlobals():void {
		$protection = new Protection();
		$protection->overrideInternals(
			$protection->removeGlobals([
				"server" => $this->globals["_SERVER"],
				"files" => $this->globals["_FILES"],
				"get" => $this->globals["_GET"],
				"post" => $this->globals["_POST"],
				"env" => $this->globals["_ENV"],
				"cookie" => $this->globals["_COOKIE"],
			], [
				"_ENV" => explode(",", $this->config->getString("app.globals_whitelist_env") ?? ""),
				"_SERVER" => explode(",", $this->config->getString("app.globals_whitelist_server") ?? ""),
				"_GET" => explode(",", $this->config->getString("app.globals_whitelist_get") ?? ""),
				"_POST" => explode(",", $this->config->getString("app.globals_whitelist_post") ?? ""),
				"_FILES" => explode(",", $this->config->getString("app.globals_whitelist_files") ?? ""),
				"_COOKIE" => explode(",", $this->config->getString("app.globals_whitelist_cookies") ?? ""),
			])
		);
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
			$response = $this->dispatcher->generateBasicErrorResponse($innerThrowable, $throwable);
		}
		$this->outputHeaders(
			$response->getStatusCode(),
			$response->getHeaders(),
		);
		/** @var Stream $responseBody */
		$responseBody = $response->getBody();
		$this->outputResponseBody($responseBody);
	}

	private function logError(Throwable $throwable):void {
		// TODO: implement
	}

	/** @param array<string, array<string>> $headers */
	private function outputHeaders(int $statusCode, array $headers):void {
		foreach($headers as $key => $value) {
// TODO: Is this how multi-value headers should be set?
			$valueString = implode(";", $value);
			header("$key: $valueString", true);
		}

		http_response_code($statusCode);

	}

	/**
	 * The response body is not the same as the currently-held output
	 * buffer ($this->outputBuffer). The output buffer is used for debug
	 * purposes, to allow developers to use var_dump, echo, etc. without
	 * messing up their page.
	 *
	 * The response body is the actual response HTML, JSON, etc. that is
	 * to be rendered directly to the web client.
	 *
	 * `ob_*` functions are used here to ensure that the response body is
	 * flushed and doesn't get rendered into another open buffer.
	 */
	private function outputResponseBody(Stream $responseBody):void {
		$length = $this->config->getInt("app.render_buffer_size");

		$responseBody->rewind();
		ob_start();
		while(!$responseBody->eof()) {
			echo $responseBody->read($length);
			ob_flush();
			flush();
		}

		ob_end_flush();
	}
}
