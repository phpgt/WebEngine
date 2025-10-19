<?php
namespace GT\WebEngine\Test\Logic;

use GT\WebEngine\Logic\AppAutoloader;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class AppAutoloaderTest extends TestCase {
	private string $classDir;

	protected function setUp():void {
		// Use system temporary directory to avoid writing into the project tree.
		$sysTmp = rtrim(sys_get_temp_dir(), "/\\");
		$this->classDir = $sysTmp . "/phpgt-webengine-test--Logic-AppAutoloader-" . uniqid();
		@mkdir($this->classDir, recursive: true);
	}

	protected function tearDown():void {
		// Recursively remove the temporary directory we created.
		$path = $this->classDir;
		if(is_dir($path)) {
			self::rrmdir($path);
		}
	}

	private static function rrmdir(string $dir):void {
		/** @var list<string> $items */
		$items = @scandir($dir) ?: [];
		foreach($items as $item) {
			if($item === "." || $item === "..") {
				continue;
			}
			$full = $dir . "/" . $item;
			if(is_dir($full)) {
				self::rrmdir($full);
			}
			else {
				@unlink($full);
			}
		}
		@rmdir($dir);
	}

	#[RunInSeparateProcess]
	public function testLoadsClassFromConfiguredDirectory():void {
		// Arrange: create a simple class file under the configured directory.
		$ns = 'TestApp';
		$cls = 'Widget';
		$code = <<<PHP
		<?php
		namespace {$ns};
		class {$cls} {
			public function ping(): string { return 'pong'; }
		}
		PHP;

		$filePath = "{$this->classDir}/{$cls}.php";
		file_put_contents($filePath, $code);

		$sut = new AppAutoloader(namespace: $ns, classDir: $this->classDir);
		$sut->setup();

		// Act: reference the class so the autoloader resolves it.
		$fullClass = "\\{$ns}\\{$cls}";
		$instance = new $fullClass();

		// Assert
		self::assertSame('pong', $instance->ping());
	}

	#[RunInSeparateProcess]
	public function testLoadsClassFromNestedNamespaceUsingUcfirstPath():void {
		// Arrange: nested namespace parts should map to capitalised path segments.
		$ns = 'TestApp2';
		$nsParts = ['foo', 'bar'];
		$class = 'baz';

		// The autoloader ucfirst()s each part, so we create directories capitalised.
		$dir = "{$this->classDir}/" . implode('/', array_map('ucfirst', $nsParts));
		@mkdir($dir, recursive: true);

		$code = <<<PHP
		<?php
		namespace {$ns}\\foo\\bar;
		class {$class} { public function id(): string { return 'ok'; } }
		PHP;

		file_put_contents($dir . '/Baz.php', $code);

		$sut = new AppAutoloader(namespace: $ns, classDir: $this->classDir);
		$sut->setup();

		// Use lowercased parts to confirm ucfirst mapping works.
		$fullClass = "\\{$ns}\\{$nsParts[0]}\\{$nsParts[1]}\\$class";
		$instance = new $fullClass();

		self::assertSame('ok', $instance->id());
	}
}
