<?php
/**
 * Namespace compatibility layer for Gt -> GT transition.
 * To mark the rebranding of PHP.GT and the release of WebEngine v5, wherever
 * "GT" is mentioned, it will be used uppercase.
 * This allows code to use GT\* namespaces while packages still define Gt\*
 */

spl_autoload_register(function(string $class):void {
	if(str_starts_with($class, 'GT\\')) {
		$legacyClass = 'Gt' . substr($class, 2);
		class_alias($legacyClass, $class, true);
	}
}, true, false);
