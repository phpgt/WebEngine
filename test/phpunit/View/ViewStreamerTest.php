<?php
namespace GT\WebEngine\Test\View;

use GT\Dom\HTMLDocument;
use GT\Json\Schema\JSONDocument;
use GT\WebEngine\View\HTMLView;
use GT\WebEngine\View\JSONView;
use GT\WebEngine\View\ViewStreamer;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

class ViewStreamerTest extends TestCase {
	public function testStream_delegatesToViewStreamMethod():void {
		$document = new HTMLDocument("<main>Streamed</main>");
		$view = $this->getMockBuilder(HTMLView::class)
			->setConstructorArgs([$this->newRecordingStream()])
			->onlyMethods(["stream"])
			->getMock();
		$view->expects(self::once())
			->method("stream")
			->with($document);

		$sut = new ViewStreamer();
		$sut->stream($view, $document);
	}

	public function testStream_delegatesJsonDocumentToViewStreamMethod():void {
		$document = new JSONDocument();
		$document->set("hello", "Greg");
		$view = $this->getMockBuilder(JSONView::class)
			->setConstructorArgs([$this->newRecordingStream()])
			->onlyMethods(["stream"])
			->getMock();
		$view->expects(self::once())
			->method("stream")
			->with($document);

		$sut = new ViewStreamer();
		$sut->stream($view, $document);
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
