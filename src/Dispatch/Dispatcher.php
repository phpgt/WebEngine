<?php
namespace GT\WebEngine\Dispatch;

use Closure;
use GT\Config\Config;
use GT\Csrf\Exception\CsrfException;
use GT\Csrf\HTMLDocumentProtector;
use GT\Csrf\SessionTokenStore;
use GT\Dom\Element;
use GT\Dom\HTMLDocument;
use GT\DomTemplate\BindableCache;
use GT\DomTemplate\Binder;
use GT\DomTemplate\ComponentBinder;
use GT\DomTemplate\DocumentBinder;
use GT\DomTemplate\ElementBinder;
use GT\DomTemplate\HTMLAttributeBinder;
use GT\DomTemplate\HTMLAttributeCollection;
use GT\DomTemplate\ListBinder;
use GT\DomTemplate\ListElementCollection;
use GT\DomTemplate\PlaceholderBinder;
use GT\DomTemplate\TableBinder;
use GT\Http\Request;
use GT\Http\Response;
use GT\Http\ResponseStatusException\ClientError\HttpNotFound;
use GT\Http\ResponseStatusException\ResponseStatusException;
use GT\Http\StatusCode;
use GT\Http\Stream;
use GT\Input\Input;
use GT\Input\InputData\InputData;
use GT\Json\Schema\JSONDocument;
use GT\Logger\Log;
use GT\Routing\Assembly;
use GT\Routing\BaseRouter;
use GT\Routing\Path\DynamicPath;
use GT\ServiceContainer\Container;
use GT\ServiceContainer\Injector;
use GT\WebEngine\Init\RequestInit;
use GT\WebEngine\Init\RouterInit;
use GT\WebEngine\Init\SessionInit;
use GT\WebEngine\Init\ViewModelInit;
use GT\WebEngine\Logic\AppAutoloader;
use GT\WebEngine\Logic\LogicExecutor;
use GT\WebEngine\Logic\LogicStreamHandler;
use GT\WebEngine\Logic\ViewModelProcessor;
use GT\WebEngine\Service\ContainerFactory;
use GT\WebEngine\View\HTMLView;
use GT\WebEngine\View\JSONView;
use GT\WebEngine\View\NullView;
use GT\WebEngine\View\ViewStreamer;
use Throwable;

/**
 * @SuppressWarnings("PHPMD.TooManyFields")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class Dispatcher {
	private const LOGIC_EXECUTION_HEADER = "X-Logic-Execution";
	private const COMPONENT_INPUT_NAME = "__component";

	private Config $config;
	private Request $request;
	/** @var array<string, array<string, string|array<string, string>>> */
	private array $globals;
	private Closure $finishCallback;

	private AppAutoloader $appAutoloader;
	private LogicStreamHandler $logicStreamHandler;
	private Input $input;
	private Response $response;
	private Container $serviceContainer;

	private Injector $injector;
	private NullView|JSONView|HTMLView $view;
	private HTMLDocument|JSONDocument $viewModel;
	private ?ViewModelProcessor $viewModelProcessor;
	private BaseRouter $router;
	private ?SessionInit $sessionInit = null;
	private Assembly $logicAssembly;
	private Assembly $viewAssembly;
	private LogicExecutor $logicExecutor;
	private ViewStreamer $viewStreamer;

	private HeaderManager $headerManager;
	private Closure $viewInitCb;
	private bool $redirectPrepared = false;
	private bool $interruptResponse = false;
	/** @var array<int, string> */
	private array $logicExecutionOrder = [];

	/**
	 * @param array<string, array<string, string|array<string, string>>> $globals
	 * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
	 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
	 */
	// phpcs:disable Generic.Metrics.CyclomaticComplexity.TooHigh
	public function __construct(
		Config $config,
		Request $request,
		array $globals,
		Closure $finishCallback,
		?int $errorStatus = null,

		?AppAutoloader $appAutoloader = null,
		?LogicStreamHandler $logicStreamHandler = null,

		?RequestInit $requestInit = null,
		?RouterInit $routerInit = null,
		?SessionInit $sessionInit = null,
		?ViewModelInit $viewModelInit = null,

		?LogicExecutor $logicExecutor = null,
		?ViewStreamer $viewStreamer = null,
		?HeaderManager $headerManager = null,
	) {
// Set the first Dispatcher dependencies - these are required to be passed from
// the Application, so come first as non-nullable:
		$this->config = $config;
		$this->request = $request;
		$this->globals = $globals;
		$this->finishCallback = $finishCallback;

// Next, we set up the response and service container, which will be used by the
// Dispatcher dependencies throughout the request-response lifecycle.
		$this->response = $this->setupResponse();
		$containerFactory = new ContainerFactory();
		$this->serviceContainer = $containerFactory->create($this->config);
		$this->injector = new Injector($this->serviceContainer);
		$this->serviceContainer->set($this->request);
		$this->serviceContainer->set($this->response);
		$this->serviceContainer->set($this->request->getUri());

// The following Dispatcher dependencies are all nullable. They don't expect to
// be passed from the Application, so will default to null. The reason for this
// is that unit tests can pass mocked versions of all dependencies to produce a
// predictable application state without requiring a real web browser context.
		$appNamespace = $config->getString("app.namespace");
		$this->appAutoloader = $appAutoloader ?? new AppAutoloader(
			$appNamespace,
			$config->getString("app.class_dir"),
		);
		$this->appAutoloader->setup();

// TODO: I think it makes sense to initialise and setup the logic stream handler just before the logic executor is set up.
		$this->logicStreamHandler = $logicStreamHandler ?? new LogicStreamHandler();
		$this->logicStreamHandler->setup();

		$pathNormaliser = new PathNormaliser();
		/** @var \GT\Http\Uri $requestUri */
		$requestUri = $request->getUri();
		$requestInit = $requestInit ?? new RequestInit(
			$pathNormaliser,
			$requestUri,
			$config->getBool("app.force_trailing_slash") ?? true,
			$this->response->redirect(...),
			$this->globals["_GET"],
			$this->globals["_POST"],
			$this->globals["_FILES"],
			$this->globals["_SERVER"],
		);
		$this->input = $requestInit->getInput();
		$this->serviceContainer->set($this->input);
		$this->serviceContainer->set($requestInit->getServerInfo());
		if($this->isRedirectPrepared()) {
			$this->redirectPrepared = true;
			return;
		}

		$routerInit = $routerInit ?? new RouterInit(
			$this->request,
			$this->response,
			$this->serviceContainer,
			$appNamespace,
			$this->config->getString("router.router_file"),
			$this->config->getString("router.router_class"),
			dirname(__FILE__, 3) . DIRECTORY_SEPARATOR . "router.default.php",
			"\\GT\\WebEngine\\DefaultRouter",
			$this->config->getInt("router.redirect_response_code"),
			$this->config->getString("router.default_content_type"),
			$errorStatus,
		);
		$view = $routerInit->getView();
		$this->serviceContainer->set($view);
		$this->view = $view;
		$this->viewModel = $routerInit->getViewModel();
		$this->serviceContainer->set($this->viewModel);
		$this->router = $routerInit->getBaseRouter();
		$this->serviceContainer->set($this->router);
		$dynamicPath = $routerInit->getDynamicPath();
		$this->serviceContainer->set($dynamicPath);
		$this->viewAssembly = $routerInit->getViewAssembly();
		$this->logicAssembly = $routerInit->getLogicAssembly();

		$sessionInit = $sessionInit ?? new SessionInit(
			$this->config->getString("session.name"),
			$this->config->getString("session.handler"),
			$this->config->getString("session.path"),
			$this->config->getBool("session.use_trans_sid"),
			$this->config->getBool("session.use_cookies"),
			cookieLifetime: $this->config->getInt("session.cookie_lifetime"),
			cookiePath: $this->config->getString("session.cookie_path"),
			cookieDomain: $this->config->getString("session.cookie_domain"),
			cookieSecure: $this->config->getBool("session.cookie_secure"),
			cookieHttpOnly: $this->config->getBool("session.cookie_httponly"),
			cookieSameSite: $this->config->getString("session.cookie_samesite"),
			useOnlyCookies: $this->config->getBool("session.use_only_cookies"),
			useStrictMode: $this->config->getBool("session.use_strict_mode"),
			currentCookieArray: $this->globals["_COOKIE"],
		);
		$this->sessionInit = $sessionInit;
		$this->serviceContainer->set($sessionInit->getSession());

		$viewModelInit = $viewModelInit ?? new ViewModelInit(
			$this->viewModel,
			$this->config->getString("view.component_directory"),
			$this->config->getString("view.partial_directory"),
		);
		$this->viewModelProcessor = $viewModelInit->getViewModelProcessor();
		$this->viewInitCb = $this->viewModel instanceof HTMLDocument
		? function()use($viewModelInit):void {
			$documentBinder = $this->serviceContainer->get(Binder::class);
			assert($documentBinder instanceof DocumentBinder);
			$viewModelInit->initHTMLDocument(
				$documentBinder,
				$this->serviceContainer->get(HTMLAttributeBinder::class),
				$this->serviceContainer->get(ListBinder::class),
				$this->serviceContainer->get(TableBinder::class),
				$this->serviceContainer->get(ElementBinder::class),
				$this->serviceContainer->get(PlaceholderBinder::class),
				$this->serviceContainer->get(HTMLAttributeCollection::class),
				$this->serviceContainer->get(ListElementCollection::class),
				$this->serviceContainer->get(BindableCache::class),
			);
		}
		: static fn() => null;

		$this->logicExecutor = $logicExecutor ?? new LogicExecutor(
			$appNamespace,
			$this->injector,
		);
		$this->viewStreamer = $viewStreamer ?? new ViewStreamer();
		$this->headerManager = $headerManager ?? new HeaderManager();
	}
	// phpcs:enable Generic.Metrics.CyclomaticComplexity.TooHigh

	public function generateResponse():Response {
		if($this->redirectPrepared) {
			return $this->response;
		}
		
// The routing is now complete and all services are properly configured. This
// function's responsibility is to execute the logic that builds the response.
// Since this involves running user code that may throw errors, we execute each
// step individually to ensure proper error handling throughout the process.
		if(!$this->viewAssembly->containsDistinctFile() && !$this->logicAssembly->containsDistinctFile()) {
			$this->response = $this->response->withStatus(StatusCode::NOT_FOUND);
			throw new HttpNotFound();
		}

		$this->processResponse();

		if(!$this->response->getStatusCode()) {
			$this->response = $this->response->withStatus(StatusCode::OK);
		}

		return $this->response;
	}

	public function generateErrorResponse(Throwable $throwable):Response {
		$this->serviceContainer->set($throwable);

		$errorStatusCode = StatusCode::INTERNAL_SERVER_ERROR;
		if($throwable instanceof ResponseStatusException) {
			$errorStatusCode = $throwable->getHttpCode();
		}

		if(!$this->viewAssembly->containsDistinctFile()) {
			Log::warning(
				"Error page template not found for HTTP " . $errorStatusCode,
				$this->getLogContext(),
			);
			throw new ErrorPageNotFoundException(code: $errorStatusCode);
		}

		$this->processResponse($throwable);
		$this->response = $this->response->withStatus($errorStatusCode);
		return $this->response;
	}

	public function generateBasicErrorResponse(
		Throwable $actualThrowable,
		Throwable $innerThrowable,
	):Response {
// TODO: Handle innerThrowable for if there's an error thrown in WebEngine itself.
		$errorStatusCode = $this->response->getStatusCode();
		if(!$errorStatusCode) {
			$errorStatusCode = $actualThrowable instanceof ResponseStatusException
				? $actualThrowable->getHttpCode()
				: StatusCode::INTERNAL_SERVER_ERROR;
		}
		$errorType = get_class($actualThrowable);
//		var_dump($errorType);die();
		$errorMessage = $actualThrowable->getMessage();
		$detail = "";

		$errorPageDir = $this->config->getString("app.error_page_dir");

		if(!$errorMessage) {
			if($actualThrowable instanceof HttpNotFound) {
				$errorMessage = "The server could not find the requested resource.";

				if(!$this->config->getBool("app.production")) {
					$detail .= " Additionally, there was no error page found in your "
						. "application at <strong>$errorPageDir/$errorStatusCode.html</strong>";
				}
			}
		}

		if($errorStatusCode >= 500 && !$this->config->getBool("app.production")) {
			$detail .= implode(":", [
				$actualThrowable->getFile(),
				$actualThrowable->getLine(),
			]) . "\n\n";
			foreach($actualThrowable->getTrace() as $i => $t) {
				if(isset($t["file"]) && str_ends_with($t["file"], "/vendor/phpgt/servicecontainer/src/Injector.php")) {
					break;
				}
				$detail .= "#$i\n";

				foreach($t as $key => $value) {
					$detail .= "$key:\t$value\n";
				}
			}
		}

		// TODO: Load this HTML from a file in the root of WebEngine!
		$html = <<<HTML
		<!doctype html>
		<h1>Error $errorStatusCode</h1>
		<h2>$errorType</h2>
		<p>$errorMessage</p>
		<p style="white-space: pre">$detail</p>
		HTML;

		$body = new Stream();
		$body->write($html);
		$response = new Response(null, null, $this->request);
		$response = $response->withBody($body);
		return $response->withStatus($errorStatusCode);
	}

	private function setupResponse():Response {
		$response = new Response(null, null, $this->request);
		$response->setExitCallback(function() {
			($this->finishCallback)($this->response);
			if($this->interruptResponse && $this->isRedirectPrepared()) {
				throw new ResponseInterrupt();
			}
		});
		return $response;
	}

	private function handleLogicExecution(
		Assembly $logicAssembly,
		Input $input,
		?Element $component = null,
	):void {
		$extraArgs = [];

		if($component) {
			$binder = new ComponentBinder($this->viewModel);
			$binder->setDependencies(
				$this->serviceContainer->get(ElementBinder::class),
				$this->serviceContainer->get(PlaceholderBinder::class),
				$this->serviceContainer->get(TableBinder::class),
				$this->serviceContainer->get(ListBinder::class),
				$this->serviceContainer->get(ListElementCollection::class),
				$this->serviceContainer->get(BindableCache::class),
			);
			$binder->setComponentBinderDependencies($component);
			$extraArgs[Binder::class] = $binder;
			$extraArgs[Element::class] = $component;
// This is a temporary fix while repos transition to the GT namespace:
			$legacyBinderClass = Binder::class[0] . strtolower(Binder::class[1]) . substr(Binder::class, 2);
			$legacyElementClass = Element::class[0] . strtolower(Element::class[1]) . substr(Element::class, 2);
			$extraArgs[$legacyBinderClass] = $binder;
			$extraArgs[$legacyElementClass] = $component;
		}

		foreach($this->logicExecutor->invoke($logicAssembly, "go_before", $extraArgs) as $functionReference) {
			$this->recordLogicExecution($functionReference);
		}

		if($doAction = $this->getDoActionForLogic($input, $component)) {
			$doName = "do_" . str_replace("-", "_", $doAction);

			foreach($this->logicExecutor->invoke($logicAssembly, $doName, $extraArgs) as $functionReference) {
				$this->recordLogicExecution($functionReference);
			}
		}

		foreach($this->logicExecutor->invoke($logicAssembly, "go", $extraArgs) as $functionReference) {
			$this->recordLogicExecution($functionReference);
		}

		foreach($this->logicExecutor->invoke($logicAssembly, "go_after", $extraArgs) as $functionReference) {
			$this->recordLogicExecution($functionReference);
		}
	}

	private function getDoActionForLogic(Input $input, ?Element $component):?string {
		$doAction = $input->getString("do");
		if($doAction === null || $doAction === "") {
			return null;
		}

		$submittedComponent = $input->getString(self::COMPONENT_INPUT_NAME);
		if($submittedComponent === null || $submittedComponent === "") {
			return $doAction;
		}

		if(!$component) {
			return null;
		}

		if(strtolower($component->tagName) !== strtolower($submittedComponent)) {
			return null;
		}

		return $doAction;
	}

	/**
	 * @return void
	 */
	// phpcs:disable Generic.Metrics.CyclomaticComplexity.TooHigh
	public function processResponse(
		?Throwable $errorThrowable = null,
	):void {
		$this->logicExecutionOrder = [];
		$this->interruptResponse = true;
		$csrfToken = null;

		try {
			$dynamicPath = $this->serviceContainer->get(DynamicPath::class);

			$this->viewModelProcessor?->processDynamicPath(
				$this->viewModel,
				$dynamicPath,
			);

			$componentList = $this->viewModelProcessor?->processPartialContent(
				$this->viewModel,
				$this->viewAssembly,
			);

// If there's an error, we don't need to verify the token, otherwise error
// redirects could get stuck in a state where it's impossible to refresh the
// page due to the token already being used.
			if(!$errorThrowable) {
				$csrfToken = $this->verifyCsrfRequest(
					$this->request->getMethod(),
					$this->input->getAll(Input::DATA_BODY),
				);
			}
			($this->viewInitCb)();
			if($errorThrowable) {
				$this->bindErrorDetails($errorThrowable);
			}

			foreach($componentList ?? [] as $componentLogic) {
				$assembly = $componentLogic->assembly;
				$componentElement = $componentLogic->component;
				$this->serviceContainer->set($componentElement);

				try {
					$this->handleLogicExecution(
						$assembly,
						$this->input,
						$componentElement,
					);
				}
				catch(Throwable $throwable) {
					if(!$errorThrowable) {
						throw $throwable;
					}
				}
			}

			try {
				$this->handleLogicExecution(
					$this->logicAssembly,
					$this->input,
				);
			}
			catch(Throwable $throwable) {
				if(!$errorThrowable) {
					throw $throwable;
				}
			}

			if($this->logicExecutionOrder) {
				$this->response = $this->response->withHeader(
					self::LOGIC_EXECUTION_HEADER,
					implode(";", $this->logicExecutionOrder),
				);
			}

			if($responseWithHeader = $this->headerManager->applyWithHeader(
				$this->response->getResponseHeaders(),
				$this->response->withHeader(...)
			)) {
				$this->response = $responseWithHeader;
			}

			$documentBinder = $this->serviceContainer->get(Binder::class);
			assert($documentBinder instanceof DocumentBinder);
			$documentBinder->cleanupDocument();
			$this->protectHtmlDocumentFromCsrf();

			$this->viewStreamer->stream($this->view, $this->viewModel);
			$this->consumeCsrfToken($csrfToken);
		}
		catch(ResponseInterrupt) {
			$this->consumeCsrfToken($csrfToken);
			return;
		}
		finally {
			$this->interruptResponse = false;
		}
	}
	// phpcs:enable Generic.Metrics.CyclomaticComplexity.TooHigh

	private function recordLogicExecution(string $functionReference):void {
		$refWithoutAttributes = explode("#", $functionReference, 2)[0];
		$separatorPosition = strrpos($refWithoutAttributes, "::");
		if($separatorPosition === false) {
			return;
		}

		$file = substr($refWithoutAttributes, 0, $separatorPosition);
		$function = substr($refWithoutAttributes, $separatorPosition + 2);
		$function = preg_replace('/\(\)$/', '', $function);
		if($function === null || $function === "") {
			return;
		}

		$this->logicExecutionOrder[] = sprintf(
			"%s:%s",
			$this->normaliseLogicExecutionFile($file),
			$function,
		);
	}

	private function normaliseLogicExecutionFile(string $file):string {
		$cwd = getcwd();
		if($cwd && str_starts_with($file, $cwd . DIRECTORY_SEPARATOR)) {
			$file = substr($file, strlen($cwd) + 1);
		}

		if(str_ends_with($file, ".php")) {
			$file = substr($file, 0, -4);
		}

		return $file;
	}

	private function verifyCsrfRequest(string $method, InputData $inputData):?string {
		if($method !== "POST" || $this->isIgnoredCsrfPath()) {
			return null;
		}

		$tokenStore = $this->createCsrfTokenStore();

		try {
			$postData = $inputData->asArray();
			if(empty($postData)) {
				return null;
			}

			if(!isset($postData[HTMLDocumentProtector::TOKEN_NAME])) {
				$tokenStore->verify($inputData);
			}

			$token = $postData[HTMLDocumentProtector::TOKEN_NAME];
			$tokenStore->verifyToken($token);
			return $token;
		}
		catch(CsrfException $exception) {
			Log::warning(
				"CSRF verification failed: " . $exception->getMessage(),
				$this->getLogContext(),
			);
			throw $this->createCsrfForbiddenException($exception->getMessage());
		}
	}

	private function consumeCsrfToken(?string $token):void {
		if(!$token) {
			return;
		}

		$this->createCsrfTokenStore()->consumeToken($token);
	}

	private function protectHtmlDocumentFromCsrf():void {
		if(!($this->viewModel instanceof HTMLDocument)) {
			return;
		}

		$protector = new HTMLDocumentProtector(
			$this->viewModel,
			$this->createCsrfTokenStore(),
		);
		$protector->protect($this->getCsrfTokenSharingMode());
	}

	private function createCsrfTokenStore():SessionTokenStore {
		$tokenStore = new SessionTokenStore(
			$this->sessionInit->getSession(),
			$this->config->getInt("security.csrf_max_tokens"),
		);
		$tokenStore->setTokenLength(
			$this->config->getInt("security.csrf_token_length")
		);
		return $tokenStore;
	}

	private function getCsrfTokenSharingMode():string {
		return match(strtolower($this->config->getString("security.csrf_token_sharing"))) {
			"per-form" => HTMLDocumentProtector::ONE_TOKEN_PER_FORM,
			default => HTMLDocumentProtector::ONE_TOKEN_PER_PAGE,
		};
	}

	private function isIgnoredCsrfPath():bool {
		$ignoredPathList = array_filter(array_map(
			trim(...),
			explode(",", $this->config->getString("security.csrf_ignore_path")),
		));
		$requestPath = $this->request->getUri()->getPath();

		foreach($ignoredPathList as $ignoredPathPattern) {
			if(fnmatch($ignoredPathPattern, $requestPath)) {
				return true;
			}
		}

		return false;
	}

	private function createCsrfForbiddenException(string $message):ResponseStatusException {
		return new class($message) extends ResponseStatusException {
			public function getHttpCode():int {
				return StatusCode::FORBIDDEN;
			}
		};
	}

	/** @return array<string, mixed> */
	private function getLogContext():array {
		$context = [
			"uri" => $this->request->getUri()->getPath(),
		];
		$remoteAddress = $this->globals["_SERVER"]["REMOTE_ADDR"] ?? "";
		if($remoteAddress !== "") {
			$context["id"] = $remoteAddress . ":" . substr(session_id(), 0, 4);
		}

		return $context;
	}

	public function getSessionInit():?SessionInit {
		return $this->sessionInit;
	}

	private function isRedirectPrepared():bool {
		$status = $this->response->getStatusCode();
		if($status < 300 || $status >= 400) {
			return false;
		}

		return $this->response->hasHeader("Location");
	}

	// phpcs:disable Generic.Metrics.CyclomaticComplexity.TooHigh
	private function bindErrorDetails(Throwable $throwable):void {
		$trace = $throwable->getTrace();
		array_unshift($trace, [
			"file" => $throwable->getFile(),
			"line" => $throwable->getLine(),
			"class" => get_class($throwable) . "(\"" . $throwable->getMessage() . "\")",
		]);
		foreach($trace as $i => $traceItem) {
			if(isset($traceItem["file"])) {
				$cwd = getcwd() . DIRECTORY_SEPARATOR;
				if(str_starts_with($traceItem["file"], $cwd)) {
					$trace[$i]["file"] = substr($traceItem["file"], strlen($cwd));
				}
			}

			if(isset($traceItem["file"]) && str_starts_with($traceItem["file"], "gt-logic-stream://")) {
				$trace = array_slice($trace, 0, $i + 1);
				break;
			}
		}

		$binder = $this->serviceContainer->get(Binder::class);
		$binder->bindValue($throwable->getMessage());
		if(!$this->config->getBool("app.production")) {
			$traceString = "";
			foreach($trace as $i => $traceItem) {
				$traceString .= "#$i ";
				if(isset($traceItem["class"])) {
					$traceString .= $traceItem["class"];
					if(isset($traceItem["function"])) {
						$traceString .= "::";
						$traceString .= $traceItem["function"];
					}
					$traceString .= " -> ";
				}
				if(isset($traceItem["file"])) {
					$traceString .= $traceItem["file"];
				}
				if(isset($traceItem["line"])) {
					$traceString .= "(" . $traceItem["line"] . ")";
				}
				$traceString .= "\n";
			}
			$binder->bindKeyValue("trace", $traceString);
		}
	}
	// phpcs:enable Generic.Metrics.CyclomaticComplexity.TooHigh
}
