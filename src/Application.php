<?php
namespace GT\WebEngine;

use Closure;
use GT\Http\ResponseStatusException\ClientError\HttpNotFound;
use GT\Http\ResponseStatusException\ClientError\ClientErrorException;
use GT\Http\ResponseStatusException\ResponseStatusException;
use GT\Logger\Log;
use GT\Logger\LogConfig;
use GT\Logger\LogHandler\StdErrHandler;
use GT\Logger\LogLevel;
use Throwable;
use ErrorException;
use ReflectionMethod;
use GT\WebEngine\Debug\OutputBuffer;
use GT\WebEngine\Debug\Timer;
use GT\WebEngine\Redirection\Redirect;
use GT\WebEngine\Redirection\RedirectUri;
use GT\WebEngine\Dispatch\Dispatcher;
use GT\WebEngine\Dispatch\DispatcherFactory;
use GT\Config\Config;
use GT\Config\ConfigFactory;
use GT\Http\Request;
use GT\Http\RequestFactory;
use GT\Http\Response;
use GT\Http\Stream;
use GT\ProtectedGlobal\Protection;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The fundamental purpose of any PHP framework is to provide a mechanism for
 * generating an HTTP response from an incoming HTTP request. This functionality
 * is what's wrapped into the WebEngine Application class here.
 *
 * The heavy lifting of converting Request to Response is performed in the
 * Dispatcher's generateResponse() method.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class Application {
	private Redirect $redirect;
	private Timer $timer;
	private OutputBuffer $outputBuffer;
	private RequestFactory $requestFactory;
	/** @var Request&ServerRequestInterface */
	private Request $request;
	/** @var array<string, array<string, string|array<string, string>>> */
	private array $globals;
	private Protection $globalProtection;
	private Config $config;
	private DispatcherFactory $dispatcherFactory;
	private Dispatcher $dispatcher;
	private static bool $loggerConfigured = false;
	private bool $finished = false;

	/**
	 * @param null|array<string, array<string, string>> $globals
	 * @SuppressWarnings("PHPMD.Superglobals")
	 */
	public function __construct(
		?Config $config = null,
		?Redirect $redirect = null,
		?Timer $timer = null,
		?OutputBuffer $outputBuffer = null,
		?RequestFactory $requestFactory = null,
		?DispatcherFactory $dispatcherFactory = null,
		?array $globals = null,
		?Closure $handleShutdown = null,
		?Protection $globalProtection = null,
	) {
		$this->config = $config ?? $this->loadConfig();
		$this->redirect = $redirect ?? new Redirect();
		$this->configureLoggerStreams();
		$application = $this;
		$this->timer = $timer ?? new Timer(
			$this->config->getFloat("app.slow_delta"),
			$this->config->getFloat("app.very_slow_delta"),
			fn(string $message) => Log::notice($message, $application->getLogContext()),
		);
		$this->outputBuffer = $outputBuffer ?? new OutputBuffer(
			$this->config->getBool("logger.debug_to_javascript")
		);
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
		$this->globalProtection = $globalProtection ?? new Protection();
		register_shutdown_function($handleShutdown ?? $this->handleShutdown(...));
	}

	public function start():void {
// Before we start, we check if the current URI should be redirected. If it
// should, we won't go any further into the lifecycle.
		$requestedPath = $this->getRequestedPath();
		$redirectUri = $this->redirect->execute($requestedPath);
		if($redirectUri) {
			$this->logRedirect($redirectUri, $requestedPath);
			return;
		}

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
		$request = $this->requestFactory->createServerRequestFromGlobalState(
			$this->globals["_SERVER"] ?? [],
			$this->globals["_FILES"] ?? [],
			$this->globals["_GET"] ?? [],
			$this->globals["_POST"] ?? [],
		);
		assert($request instanceof Request);
		$this->request = $request;

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
			$this->request,
			$this->globals,
			$this->finish(...),
		);

		try {
			$response = $this->dispatcher->generateResponse();
		}
		catch(Throwable $throwable) {
			if ($errorScript = $this->config->getString('app.error_script')) {
				$this->restoreGlobals();
				require($errorScript);
				return;
			}

			$this->logError($throwable);
			$errorStatus = 500;

			if($throwable instanceof ResponseStatusException) {
				$errorStatus = $throwable->getHttpCode();
			}

			$this->dispatcher = $this->dispatcherFactory->create(
				$this->config,
				$this->request,
				$this->globals,
				$this->finish(...),
				$errorStatus,
				$this->dispatcher->getSessionInit(),
			);

			try {
				$response = $this->dispatcher->generateErrorResponse($throwable);
			}
			catch(Throwable $innerThrowable) {
				$response = $this->dispatcher->generateBasicErrorResponse($throwable, $innerThrowable);
			}
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

		if($this->shouldLogRedirectResponse($response)) {
			$this->logInfoResponse(
				$response->getStatusCode(),
				$this->getLogContext($response)
			);
		}
		elseif($this->shouldLogRequest($response)) {
			Log::info(
				"HTTP " . $response->getStatusCode(),
				$this->getLogContext($response),
			);
		}

		/** @var Stream $responseBody */
		$responseBody = $response->getBody();
		$this->outputResponseBody(
			$responseBody,
			$this->outputBuffer->debugOutput(),
		);

		$this->timer->stop();
		$this->timer->logDelta();
	}

	private function protectGlobals():void {
		$this->globalProtection->overrideInternals(
			$this->globalProtection->removeGlobals([
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

	/** @SuppressWarnings("PHPMD.Superglobals") */
	public function restoreGlobals(): void {
		foreach ($this->globals as $key => $value) {
			$GLOBALS[$key] = $value;

			if (in_array($key, [
				"_GET",
				"_POST",
				"_SERVER",
				"_COOKIE",
				"_FILES",
				"_ENV"
			])) {
				$GLOBALS[substr($key, 1)] = $value;
			}
		}
	}

	private function loadConfig():Config {
		$configFactory = new ConfigFactory();
		return $configFactory->createForProject(
			getcwd(),
			"vendor/phpgt/webengine/config.default.ini"
		);
	}

	private function configureLoggerStreams():void {
		if(self::$loggerConfigured) {
			return;
		}

		$minimumLogLevel = $this->getMinimumLogLevel();
		$minimumLogLevelIndex = array_search($minimumLogLevel, LogLevel::ALL_LEVELS, true);
		if($minimumLogLevelIndex === false) {
			return;
		}

		$stderrMinLevel = $this->getStderrMinimumLogLevel();
		$stderrMinLevelIndex = array_search($stderrMinLevel, LogLevel::ALL_LEVELS, true);
		if($stderrMinLevelIndex === false) {
			return;
		}

		if(!class_exists(StdErrHandler::class)) {
			return;
		}

		$addHandlerMethod = new ReflectionMethod(LogConfig::class, "addHandler");

		if($addHandlerMethod->getNumberOfParameters() < 3) {
			return;
		}

		LogConfig::setDefaultHandlerLevel($minimumLogLevel);

		if($stderrMinLevelIndex > $minimumLogLevelIndex) {
			$stdoutMaxLevel = LogLevel::ALL_LEVELS[$stderrMinLevelIndex - 1];
			LogConfig::addHandler(
				LogConfig::getDefaultHandler(),
				$minimumLogLevel,
				$stdoutMaxLevel,
			);
		}
		LogConfig::addHandler(
			new StdErrHandler(),
			LogLevel::ALL_LEVELS[max($stderrMinLevelIndex, $minimumLogLevelIndex)],
			LogLevel::EMERGENCY,
		);
		self::$loggerConfigured = true;
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
		if($throwable instanceof HttpNotFound) {
			if($this->config->getBool("logger.log_404_to_error_log")) {
				$this->logErrorMessage(
					"HTTP " . $throwable->getHttpCode(),
					$this->getLogContext(),
				);
			}
			return;
		}

		if($throwable instanceof ClientErrorException) {
			return;
		}

		$this->logErrorMessage((string)$throwable);
	}

	/** @param array<string, mixed> $context */
	private function logErrorMessage(string $message, array $context = []):void {
		if(self::$loggerConfigured) {
			Log::error($message, $context);
			return;
		}

		$stderrMinLevel = $this->getStderrMinimumLogLevel();
		$stderrMinLevelIndex = array_search($stderrMinLevel, LogLevel::ALL_LEVELS, true);
		$errorLevelIndex = array_search(LogLevel::ERROR, LogLevel::ALL_LEVELS, true);
		if($stderrMinLevelIndex === false || $stderrMinLevelIndex > $errorLevelIndex) {
			Log::error($message, $context);
			return;
		}

		if(!str_ends_with($message, PHP_EOL)) {
			$message .= PHP_EOL;
		}
		file_put_contents("php://stderr", $message, FILE_APPEND);
	}

	private function getStderrMinimumLogLevel():string {
		$configuredLevel = strtoupper(
			$this->config->getString("logger.stderr_level") ?: LogLevel::ERROR
		);
		if(in_array($configuredLevel, LogLevel::ALL_LEVELS, true)) {
			return $configuredLevel;
		}

		return LogLevel::ERROR;
	}

	private function getMinimumLogLevel():string {
		$configuredLevel = $this->config->getString("logger.level")
			?: LogLevel::DEBUG;
		$configuredLevel = strtoupper($configuredLevel);
		if(in_array($configuredLevel, LogLevel::ALL_LEVELS, true)) {
			return $configuredLevel;
		}

		return LogLevel::DEBUG;
	}

	/**
	 * @return array<string, mixed>
	 * @SuppressWarnings("PHPMD.Superglobals")
	 */
	private function getLogContext(?Response $response = null):array {
		$uri = $this->request->getUri();
		$context = $this->buildLogContext(
			$uri->getPath(),
			$this->request->getQueryParams(),
			$this->request->getParsedBody(),
			$this->request->getServerParams()["REMOTE_ADDR"] ?? "",
		);
		if($response && $response->hasHeader("Location")) {
			$context["location"] = $response->getHeaderLine("Location");
		}

		return $context;
	}

	/**
	 * @param array<string, mixed> $query
	 * @param array<string, mixed>|object|null $postBody
	 * @return array<string, mixed>
	 */
	private function buildLogContext(
		string $uriPath,
		array $query = [],
		array|object|null $postBody = null,
		string $remoteAddress = "",
	):array {
		$postArray = is_array($postBody)
			? $postBody
			: [];

		$context = [
			"id" => $remoteAddress . ":" . substr(session_id(), 0, 4),
			"uri" => $uriPath,
		];
		if($query) {
			$context["query"] = $query;
		}
		if($postArray) {
			$context["post"] = $postArray;
		}

		return $context;
	}

	private function getRequestedPath():string {
		$requestUri = $this->globals["_SERVER"]["REQUEST_URI"] ?? "/";
		$path = parse_url($requestUri, PHP_URL_PATH);
		if(!is_string($path) || $path === "") {
			return "/";
		}

		return urldecode($path);
	}

	private function logRedirect(RedirectUri $redirectUri, string $requestedPath):void {
		if(!$this->config->getBool("logger.log_redirects")) {
			return;
		}

		$context = $this->buildLogContext(
			$requestedPath,
			$this->globals["_GET"],
			$this->globals["_POST"],
			$this->globals["_SERVER"]["REMOTE_ADDR"] ?? "",
		);
		$context["location"] = $redirectUri->uri;
		$this->logInfoResponse($redirectUri->code, $context);
	}

	private function shouldLogRedirectResponse(Response $response):bool {
		$statusCode = $response->getStatusCode();
		if($statusCode < 300 || $statusCode >= 400) {
			return false;
		}

		return $this->config->getBool("logger.log_redirects")
			&& $response->hasHeader("Location");
	}

	private function shouldLogRequest(Response $response):bool {
		if(!$this->config->getBool("logger.log_all_requests")) {
			return false;
		}

		$statusCode = $response->getStatusCode();
		if($statusCode === 404) {
			return false;
		}

		return $statusCode < 300 || $statusCode >= 400;
	}

	/** @param array<string, mixed> $context */
	private function logInfoResponse(int $statusCode, array $context):void {
		Log::info("HTTP " . $statusCode, $context);
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
	private function outputResponseBody(Stream $responseBody, ?string $debugScript = null):void {
		$length = $this->config->getInt("app.render_buffer_size");

		$responseBody->rewind();
		ob_start();
		while(!$responseBody->eof()) {
			$content = $responseBody->read($length);
			if($debugScript) {
				$closingBody = strpos($content, "</body>");
				if(false !== $closingBody) {
					$content = substr_replace($content, $debugScript, $closingBody, 0);
				}
			}
			echo $content;

			ob_flush();
			flush();
		}

		ob_end_flush();
	}
}
