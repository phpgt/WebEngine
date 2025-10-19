<?php
namespace GT\WebEngine\Dispatch;

use Closure;
use GT\Config\Config;
use Gt\Dom\Element;
use GT\Dom\HTMLDocument;
use Gt\DomTemplate\BindableCache;
use Gt\DomTemplate\Binder;
use Gt\DomTemplate\ComponentBinder;
use Gt\DomTemplate\ComponentExpander;
use GT\DomTemplate\DocumentBinder;
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
use GT\Http\Header\ResponseHeaders;
use GT\Http\Request;
use GT\Http\Response;
use GT\Http\ServerInfo;
use GT\Http\StatusCode;
use GT\Input\Input;
use Gt\Input\InputData\InputData;
use GT\Routing\Assembly;
use GT\Routing\BaseRouter;
use GT\Routing\Path\DynamicPath;
use GT\Routing\RouterConfig;
use GT\ServiceContainer\Container;
use GT\ServiceContainer\Injector;
use Gt\ServiceContainer\ServiceNotFoundException;
use GT\Session\Session;
use GT\Session\SessionSetup;
use GT\WebEngine\Logic\AppAutoloader;
use GT\WebEngine\Logic\LogicExecutor;
use GT\WebEngine\Logic\LogicStreamHandler;
use GT\WebEngine\Middleware\DefaultServiceLoader;
use GT\WebEngine\Service\ContainerFactory;
use GT\WebEngine\View\BaseView;
use GT\WebEngine\View\NullView;
use Throwable;

class Dispatcher {
	private AppAutoloader $appAutoloader;
	private LogicStreamHandler $logicStreamHandler;
	private Container $serviceContainer;
	private Response $response;
	private Input $input;
	private Injector $injector;
	private DynamicPath $dynamicPath;
	private BaseView $view;
	private HTMLDocument/*|NullViewModel*/ $viewModel;
	private Assembly $viewAssembly;
	private Assembly $logicAssembly;

	/**
	 * @param array<string, array<string, string|array<string, string>>> $globals
	 */
	public function __construct(
		private Config $config,
		private Request $request,
		private array $globals,
		private Closure $finishCallback,
		?AppAutoloader $appAutoloader = null,
		?LogicStreamHandler $logicStreamHandler = null,
	) {
		$appNamespace = $config->getString("app.namespace");
		$this->appAutoloader = $appAutoloader ?? new AppAutoloader(
			$appNamespace,
			$config->getString("app.class_dir"),
		);
		$this->appAutoloader->setup();
		$this->logicStreamHandler = $logicStreamHandler ?? new LogicStreamHandler();
		$this->logicStreamHandler->setup();

		$this->response = $this->setupResponse();
		$this->serviceContainer = ContainerFactory::create($this->config);
	}

	private function setupResponse():Response {
		$response = new Response(request: $this->request);
		$response->setExitCallback(function() {($this->finishCallback)($this->response); });
		return $response;
	}

	private function handleRequest():void {
		$this->forceTrailingSlash();
		$this->input = new Input(
			$this->globals["_GET"],
			$this->globals["_POST"],
			$this->globals["_FILES"],
		);

		$this->serviceContainer->set(
			$this->config,
			$this->request,
			$this->response,
			$this->response->headers,
			$this->input,
			new ServerInfo($this->globals["_SERVER"]),
		);
		$this->injector = new Injector($this->serviceContainer);
	}

	private function handleRouting(Request $request):void {
		$router = $this->createRouter(
			$this->serviceContainer,
			$this->config->getString("app.namespace"),
			$this->config->getString("router.router_file"),
			$this->config->getString("router.router_class"),
			dirname(__FILE__, 3) . "/router.default.php",
			$this->config->getInt("router.redirect_response_code"),
			$this->config->getString("router.default_content_type"),
		);
		$router->route($request);
		$viewClass = $router->getViewClass() ?? NullView::class;
		if(str_starts_with($viewClass, "Gt\\")) {
			$viewClass = str_replace("Gt\\", "GT\\", $viewClass);
		}
		$this->view = new $viewClass($this->response->getBody());
		$this->viewAssembly = $router->getViewAssembly();
		$this->logicAssembly = $router->getLogicAssembly();

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

		$this->dynamicPath = new DynamicPath(
			$request->getUri()->getPath(),
			$this->viewAssembly,
			$this->logicAssembly,
		);

		$this->serviceContainer->set($this->dynamicPath);
	}

	private function handleSession():void {
		if($this->serviceContainer->has(Session::class)) {
// TODO: Why would there ever be a session in the container already? Seems a redundant check...
			return;
		}

		$sessionConfig = $this->config->getSection("session");
		$sessionId = $this->globals["_COOKIE"][$sessionConfig["name"]] ?? null;
		$sessionSetup = new SessionSetup();
		$sessionHandler = $sessionSetup->attachHandler(
			$sessionConfig->getString("handler")
		);

		$session = new Session(
			$sessionHandler,
			$sessionConfig,
			$sessionId,
		);
		$this->serviceContainer->set($session);
	}

	private function handleHTMLDocumentViewModel_RENAME_ME():array {
		$expandedLogicAssemblyList = [];
		$expandedComponentList = [];

		try {
			$componentDirectory = $this->config->getString("view.component_directory");// TODO: Pass in as param.
			$partialDirectory = $this->config->getString("view.partial_directory"); // TODO: Pass in as param.

			$partial = new PartialContent(implode(DIRECTORY_SEPARATOR, [
				getcwd(),
				$componentDirectory,
			]));
			$componentExpander = new ComponentExpander(
				$this->viewModel, // TODO: Pass in as param.
				$partial,
			);

			foreach($componentExpander->expand() as $componentElement) {
				$filePath = $componentDirectory;
				$filePath .= DIRECTORY_SEPARATOR;
				$filePath .= strtolower($componentElement->tagName);
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
				$partialDirectory,
			]));

			$partialExpander = new PartialExpander(
				$this->viewModel,
				$partial,
			);
			$partialExpander->expand();
		}
		catch(PartialContentDirectoryNotFoundException) {}

		$dynamicUri = $this->dynamicPath->getUrl("page/"); // TODO: Pass in as param.
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

		$tupleList = [];
		foreach($expandedLogicAssemblyList as $i => $assembly) {
			$component = $expandedComponentList[$i];
			array_push($tupleList, [$assembly, $component]);
		}

		return $tupleList;
	}

	private function handleLogicExecution(
		Assembly $logicAssembly,
		Injector $injector,
		Input $input,
		string $appNamespace,
		?Element $component = null,
	):void {
		$logicExecutor = new LogicExecutor(
			$logicAssembly,
			$injector,
			$appNamespace,
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

	private function createRouter(
		Container $container,
		string $configAppNamespace,
		string $configAppRouterFile,
		string $configAppRouterClass,
		string $configDefaultRouterFile,
		int $configRedirectResponseCode,
		string $configDefaultContentType,
	):BaseRouter {
		if(file_exists($configAppRouterFile)) {
			require_once($configAppRouterFile);
			$class = "\\$configAppNamespace\\$configAppRouterClass";
		}
		else {
			require_once($configDefaultRouterFile);
			$class = "\\GT\\WebEngine\\DefaultRouter";
		}

		$routerConfig = new RouterConfig(
			$configRedirectResponseCode,
			$configDefaultContentType,
		);

		/** @var BaseRouter $router */
		$router = new $class($routerConfig);
		$router->setContainer($container);
		return $router;
	}

	private function forceTrailingSlash():void {
		$uri = $this->request->getUri();
		$uriPath = $uri->getPath();
		$forceTrailingSlash = $this->config->getBool("app.force_trailing_slash");
		if($forceTrailingSlash) {
			if(!str_ends_with($uriPath, "/")) {
				$this->response->redirect($uri->withPath("$uriPath/"));
			}
		}
		else {
			if(str_ends_with($uriPath, "/") && $uriPath !== "/") {
				$this->response->redirect($uri->withPath(rtrim($uriPath, "/")));
			}
		}
	}

	public function generateResponse():Response {
		$appNamespace = $this->config->getString("app.namespace");

		$this->handleRequest();
		$this->handleRouting($this->request);
		$this->handleSession();

		try {
			if(isset($this->viewModel) && $this->viewModel instanceof HTMLDocument) {
				$toExecute = $this->handleHTMLDocumentViewModel_RENAME_ME();

				foreach($toExecute as $logicAssemblyComponentTuple) {
					$logicAssembly = $logicAssemblyComponentTuple[0];
					$component = $logicAssemblyComponentTuple[1];
					$this->handleLogicExecution(
						$logicAssembly,
						$this->injector,
						$this->input,
						$appNamespace,
						$component,
					);
				}
// TODO: Handle CSRF here?
			}

			$this->handleLogicExecution(
				$this->logicAssembly,
				$this->injector,
				$this->input,
				$appNamespace,
			);
		}
		catch(Throwable $throwable) {
			// TODO: What's the correct flow for handling errors?
			var_dump($throwable);
			die("error!");
		}

// TODO: Why is this here in the dispatcher?
		$documentBinder = $this->serviceContainer->get(Binder::class);
		$documentBinder->cleanupDocument();

		$this->view->stream($this->viewModel);
		try {
			$responseHeaders = $this->serviceContainer->get(ResponseHeaders::class);
		}
// This try/catch can be removed once the transition from Gt to GT is complete.
		catch(ServiceNotFoundException) {
			$responseHeaders = $this->serviceContainer->get(str_replace("GT\\", "Gt\\", ResponseHeaders::class));
		}
		foreach($responseHeaders->asArray() as $name => $value) {
			$this->response = $this->response->withHeader(
				$name,
				$value,
			);
		}

		return $this->response;
	}

	public function generateErrorResponse(Throwable $throwable):Response {
// TODO: Override the request's path to load the appropriate error page, and inject the throwable into the service container.
		return $this->response;
	}

	public function generateBasicErrorResponse(
		Throwable $throwable,
		Throwable $previousThrowable,
	):Response {
		return new Response(request: $this->request);
	}
}
