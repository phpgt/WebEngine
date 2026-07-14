<?php
namespace GT\WebEngine\View;

use GT\Dom\HTMLDocument;
use GT\Json\Schema\JSONDocument;

/**
 * This file is just stubbed out for now, and does not need unit tests writing
 * for it until the structured object document feature is planned out properly.
 */
class JSONView extends BaseView {
	public function createViewModel():JSONDocument {
		return new JSONDocument();
	}

	public function stream(HTMLDocument|JSONDocument $viewModel):void {
		$this->outputStream->write((string)$viewModel . "\n");
	}
}
