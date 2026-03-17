<?php
namespace GT\WebEngine\View;

use GT\Json\Schema\JsonDocument;

/**
 * This file is just stubbed out for now, and does not need unit tests writing
 * for it until the structured object document feature is planned out properly.
 */
class JSONView extends BaseView {
	public function createViewModel():JsonDocument {
		return new JsonDocument();
	}
}
