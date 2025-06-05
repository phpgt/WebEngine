<?php
namespace Gt\WebEngine\Middleware;

use Gt\Config\Config;
use Gt\Config\ConfigSection;
use Gt\Csrf\HTMLDocumentProtector;
use Gt\Csrf\SessionTokenStore;
use Gt\Dom\Element;
use Gt\Dom\HTMLDocument;
use Gt\DomTemplate\BindableCache;
use Gt\DomTemplate\Binder;
use Gt\DomTemplate\ComponentBinder;
use Gt\DomTemplate\ComponentExpander;
use Gt\DomTemplate\DocumentBinder;
use Gt\DomTemplate\ElementBinder;
use Gt\DomTemplate\HTMLAttributeBinder;
use Gt\DomTemplate\HTMLAttributeCollection;
use Gt\DomTemplate\ListBinder;
use Gt\DomTemplate\ListElementCollection;
use Gt\DomTemplate\PartialContent;
use Gt\DomTemplate\PartialContentDirectoryNotFoundException;
use Gt\DomTemplate\PartialExpander;
use Gt\DomTemplate\PlaceholderBinder;
use Gt\DomTemplate\TableBinder;
use Gt\Http\Header\ResponseHeaders;
use Gt\Http\Response;
use Gt\Http\ServerInfo;
use Gt\Http\StatusCode;
use Gt\Input\Input;
use Gt\Input\InputData\InputData;
use Gt\Logger\LogConfig;
use Gt\Logger\LogHandler\FileHandler;
use Gt\Logger\LogHandler\StdOutHandler;
use Gt\Logger\LogHandler\StreamHandler;
use Gt\ProtectedGlobal\Protection;
use Gt\Routing\Assembly;
use Gt\Routing\BaseRouter;
use Gt\Routing\LogicStream\LogicStreamWrapper;
use Gt\Routing\Path\DynamicPath;
use Gt\ServiceContainer\Container;
use Gt\ServiceContainer\Injector;
use Gt\Session\Session;
use Gt\Session\SessionSetup;
use Gt\WebEngine\Logic\AppAutoloader;
use Gt\WebEngine\Logic\LogicExecutor;
use Gt\WebEngine\View\BaseView;
use Gt\WebEngine\View\NullView;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RequestHandler implements RequestHandlerInterface {
	/** @var callable(ResponseInterface,ConfigSection) */
	protected $finishCallback;
	protected Container $serviceContainer;
	protected Injector $injector;
	protected Response $response;
	protected Assembly $viewAssembly;
	protected Assembly $logicAssembly;
	protected DynamicPath $dynamicPath;
	protected HTMLDocument/*|NullViewModel*/ $viewModel;
	protected BaseView $view;

	public function __construct(
		protected readonly Config $config,
		callable $finishCallback,
		protected array $getArray,
		protected array $postArray,
		protected array $filesArray,
		protected array $serverArray,
	) {
		$this->finishCallback = $finishCallback;

		$this->setupLogger(
			$this->config->getSection("logger")
		);

		$appAutoloader = new AppAutoloader(
			$this->config->get("app.namespace"),
			$this->config->get("app.class_dir"),
		);
		$appAutoloader->setup();

		if(!in_array("gt-logic-stream", stream_get_wrappers())) {
			stream_wrapper_register(
				"gt-logic-stream",
				LogicStreamWrapper::class,
			);
		}
	}

	public function getConfigSection(string $sectionName):ConfigSection {
		return $this->config->getSection($sectionName);
	}

	public function getServiceContainer():Container {
		return $this->serviceContainer;
	}

	public function handle(
		ServerRequestInterface $request
	):ResponseInterface {
		$this->completeRequestHandling($request);
		return $this->response;
	}

	protected function completeRequestHandling(
		ServerRequestInterface $request
	):void {
		$this->setupResponse($request);
		$this->forceTrailingSlashes($request);
		$this->setupServiceContainer();

		$input = new Input($this->getArray, $this->postArray, $this->filesArray);

		$this->serviceContainer->set(
			$this->config,
			$request,
			$this->response,
			$this->response->headers,
			$input,
			new ServerInfo($this->serverArray),
		);
		$this->injector = new Injector($this->serviceContainer);

		$this->handleRouting($request);
		if(!$this->serviceContainer->has(Session::class)) {
			$this->handleSession();
		}

		$this->handleProtectedGlobals();

		if(isset($this->viewModel) && $this->viewModel instanceof HTMLDocument) {
			$this->handleHTMLDocumentViewModel();
//			$this->handleCsrf($request);
		}

		$this->handleLogicExecution($this->logicAssembly);

// TODO: Why is this in the handle function?
		$documentBinder = $this->serviceContainer->get(Binder::class);
		$documentBinder->cleanupDocument();

		$this->view->stream($this->viewModel);

		$responseHeaders = $this->serviceContainer->get(ResponseHeaders::class);
		foreach($responseHeaders->asArray() as $name => $value) {
			$this->response = $this->response->withHeader(
				$name,
				$value,
			);
		}
	}

	protected function handleRouting(ServerRequestInterface $request) {
		$router = $this->createRouter($this->serviceContainer);
		$router->route($request);

		$viewClass = $router->getViewClass() ?? NullView::class;
		$this->view = new $viewClass($this->response->getBody());

		$this->viewAssembly = $router->getViewAssembly();
		$this->logicAssembly = $router->getLogicAssembly();

		$this->dynamicPath = new DynamicPath(
			$request->getUri()->getPath(),
			$this->viewAssembly,
			$this->logicAssembly,
		);

		$this->serviceContainer->set($this->dynamicPath);

		if(!$this->viewAssembly->containsDistinctFile()) {
			$this->response = $this->response->withStatus(StatusCode::NOT_FOUND);
		}

		foreach($this->viewAssembly as $viewFile) {
			$this->view->addViewFile($viewFile);
		}
		if($viewModel = $this->view->createViewModel()) {
			$this->serviceContainer->set($viewModel);
			$this->viewModel = $viewModel;
		}
	}

	protected function handleHTMLDocumentViewModel():void {
		$expandedLogicAssemblyList = [];
		$expandedComponentList = [];

		try {
			$partial = new PartialContent(implode(DIRECTORY_SEPARATOR, [
				getcwd(),
				$this->config->getString("view.component_directory")
			]));
			$componentExpander = new ComponentExpander(
				$this->viewModel,
				$partial,
			);

			foreach($componentExpander->expand() as $componentElement) {
				$filePath = $this->config->getString("view.component_directory");
				$filePath .= "/";
				$filePath .= $componentElement->tagName;
				$filePath .= ".php";

				if(is_file($filePath)) {
					$componentAssembly = new Assembly();
					$componentAssembly->add($filePath);
					array_push($expandedLogicAssemblyList, $componentAssembly);
					array_push($expandedComponentList, $componentElement);
				}
			}
		}
		catch(PartialContentDirectoryNotFoundException) {}

		try {
			$partial = new PartialContent(implode(DIRECTORY_SEPARATOR, [
				getcwd(),
				$this->config->getString("view.partial_directory")
			]));

			$partialExpander = new PartialExpander(
				$this->viewModel,
				$partial
			);
			$partialExpander->expand();
		}
		catch(PartialContentDirectoryNotFoundException) {}

		$dynamicUri = $this->dynamicPath->getUrl("page/");
		$dynamicUri = str_replace("/", "--", $dynamicUri);
		$dynamicUri = str_replace("@", "_", $dynamicUri);
		$this->viewModel->body->classList->add("uri" . $dynamicUri);
		$bodyDirClass = "dir";
		foreach(explode("--", $dynamicUri) as $i => $pathPart) {
			if($i === 0) {
				continue;
			}
			$bodyDirClass .= "--$pathPart";
			$this->viewModel->body->classList->add($bodyDirClass);
		}

		$this->serviceContainer->get(HTMLAttributeBinder::class)->setDependencies(
			$this->serviceContainer->get(ListBinder::class),
			$this->serviceContainer->get(TableBinder::class),
		);
		$this->serviceContainer->get(ElementBinder::class)->setDependencies(
			$this->serviceContainer->get(HTMLAttributeBinder::class),
			$this->serviceContainer->get(HTMLAttributeCollection::class),
			$this->serviceContainer->get(PlaceholderBinder::class),
		);
		$this->serviceContainer->get(TableBinder::class)->setDependencies(
			$this->serviceContainer->get(ListBinder::class),
			$this->serviceContainer->get(ListElementCollection::class),
			$this->serviceContainer->get(ElementBinder::class),
			$this->serviceContainer->get(HTMLAttributeBinder::class),
			$this->serviceContainer->get(HTMLAttributeCollection::class),
			$this->serviceContainer->get(PlaceholderBinder::class),
		);
		$this->serviceContainer->get(ListBinder::class)->setDependencies(
			$this->serviceContainer->get(ElementBinder::class),
			$this->serviceContainer->get(ListElementCollection::class),
			$this->serviceContainer->get(BindableCache::class),
			$this->serviceContainer->get(TableBinder::class),
		);
		$this->serviceContainer->get(Binder::class)->setDependencies(
			$this->serviceContainer->get(ElementBinder::class),
			$this->serviceContainer->get(PlaceholderBinder::class),
			$this->serviceContainer->get(TableBinder::class),
			$this->serviceContainer->get(ListBinder::class),
			$this->serviceContainer->get(ListElementCollection::class),
			$this->serviceContainer->get(BindableCache::class),
		);

//		$listElementCollection = $this->serviceContainer->get(ListElementCollection::class);
//		var_dump($listElementCollection);die();

		foreach($expandedLogicAssemblyList as $i => $assembly) {
			$componentElement = $expandedComponentList[$i];
			if(!$componentElement) {
				var_dump($assembly);die();
			}
			$this->handleLogicExecution($assembly, $componentElement);
		}
	}

	protected function handleSession():void {
		$sessionConfig = $this->config->getSection("session");
		$sessionId = $_COOKIE[$sessionConfig["name"]] ?? null;
		$sessionHandler = SessionSetup::attachHandler(
			$sessionConfig->getString("handler")
		);

		$session = new Session(
			$sessionHandler,
			$sessionConfig,
			$sessionId,
		);
		$this->serviceContainer->set($session);
	}

	protected function handleCsrf(ServerRequestInterface $request):void {
		$shouldVerifyCsrf = true;
		$ignoredPathArray = explode(",", $this->config->getString("security.csrf_ignore_path") ?? "");
		foreach($ignoredPathArray as $ignoredPath) {
			if(empty($ignoredPath)) {
				continue;
			}

			if(str_contains($ignoredPath, "*")) {
				$pattern = strtr(rtrim($ignoredPath, "/"), [
					"*" => ".*",
				]);
				if(preg_match("|$pattern|", rtrim($request->getUri()->getPath(), "/"))) {
					$shouldVerifyCsrf = false;
				}
			}
			else {
				if(rtrim($request->getUri()->getPath(), "/") === rtrim($ignoredPath, "/")) {
					$shouldVerifyCsrf = false;
				}
			}
		}

		if($shouldVerifyCsrf) {
			$session = $this->serviceContainer->get(Session::class);
			$csrfTokenStore = new SessionTokenStore(
				$session->getStore("webengine.csrf", true),
				$this->config->getInt("security.csrf_max_tokens")
			);
			$csrfTokenStore->setTokenLength(
				$this->config->getInt("security.csrf_token_length")
			);

			if($request->getMethod() === "POST") {
				$csrfTokenStore->verify($_POST);
			}

			$sharing = match($this->config->getString("security.csrf_token_sharing")) {
				"per-page" => HTMLDocumentProtector::ONE_TOKEN_PER_PAGE,
				default => HTMLDocumentProtector::ONE_TOKEN_PER_FORM,
			};
			$protector = new HTMLDocumentProtector(
				$this->viewModel,
				$csrfTokenStore
			);
			$tokens = $protector->protect($sharing);
			$this->response = $this->response->withHeader($this->config->getString("security.csrf_header"), $tokens);
		}

	}

	protected function handleProtectedGlobals():void {
		Protection::overrideInternals(
			Protection::removeGlobals($GLOBALS, [
					"_ENV" => explode(",", $this->config->getString("app.globals_whitelist_env") ?? ""),
					"_SERVER" => explode(",", $this->config->getString("app.globals_whitelist_server") ?? ""),
					"_GET" => explode(",", $this->config->getString("app.globals_whitelist_get") ?? ""),
					"_POST" => explode(",", $this->config->getString("app.globals_whitelist_post") ?? ""),
					"_FILES" => explode(",", $this->config->getString("app.globals_whitelist_files") ?? ""),
					"_COOKIES" => explode(",", $this->config->getString("app.globals_whitelist_cookies") ?? ""),
				]
			));
	}

	protected function handleLogicExecution(Assembly $logicAssembly, ?Element $component = null):void {
		$logicExecutor = new LogicExecutor(
			$logicAssembly,
			$this->injector,
			$this->config->getString("app.namespace")
		);
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
		}

		foreach($logicExecutor->invoke("go_before", $extraArgs) as $file) {
			// TODO: Hook up to debug output
		}

		$input = $this->serviceContainer->get(Input::class);
		$input->when("do")->call(
			function(InputData $data)use($logicExecutor, $extraArgs) {
				$doName = "do_" . str_replace(
					"-",
					"_",
					$data->getString("do"),
				);

				foreach($logicExecutor->invoke($doName, $extraArgs) as $file) {
					// TODO: Hook up to debug output
				}
			}
		);
		foreach($logicExecutor->invoke("go", $extraArgs) as $file) {
			// TODO: Hook up to debug output
		}
		foreach($logicExecutor->invoke("go_after", $extraArgs) as $file) {
			// TODO: Hook up to debug output
		}
	}

	protected function setupLogger(ConfigSection $logConfig):void {
		$type = $logConfig->getString("type");
		$path = $logConfig->getString("path");
		$level = $logConfig->getString("level");
		$timestampFormat = $logConfig->getString("timestamp_format");
		$logFormat = explode("\\t", $logConfig->getString("log_format"));
		$separator = $logConfig->getString("separator");
		$newLine = $logConfig->getString("newline");
		$logHandler = match($type) {
			"file" => new FileHandler($path, $timestampFormat, $logFormat, $separator, $newLine),
			"stream" => new StreamHandler($path),
			default => new StdOutHandler()
		};
		LogConfig::addHandler($logHandler, $level);
	}

	protected function createRouter(Container $container):BaseRouter {
		$routerConfig = $this->config->getSection("router");
		$namespace = $this->config->getString("app.namespace");
		$appRouterFile = $routerConfig->getString("router_file");
		$appRouterClass = $routerConfig->getString("router_class");
		$defaultRouterFile = dirname(__DIR__, 2) . "/router.default.php";

		if(file_exists($appRouterFile)) {
			require_once($appRouterFile);
			$class = "\\$namespace\\$appRouterClass";
		}
		else {
			require_once($defaultRouterFile);
			$class = "\\Gt\\WebEngine\\DefaultRouter";
		}

		/** @var BaseRouter $router */
		$router = new $class($routerConfig);
		$router->setContainer($container);
		return $router;
	}

	private function setupResponse(ServerRequestInterface $request):void {
		$this->response = new Response(request: $request);

		$this->response->setExitCallback(fn() => call_user_func(
			$this->finishCallback,
			$this->response,
			$this->config->getSection("app")
		));
	}

	private function setupServiceContainer():void {
		if(isset($this->serviceContainer)) {
			return;
		}
		$this->serviceContainer = new Container();
		$this->serviceContainer->addLoaderClass(
			new DefaultServiceLoader(
				$this->config,
				$this->serviceContainer
			)
		);
		$customServiceContainerClassName = implode("\\", [
			$this->config->get("app.namespace"),
			$this->config->get("app.service_loader"),
		]);
		if(class_exists($customServiceContainerClassName)) {
			$constructorArgs = [];
			if(is_a($customServiceContainerClassName, DefaultServiceLoader::class, true)) {
				$constructorArgs = [
					$this->config,
					$this->serviceContainer,
				];
			}

			$this->serviceContainer->addLoaderClass(
				new $customServiceContainerClassName(
					...$constructorArgs
				)
			);
		}
	}

	/**
	 * Force trailing slashes in URLs. This is useful for consistency, but
	 * also helps identify that WebEngine requests do not match an actual
	 * static file, as file requests will never end in a slash. Another
	 * benefit is that links can behave relatively (e.g.
	 * <a href="./something/"> ) 307 is used here to preserve any POST data
	 * that may be in the request.
	 */
	private function forceTrailingSlashes(ServerRequestInterface $request):void {
		if(str_ends_with($request->getUri()->getPath(), "/")) {
			return;
		}

		$this->response = $this->response
			->withHeader(
				"Location",
				$request->getUri()->withPath(
					$request->getUri()->getPath() . "/"
				)
			)
			->withStatus(307);
	}

	/** @return array<class-string> */
	private function getAttributesFromFile(string $file):array {
		$attrArray = [];

		$firstHash = strpos($file, "#");
		if($firstHash === false) {
			return $attrArray;
		}

		$file = substr($file, $firstHash + 1);

		foreach(explode("#", $file) as $attrString) {
			array_push($attrArray, strtok($attrString, "("));
		}
		return $attrArray;
	}
}
