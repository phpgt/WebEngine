<?php
namespace GT\WebEngine\Logic;

use Generator;
use GT\Routing\Assembly;
use GT\Routing\LogicStream\LogicStreamNamespace;
use GT\Routing\LogicStream\LogicStreamWrapper;
use GT\ServiceContainer\Injector;
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
				$file,
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
						$closure = $fnReference(...);
						$refFunction = new ReflectionFunction($closure);
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
		$streamPath = LogicStreamWrapper::STREAM_NAME . "://$file";
		require_once($streamPath);
	}
}
