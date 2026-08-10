<?php
namespace GT\WebEngine\Dispatch;

use Closure;
use GT\Http\StatusCode;
use Psr\Http\Message\UriInterface;

class PathNormaliser {
	public function normaliseTrailingSlash(
		UriInterface $uri,
		bool $forceTrailingSlash,
		Closure $redirect,
	):void {
		$path = $uri->getPath();

		if($forceTrailingSlash) {
			if(!str_ends_with($path, "/")) {
				$redirect(
					$uri->withPath("$path/"),
					StatusCode::PERMANENT_REDIRECT,
				);
			}
		}
		else {
			if(str_ends_with($path, "/") && $path !== "/") {
				$redirect(
					$uri->withPath(rtrim($path, "/")),
					StatusCode::PERMANENT_REDIRECT,
				);
			}
		}
	}

}
