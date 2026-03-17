<?php
namespace GT\WebEngine\Service;

use GT\Config\Config;
use GT\ServiceContainer\Container;

class ContainerFactory {
	public function create(Config $config):Container {
		$container = new Container();

			// Optionally, register an application-provided service loader.
			$appNamespace = trim((string)$config->get("app.namespace"), "\\");
			$serviceLoaderClass = trim((string)$config->get("app.service_loader"), "\\");
		$customLoaderClass = "";
		if($appNamespace !== "" && $serviceLoaderClass !== "") {
			$customLoaderClass = implode("\\", [
				$appNamespace,
				$serviceLoaderClass,
			]);
		}

		if($customLoaderClass !== "" && class_exists($customLoaderClass)) {
			$container->addLoaderClass(new $customLoaderClass($config, $container));
			}
			else {
				// Always register the DefaultServiceLoader for core WebEngine services.
				$container->addLoaderClass(new DefaultServiceLoader($config, $container));
		}

		return $container;
	}
}
