<?php
namespace GT\WebEngine\Test\Service;

use GT\Config\Config;
use GT\Database\Connection\DefaultSettings;
use GT\Database\Connection\Settings;
use GT\Database\Database;
use GT\Dom\HTMLDocument;
use GT\DomTemplate\BindableCache;
use GT\DomTemplate\DocumentBinder;
use GT\DomTemplate\ElementBinder;
use GT\DomTemplate\HTMLAttributeBinder;
use GT\DomTemplate\HTMLAttributeCollection;
use GT\DomTemplate\ListBinder;
use GT\DomTemplate\PlaceholderBinder;
use GT\DomTemplate\TableBinder;
use GT\Http\Request;
use GT\Http\Response;
use GT\Http\Uri;
use GT\ServiceContainer\Container;
use GT\WebEngine\Service\DefaultServiceLoader;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class DefaultServiceLoaderTest extends TestCase {
	public function testLoadResponseHeaders_returnsHeadersFromResponseInContainer():void {
		$response = new Response();
		$response = $response->withHeader("X-Test", "one");

		$container = new Container();
		$container->set($response);

		$sut = new DefaultServiceLoader($this->createStub(Config::class), $container);
		$headers = $sut->loadResponseHeaders();

		self::assertSame($response->headers, $headers);
		self::assertSame("one", $headers->get("X-Test")?->getValue());
	}

	public function testLoadDatabase_usesConfiguredValues():void {
		$config = $this->createDatabaseConfig([
			"database.query_directory" => "/tmp/queries",
			"database.driver" => Settings::DRIVER_SQLITE,
			"database.schema" => Settings::SCHEMA_IN_MEMORY,
			"database.host" => "db.example.test",
			"database.port" => "9999",
			"database.username" => "alice",
			"database.password" => "secret",
			"database.connection_name" => "analytics",
			"database.collation" => "latin1_swedish_ci",
			"database.charset" => "latin1",
		]);

		$sut = new DefaultServiceLoader($config, new Container());
		$database = $sut->loadDatabase();
		$settings = $this->extractDriverSettings($database, "analytics");

		self::assertSame("/tmp/queries", $settings->getBaseDirectory());
		self::assertSame(Settings::DRIVER_SQLITE, $settings->getDriver());
		self::assertSame(Settings::SCHEMA_IN_MEMORY, $settings->getSchema());
		self::assertSame("db.example.test", $settings->getHost());
		self::assertSame(9999, $settings->getPort());
		self::assertSame("alice", $settings->getUsername());
		self::assertSame("secret", $settings->getPassword());
		self::assertSame("analytics", $settings->getConnectionName());
		self::assertSame("latin1_swedish_ci", $settings->getCollation());
		self::assertSame("latin1", $settings->getCharset());
	}

	public function testLoadDatabase_usesDefaultConnectionValuesWhenConfigIsEmpty():void {
		$config = $this->createDatabaseConfig([
			"database.query_directory" => "/tmp/queries",
			"database.driver" => Settings::DRIVER_SQLITE,
			"database.schema" => Settings::SCHEMA_IN_MEMORY,
			"database.host" => "localhost",
			"database.port" => null,
			"database.username" => "",
			"database.password" => "",
			"database.connection_name" => "",
			"database.collation" => "",
			"database.charset" => "",
		]);

		$sut = new DefaultServiceLoader($config, new Container());
		$database = $sut->loadDatabase();
		$settings = $this->extractDriverSettings($database);

		self::assertSame(DefaultSettings::DEFAULT_NAME, $settings->getConnectionName());
		self::assertSame(DefaultSettings::DEFAULT_COLLATION, $settings->getCollation());
		self::assertSame(DefaultSettings::DEFAULT_CHARSET, $settings->getCharset());
	}

	public function testPrimitiveLoaderMethods_returnExpectedTypes():void {
		$sut = new DefaultServiceLoader($this->createStub(Config::class), new Container());

		self::assertInstanceOf(BindableCache::class, $sut->loadBindableCache());
		self::assertInstanceOf(HTMLAttributeBinder::class, $sut->loadHTMLAttributeBinder());
		self::assertInstanceOf(HTMLAttributeCollection::class, $sut->loadHTMLAttributeCollection());
		self::assertInstanceOf(PlaceholderBinder::class, $sut->loadPlaceholderBinder());
		self::assertInstanceOf(ElementBinder::class, $sut->loadElementBinder());
		self::assertInstanceOf(TableBinder::class, $sut->loadTableBinder());
		self::assertInstanceOf(ListBinder::class, $sut->loadListBinder());
	}

	public function testLoadListElementCollection_usesDocumentTemplatesFromContainer():void {
		$document = new HTMLDocument('<!doctype html><body><ul><li data-list="items">Template</li></ul></body>');
		$container = new Container();
		$container->set($document);

		$sut = new DefaultServiceLoader($this->createStub(Config::class), $container);
		$collection = $sut->loadListElementCollection();
		$listElement = $collection->get($document, "items");

		self::assertSame("li", strtolower($listElement->getClone()->tagName));
	}

	public function testLoadBinder_returnsDocumentBinderBoundToContainerDocument():void {
		$document = new HTMLDocument("<!doctype html><body></body>");
		$container = new Container();
		$container->set($document);

		$sut = new DefaultServiceLoader($this->createStub(Config::class), $container);
		$binder = $sut->loadBinder();

		self::assertInstanceOf(DocumentBinder::class, $binder);
		self::assertSame($document, $this->getObjectProperty($binder, "document"));
	}

	public function testLoadRequestUri_returnsRequestUriFromContainer():void {
		$request = $this->createStub(Request::class);
		$uri = new Uri("https://example.test/service");
		$request->method("getUri")
			->willReturn($uri);

		$container = new Container();
		$container->set($request);

		$sut = new DefaultServiceLoader($this->createStub(Config::class), $container);

		self::assertSame($uri, $sut->loadRequestUri());
	}

	private function createDatabaseConfig(array $map):Config {
		$config = $this->createStub(Config::class);
		$config->method("get")
			->willReturnCallback(fn(string $key):mixed => $map[$key] ?? null);
		return $config;
	}

	private function extractDriverSettings(
		Database $database,
		string $connectionName = DefaultSettings::DEFAULT_NAME,
	):Settings {
		$driver = $database->getDriver($connectionName);
		return $this->getObjectProperty($driver, "settings");
	}

	private function getObjectProperty(object $object, string $name):mixed {
		$property = new ReflectionProperty($object, $name);
		return $property->getValue($object);
	}
}
