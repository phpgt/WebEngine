<?php
namespace GT\WebEngine\Dispatch;

use Gt\Routing\BaseRouter;
use Gt\Routing\RouterConfig;
use Gt\ServiceContainer\Container;

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
		?int $errorStatus = null,
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
		$router = new $class($routerConfig, errorStatus: $errorStatus);
		$router->setContainer($container);
		return $router;
	}
}
