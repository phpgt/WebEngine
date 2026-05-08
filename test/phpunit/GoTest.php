<?php
namespace GT\WebEngine\Test;

use GT\Logger\LogConfig;
use GT\WebEngine\Test\Fixture\TestLogHandler;
use PHPUnit\Framework\TestCase;

class GoTest extends TestCase {
	private string $originalCwd;
	private array $originalServer;
	private string $projectRoot;
	private string $documentRoot;

	protected function setUp():void {
		parent::setUp();
		$this->originalCwd = getcwd() ?: "";
		$this->originalServer = $_SERVER;
		$this->projectRoot = sys_get_temp_dir() . "/webengine-go-test-" . uniqid();
		$this->documentRoot = $this->projectRoot . "/public";
		mkdir($this->documentRoot, 0777, true);
		$this->resetLoggerState();
	}

	protected function tearDown():void {
		chdir($this->originalCwd);
		$_SERVER = $this->originalServer;
		$this->resetLoggerState();
		$this->deleteDirectory($this->projectRoot);
		parent::tearDown();
	}

	public function testGoDoesNotLogStaticRequestsWhenDisabled():void {
		file_put_contents($this->documentRoot . "/app.css", "body{}");
		file_put_contents($this->projectRoot . "/config.ini", <<<INI
		[logger]
		log_static_requests=false
		INI);

		$_SERVER["DOCUMENT_ROOT"] = $this->documentRoot;
		$_SERVER["REQUEST_URI"] = "/app.css";

		$return = include getcwd() . "/go.php";

		self::assertFalse($return);
		self::assertSame([], TestLogHandler::$records);
	}

	public function testGoLogsStaticRequestsWhenEnabled():void {
		file_put_contents($this->documentRoot . "/photo.jpg", "jpeg");
		file_put_contents($this->projectRoot . "/config.ini", <<<INI
		[logger]
		log_static_requests=true
		INI);
		LogConfig::addHandler(new TestLogHandler());

		$_SERVER["DOCUMENT_ROOT"] = $this->documentRoot;
		$_SERVER["REQUEST_URI"] = "/photo.jpg";

		$return = include getcwd() . "/go.php";

		self::assertFalse($return);
		self::assertCount(1, TestLogHandler::$records);
		self::assertSame("INFO", TestLogHandler::$records[0]["level"]);
		self::assertSame("HTTP 200", TestLogHandler::$records[0]["message"]);
		self::assertSame("/photo.jpg", TestLogHandler::$records[0]["context"]["uri"]);
	}

	public function testGoDoesNotLogStaticInfoBelowConfiguredMinimumLogLevel():void {
		file_put_contents($this->documentRoot . "/site.css", "body{}");
		file_put_contents($this->projectRoot . "/config.ini", <<<INI
		[logger]
		log_static_requests=true
		level=warning
		INI);

		$command = implode(" ", [
			escapeshellarg(PHP_BINARY),
			"-r",
			escapeshellarg(implode("\n", [
				'$_SERVER["DOCUMENT_ROOT"] = ' . var_export($this->documentRoot, true) . ';',
				'$_SERVER["REQUEST_URI"] = "/site.css";',
				'include ' . var_export(getcwd() . "/go.php", true) . ';',
			])),
		]);
		$output = shell_exec($command);

		self::assertTrue($output === null || $output === "");
	}

	private function resetLoggerState():void {
		TestLogHandler::$records = [];
		$this->setStaticProperty(LogConfig::class, "handlers", []);
		$this->setStaticProperty(LogConfig::class, "handlerMinLevels", []);
		$this->setStaticProperty(LogConfig::class, "handlerMaxLevels", []);
		$this->setStaticProperty(LogConfig::class, "defaultHandlerLevel", "DEBUG");
	}

	private function setStaticProperty(string $className, string $propertyName, mixed $value):void {
		$property = new \ReflectionProperty($className, $propertyName);
		$property->setValue(null, $value);
	}

	private function deleteDirectory(string $directory):void {
		if(!is_dir($directory)) {
			return;
		}

		$items = scandir($directory);
		if($items === false) {
			return;
		}

		foreach($items as $item) {
			if($item === "." || $item === "..") {
				continue;
			}

			$path = $directory . DIRECTORY_SEPARATOR . $item;
			if(is_dir($path)) {
				$this->deleteDirectory($path);
				continue;
			}

			unlink($path);
		}

		rmdir($directory);
	}
}
