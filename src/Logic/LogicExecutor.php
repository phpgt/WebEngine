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
		private Assembly $assembly,
		private Injector $injector,
		private string $appNamespace
	) {
		foreach($assembly as $file) {
			$this->loadLogicFile($file);
		}
	}

	/** @return Generator<string> filename::function() */
	public function invoke(string $name, array $extraArgs = []):Generator {
		foreach(iterator_to_array($this->assembly) as $file) {
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
