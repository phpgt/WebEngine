<?php
namespace GT\WebEngine\Debug;

readonly class ExceptionContext {
	public function __construct(
		public string $message,
		public string $suggestion,
		public string $documentationUrl,
	) {}
}
