<?php
namespace GT\WebEngine\View;

use GT\Dom\HTMLDocument;

class ViewStreamer {
	public function stream(
		HTMLView|JSONView|NullView $view,
		HTMLDocument $viewModel,
	):void {
		$view->stream($viewModel);
	}

}
