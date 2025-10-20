<?php
namespace Gt\WebEngine\Dispatch;

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
}
