<?php
namespace GT\WebEngine\Test\Logic;

require_once __DIR__ . "/../Fixture/TestAttribute.php";

use GT\WebEngine\Logic\LogicExecutor;
use GT\WebEngine\Logic\LogicProjectNamespace;
use GT\WebEngine\Logic\LogicStreamHandler;
use Gt\Routing\Assembly;
use Gt\ServiceContainer\Injector;
use PHPUnit\Framework\TestCase;

class LogicExecutorTest extends TestCase {
	private string $tmpDir;

	protected function setUp():void {
		parent::setUp();
		$this->tmpDir = getcwd() . "/test/phpunit/tmplogic" . uniqid();
		mkdir($this->tmpDir, recursive: true);
		new LogicStreamHandler()->setup();
	}

	protected function tearDown():void {
		$this->deleteDir($this->tmpDir);
		parent::tearDown();
	}

	public function testInvoke_executesClassMethodFromProjectNamespace():void {
		$relativePath = $this->createLogicFile("ClassPage.php", "<?php\n");
		$projectClassName = (string)(new LogicProjectNamespace($relativePath, "Example\\App"));
		[$projectNamespace, $className] = $this->splitClassName($projectClassName);
		file_put_contents(
			getcwd() . "/" . $relativePath,
			"<?php\nnamespace $projectNamespace;\nclass $className { public function go():void {} }\n",
		);

		$assembly = new Assembly();
		$assembly->add($relativePath);
		$extraArgs = ["flag" => "value"];

		$injector = $this->createMock(Injector::class);
		$injector->expects(self::once())
			->method("invoke")
			->with(
				self::callback(fn(object $instance):bool => $instance::class === $projectClassName),
				"go",
				$extraArgs,
			);

		$sut = new LogicExecutor("Example\\App", $injector);
		$invoked = iterator_to_array($sut->invoke($assembly, "go", $extraArgs), false);

		self::assertSame(["$relativePath::go()"], $invoked);
	}

	public function testInvoke_executesProjectNamespacedFunctionWhenNoClassExists():void {
		$relativePath = $this->createLogicFile("FunctionPage.php", "<?php\n");
		$projectNamespace = (string)(new LogicProjectNamespace($relativePath, "Example\\App"));
		file_put_contents(
			getcwd() . "/" . $relativePath,
			"<?php\nnamespace $projectNamespace;\nfunction go():void {}\n",
		);

		$assembly = new Assembly();
		$assembly->add($relativePath);
		$extraArgs = ["token" => "abc"];
		$expectedFunction = (string)(new LogicProjectNamespace($relativePath, "Example\\App")) . "\\go";

		$injector = $this->createMock(Injector::class);
		$injector->expects(self::once())
			->method("invoke")
			->with(null, $expectedFunction, $extraArgs);

		$sut = new LogicExecutor("Example\\App", $injector);
		$invoked = iterator_to_array($sut->invoke($assembly, "go", $extraArgs), false);

		self::assertSame(["$relativePath::go()"], $invoked);
	}

	public function testInvoke_executesStreamWrappedFunctionAndIncludesAttributeMetadata():void {
		$relativePath = $this->createLogicFile(
			"attribute-demo.php",
			<<<'PHP'
			<?php
			use GT\WebEngine\Test\Fixture\TestAttribute;

			#[TestAttribute("alpha", 123)]
			function go():void {}
			PHP
		);

		$assembly = new Assembly();
		$assembly->add($relativePath);
		$extraArgs = ["payload" => 123];

		$wrappedNamespace = "GT\\AppLogic\\" . str_replace(["/", ".", "-", "@", "+", "~", "%"], ["\\", "_", "_", "_", "_", "_", "_"], $relativePath);
		$expectedFunction = $wrappedNamespace . "\\go";

		$injector = $this->createMock(Injector::class);
		$injector->expects(self::once())
			->method("invoke")
			->with(null, $expectedFunction, $extraArgs);

		$sut = new LogicExecutor("Example\\App", $injector);
		$invoked = iterator_to_array($sut->invoke($assembly, "go", $extraArgs), false);

		self::assertSame([
			$relativePath . '::go()#GT\WebEngine\Test\Fixture\TestAttribute("alpha",123)',
		], $invoked);
	}

	public function testInvoke_skipsMissingHandlersWithoutCallingInjector():void {
		$relativePath = $this->createLogicFile(
			"missing.php",
			<<<'PHP'
			<?php
			function different_function():void {}
			PHP
		);

		$assembly = new Assembly();
		$assembly->add($relativePath);

		$injector = $this->createMock(Injector::class);
		$injector->expects(self::never())
			->method("invoke");

		$sut = new LogicExecutor("Example\\App", $injector);
		$invoked = iterator_to_array($sut->invoke($assembly, "go"), false);

		self::assertSame([], $invoked);
	}

	private function createLogicFile(string $name, string $contents):string {
		$file = $this->tmpDir . "/" . $name;
		file_put_contents($file, $contents);
		return ltrim(substr($file, strlen(getcwd())), "/");
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

	/** @return array{0:string,1:string} */
	private function splitClassName(string $fqcn):array {
		$pos = strrpos($fqcn, "\\");
		return [
			substr($fqcn, 0, $pos),
			substr($fqcn, $pos + 1),
		];
	}
}
