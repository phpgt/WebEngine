<?php
namespace Gt\WebEngine\Dispatch;

use Gt\Config\Config;
use Gt\Dom\HTMLDocument;
use Gt\DomTemplate\BindableCache;
use Gt\DomTemplate\Binder;
use Gt\DomTemplate\DocumentBinder;
use Gt\DomTemplate\ComponentExpander;
use Gt\DomTemplate\ElementBinder;
use Gt\DomTemplate\HTMLAttributeBinder;
use Gt\DomTemplate\HTMLAttributeCollection;
use Gt\DomTemplate\ListBinder;
use Gt\DomTemplate\ListElementCollection;
use Gt\DomTemplate\PartialContent;
use Gt\DomTemplate\PartialContentDirectoryNotFoundException;
use Gt\DomTemplate\PartialExpander;
use Gt\DomTemplate\PlaceholderBinder;
use Gt\DomTemplate\TableBinder;
use Gt\Routing\Assembly;
use Gt\Routing\Path\DynamicPath;
use Gt\ServiceContainer\Container;

/**
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * Reason: This coordinator wires multiple binder components together in one place
 * to prepare the document for binding. The high coupling reflects that explicit
 * composition step; each dependency is injected via the container and can be
 * isolated in unit tests. Splitting this further would reduce clarity rather
 * than improve design, as the purpose is to centralise binder setup.
 */
class HtmlDocumentProcessor {
	public function __construct(
		private Config $config,
		private Container $container,
	) {}

	/**
	 * @return array{Assembly, \Gt\Dom\Element}[]
	 */
	public function process(HTMLDocument $document, DynamicPath $dynamicPath): array {
		$expandedLogicAssemblyList = [];
		$expandedComponentList = [];
		
		$componentDirectory = $this->config->getString("view.component_directory");
		$partialDirectory = $this->config->getString("view.partial_directory");
		
		try {

			$partial = new PartialContent(implode(DIRECTORY_SEPARATOR, [
				getcwd(),
				$componentDirectory,
			]));
			$componentExpander = new ComponentExpander(
				$document,
				$partial,
			);

			foreach($componentExpander->expand() as $componentElement) {
				$filePath = $componentDirectory;
				$filePath .= DIRECTORY_SEPARATOR;
				$filePath .= strtolower($componentElement->tagName);
				$filePath .= ".php";

				if(is_file($filePath)) {
					$componentAssembly = new Assembly();
					$componentAssembly->add($filePath);
					array_push($expandedLogicAssemblyList, $componentAssembly);
					array_push($expandedComponentList, $componentElement);
				}
			}
		}
		// phpcs:ignore
		catch(PartialContentDirectoryNotFoundException) {
			// Directory is optional; continue processing when not present.
			// @SuppressWarnings(PHPMD.EmptyCatchBlock)
		}
		
		try {
			$partial = new PartialContent(implode(DIRECTORY_SEPARATOR, [
				getcwd(),
				$partialDirectory,
			]));

			$partialExpander = new PartialExpander(
				$document,
				$partial,
			);
			$partialExpander->expand();
		}
		// phpcs:ignore
		catch(PartialContentDirectoryNotFoundException) {
			// Directory is optional; continue processing when not present.
			// @SuppressWarnings(PHPMD.EmptyCatchBlock)
		}
		
		$dynamicUri = $dynamicPath->getUrl("page/");
		$dynamicUri = str_replace("/", "--", $dynamicUri);
		$dynamicUri = str_replace("@", "_", $dynamicUri);
		$document->body->classList->add("uri" . $dynamicUri);
		$bodyDirClass = "dir";
		foreach(explode("--", $dynamicUri) as $i => $pathPart) {
			if($i === 0) {
				continue;
			}
			$bodyDirClass .= "--$pathPart";
			$document->body->classList->add($bodyDirClass);
		}

		$this->container->get(HTMLAttributeBinder::class)->setDependencies(
			$this->container->get(ListBinder::class),
			$this->container->get(TableBinder::class),
		);
		$this->container->get(ElementBinder::class)->setDependencies(
			$this->container->get(HTMLAttributeBinder::class),
			$this->container->get(HTMLAttributeCollection::class),
			$this->container->get(PlaceholderBinder::class),
		);
		$this->container->get(TableBinder::class)->setDependencies(
			$this->container->get(ListBinder::class),
			$this->container->get(ListElementCollection::class),
			$this->container->get(ElementBinder::class),
			$this->container->get(HTMLAttributeBinder::class),
			$this->container->get(HTMLAttributeCollection::class),
			$this->container->get(PlaceholderBinder::class),
		);
		$this->container->get(ListBinder::class)->setDependencies(
			$this->container->get(ElementBinder::class),
			$this->container->get(ListElementCollection::class),
			$this->container->get(BindableCache::class),
			$this->container->get(TableBinder::class),
		);
		/** @var DocumentBinder $binder */
		$binder = $this->container->get(Binder::class);
		$binder->setDependencies(
			$this->container->get(ElementBinder::class),
			$this->container->get(PlaceholderBinder::class),
			$this->container->get(TableBinder::class),
			$this->container->get(ListBinder::class),
			$this->container->get(ListElementCollection::class),
			$this->container->get(BindableCache::class),
		);

		$tupleList = [];
		foreach($expandedLogicAssemblyList as $i => $assembly) {
			$component = $expandedComponentList[$i];
			array_push($tupleList, [$assembly, $component]);
		}

		return $tupleList;
	}
}
