<?php
namespace GT\WebEngine\Dispatch;

use GT\Routing\BaseRouter;
use GT\Routing\RouterConfig;
use GT\ServiceContainer\Container;

class RouterFactory {
	/** @SuppressWarnings("PHPMD.LongVariable") */
	public function create(
		Container $container,
		string $appNamespace,
		string $appRouterFile,
		string $appRouterClass,
		string $defaultRouterFile,
		string $defaultRouterClass,
		int $redirectResponseCode,
		string $defaultContentType,
		?int $errorStatus = null,
	):BaseRouter {
		if(file_exists($appRouterFile)) {
			require_once($appRouterFile);
			$class = "\\$appNamespace\\$appRouterClass";
		}
		else {
			require_once($defaultRouterFile);
			$class = $defaultRouterClass;
		}

		$routerConfig = new RouterConfig(
			$redirectResponseCode,
			$defaultContentType,
		);

		/** @var BaseRouter $router */
		$router = new $class($routerConfig, errorStatus: $errorStatus);
		$router->setContainer($container);
		return $router;
	}
}
