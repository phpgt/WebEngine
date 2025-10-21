<?php
namespace GT\WebEngine\Dispatch;

use GT\Routing\BaseRouter;
use GT\Routing\RouterConfig;
use GT\ServiceContainer\Container;

class RouterFactory {
	public function create(
		Container $container,
		string $configAppNamespace,
		string $configAppRouterFile,
		string $configAppRouterClass,
		string $configDefaultRouterFile,
		string $configDefaultRouterClass,
		int $configRedirectResponseCode,
		string $configDefaultContentType,
	):BaseRouter {
		if(file_exists($configAppRouterFile)) {
			require_once($configAppRouterFile);
			$class = "\\$configAppNamespace\\$configAppRouterClass";
		}
		else {
			require_once($configDefaultRouterFile);
			$class = $configDefaultRouterClass;
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
}
