<?php
namespace GT\WebEngine\Test\View;

use Gt\Dom\HTMLDocument;
use GT\WebEngine\View\HTMLView;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

class HTMLViewTest extends TestCase {
	private string $tmpDir;

	protected function setUp():void {
		parent::setUp();
		$this->tmpDir = sys_get_temp_dir() . "/phpgt-webengine-test--View-HTMLView-" . uniqid();
		if(!is_dir($this->tmpDir)) {
			mkdir($this->tmpDir, recursive: true);
		}
	}

	protected function tearDown():void {
		foreach(scandir($this->tmpDir) ?: [] as $f) {
			if($f === '.' || $f === '..') { continue; }
			@unlink($this->tmpDir . DIRECTORY_SEPARATOR . $f);
		}
		@rmdir($this->tmpDir);
		parent::tearDown();
	}

	private function newRecordingStream(): StreamInterface {
		return new class implements StreamInterface {
			public string $buffer = "";
			public function __toString():string { return $this->buffer; }
			public function close():void {}
			public function detach() {}
			public function getSize():?int { return strlen($this->buffer); }
			public function tell():int { return strlen($this->buffer); }
			public function eof():bool { return true; }
			public function isSeekable():bool { return false; }
			public function seek($offset, $whence = SEEK_SET):void {}
			public function rewind():void {}
			public function isWritable():bool { return true; }
			public function write($string):int { $this->buffer .= (string)$string; return strlen((string)$string); }
			public function isReadable():bool { return false; }
			public function read($length):string { return ""; }
			public function getContents():string { return $this->buffer; }
			public function getMetadata($key = null) { return null; }
		};
	}

	public function testCreateViewModel_concatenatesFilesIntoHtmlDocument():void {
		$file1 = $this->tmpDir . "/one.html";
		$file2 = $this->tmpDir . "/two.html";
		file_put_contents($file1, "<div id=one>One</div>");
		file_put_contents($file2, "<p id=two>Two</p>");

		$sut = new HTMLView($this->newRecordingStream());
		$sut->addViewFile($file1);
		$sut->addViewFile($file2);

		$doc = $sut->createViewModel();
		self::assertInstanceOf(HTMLDocument::class, $doc);
		$docStr = (string)$doc;
		self::assertTrue(strpos($docStr, 'One') !== false);
		self::assertTrue(strpos($docStr, 'Two') !== false);
	}

	public function testStream_serialisesHtmlDocumentToOutput():void {
		$stream = $this->newRecordingStream();
		$sut = new HTMLView($stream);
		$doc = new HTMLDocument("<main>Content</main>");
		$sut->stream($doc);
		self::assertSame((string)$doc, (string)$stream);
	}

	public function testCreateViewModel_withNoFiles_returnsEmptyDocument():void {
		$sut = new HTMLView($this->newRecordingStream());
		$doc = $sut->createViewModel();
		self::assertInstanceOf(HTMLDocument::class, $doc);
		self::assertIsString((string)$doc);
	}
}
