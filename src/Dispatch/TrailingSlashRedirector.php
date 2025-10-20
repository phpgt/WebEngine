<?php
namespace Gt\WebEngine\Dispatch;

use Gt\Config\Config;
use Gt\Http\Request;
use Gt\Http\Response;

class TrailingSlashRedirector {
	public function apply(Request $request, Config $config, Response $response):void {
		$uri = $request->getUri();
		$uriPath = $uri->getPath();
		$forceTrailingSlash = $config->getBool("app.force_trailing_slash");
		if($forceTrailingSlash) {
			if(!str_ends_with($uriPath, "/")) {
				$response->redirect($uri->withPath("$uriPath/"));
			}
		}
		else {
			if(str_ends_with($uriPath, "/") && $uriPath !== "/") {
				$response->redirect($uri->withPath(rtrim($uriPath, "/")));
			}
		}
	}
}
