<?php
namespace Gt\WebEngine\View;

use Psr\Http\Message\StreamInterface;

abstract class BaseView {
	protected StreamInterface $outputStream;
	/** @var array<string> */
	protected array $viewFileArray;

	public function __construct(StreamInterface $outputStream) {
		$this->outputStream = $outputStream;
		$this->viewFileArray = [];
	}

	abstract public function createViewModel():mixed;

	public function addViewFile(string $fileName):void {
		array_push($this->viewFileArray, $fileName);
	}

	public function stream(mixed $viewModel):void {
		$this->outputStream->write((string)$viewModel);
	}
}
