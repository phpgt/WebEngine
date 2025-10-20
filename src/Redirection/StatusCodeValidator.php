<?php
namespace Gt\WebEngine\Redirection;

class StatusCodeValidator {
	public const int DEFAULT_CODE = 307;
	public const int MIN_CODE = 301;
	public const int MAX_CODE = 308;

	public static function validate(mixed $raw):int {
		if(!filter_var($raw, FILTER_VALIDATE_INT) || $raw < self::MIN_CODE || $raw > self::MAX_CODE) {
			throw new RedirectException("Invalid HTTP status code in redirect file: $raw");
		}

		return (int)$raw;
	}
}
