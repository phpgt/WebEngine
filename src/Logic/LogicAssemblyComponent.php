<?php
namespace GT\WebEngine\Logic;

use GT\Dom\Element;
use GT\Routing\Assembly;

readonly class LogicAssemblyComponent {
	public function __construct(
		public Assembly $assembly,
		public Element $component, // TODO: Or other type of component
	) {}
}
