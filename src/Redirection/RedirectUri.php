<?php
namespace GT\WebEngine\Redirection;

class RedirectUri {
	public function __construct(
		public string $uri,
		public int $code = 307,
	) {}
}
