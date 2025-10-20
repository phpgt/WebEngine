<?php
namespace Gt\WebEngine\Test\Redirection;

use Gt\WebEngine\Redirection\IniRedirectLoader;
use Gt\WebEngine\Redirection\RedirectException;
use Gt\WebEngine\Redirection\RedirectMap;
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
		(new IniRedirectLoader())->load($this->tmpFile, $map);
		self::assertNotNull($map->match('/one'));
		self::assertSame('/a', $map->match('/one')->uri);
		self::assertSame('/b', $map->match('/two')->uri);
	}

	public function testLoad_sectionedIni_validCodes():void {
		$contents = "[301]\n/a=/x\n[308]\n/b=/y\n";
		file_put_contents($this->tmpFile, $contents);
		$map = new RedirectMap();
		(new IniRedirectLoader())->load($this->tmpFile, $map);
		self::assertSame(301, $map->match('/a')->code);
		self::assertSame(308, $map->match('/b')->code);
	}

	public function testLoad_invalidSectionThrows():void {
		$contents = "[foo]\n/a=/x\n";
		file_put_contents($this->tmpFile, $contents);
		$map = new RedirectMap();
		self::expectException(RedirectException::class);
		(new IniRedirectLoader())->load($this->tmpFile, $map);
	}

	public function testLoad_regexRule():void {
		$contents = "~^/blog/(.+)$=/articles/$1\n";
		file_put_contents($this->tmpFile, $contents);
		$map = new RedirectMap();
		(new IniRedirectLoader())->load($this->tmpFile, $map);
		self::assertSame('/articles/hello', $map->match('/blog/hello')->uri);
	}
}
