<?php
namespace Gt\WebEngine\View;

use Gt\Dom\HTMLDocument;

class HTMLView extends BaseView {
	public function createViewModel():HTMLDocument {
		$html = "";
		foreach($this->viewFileArray as $viewFile) {
			$html .= file_get_contents($viewFile);
		}

		return new HTMLDocument($html);
	}
}
