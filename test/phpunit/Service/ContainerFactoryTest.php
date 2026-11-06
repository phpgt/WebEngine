<?php
namespace GT\WebEngine\Test\Service;

require_once __DIR__ . "/Fixture/CustomBindableCache.php";
require_once __DIR__ . "/Fixture/CustomServiceLoader.php";
require_once __DIR__ . "/Fixture/AdditionalServiceLoader.php";

use Example\App\CustomBindableCache;
use Example\App\AdditionalServiceLoader;
use ArrayObject;
use PHPUnit\Framework\Attributes\DataProvider;
use GT\Config\Config;
use GT\DomTemplate\BindableCache;
use GT\Http\Header\ResponseHeaders;
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
	#[DataProvider("loaderLists")]
	public function testCreate_registersAllAvailableLoadersInOrder(string $loaders, string $expectedCache):void {
		$config = $this->createStub(Config::class);
		$config->method("get")->willReturnMap([
			["app.namespace", "Example\\App"],
			["app.service_loader", $loaders],
		]);
		$container = (new ContainerFactory())->create($config);
		self::assertSame($expectedCache, get_class($container->get(BindableCache::class)));
		$additional = $container->get(ArrayObject::class);
		self::assertSame($config, $additional["config"]);
		self::assertSame($container, $additional["container"]);
		self::assertFalse($container->has(ResponseHeaders::class));
	}

	public static function loaderLists():iterable {
		yield "last loader overrides" => ["CustomServiceLoader,AdditionalServiceLoader", BindableCache::class];
		yield "reversed order" => ["AdditionalServiceLoader,CustomServiceLoader", CustomBindableCache::class];
		yield "missing aliases and whitespace" => [
			" MissingLoader, \\AdditionalServiceLoader\\ , , CustomServiceLoader, MissingClassLoader ",
			CustomBindableCache::class,
		];
	}

	public function testCreate_doesNotConstructDuplicateLoaderNamesTwice():void {
		AdditionalServiceLoader::$constructions = 0;
		$config = $this->createStub(Config::class);
		$config->method("get")->willReturnMap([
			["app.namespace", "Example\\App"],
			["app.service_loader", "AdditionalServiceLoader, additionalserviceloader"],
		]);
		$container = (new ContainerFactory())->create($config);
		self::assertTrue($container->has(ArrayObject::class));
		self::assertSame(1, AdditionalServiceLoader::$constructions);
	}

	#[DataProvider("unavailableLoaders")]
	public function testCreate_retainsDefaultFallbackWhenNoLoaderExists(string $namespace, string $loaders):void {
		$config = $this->createStub(Config::class);
		$config->method("get")->willReturnMap([
			["app.namespace", $namespace],
			["app.service_loader", $loaders],
		]);
		$container = (new ContainerFactory())->create($config);
		self::assertTrue($container->has(ResponseHeaders::class));
		self::assertInstanceOf(BindableCache::class, $container->get(BindableCache::class));
	}

	public static function unavailableLoaders():iterable {
		yield "missing aliases" => ["Example\\App", "ServiceLoader,ClassLoader"];
		yield "blank entries" => ["Example\\App", " , , "];
		yield "no application namespace" => ["", "CustomServiceLoader,AdditionalServiceLoader"];
	}

}
