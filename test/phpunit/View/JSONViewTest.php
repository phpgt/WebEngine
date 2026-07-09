<?php
namespace GT\WebEngine\Test\View;

use GT\Json\Schema\JSONDocument;
use GT\WebEngine\View\JSONView;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

class JSONViewTest extends TestCase {
	private function newRecordingStream():StreamInterface {
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

	public function testCreateViewModel_returnsJsonDocument():void {
		$sut = new JSONView($this->newRecordingStream());

		self::assertInstanceOf(JSONDocument::class, $sut->createViewModel());
	}

	public function testStream_appendsTrailingNewline():void {
		$stream = $this->newRecordingStream();
		$sut = new JSONView($stream);
		$doc = new JSONDocument();
		$doc->set("hello", "Greg");

		$sut->stream($doc);

		self::assertSame("{\"hello\":\"Greg\"}\n", (string)$stream);
	}
}
