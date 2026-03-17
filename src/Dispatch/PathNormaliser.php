<?php
namespace GT\WebEngine\Dispatch;

use Closure;
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
				$redirect($uri->withPath("$path/"));
			}
		}
		else {
			if(str_ends_with($path, "/") && $path !== "/") {
				$redirect($uri->withPath(rtrim($path, "/")));
			}
		}
	}

}
