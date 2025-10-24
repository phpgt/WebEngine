<?php
namespace GT\WebEngine;

use Gt\Http\Request;
use Gt\Routing\BaseRouter;
use Gt\Routing\Method\Any;
use Gt\Routing\Method\Get;
use Gt\Routing\Method\Post;
use Gt\Routing\Path\FileMatch\BasicFileMatch;
use Gt\Routing\Path\FileMatch\MagicFileMatch;
use Gt\Routing\Path\PathMatcher;
use Gt\Routing\Path\DynamicPath;
use Gt\WebEngine\View\BaseView;
use Gt\WebEngine\View\HTMLView;
use Gt\WebEngine\View\NullView;

class DefaultRouter extends BaseRouter {
	#[Any(name: "page-route", accept: "text/html,application/xhtml+xml")]
	public function page(Request $request):void {
		$pathMatcher = new PathMatcher("page");
		$this->setViewClass(HTMLView::class);
		$this->pathMatcherFilter($pathMatcher);

// This sort function allow multiple headers and footers to be in nested
// directories, so the highest level header is at the start of the list,
// with the reverse logic applied to footers.
// TODO: Extract into own function. Should this be maintained within PHP.Gt/Routing ?
		$headerFooterSort = function(string $a, string $b):int {
			$fileNameA = pathinfo($a, PATHINFO_FILENAME);
			$fileNameB = pathinfo($b, PATHINFO_FILENAME);

			if($fileNameA === "_header") {
				if($fileNameB === "_header") {
					$aDepth = substr_count($a, "/");
					$bDepth = substr_count($b, "/");
					if($aDepth > $bDepth) {
						return 1;
					}
					elseif($aDepth < $bDepth) {
						return -1;
					}
					else {
						return 0;
					}
				}


				return -1;
			}

			if($fileNameA === "_footer") {
				if($fileNameB === "_footer") {
					$aDepth = substr_count($a, "/");
					$bDepth = substr_count($b, "/");
					if($aDepth < $bDepth) {
						return 1;
					}
					elseif($aDepth > $bDepth) {
						return -1;
					}
					else {
						return 0;
					}
				}

				return 1;
			}

			if($fileNameB === "_header") {
				return 1;
			}

			if($fileNameB === "_footer") {
				return -1;
			}

			return 0;
		};

		$sortNestLevelCallback = fn(string $a, string $b) =>
		substr_count($a, "/") > substr_count($b, "/")
			? 1
			: (substr_count($a, "/") < substr_count($b, "/")
			? -1
			: 0);

		if($this->errorStatus) {
			$uriPath = "_error/$this->errorStatus";
		}
		else {
			$uriPath = $request->getUri()->getPath();
		}

		$matchingLogics = $pathMatcher->findForUriPath(
			$uriPath,
			"page",
			"php"
		);
		usort($matchingLogics, $sortNestLevelCallback);
		foreach($matchingLogics as $path) {
			$this->addToLogicAssembly($path);
		}

		$matchingViews = $pathMatcher->findForUriPath(
			$uriPath,
			"page",
			"html"
		);
		usort($matchingViews, $headerFooterSort);
		foreach($matchingViews as $path) {
			$this->addToViewAssembly($path);
		}
	}

	#[Any(name: "api-route", accept: "application/xml,application/json")]
	public function api(
		Request $request
	):void {
		$pathMatcher = new PathMatcher("api");
		$this->pathMatcherFilter($pathMatcher);
		$this->setViewClass(NullView::class);
		$sortNestLevelCallback = fn(string $a, string $b) =>
		substr_count($a, "/") > substr_count($b, "/")
			? 1
			: (substr_count($a, "/") < substr_count($b, "/")
			? -1
			: 0);

		$matchingLogics = $pathMatcher->findForUriPath(
			$request->getUri()->getPath(),
			"api",
			"php"
		);
		usort($matchingLogics, $sortNestLevelCallback);
		foreach($matchingLogics as $path) {
			$this->addToLogicAssembly($path);
		}

		$matchingViews = $pathMatcher->findForUriPath(
			$request->getUri()->getPath(),
			"api",
			"xml"
		);
		foreach($matchingViews as $path) {
			$this->addToViewAssembly($path);
		}
	}

	public function pathMatcherFilter(PathMatcher $pathMatcher):void {
		$pathMatcher->addFilter(function(string $filePath, string $uriPath, string $baseDir):bool {
			foreach(glob($baseDir . $uriPath . ".*") as $globMatch) {
				$URI_CONTAINER = pathinfo($uriPath, PATHINFO_DIRNAME);
				$TRIM_THIS = $baseDir . $URI_CONTAINER;
				if(str_starts_with($globMatch, $TRIM_THIS)) {
					$trimmed = substr($filePath, strlen($TRIM_THIS));
					if(str_contains($trimmed, "@")) {
						return false;
					}
				}
			}

// There are three types of matching files: Basic, Magic and Dynamic.
// Basic is where a URI matches directly to a file on disk.
// Magic is where a URI matches a PHP.Gt-specific file, like _common or _header.
// Dynamic is where a URI matches a file/directory marked as dynamic with "@".
			$basicFileMatch = new BasicFileMatch($filePath, $baseDir);
			if($basicFileMatch->matches($uriPath)) {
				return true;
			}

			$magicFileMatch = new MagicFileMatch($filePath, $baseDir);
			if($magicFileMatch->matches($uriPath)) {
				return true;
			}

			return false;
		});
	}
}
