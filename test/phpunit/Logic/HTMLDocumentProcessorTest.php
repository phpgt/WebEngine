<?php
namespace GT\WebEngine\Test\Logic;

require_once __DIR__ . "/../Fixture/TestLogHandler.php";

use GT\WebEngine\Logic\HTMLDocumentProcessor;
use GT\Dom\Element;
use GT\Dom\HTMLDocument;
use GT\Logger\LogConfig;
use GT\Routing\Assembly;
use GT\Routing\Path\DynamicPath;
use GT\WebEngine\Test\Fixture\TestLogHandler;
use PHPUnit\Framework\TestCase;

class HTMLDocumentProcessorTest extends TestCase {
	private string $tmpDir;
	private string $componentDir;
	private string $partialDir;

	protected function setUp():void {
		parent::setUp();
		$this->tmpDir = getcwd() . "/test/phpunit/tmplogichtml" . uniqid();
		$this->componentDir = $this->tmpDir . "/components";
		$this->partialDir = $this->tmpDir . "/partials";
		mkdir($this->componentDir, recursive: true);
		mkdir($this->partialDir, recursive: true);
		$this->resetLoggerState();
	}

	protected function tearDown():void {
		$this->resetLoggerState();
		$this->deleteDir($this->tmpDir);
		parent::tearDown();
	}

	public function testProcessDynamicPath_addsUriAndDirectoryClasses():void {
		$viewAssembly = new Assembly();
		$viewAssembly->add("page/blog/@id/article.html");
		$processor = new HTMLDocumentProcessor("components", "partials");
		$document = new HTMLDocument("<!doctype html><body></body>");

		$processor->processDynamicPath(
			$document,
			new DynamicPath("/blog/42/article/", $viewAssembly),
		);

		$classes = iterator_to_array($document->body->classList);
		self::assertContains("uri--blog--_id--article", $classes);
		self::assertContains("dir--blog", $classes);
		self::assertContains("dir--blog--_id", $classes);
		self::assertContains("dir--blog--_id--article", $classes);
	}

	public function testProcessPartialContent_expandsComponentsAndPartialsAndRegistersOnlyLogicBackedComponents():void {
		LogConfig::addHandler(new TestLogHandler());
		file_put_contents(
			$this->componentDir . "/site-card.html",
			"<article><form method='post'><button>Save</button></form></article>",
		);
		file_put_contents($this->componentDir . "/site-card.php", "<?php\n");
		file_put_contents($this->componentDir . "/no-logic.html", "<aside>No logic</aside>");
		file_put_contents(
			$this->partialDir . "/layout.html",
			"<!doctype html><html><body><section data-partial></section></body></html>",
		);

		$document = new HTMLDocument(
			"<!doctype html><!-- extends = layout --><site-card></site-card><no-logic></no-logic><main>Page</main>"
		);
		$processor = new HTMLDocumentProcessor(
			ltrim(substr($this->componentDir, strlen(getcwd())), "/"),
			ltrim(substr($this->partialDir, strlen(getcwd())), "/"),
		);

		$componentList = $processor->processPartialContent($document);

		self::assertCount(1, $componentList);
		$component = $componentList[0];
		$component->assembly->rewind();
		self::assertSame(
			ltrim(substr($this->componentDir, strlen(getcwd())), "/") . "/site-card.php",
			$component->assembly->current(),
		);
		self::assertInstanceOf(Element::class, $component->component);
		self::assertSame("site-card", strtolower($component->component->tagName));
		self::assertSame("site-card", $document->querySelector("input[name='__component']")?->getAttribute("value"));
		self::assertSame("section", strtolower($document->body->firstElementChild->tagName));
		self::assertStringContainsString("<main>Page</main>", (string)$document);
		self::assertStringContainsString("<aside>No logic</aside>", (string)$document);
		self::assertCount(1, TestLogHandler::$records);
		self::assertSame("DEBUG", TestLogHandler::$records[0]["level"]);
		self::assertSame("Component detected without matching logic file", TestLogHandler::$records[0]["message"]);
		self::assertSame("no-logic", TestLogHandler::$records[0]["context"]["component"]);
	}

	public function testProcessPartialContent_ignoresMissingDirectories():void {
		$document = new HTMLDocument("<!doctype html><body><site-card></site-card></body>");
		$processor = new HTMLDocumentProcessor(
			"test/phpunit/does-not-exist-components",
			"test/phpunit/does-not-exist-partials",
		);

		$componentList = $processor->processPartialContent($document);

		self::assertCount(0, $componentList);
		self::assertSame("<site-card></site-card>", trim($document->body->innerHTML));
	}

	private function deleteDir(string $dir):void {
		if(!is_dir($dir)) {
			return;
		}

		foreach(scandir($dir) ?: [] as $item) {
			if($item === "." || $item === "..") {
				continue;
			}

			$path = $dir . "/" . $item;
			if(is_dir($path)) {
				$this->deleteDir($path);
			}
			else {
				unlink($path);
			}
		}

		rmdir($dir);
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
}
