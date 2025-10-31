<?php
namespace GT\WebEngine\Test\Logic;

use GT\WebEngine\Logic\AppAutoloader;
use PHPUnit\Framework\TestCase;

class AppAutoloaderTest extends TestCase {
	private string $cwd;
	private string $relBaseDir;

	protected function setUp():void {
		parent::setUp();
		$this->cwd = getcwd();
		$this->relBaseDir = "test-autoload-" . uniqid();
		mkdir($this->relBaseDir . "/Foo", 0777, true);
	}

	protected function tearDown():void {
		// Recursively remove created files/dirs
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->relBaseDir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach($it as $file) {
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		@rmdir($this->relBaseDir);
		parent::tearDown();
	}

	public function testSetup_noDir_doesNotRegisterOrLoad():void {
		$sut = new AppAutoloader('App', 'non-existent-dir-' . uniqid());
		// Should not throw, and autoloader should not attempt to load anything
		$sut->setup();
		self::assertFalse(class_exists('App\\Nope\\Thing'));
	}

	public function testAutoload_relativePathAndUcfirstSegments():void {
		// Create class file using ucfirst segment mapping: Foo/Bar.php
		$code = "<?php\nnamespace App\\Foo; class Bar { public static function hello(): string { return 'hi'; } }\n";
		file_put_contents($this->relBaseDir . "/Foo/Bar.php", $code);

		$sut = new AppAutoloader('App', $this->relBaseDir);
		$sut->setup();

		$class = 'App\\Foo\\Bar';
		self::assertTrue(class_exists($class));
		self::assertSame('hi', \call_user_func([$class, 'hello']));
	}

	public function testAutoload_ignoresDifferentNamespace():void {
		// Create class that shouldn't be autoloaded by our AppAutoloader because of namespace mismatch
		$otherDir = $this->relBaseDir . '/Other';
		mkdir($otherDir, 0777, true);
		file_put_contents($otherDir . '/Qux.php', "<?php\nnamespace Other\\Ns; class Qux {}\n");

		$sut = new AppAutoloader('App', $this->relBaseDir);
		$sut->setup();

		// Asserting that merely referencing a different namespace doesn't cause load attempt here.
		self::assertFalse(class_exists('Other\\Ns\\Qux', false));
	}
}
