<?php
namespace GT\WebEngine\Service;

use GT\Config\Config;
use GT\ServiceContainer\Container;

class ContainerFactory {
	public function create(Config $config):Container {
		$container = new Container();
		$registered = false;
		foreach($this->getLoaderClassNames($config) as $loaderClass) {
			if(!class_exists($loaderClass)) {
				continue;
			}

			$container->addLoaderClass(new $loaderClass($config, $container));
			$registered = true;
		}

		if(!$registered) {
			$container->addLoaderClass(new DefaultServiceLoader($config, $container));
		}

		return $container;
	}

	/** @return array<int, string> */
	private function getLoaderClassNames(Config $config):array {
		$trimCharacters = " \t\n\r\0\x0B\\";
		$appNamespace = trim((string)$config->get("app.namespace"), $trimCharacters);
		if($appNamespace === "") {
			return [];
		}

		$classes = [];
		foreach(explode(",", (string)$config->get("app.service_loader")) as $loaderClass) {
			$loaderClass = trim($loaderClass, $trimCharacters);
			if($loaderClass === "") {
				continue;
			}

			$className = $appNamespace . "\\" . $loaderClass;
			$classes[strtolower($className)] ??= $className;
		}

		return array_values($classes);
	}
}
