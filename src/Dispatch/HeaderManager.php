<?php
namespace GT\WebEngine\Dispatch;

use Closure;
use Gt\Http\Header\ResponseHeaders;
use GT\Http\Response;

class HeaderManager {
	public function applyWithHeader(ResponseHeaders $responseHeaders, Closure $withHeaderCallback):?Response {
		$response = null;

		foreach($responseHeaders->asArray() as $name => $value) {
			$response = $withHeaderCallback($name, $value);
		}

		return $response;
	}
}
