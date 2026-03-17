<?php
namespace Example\App;

use Gt\Config\Config;
use Gt\DomTemplate\BindableCache;
use Gt\ServiceContainer\Container;

class CustomServiceLoader {
	public function __construct(
		private readonly Config $config,
		private readonly Container $container,
	) {}

	public function loadBindableCache():BindableCache {
		return new CustomBindableCache();
	}
}
