<?php
namespace GT\WebEngine\View;

use GT\Dom\HTMLDocument;
use GT\Json\Schema\JSONDocument;

class ViewStreamer {
	public function stream(
		HTMLView|JSONView|NullView $view,
		HTMLDocument|JSONDocument $viewModel,
	):void {
		$view->stream($viewModel);
	}

}
