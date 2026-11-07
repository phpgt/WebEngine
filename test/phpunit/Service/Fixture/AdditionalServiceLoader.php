<?php
namespace Example\App;

use ArrayObject;
use GT\Config\Config;
use GT\DomTemplate\BindableCache;
use GT\ServiceContainer\Container;

class AdditionalServiceLoader {
	public static int $constructions = 0;

	public function __construct(
		private readonly Config $config,
		private readonly Container $container,
	) {
		self::$constructions++;
	}

	public function loadAdditionalService():ArrayObject {
		return new ArrayObject(["config" => $this->config, "container" => $this->container]);
	}

	public function loadBindableCache():BindableCache {
		return new BindableCache();
	}
}
