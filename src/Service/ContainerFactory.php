<?php
namespace GT\WebEngine\Service;

use GT\Config\Config;
use GT\ServiceContainer\Container;
use GT\WebEngine\Middleware\DefaultServiceLoader;

class ContainerFactory {
	public function create(Config $config):Container {
		$container = new Container();

		// Always register the DefaultServiceLoader for core WebEngine services.
		$container->addLoaderClass(new DefaultServiceLoader($config, $container));

		// Optionally, register an application-provided service loader.
		$customServiceContainerClassName = implode("\\", [
			$config->get("app.namespace"),
			$config->get("app.service_loader"),
		]);

		if(class_exists($customServiceContainerClassName)) {
			$constructorArgs = [];
			if(is_a($customServiceContainerClassName, DefaultServiceLoader::class, true)) {
				$constructorArgs = [
					$config,
					$container,
				];
			}

			$container->addLoaderClass(new $customServiceContainerClassName(...$constructorArgs));
		}

		return $container;
	}
}
