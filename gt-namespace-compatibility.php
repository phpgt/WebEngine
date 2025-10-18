
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
		// Trigger autoloading for the legacy class
		spl_autoload_call($legacyClass);
		// Only create alias if it was loaded and target doesn't already exist
		if((class_exists($legacyClass, false) || interface_exists($legacyClass, false) || trait_exists($legacyClass, false))
			&& !class_exists($class, false) && !interface_exists($class, false) && !trait_exists($class, false)) {
			class_alias($legacyClass, $class);
		}
	}
}, true, true);
