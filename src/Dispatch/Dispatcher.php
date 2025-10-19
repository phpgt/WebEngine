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
use GT\DomTemplate\ListBinder;
use GT\DomTemplate\ListElementCollection;
use GT\DomTemplate\PlaceholderBinder;
use GT\DomTemplate\TableBinder;
use GT\Http\Request;
use GT\Http\Response;
use GT\Http\ServerInfo;
use GT\Http\StatusCode;
use GT\Input\Input;
use GT\Input\InputData\InputData;
use GT\Routing\Assembly;
use GT\Routing\Path\DynamicPath;
use GT\ServiceContainer\Container;
use GT\ServiceContainer\Injector;
use GT\Session\Session;
use GT\Session\SessionSetup;
use GT\WebEngine\Logic\AppAutoloader;
use GT\WebEngine\Logic\LogicExecutor;
use GT\WebEngine\Logic\LogicStreamHandler;
use GT\WebEngine\Service\ContainerFactory;
use GT\WebEngine\View\BaseView;
use GT\WebEngine\View\NullView;
use Throwable;

/**
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * Reason: Dispatcher orchestrates the request lifecycle and necessarily touches
 * many collaborators (routing, IoC, templating, sessions). The complexity is
 * managed by delegating to focused classes and factories; keeping the wiring
 * here improves readability and test isolation without leaking concerns.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * Reason: A few collaborators expose intentional static factories or helpers
 * (e.g., config access or legacy shims). These are used in constrained places
 * where dependency injection would add noise without improving design.
 */
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
		$response->setExitCallback(function() {
			($this->finishCallback)($this->response);
		});
		return $response;
	}

	private function handleRequest():void {
		(new TrailingSlashRedirector())->apply($this->request, $this->config, $this->response);
		$this->input = new Input(
			$this->globals["_GET"],
			$this->globals["_POST"],
			$this->globals["_FILES"],
		);

		$serverInfo = new ServerInfo($this->globals["_SERVER"]);
		$this->serviceContainer->set(
			$this->config,
			$this->request,
			$this->response,
			$this->response->headers,
			$this->input,
			$serverInfo,
			$serverInfo->getRequestUri(),
		);
		$this->injector = new Injector($this->serviceContainer);
	}

	private function handleRouting(Request $request):void {
		$router = (new RouterFactory())->create(
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
			$this->serviceContainer->set($binder, $component);
		}

		// @SuppressWarnings(PHPMD.EmptyForeachStatement, PHPMD.UnusedLocalVariable)
		// Reason: The iteratee yields file paths of executed logic for potential
		// debug output. The loop is intentionally empty until the debug sink is
		// wired (see TODO below). Suppressing prevents false positives while we
		// maintain the observable behaviour.
		// phpcs:ignore
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

				// @SuppressWarnings(PHPMD.EmptyForeachStatement, PHPMD.UnusedLocalVariable)
				// Reason: Same as go_before — we intentionally consume the iterator of
				// invoked files for future debug output. The loop body remains empty
				// until the debug output is wired. Keeping the structure makes that
				// integration trivial without behavioural change.
				// phpcs:ignore
				foreach($logicExecutor->invoke($doName, $extraArgs) as $file) {
					// TODO: Hook up to debug output
				}
			}
		);
		// @SuppressWarnings(PHPMD.EmptyForeachStatement, PHPMD.UnusedLocalVariable)
		// Reason: As above, the empty body is deliberate; iterating triggers lazy
		// execution and gives us access to file names for optional diagnostics.
		// This placeholder avoids introducing side-effects before logging is ready.
		// phpcs:ignore
		foreach($logicExecutor->invoke("go", $extraArgs) as $file) {
			// TODO: Hook up to debug output
		}
		// @SuppressWarnings(PHPMD.EmptyForeachStatement, PHPMD.UnusedLocalVariable)
		// Reason: Final hook for logic invocation; same rationale as previous
		// loops. The iteration is intentional and the empty body is a placeholder
		// until debug output is integrated.
		// phpcs:ignore
		foreach($logicExecutor->invoke("go_after", $extraArgs) as $file) {
			// TODO: Hook up to debug output
		}
	}
	
	/**
	 * @SuppressWarnings("PHPMD.ExitExpression")
	 * Reason: The response lifecycle may terminate execution after streaming the
	 * response (via callbacks configured on Response). This method coordinates
	 * that end-of-request behaviour, so the warning is suppressed here to reflect
	 * the framework’s intentional control of script termination.
	 */
	public function generateResponse():Response {
		$appNamespace = $this->config->getString("app.namespace");

		$this->handleRequest();
		$this->handleRouting($this->request);
		$this->handleSession();

		try {
			if(isset($this->viewModel)) {
				$this->serviceContainer->set($this->viewModel); // TODO: I Think this has already been set in handleRouting ???
				$toExecute = (new HtmlDocumentProcessor($this->config, $this->serviceContainer))
					->process($this->viewModel, $this->dynamicPath);

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
		}

// TODO: Why is this here in the dispatcher?
		$documentBinder = $this->serviceContainer->get(DocumentBinder::class);
		$documentBinder->cleanupDocument();

		$this->view->stream($this->viewModel);
		$this->response = (new HeaderApplier())->apply($this->serviceContainer, $this->response);

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
