<?php
namespace GT\WebEngine\Test\Redirection;

use GT\WebEngine\Redirection\Redirect;
use GT\WebEngine\Redirection\RedirectException;
use PHPUnit\Framework\TestCase;

class RedirectTest extends TestCase {
	private string $tmpDir;

	protected function setUp():void {
		parent::setUp();
		$this->tmpDir = sys_get_temp_dir() . "/phpgt-webengine-test--Redirection-Redirect-" . uniqid();
		if(!is_dir($this->tmpDir)) {
			mkdir($this->tmpDir, recursive: true);
		}
		chdir($this->tmpDir);
	}

	protected function tearDown():void {
		$this->removeDir($this->tmpDir);
		parent::tearDown();
	}

	private function removeDir(string $dir):void {
		if(!is_dir($dir)) {
			return;
		}

		$items = scandir($dir);
		foreach($items as $item) {
			if($item === "." || $item === "..") {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;

			if(is_dir($path)) {
				$this->removeDir($path);
			}
			else {
				unlink($path);
			}
		}

		rmdir($dir);
	}

	public function testConstruct_multipleFile():void {
		touch($this->tmpDir . "/redirect.csv");
		touch($this->tmpDir . "/redirect.ini");

		self::expectException(RedirectException::class);
		self::expectExceptionMessage("Multiple redirect files in project root");
		new Redirect($this->tmpDir . "/redirect.{csv,tsv,ini}");
	}

	public function testGetRedirectUri_iniNoSections():void {
		file_put_contents("$this->tmpDir/redirect.ini", "/old-path=/new-path");
		$sut = new Redirect();
		$redirectUri = $sut->getRedirectUri("/old-path");
		self::assertSame("/new-path", $redirectUri->uri);
		self::assertSame(307, $redirectUri->code);
	}

	public function testGetRedirectUri_iniSections():void {
		$ini = <<<INI
		[301]
		/old-path=/new-path
		/another301=/new-301
		
		[303]
		/redirect/303 = /path?redirect=303		
		INI;

		file_put_contents("$this->tmpDir/redirect.ini", $ini);
		$sut = new Redirect();
		$redirect1 = $sut->getRedirectUri("/old-path");
		self::assertSame("/new-path", $redirect1->uri);
		self::assertSame(301, $redirect1->code);
		$redirect2 = $sut->getRedirectUri("/another301");
		self::assertSame("/new-301", $redirect2->uri);
		self::assertSame(301, $redirect2->code);
		$redirect3 = $sut->getRedirectUri("/redirect/303");
		self::assertSame("/path?redirect=303", $redirect3->uri);
		self::assertSame(303, $redirect3->code);
	}

	public function testGetRedirectUri_csvNoCode():void {
		file_put_contents("$this->tmpDir/redirect.csv", "/old-csv,/new-csv\n/another-csv,/second-csv\n");
		$sut = new Redirect();
		$r1 = $sut->getRedirectUri("/old-csv");
		self::assertSame("/new-csv", $r1->uri);
		self::assertSame(307, $r1->code);
		$r2 = $sut->getRedirectUri("/another-csv");
		self::assertSame("/second-csv", $r2->uri);
		self::assertSame(307, $r2->code);
	}

	public function testGetRedirectUri_csv():void {
		file_put_contents("$this->tmpDir/redirect.csv", "/one,/moved,301\n/two,/temp,302\n/three,/see-other,303\n");
		$sut = new Redirect();
		$r1 = $sut->getRedirectUri("/one");
		self::assertSame("/moved", $r1->uri);
		self::assertSame(301, $r1->code);
		$r2 = $sut->getRedirectUri("/two");
		self::assertSame("/temp", $r2->uri);
		self::assertSame(302, $r2->code);
		$r3 = $sut->getRedirectUri("/three");
		self::assertSame("/see-other", $r3->uri);
		self::assertSame(303, $r3->code);
	}

	public function testGetRedirectUri_tsvNoCode():void {
		file_put_contents("$this->tmpDir/redirect.tsv", "/old-tsv\t/new-tsv\n/another-tsv\t/second-tsv\n");
		$sut = new Redirect();
		$r1 = $sut->getRedirectUri("/old-tsv");
		self::assertSame("/new-tsv", $r1->uri);
		self::assertSame(307, $r1->code);
		$r2 = $sut->getRedirectUri("/another-tsv");
		self::assertSame("/second-tsv", $r2->uri);
		self::assertSame(307, $r2->code);
	}

	public function testGetRedirectUri_tsv():void {
		file_put_contents("$this->tmpDir/redirect.tsv", "/one-tsv\t/new-tsv1\t301\n/two-tsv\t/new-tsv2\t308\n");
		$sut = new Redirect();
		$r1 = $sut->getRedirectUri("/one-tsv");
		self::assertSame("/new-tsv1", $r1->uri);
		self::assertSame(301, $r1->code);
		$r2 = $sut->getRedirectUri("/two-tsv");
		self::assertSame("/new-tsv2", $r2->uri);
		self::assertSame(308, $r2->code);
	}

	public function testGetRedirectUri_noFile_returnsNull():void {
		$sut = new Redirect();
		self::assertNull($sut->getRedirectUri("/not-found"));
	}

	public function testExecute_callsHandlerOnMatch():void {
		file_put_contents("$this->tmpDir/redirect.ini", "/go=/there");
		$called = false;
		$calledUri = null;
		$calledCode = null;
		$sut = new Redirect(redirectHandler: function(string $uri, int $code) use (&$called, &$calledUri, &$calledCode) {
			$called = true;
			$calledUri = $uri;
			$calledCode = $code;
		});
		$sut->execute("/go");
		self::assertTrue($called);
		self::assertSame("/there", $calledUri);
		self::assertSame(307, $calledCode);
	}

	public function testExecute_noMatch_doesNotCallHandler():void {
		$callCount = 0;
		$sut = new Redirect(redirectHandler: function() use (&$callCount) {
			$callCount++;
		});
		$sut->execute("/nope");
		self::assertSame(0, $callCount);
	}

	public function testIni_nonNumericSectionsThrow():void {
		$ini = <<<INI
		[foo]
		/in-foo=/ignored
		[301]
		/ok=/yes
		INI;
		file_put_contents("$this->tmpDir/redirect.ini", $ini);
		self::expectException(RedirectException::class);
		new Redirect();
	}

	public function testCsv_nonNumericCodeThrows():void {
		file_put_contents("$this->tmpDir/redirect.csv", "/a,/b,abc\n");
		self::expectException(RedirectException::class);
		self::expectExceptionMessage("Invalid HTTP status code in redirect file: abc");
		new Redirect();
	}

	public function testTsv_nonNumericCodeThrows():void {
		file_put_contents("$this->tmpDir/redirect.tsv", "/a\t/b\tbad\n");
		self::expectException(RedirectException::class);
		self::expectExceptionMessage("Invalid HTTP status code in redirect file: bad");
		new Redirect();
	}

	public function testRegex_ini_default307():void {
		$ini = "~^/shop/([^/]+)/(.+)$=/newShop/$1/$2";
		file_put_contents("$this->tmpDir/redirect.ini", $ini);
		$sut = new Redirect();
		$r1 = $sut->getRedirectUri("/shop/myCategory/thing");
		self::assertSame("/newShop/myCategory/thing", $r1->uri);
		self::assertSame(307, $r1->code);
		$r2 = $sut->getRedirectUri("/shop/myOtherCategory/whatever");
		self::assertSame("/newShop/myOtherCategory/whatever", $r2->uri);
		self::assertSame(307, $r2->code);
	}

	public function testRegex_ini_section301():void {
		$ini = <<<INI
		[301]
		~^/shop/([^/]+)/(.+)$=/newShop/$1/$2
		INI;
		file_put_contents("$this->tmpDir/redirect.ini", $ini);
		$sut = new Redirect();
		$r = $sut->getRedirectUri("/shop/cat/item");
		self::assertSame("/newShop/cat/item", $r->uri);
		self::assertSame(301, $r->code);
	}

	public function testRegex_csv():void {
		$csv = "~^/shop/([^/]+)/(.+)$,/newShop/$1/$2,302\n";
		file_put_contents("$this->tmpDir/redirect.csv", $csv);
		$sut = new Redirect();
		$r = $sut->getRedirectUri("/shop/abc/def");
		self::assertSame("/newShop/abc/def", $r->uri);
		self::assertSame(302, $r->code);
	}

	public function testRegex_tsv():void {
		$tsv = "~^/shop/([^/]+)/(.+)$\t/newShop/$1/$2\t308\n";
		file_put_contents("$this->tmpDir/redirect.tsv", $tsv);
		$sut = new Redirect();
		$r = $sut->getRedirectUri("/shop/xyz/ghi");
		self::assertSame("/newShop/xyz/ghi", $r->uri);
		self::assertSame(308, $r->code);
	}

	public function testRegex_execute_callsHandler():void {
		$ini = "~^/shop/([^/]+)/(.+)$=/newShop/$1/$2";
		file_put_contents("$this->tmpDir/redirect.ini", $ini);
		$called = false;
		$uri = null;
		$code = null;
		$sut = new Redirect(redirectHandler: function(string $u, int $c) use (&$called, &$uri, &$code) {
			$called = true;
			$uri = $u;
			$code = $c;
		});
		$sut->execute("/shop/cat/item");
		self::assertTrue($called);
		self::assertSame("/newShop/cat/item", $uri);
		self::assertSame(307, $code);
	}

	public function testRegex_noMatch_returnsNull():void {
		$ini = "~^/shop/([^/]+)/(.+)$=/newShop/$1/$2";
		file_put_contents("$this->tmpDir/redirect.ini", $ini);
		$sut = new Redirect();
		self::assertNull($sut->getRedirectUri("/blog/post/123"));
	}

	public function testConstruct_noBracePatternLoadsSingleFile():void {
		file_put_contents($this->tmpDir . '/redirect.csv', "/only,/single\n");
		$sut = new Redirect($this->tmpDir . '/redirect.csv');
		self::assertSame('/single', $sut->getRedirectUri('/only')->uri);
	}

	public function testExecute_sameUri_doesNotCallHandler():void {
		file_put_contents($this->tmpDir . '/redirect.ini', "/same=/same\n");
		$called = false;
		$sut = new Redirect(redirectHandler: function() use (&$called) {
			$called = true;
		});
		$sut->execute('/same');
		self::assertFalse($called, 'Handler should not be called when redirect URI equals input URI');
	}
}
