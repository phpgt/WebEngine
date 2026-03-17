<?php
namespace Example\App;

use GT\Config\Config;
use GT\DomTemplate\BindableCache;
use GT\ServiceContainer\Container;

class CustomServiceLoader {
	public function __construct(
		private readonly Config $config,
		private readonly Container $container,
	) {}

	public function loadBindableCache():BindableCache {
		return new CustomBindableCache();
	}
}
