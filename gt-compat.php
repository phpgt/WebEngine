<?php
const GT_COMPAT_LEGACY_PREFIX = "Gt\\";
const GT_COMPAT_MODERN_PREFIX = "GT\\";
const GT_COMPAT_LOCAL_PREFIX = "GT\\WebEngine\\";

spl_autoload_register(static function(string $className):void {
	if(!str_starts_with($className, GT_COMPAT_MODERN_PREFIX)
	|| str_starts_with($className, GT_COMPAT_LOCAL_PREFIX)) {
		return;
	}

	$legacyClassName = GT_COMPAT_LEGACY_PREFIX . substr(
		$className,
		strlen(GT_COMPAT_MODERN_PREFIX)
	);

	$classMapPath = "vendor/composer/autoload_classmap.php";
	if(is_file($classMapPath)) {
		/** @var array<string,string> $classMap */
		$classMap = require $classMapPath;
		if(isset($classMap[$legacyClassName])) {
			require_once $classMap[$legacyClassName];
			return;
		}
	}

	$psr4Path = "vendor/composer/autoload_psr4.php";
	if(!is_file($psr4Path)) {
		return;
	}

	/** @var array<string,list<string>> $psr4 */
	$psr4 = require $psr4Path;
	$prefix = $legacyClassName;

	while(($separatorPos = strrpos($prefix, "\\")) !== false) {
		$prefix = substr($legacyClassName, 0, $separatorPos + 1);
		$relativeClass = substr($legacyClassName, $separatorPos + 1);

		if(!isset($psr4[$prefix])) {
			$prefix = rtrim($prefix, "\\");
			continue;
		}

		$relativePath = str_replace("\\", "/", $relativeClass) . ".php";
		foreach($psr4[$prefix] as $baseDirectory) {
			$pathName = $baseDirectory . "/" . $relativePath;
			if(is_file($pathName)) {
				require_once $pathName;
				return;
			}
		}

		$prefix = rtrim($prefix, "\\");
	}
});
