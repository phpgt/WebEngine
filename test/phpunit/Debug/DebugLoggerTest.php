<?php
namespace GT\WebEngine\Test\Debug;

require_once __DIR__ . "/../Fixture/TestLogHandler.php";

use GT\Config\Config;
use GT\Config\ConfigSection;
use GT\Logger\LogConfig;
use GT\WebEngine\Debug\DebugLogger;
use GT\WebEngine\Test\Fixture\TestLogHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DebugLoggerTest extends TestCase {
	private array $loggerState = [];

	protected function setUp():void {
		foreach(["handlers", "handlerMinLevels", "handlerMaxLevels", "defaultHandlerLevel"] as $name) {
			$property = new \ReflectionProperty(LogConfig::class, $name);
			$this->loggerState[$name] = $property->getValue();
			$property->setValue(null, $name === "defaultHandlerLevel" ? "DEBUG" : []);
		}
		TestLogHandler::$records = [];
	}

	protected function tearDown():void {
		foreach($this->loggerState as $name => $value) {
			(new \ReflectionProperty(LogConfig::class, $name))->setValue(null, $value);
		}
		TestLogHandler::$records = [];
	}

	public static function categories():array {
		return array_map(fn($category) => [$category], [
			"html_components", "html_partials", "requests", "routing",
			"logic", "views", "csrf", "services",
		]);
	}

	#[DataProvider("categories")]
	public function testCategoriesAreIndependentAndRespectMinimumLevel(string $category):void {
		LogConfig::addHandler(new TestLogHandler(), "DEBUG");
		$logger = new DebugLogger(new Config(new ConfigSection("logger", [
			"debug_" . $category => "true",
			"debug_disabled" => "false",
		])));
		$logger->log("disabled", "Disabled");
		$logger->log("missing", "Missing");
		(new DebugLogger())->log($category, "No config");
		$logger->log($category, "Enabled", ["file" => "page/index.php"]);
		self::assertSame([[
			"level" => "DEBUG",
			"message" => "Enabled",
			"context" => ["file" => "page/index.php"],
		]], TestLogHandler::$records);

		foreach(["handlers", "handlerMinLevels", "handlerMaxLevels"] as $name) {
			(new \ReflectionProperty(LogConfig::class, $name))->setValue(null, []);
		}
		LogConfig::addHandler(new TestLogHandler(), "INFO");
		$logger->log($category, "Below minimum");
		self::assertCount(1, TestLogHandler::$records);
	}
}
