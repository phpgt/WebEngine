<?php
namespace GT\WebEngine\Dispatch;

use Closure;
use GT\Config\Config;
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
use GT\Http\Header\ResponseHeaders;
use GT\Http\Request;
use GT\Http\Response;
use GT\Input\Input;
use GT\Input\InputData\InputData;
use GT\Routing\Assembly;
use GT\Routing\Path\DynamicPath;
use GT\ServiceContainer\Container;
use GT\ServiceContainer\Injector;
use GT\WebEngine\DefaultRouter;
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

class Dispatcher {
	private Config $config;
	private Request $request;
	private array $globals;
	private Closure $finishCallback;

	private AppAutoloader $appAutoloader;
	private LogicStreamHandler $logicStreamHandler;
	private Input $input;
	private Response $response;
	private Container $serviceContainer;

	private Injector $injector;
	private NullView|JSONView|HTMLView $view;
	private HTMLDocument/*|NullViewModel*/ $viewModel;
	private ViewModelProcessor $viewModelProcessor;
	private Assembly $logicAssembly;
	private Assembly $viewAssembly;
	private LogicExecutor $logicExecutor;
	private ViewStreamer $viewStreamer;
	private HeaderManager $headerManager;

	private Closure $viewREADYTHING;

	/**
	 * @param array<string, array<string, string|array<string, string>>> $globals
	 */
	public function __construct(
		Config $config,
		Request $request,
		array $globals,
		Closure $finishCallback,

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
		$requestInit = $requestInit ?? new RequestInit(
			$pathNormaliser,
			$request->getUri(),
			$config->getBool("app.force_trailing_slash"),
			$this->response->redirect(...),
			$this->globals["_GET"],
			$this->globals["_POST"],
			$this->globals["_FILES"],
			$this->globals["_SERVER"],
		);
		$this->input = $requestInit->getInput();
		$this->serviceContainer->set($this->input);
		$this->serviceContainer->set($requestInit->getServerInfo());

		$routerInit = $routerInit ?? new RouterInit(
			$this->request,
			$this->response,
			$this->serviceContainer,
			$appNamespace,
			$this->config->getString("router.router_file"),
			$this->config->getString("router.router_class"),
			dirname(__FILE__, 3) . DIRECTORY_SEPARATOR . "router.default.php",
			DefaultRouter::class,
			$this->config->getInt("router.redirect_response_code"),
			$this->config->getString("router.default_content_type"),
		);
		$view = $routerInit->getView();
		$this->serviceContainer->set($view);
		$this->view = $view;
		$this->viewModel = $routerInit->getViewModel();
		$this->serviceContainer->set($this->viewModel);
		$this->serviceContainer->set($routerInit->getBaseRouter());
		$dynamicPath = $routerInit->getDynamicPath();
		$this->serviceContainer->set($dynamicPath);
		$this->viewAssembly = $routerInit->getViewAssembly();
		$this->logicAssembly = $routerInit->getLogicAssembly();

		$sessionInit = $sessionInit ?? new SessionInit(
			$this->config->getString("session.name"),
			$this->config->getString("session.handler"),
			$this->config->getString("session.path"),
			$this->config->getBool("session.use_trans_sid") ?? false,
			$this->config->getBool("session.use_cookies") ?? false,
			$this->globals["_COOKIE"],
		);
		$this->serviceContainer->set($sessionInit->getSession());

		$viewModelInit = $viewModelInit ?? new ViewModelInit(
			$this->viewModel,
			$this->config->getString("view.component_directory"),
			$this->config->getString("view.partial_directory"),
		);
		$this->viewModelProcessor = $viewModelInit->getViewModelProcessor();
		$this->viewREADYTHING = fn() => $viewModelInit->initHTMLDocument(
			$this->serviceContainer->get(Binder::class),
			$this->serviceContainer->get(HTMLAttributeBinder::class),
			$this->serviceContainer->get(ListBinder::class),
			$this->serviceContainer->get(TableBinder::class),
			$this->serviceContainer->get(ElementBinder::class),
			$this->serviceContainer->get(PlaceholderBinder::class),
			$this->serviceContainer->get(HTMLAttributeCollection::class),
			$this->serviceContainer->get(ListElementCollection::class),
			$this->serviceContainer->get(BindableCache::class),
		);

		$this->logicExecutor = $logicExecutor ?? new LogicExecutor(
			$appNamespace,
			$this->injector,
		);
		$this->viewStreamer = $viewStreamer ?? new ViewStreamer();
		$this->headerManager = $headerManager ?? new HeaderManager();
	}

	public function generateResponse():Response {
// The routing is now complete and all services are properly configured. This
// function's responsibility is to execute the logic that builds the response.
// Since this involves running user code that may throw errors, we execute each
// step individually to ensure proper error handling throughout the process.
		$dynamicPath = $this->serviceContainer->get(DynamicPath::class);

		$this->viewModelProcessor->processDynamicPath(
			$this->viewModel,
			$dynamicPath,
		);

		$logicAssemblyComponentList = $this->viewModelProcessor->processPartialContent(
			$this->viewModel,
		);


// TODO: CSRF handling - needs to be done on any POST request.
		($this->viewREADYTHING)();
		foreach($logicAssemblyComponentList as $logicAssemblyComponent) {
			$assembly = $logicAssemblyComponent->assembly;
			$componentElement = $logicAssemblyComponent->component;
			$this->serviceContainer->set($componentElement);

			$this->handleLogicExecution(
				$assembly,
				$this->input,
				$componentElement,
			);
		}

		$this->handleLogicExecution(
			$this->logicAssembly,
			$this->input,
		);

		if($responseWithHeader = $this->headerManager->apply(
			$this->response->getResponseHeaders(),
			$this->response->withHeader(...)
		)) {
			$this->response = $responseWithHeader;
		}

// TODO: Why is this here in the dispatcher? Move it to the ViewModel::cleanup() or similar
		$documentBinder = $this->serviceContainer->get(Binder::class);
		$documentBinder->cleanupDocument();

		$this->viewStreamer->stream($this->view, $this->viewModel);

		return $this->response;
	}

	public function generateErrorResponse(Throwable $throwable):Response {
		var_dump($throwable);
		die("ERRRRRRRRRRRRRRRRRRRRRRRORororororororororo");
// TODO: Override the request's path to load the appropriate error page, and inject the throwable into the service container.
		return $this->response;
	}

	public function generateBasicErrorResponse(
		Throwable $throwable,
		Throwable $previousThrowable,
	):Response {
		die("NONONO");
		return new Response(request: $this->request);
	}

	private function setupResponse():Response {
		$response = new Response(request: $this->request);
		$response->setExitCallback(function() {
			($this->finishCallback)($this->response);
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
// This is a temporary fix while repos transition from Gt to GT namespaces:
			$extraArgs[str_replace("GT\\", "Gt\\", Binder::class)] = $binder;
			$extraArgs[str_replace("GT\\", "Gt\\", Element::class)] = $component;
		}

		foreach($this->logicExecutor->invoke($logicAssembly, "go_before", $extraArgs) as $file) {
			// TODO: Hook up to debug output
		}

// TODO: No need to have the whole Input class. Just pass a nullable string in called $doMethod, from $input->getString("do")
		$input->when("do")->call(
			function(InputData $data)use($logicAssembly, $extraArgs) {
				$doName = "do_" . str_replace(
						"-",
						"_",
						$data->getString("do"),
					);

				foreach($this->logicExecutor->invoke($logicAssembly, $doName, $extraArgs) as $file) {
					// TODO: Hook up to debug output
				}
			}
		);

		foreach($this->logicExecutor->invoke($logicAssembly, "go", $extraArgs) as $file) {
			// TODO: Hook up to debug output
		}

		foreach($this->logicExecutor->invoke($logicAssembly, "go_after", $extraArgs) as $file) {
			// TODO: Hook up to debug output
		}
	}
}
