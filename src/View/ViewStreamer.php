<?php
namespace GT\WebEngine\View;

use Gt\Dom\HTMLDocument;

class ViewStreamer {
	public function stream(
		HTMLView|JSONView|NullView $view,
		HTMLDocument $viewModel,
	):void {
		$view->stream($viewModel);
	}

}
