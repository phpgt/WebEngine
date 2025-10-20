<?php
namespace Gt\WebEngine\Redirection;

use Closure;

/**
 * Redirection: file-driven HTTP redirects for WebEngine
 *
 * This component lets you define site-wide redirects in a single file in your
 * project root, applied before the page lifecycle begins. It is designed to be
 * simple to maintain, friendly for editors, and safe by default.
 *
 * Place a file named one of the following in your project root:
 *   - redirect.ini
 *   - redirect.csv
 *   - redirect.tsv
 * The Redirect class automatically discovers and loads the single redirect file
 * using the glob pattern `redirect.{csv,tsv,ini}`.
 */
class Redirect {
	private ?string $redirectFile = null;
	private Closure $redirectHandler;
	private RedirectMap $map;

	public function __construct(
		string $glob = "redirect.{csv,tsv,ini}",
		?Closure $redirectHandler = null,
	) {
		$matches = $this->expandBraceGlob($glob);
		if (count($matches) > 1) {
			throw new RedirectException("Multiple redirect files in project root");
		}

		$this->redirectFile = $matches[0] ?? null;
		$this->redirectHandler = $redirectHandler ??
			fn(string $uri, int $code)
			=> header("Location: $uri", true, $code);

		$this->map = new RedirectMap();
		if($this->redirectFile) {
			$extension = strtolower(pathinfo($this->redirectFile, PATHINFO_EXTENSION));
			$loader = $this->createLoader($extension);
			$loader?->load($this->redirectFile, $this->map);
		}
 }

	private function createLoader(string $extension):?RedirectLoader {
		return match($extension) {
			'ini' => new IniRedirectLoader(),
			'csv' => new DelimitedRedirectLoader(','),
			'tsv' => new DelimitedRedirectLoader("\t"),
			default => null,
		};
	}

	/**
	 * Cross-platform brace expansion for glob patterns.
	 * Supports a single {a,b,c} segment. Falls back to plain glob when no braces.
	 * Returns a sorted, unique list of matches.
	 *
	 * @return array<int, string>
	 */
	private function expandBraceGlob(string $pattern): array {
		/** @noinspection RegExpRedundantEscape */
		if(preg_match('/\{([^}]+)\}/', $pattern, $braceMatch)) {
			$options = array_map('trim', explode(',', $braceMatch[1]));
			$all = [];
			foreach($options as $option) {
				$subPattern = str_replace($braceMatch[0], $option, $pattern);
				$subMatches = glob($subPattern) ?: [];
				if(!empty($subMatches)) {
					$all = array_merge($all, $subMatches);
				}
			}
			$all = array_values(array_unique($all));
			sort($all);
			return $all;
		}
		return glob($pattern) ?: [];
	}

	public function execute(string $uri = "/"):void {
		$redirect = $this->getRedirectUri($uri);
		if($redirect && $redirect->code > 0 && $redirect->uri !== $uri) {
			($this->redirectHandler)($redirect->uri, $redirect->code);
		}
	}

	public function getRedirectUri(string $oldUri):?RedirectUri {
		if($this->map->isEmpty()) {
			return null;
		}
		return $this->map->match($oldUri);
	}
}
