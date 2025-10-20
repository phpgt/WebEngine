<?php
namespace Gt\WebEngine\Logic;

/**
 * This autoloader is only necessary if Composer's PSR-4 autoloader isn't set up
 * in the project's composer.json.
 *
 * To set up PSR-4 autoloading in composer.json, add an "autoload" section with
 * "psr-4" mapping your namespace prefix to the directory containing your
 * classes:
 *
 * ```json
 * {
 *     "autoload": {
 *         "psr-4": {
 *             "MyApp\\": "src/"
 *         }
 *     }
 * }
 * ```
 *
 * When Composer's autoloader is properly configured and generated via
 * "composer dump-autoload", it will handle class loading before this autoloader
 * is called, making this class effectively inactive.
 *
 * Composer's autoloader is preferred for its performance optimisations and
 * efficient class map caching. This fallback autoloader ensures developers can
 * still work on the project during initial setup or when Composer isn't fully
 * configured - exactly when maintaining momentum is most crucial in a project.
 */
readonly class AppAutoloader {
	public function __construct(
		private string $namespace,
		private string $classDir,
	) {
	}

	public function setup():void {
		if(!is_dir($this->classDir)) {
			return;
		}

		spl_autoload_register(fn(string $className) => $this->autoload($className));
	}

	private function autoload(string $className):void {
		if(!str_starts_with($className, $this->namespace . "\\")) {
			return;
		}

		$classNameWithoutAppNamespace = substr(
			$className,
			strlen($this->namespace) + 1
		);

		$phpFilePath = $this->classDir;
		// If classDir is not absolute (neither POSIX '/' nor Windows drive letter), prefix with './'
		if(!str_starts_with($phpFilePath, "/") && !preg_match('/^[A-Za-z]:[\\\\\/]/', $phpFilePath)) {
			$phpFilePath = "./" . $phpFilePath;
		}
		foreach(explode("\\", $classNameWithoutAppNamespace) as $classPart) {
			$phpFilePath .= "/";
			$phpFilePath .= ucfirst($classPart);
		}

		$phpFilePath .= ".php";
		if(is_file($phpFilePath)) {
			require($phpFilePath);
		}
	}
}
