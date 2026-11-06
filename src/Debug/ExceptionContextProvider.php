<?php
namespace GT\WebEngine\Debug;

use Error;
use GT\Routing\LogicStream\LogicStreamWrapper;
use GT\ServiceContainer\ServiceNotFoundException;
use Throwable;

class ExceptionContextProvider {
	public function getContext(Throwable $throwable):?ExceptionContext {
		$className = $this->getMissingClassName($throwable);
		$prefix = LogicStreamWrapper::NAMESPACE_PREFIX . "\\";
		if(!$className || strncasecmp($className, $prefix, strlen($prefix)) !== 0) {
			return null;
		}

		if(!preg_match('/_php\\\\(?:[^\\\\]+\\\\)*([^\\\\]+)$/i', $className, $matches)) {
			return null;
		}

		return new ExceptionContext(
			$matches[1] . " could not be found. This usually means a class import is missing from your page logic.",
			"Add a use statement for the class's namespace at the top of the logic file, "
				. "or use its fully qualified class name. Check the spelling and autoloading if it is already imported.",
			"https://www.php.gt/docs/WebEngine/Class-imports-in-page-logic/",
		);
	}

	private function getMissingClassName(Throwable $throwable):?string {
		$pattern = match(true) {
			$throwable instanceof ServiceNotFoundException => '/^Service can not be located within Container: "([^"]+)"/',
			$throwable instanceof Error => '/^Class "([^"]+)" not found$/',
			default => null,
		};
		if(!$pattern || !preg_match($pattern, $throwable->getMessage(), $matches)) {
			return null;
		}

		return $matches[1];
	}
}
