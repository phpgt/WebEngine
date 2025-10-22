<?php
namespace GT\WebEngine\Test\Redirection;

use GT\WebEngine\Redirection\IniRedirectLoader;
use GT\WebEngine\Redirection\RedirectException;
use GT\WebEngine\Redirection\RedirectMap;
use PHPUnit\Framework\TestCase;

class IniRedirectLoaderTest extends TestCase {
	private string $tmpFile;

	protected function setUp():void {
		parent::setUp();
		$this->tmpFile = sys_get_temp_dir() . "/phpgt-webengine-test--Redirection-IniRedirectLoader-" . uniqid() . ".ini";
	}

	protected function tearDown():void {
		if(is_file($this->tmpFile)) {
			unlink($this->tmpFile);
		}
		parent::tearDown();
	}

	public function testLoad_flatIni_commentsAndBlanks():void {
		$contents = "; comment\n# also a comment\n\n/one=/a\n\n/two=/b\n";
		file_put_contents($this->tmpFile, $contents);
		$map = new RedirectMap();
		$sut = new IniRedirectLoader();
		$sut->load($this->tmpFile, $map);
		self::assertNotNull($map->match('/one'));
		self::assertSame('/a', $map->match('/one')->uri);
		self::assertSame('/b', $map->match('/two')->uri);
	}

	public function testLoad_sectionedIni_validCodes():void {
		$contents = "[301]\n/a=/x\n[308]\n/b=/y\n";
		file_put_contents($this->tmpFile, $contents);
		$map = new RedirectMap();
		$sut = new IniRedirectLoader();
		$sut->load($this->tmpFile, $map);
		self::assertSame(301, $map->match('/a')->code);
		self::assertSame(308, $map->match('/b')->code);
	}

	public function testLoad_invalidSectionThrows():void {
		$contents = "[foo]\n/a=/x\n";
		file_put_contents($this->tmpFile, $contents);
		$map = new RedirectMap();
		self::expectException(RedirectException::class);
		$sut = new IniRedirectLoader();
		$sut->load($this->tmpFile, $map);
	}

	public function testLoad_regexRule():void {
		$contents = "~^/blog/(.+)$=/articles/$1\n";
		file_put_contents($this->tmpFile, $contents);
		$map = new RedirectMap();
		$sut = new IniRedirectLoader();
		$sut->load($this->tmpFile, $map);
		self::assertSame('/articles/hello', $map->match('/blog/hello')->uri);
	}

	public function testLoad_fopenFailure_noRulesAdded():void {
		$map = new RedirectMap();
		// Suppress expected warning from fopen for a non-existent file.
		set_error_handler(static fn(int $errno) => $errno === E_WARNING);
		$sut = new IniRedirectLoader();

		try {
			$sut->load($this->tmpFile, $map);
		}
		finally {
			restore_error_handler();
		}

		self::assertTrue($map->isEmpty());
	}

	public function testLoad_lineWithoutEquals_isIgnored():void {
		file_put_contents($this->tmpFile, "noequals\n/ok=/there\n");
		$map = new RedirectMap();
		$sut = new IniRedirectLoader();
		$sut->load($this->tmpFile, $map);
		self::assertNull($map->match('noequals'));
		self::assertSame('/there', $map->match('/ok')->uri);
	}

	public function testLoad_emptyKeyOrValue_isIgnored():void {
		$ini = "/= /x\n/x=\n/good=/yes\n";
		file_put_contents($this->tmpFile, $ini);
		$map = new RedirectMap();
		$sut = new IniRedirectLoader();
		$sut->load($this->tmpFile, $map);
		self::assertNull($map->match('/'));
		self::assertNull($map->match('/x'));
		self::assertSame('/yes', $map->match('/good')->uri);
	}
}
