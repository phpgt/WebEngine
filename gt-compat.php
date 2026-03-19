<?php
namespace GT\WebEngine;

function registerNamespaceCompatibilityAutoloader():void {
	static $registered = false;

	if($registered) {
		return;
	}

	spl_autoload_register(function(string $class):void {
		if(!str_starts_with($class, 'GT\\')) {
			return;
		}

		$legacyClass = $class[0] . strtolower($class[1]) . substr($class, 2);
		spl_autoload_call($legacyClass);

		if((class_exists($legacyClass, false)
			|| interface_exists($legacyClass, false)
			|| trait_exists($legacyClass, false))
			&& !class_exists($class, false)
			&& !interface_exists($class, false)
			&& !trait_exists($class, false)) {
			class_alias($legacyClass, $class);
		}
	}, true, true);

	$registered = true;
}

registerNamespaceCompatibilityAutoloader();
