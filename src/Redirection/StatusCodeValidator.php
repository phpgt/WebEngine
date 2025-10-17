<?php
namespace Gt\WebEngine\Redirection;

final class StatusCodeValidator {
	public const DEFAULT_CODE = 307;
	public const MIN_CODE = 301;
	public const MAX_CODE = 308;

	public static function validate(mixed $raw):int {
		if(!filter_var($raw, FILTER_VALIDATE_INT) || $raw < self::MIN_CODE || $raw > self::MAX_CODE) {
			throw new RedirectException("Invalid HTTP status code in redirect file: $raw");
		}
		return (int)$raw;
	}
}
