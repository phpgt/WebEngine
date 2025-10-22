<?php
namespace GT\WebEngine\Test\View;

use GT\WebEngine\View\BaseView;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

class BaseViewTest extends TestCase {
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

	private function newTestableView(StreamInterface $stream): BaseView {
		return new class($stream) extends BaseView {
			public function createViewModel():mixed { return ""; }
			public function getViewFiles(): array { return $this->viewFileArray; }
		};
	}

	public function testStream_writesStringToOutput():void {
		$stream = $this->newRecordingStream();
		$sut = $this->newTestableView($stream);
		$sut->stream("hello world");
		self::assertSame("hello world", (string)$stream);
	}

	public function testStream_castsNonStringToString():void {
		$stream = $this->newRecordingStream();
		$sut = $this->newTestableView($stream);
		$object = new class(){ public function __toString():string { return "obj-str"; } };
		$sut->stream($object);
		self::assertSame("obj-str", (string)$stream);
	}

	public function testAddViewFile_appendsInOrder():void {
		$stream = $this->newRecordingStream();
		/** @var BaseView&object{getViewFiles: callable():array} $sut */
		$sut = $this->newTestableView($stream);
		$sut->addViewFile("one.html");
		$sut->addViewFile("two.html");
		/** @noinspection PhpUndefinedMethodInspection */
		self::assertSame(["one.html", "two.html"], $sut->getViewFiles());
	}
}
