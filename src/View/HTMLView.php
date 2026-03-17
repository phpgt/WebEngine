<?php
namespace GT\WebEngine\View;

use GT\Dom\HTMLDocument;

class HTMLView extends BaseView {
	public function createViewModel():HTMLDocument {
		$html = "";
		foreach($this->viewFileArray as $viewFile) {
			$html .= file_get_contents($viewFile);
		}

		return new HTMLDocument($html);
	}
}
