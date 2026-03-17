<?php
namespace GT\WebEngine\Test\View;

use GT\WebEngine\View\NullView;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

class NullViewTest extends TestCase {
	public function testCreateViewModel_returnsEmptyStringPlaceholder():void {
		$sut = new NullView($this->newRecordingStream());
		self::assertNull($sut->createViewModel());
	}

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
}
