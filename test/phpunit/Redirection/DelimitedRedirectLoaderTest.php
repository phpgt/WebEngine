<?php
namespace GT\WebEngine\Test\Redirection;

use GT\WebEngine\Redirection\DelimitedRedirectLoader;
use GT\WebEngine\Redirection\RedirectException;
use GT\WebEngine\Redirection\RedirectMap;
use PHPUnit\Framework\TestCase;

class DelimitedRedirectLoaderTest extends TestCase {
	private string $tmpFile;

	protected function setUp():void {
		parent::setUp();
		$this->tmpFile = sys_get_temp_dir() . "/phpgt-webengine-test--Redirection-DelimitedRedirectLoader-" . uniqid();
	}

	protected function tearDown():void {
		if(is_file($this->tmpFile)) {
			unlink($this->tmpFile);
		}
		parent::tearDown();
	}

	public function testCsv_load_defaultsTo307_whenNoThirdColumn():void {
		file_put_contents($this->tmpFile, "/a,/x\n/b,/y\n");
		$map = new RedirectMap();
		(new DelimitedRedirectLoader(','))->load($this->tmpFile, $map);
		self::assertSame(307, $map->match('/a')->code);
		self::assertSame('/y', $map->match('/b')->uri);
	}

	public function testCsv_load_withExplicitCodes():void {
		file_put_contents($this->tmpFile, "/one,/x,301\n/two,/y,308\n");
		$map = new RedirectMap();
		(new DelimitedRedirectLoader(','))->load($this->tmpFile, $map);
		self::assertSame(301, $map->match('/one')->code);
		self::assertSame(308, $map->match('/two')->code);
	}

	public function testCsv_invalidThirdColumnThrows():void {
		file_put_contents($this->tmpFile, "/bad,/x,abc\n");
		$map = new RedirectMap();
		self::expectException(RedirectException::class);
		self::expectExceptionMessage('Invalid HTTP status code in redirect file: abc');
		(new DelimitedRedirectLoader(','))->load($this->tmpFile, $map);
	}

	public function testTsv_load_andRegex():void {
		file_put_contents($this->tmpFile, "~^/blog/(.+)$\t/articles/$1\t302\n");
		$map = new RedirectMap();
		(new DelimitedRedirectLoader("\t"))->load($this->tmpFile, $map);
		self::assertSame('/articles/hello', $map->match('/blog/hello')->uri);
		self::assertSame(302, $map->match('/blog/hello')->code);
	}
}
