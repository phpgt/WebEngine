<?php
namespace GT\WebEngine\Service;

use Gt\Config\Config;
use Gt\ServiceContainer\Container;

	class ContainerFactory {
		public function create(Config $config):Container {
			$container = new Container();

			// Optionally, register an application-provided service loader.
			$appNamespace = trim((string)$config->get("app.namespace"), "\\");
			$serviceLoaderClass = trim((string)$config->get("app.service_loader"), "\\");
			$customServiceContainerClassName = "";
			if($appNamespace !== "" && $serviceLoaderClass !== "") {
				$customServiceContainerClassName = implode("\\", [
					$appNamespace,
					$serviceLoaderClass,
				]);
			}

			if($customServiceContainerClassName !== "" && class_exists($customServiceContainerClassName)) {
				$container->addLoaderClass(new $customServiceContainerClassName($config, $container));
			}
			else {
				// Always register the DefaultServiceLoader for core WebEngine services.
				$container->addLoaderClass(new DefaultServiceLoader($config, $container));
		}

		return $container;
	}
}
