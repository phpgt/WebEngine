<?php
namespace GT\WebEngine\Logic;

use Generator;
use Gt\Routing\Assembly;
use Gt\Routing\LogicStream\LogicStreamNamespace;
use Gt\Routing\LogicStream\LogicStreamWrapper;
use Gt\ServiceContainer\Injector;
use ReflectionFunction;

class LogicExecutor {
	public function __construct(
		private string $appNamespace,
		private Injector $injector,
	) {
	}

	/** @return Generator<string> filename::function() */
	public function invoke(Assembly $logicAssembly, string $name, array $extraArgs = []):Generator {
		foreach($logicAssembly as $file) {
			$this->loadLogicFile($file);
		}

// TODO: Why convert to array?
		foreach(iterator_to_array($logicAssembly) as $file) {
			$nsProject = (string)(new LogicProjectNamespace(
				$this->relativePath($file),
				$this->appNamespace
			));

			$instance = null;

			if(class_exists($nsProject)) {
				$instance = new $nsProject;
			}

			$functionReference = "$file::$name()";

			if($instance) {
				if(method_exists($instance, $name)) {
					$this->injector->invoke(
						$instance,
						$name,
						$extraArgs,
					);
					yield $functionReference;
				}
			}
			else {
				$nsDefault = (string)(new LogicStreamNamespace($file));
				$fqnsDefault = LogicStreamWrapper::NAMESPACE_PREFIX . $nsDefault;
				$fnReferenceArray = [
					"$fqnsDefault\\$name",
					"$nsProject\\$name"
				];

				foreach($fnReferenceArray as $fnReference) {
					if(function_exists($fnReference)) {
						$refFunction = new ReflectionFunction($fnReference);
						foreach($refFunction->getAttributes() as $refAttr) {
							$functionReference .= "#";
							$functionReference .= $refAttr->getName();
							$functionReference .= "(";
							foreach($refAttr->getArguments() as $refArgIndex => $refArg) {
								if($refArgIndex > 0) {
									$functionReference .= ",";
								}

								if(is_string($refArg)) {
									$functionReference .= "\"";
								}
								$functionReference .= "$refArg";
								if(is_string($refArg)) {
									$functionReference .= "\"";
								}
							}
							$functionReference .= ")";
						}

						$this->injector->invoke(
							null,
							$fnReference,
							$extraArgs
						);
						yield $functionReference;
					}
				}
			}
		}
	}

	private function loadLogicFile(string $file):void {
		// If the target file already declares a namespace, load it directly.
		// The LogicStreamWrapper injects a namespace for classless scripts, but
		// passing an already-namespaced file through the wrapper can cause an
		// extra namespace to be injected which leads to syntax errors when
		// executing tests in isolation.
		if($this->fileHasNamespace($file)) {
			require_once($file);
			return;
		}

		$streamPath = LogicStreamWrapper::STREAM_NAME . "://$file";
		require_once($streamPath);
	}

	private function fileHasNamespace(string $file):bool {
		$fh = fopen($file, "r");
		$maxLines = 50;
		$read = "";
		for($i = 0; $i < $maxLines && !feof($fh); $i++) {
			$read .= fgets($fh);
		}
		fclose($fh);
		return (bool)preg_match('/^\s*namespace\s+[^;]+;/m', $read);
	}

	private function relativePath(string $path):string {
		if(!str_starts_with($path, "/") && !preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
			return $path;
		}
		$cwd = rtrim(getcwd(), "/");
		$real = realpath($path) ?: $path;
		if(str_starts_with($real, $cwd . "/")) {
			$path = ltrim(substr($real, strlen($cwd) + 1), "/");
		}
		return $path;
	}
}
