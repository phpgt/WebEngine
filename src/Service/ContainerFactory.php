<?php
namespace GT\WebEngine\Service;

use GT\Config\Config;
use GT\WebEngine\Debug\DebugLogger;
use GT\ServiceContainer\Container;

class ContainerFactory {
	public function create(Config $config):Container {
		$debug = new DebugLogger($config);
		$container = new Container();
		$registered = false;
		foreach($this->getLoaderClassNames($config) as $loaderClass) {
			if(!class_exists($loaderClass)) {
				$debug->log("services", "Service loader class not found", ["class" => $loaderClass]);
				continue;
			}

			$container->addLoaderClass(new $loaderClass($config, $container));
			$debug->log("services", "Service loader registered", ["class" => $loaderClass]);
			$registered = true;
		}

		if(!$registered) {
			$debug->log("services", "Using default service loader");
			$container->addLoaderClass(new DefaultServiceLoader($config, $container));
		}

		return $container;
	}

	/** @return array<int, string> */
	private function getLoaderClassNames(Config $config):array {
		$trimCharacters = " \t\n\r\0\x0B\\";
		$appNamespace = trim((string)$config->get("app.namespace"), $trimCharacters);

		$classes = [];
		foreach(explode(",", (string)$config->get("app.service_loader")) as $loaderClass) {
			$absolute = str_starts_with(ltrim($loaderClass), "\\");
			$loaderClass = trim($loaderClass, $trimCharacters);
			if($loaderClass === "") {
				continue;
			}

			if(!$absolute && $appNamespace === "") {
				continue;
			}

			$className = $absolute ? $loaderClass : $appNamespace . "\\" . $loaderClass;
			$classes[strtolower($className)] ??= $className;
		}

		return array_values($classes);
	}
}
