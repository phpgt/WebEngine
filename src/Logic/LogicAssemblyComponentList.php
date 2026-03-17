<?php
namespace GT\WebEngine\Logic;

use ArrayIterator;
use Gt\Dom\Element;
use Gt\Routing\Assembly;
/** @extends ArrayIterator<int, LogicAssemblyComponent> */
class LogicAssemblyComponentList extends ArrayIterator {
	public function addAssemblyComponent(Assembly $assembly, Element $component):void {
		$this->append(
			new LogicAssemblyComponent(
				$assembly,
				$component,
			)
		);
	}
}
