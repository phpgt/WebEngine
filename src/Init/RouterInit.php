<?php
namespace GT\WebEngine\Init;

use Gt\Dom\HTMLDocument;
use GT\Http\Request;
use GT\Http\Response;
use Gt\Routing\Assembly;
use Gt\Routing\BaseRouter;
use Gt\Routing\Path\DynamicPath;
use Gt\ServiceContainer\Container;
use GT\WebEngine\Dispatch\RouterFactory;
use GT\WebEngine\View\HTMLView;
use GT\WebEngine\View\JSONView;
use GT\WebEngine\View\NullView;

class RouterInit {
	private BaseRouter $baseRouter;
	private NullView|HTMLView|JSONView $view;
// TODO: What other viewModels are there?
	private HTMLDocument $viewModel;
	private DynamicPath $dynamicPath;
	private Assembly $viewAssembly;
	private Assembly $logicAssembly;

	public function __construct(
		Request $request,
		Response $response,
		Container $container,
		string $appNamespace,
		string $appRouterFile,
		string $appRouterClass,
		string $defaultRouterFile,
		string $defaultRouterClass,
		int $redirectResponseCode,
		string $defaultContentType,
		?int $errorStatus = null,
		?RouterFactory $routerFactory = null,
	) {
		$routerFactory = $routerFactory ?? new RouterFactory();

		$this->baseRouter = $routerFactory->create(
			$container,
			$appNamespace,
			$appRouterFile,
			$appRouterClass,
			$defaultRouterFile,
			$defaultRouterClass,
			$redirectResponseCode,
			$defaultContentType,
			$errorStatus,
		);
		$this->baseRouter->route($request);
		$viewClass = $this->baseRouter->getViewClass() ?? NullView::class;
		if(str_starts_with($viewClass, "Gt\\")) {
			$viewClass = str_replace("Gt\\", "GT\\", $viewClass);
		}
		$this->view = new $viewClass($response->getBody());

		$this->viewAssembly = $this->baseRouter->getViewAssembly();
		$this->logicAssembly = $this->baseRouter->getLogicAssembly();

		foreach($this->viewAssembly as $viewFile) {
			$this->view->addViewFile($viewFile);
		}
		$this->viewModel = $this->view->createViewModel();

		$this->dynamicPath = new DynamicPath(
			$request->getUri()->getPath(),
			$this->viewAssembly,
			$this->logicAssembly,
		);
	}

	public function getView():NullView|HTMLView|JSONView {
		return $this->view;
	}

	public function getViewModel():HTMLDocument {
		return $this->viewModel;
	}

	public function getBaseRouter():BaseRouter {
		return $this->baseRouter;
	}

	public function getDynamicPath():DynamicPath {
		return $this->dynamicPath;
	}

	public function getViewAssembly():Assembly {
		return $this->viewAssembly;
	}

	public function getLogicAssembly():Assembly {
		return $this->logicAssembly;
	}
}
