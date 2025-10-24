<?php
namespace GT\WebEngine\Test\Dispatch;

use GT\WebEngine\Dispatch\PathNormaliser;
use Gt\Http\Uri;
use PHPUnit\Framework\TestCase;

class PathNormaliserTest extends TestCase {
	public function testForceTrailingSlash_addsWhenMissing():void {
		$sut = new PathNormaliser();
		$uri = new Uri("https://example.test/section");
		$called = false;
		$redirected = null;

		$sut->normaliseTrailingSlash($uri, true, function(Uri $redirectUri) use (&$called, &$redirected) {
			$called = true;
			$redirected = $redirectUri;
		});

		self::assertTrue($called, "Expected redirect when missing trailing slash");
		self::assertSame("/section/", $redirected->getPath());
	}

	public function testForceTrailingSlash_noopWhenAlreadyPresent():void {
		$sut = new PathNormaliser();
		$uri = new Uri("https://example.test/section/");
		$called = false;
		$sut->normaliseTrailingSlash($uri, true, function() use (&$called) { $called = true; });
		self::assertFalse($called);
	}

	public function testForceTrailingSlash_rootNoop():void {
		$sut = new PathNormaliser();
		$uri = new Uri("https://example.test/");
		$called = false;
		$sut->normaliseTrailingSlash($uri, true, function() use (&$called) { $called = true; });
		self::assertFalse($called);
	}

	public function testForceTrailingSlash_emptyPathBecomesRoot():void {
		$sut = new PathNormaliser();
		$uri = new Uri("https://example.test"); // empty path
		$called = false;
		$redirected = null;
		$sut->normaliseTrailingSlash($uri, true, function(Uri $u) use (&$called, &$redirected) {
			$called = true;
			$redirected = $u;
		});
		self::assertTrue($called);
		self::assertSame("/", $redirected->getPath());
	}

	public function testForceTrailingSlash_preservesQueryAndFragment():void {
		$sut = new PathNormaliser();
		$uri = new Uri("https://example.test/abc?x=1#frag");
		$redirected = null;
		$sut->normaliseTrailingSlash($uri, true, function(Uri $u) use (&$redirected) { $redirected = $u; });
		self::assertSame("/abc/", $redirected->getPath());
		self::assertSame("x=1", $redirected->getQuery());
		self::assertSame("frag", $redirected->getFragment());
	}

	public function testRemoveTrailingSlash_removesWhenPresent():void {
		sut: {
			$sut = new PathNormaliser();
			$uri = new Uri("https://example.test/section/");
			$called = false;
			$redirected = null;
			$sut->normaliseTrailingSlash($uri, false, function(Uri $u) use (&$called, &$redirected) {
				$called = true;
				$redirected = $u;
			});
			self::assertTrue($called);
			self::assertSame("/section", $redirected->getPath());
		}
	}

	public function testRemoveTrailingSlash_noopWhenNoTrailingSlash():void {
		$sut = new PathNormaliser();
		$uri = new Uri("https://example.test/section");
		$called = false;
		$sut->normaliseTrailingSlash($uri, false, function() use (&$called) { $called = true; });
		self::assertFalse($called);
	}

	public function testRemoveTrailingSlash_rootNoop():void {
		$sut = new PathNormaliser();
		$uri = new Uri("https://example.test/");
		$called = false;
		$sut->normaliseTrailingSlash($uri, false, function() use (&$called) { $called = true; });
		self::assertFalse($called);
	}

	public function testRemoveTrailingSlash_multipleSlashesCollapsed():void {
		$sut = new PathNormaliser();
		$uri = new Uri("https://example.test/section///");
		$redirected = null;
		$sut->normaliseTrailingSlash($uri, false, function(Uri $u) use (&$redirected) { $redirected = $u; });
		self::assertSame("/section", $redirected->getPath());
	}

	public function testRemoveTrailingSlash_preservesQueryAndFragment():void {
		$sut = new PathNormaliser();
		$uri = new Uri("https://example.test/abc/?x=1#frag");
		$redirected = null;
		$sut->normaliseTrailingSlash($uri, false, function(Uri $u) use (&$redirected) { $redirected = $u; });
		self::assertSame("/abc", $redirected->getPath());
		self::assertSame("x=1", $redirected->getQuery());
		self::assertSame("frag", $redirected->getFragment());
	}
}
