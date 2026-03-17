<?php
namespace Example\App {

	use Gt\Config\Config;
	use Gt\DomTemplate\BindableCache;
	use Gt\ServiceContainer\Container;

	class CustomBindableCache extends BindableCache {}

	class CustomServiceLoader {
		public function __construct(
			private readonly Config $config,
			private readonly Container $container,
		) {}

		public function loadBindableCache():BindableCache {
			return new CustomBindableCache();
		}
	}
}

namespace GT\WebEngine\Test\Service {

	use Example\App\CustomBindableCache;
	use Gt\Config\Config;
	use Gt\DomTemplate\BindableCache;
	use Gt\Http\Header\ResponseHeaders;
	use GT\WebEngine\Service\ContainerFactory;
	use PHPUnit\Framework\TestCase;

	class ContainerFactoryTest extends TestCase {
		public function testCreate_registersDefaultServiceLoaderWhenNoCustomLoaderConfigured():void {
			$config = $this->createStub(Config::class);
			$config->method("get")
				->willReturnMap([
					["app.namespace", ""],
					["app.service_loader", ""],
				]);

			$sut = new ContainerFactory();
			$container = $sut->create($config);

			self::assertTrue($container->has(ResponseHeaders::class));
			self::assertInstanceOf(BindableCache::class, $container->get(BindableCache::class));
		}

		public function testCreate_registersCustomServiceLoaderWhenConfiguredClassExists():void {
			$config = $this->createStub(Config::class);
			$config->method("get")
				->willReturnMap([
					["app.namespace", "Example\\App"],
					["app.service_loader", "CustomServiceLoader"],
				]);

			$sut = new ContainerFactory();
			$container = $sut->create($config);

			self::assertInstanceOf(CustomBindableCache::class, $container->get(BindableCache::class));
			self::assertFalse($container->has(ResponseHeaders::class));
		}
	}
}
