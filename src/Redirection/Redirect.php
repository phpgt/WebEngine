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
 * Contents
 * - Overview
 * - Quick start
 * - Redirect file formats (INI, CSV, TSV)
 * - Regex rules (nginx-style)
 * - Status codes and validation
 * - Exceptions and error messages
 * - Programmatic API (Redirect, RedirectUri)
 * - How loading and matching works
 *
 * Overview
 * Place a file named one of the following in your project root:
 *   - redirect.ini
 *   - redirect.csv
 *   - redirect.tsv
 * The Redirect class automatically discovers and loads the single redirect file
 * using the glob pattern `redirect.{csv,tsv,ini}`. If more than one file
 * matches, a RedirectException is thrown, as multiple sources would be
 * ambiguous.
 *
 * Quick start
 * 1) Create redirect.ini in your project root:
 *    /old-path=/new-path
 * 2) Start your application (Application calls Redirect->execute()). Requests to
 *    /old-path will be redirected to /new-path with HTTP 307 by default.
 *
 * Redirect file formats
 * - INI
 *   - Flat (no sections): each line `oldURI=newURI` uses the default status 307.
 *   - Sectioned: use numeric status code sections, e.g.:
 *       [301]
 *       /moved=/new-location
 *       [303]
 *       /see-other=/somewhere
 *   - Section names must be numeric between 301 and 308 inclusive; any
 *     non-numeric section causes a RedirectException.
 *
 * - CSV
 *   - Columns: oldURI,newURI[,status]
 *   - If the third column is present it must be numeric (301–308); otherwise a
 *     RedirectException is thrown. If omitted, 307 is used.
 *
 * - TSV
 *   - Same as CSV but tab-delimited: oldURI \t newURI [\t status]
 *   - Same validation rules as CSV apply.
 *
 * Regex rules (nginx-style)
 * - To define a regex match for the old URI, prefix the pattern with `~`.
 *   Example that maps both /shop/cat/item and /shop/dog/thing:
 *     ~^/shop/([^/]+)/(.+)$=/newShop/$1/$2   (INI)
 *     ~^/shop/([^/]+)/(.+)$,/newShop/$1/$2,302   (CSV)
 *   The replacement may reference capture groups using $1, $2, etc.
 * - All URIs begin with `/`, so patterns should be anchored appropriately.
 * - Invalid regex patterns result in a RedirectException.
 *
 * Status codes and validation
 * - Default status code: 307.
 * - Allowed range: 301–308 (inclusive). Values are validated and normalised.
 * - For INI with sections, the section number is the status code; for CSV/TSV,
 *   the optional third column is the status.
 * - Non-numeric INI sections and non-numeric third columns in CSV/TSV throw a
 *   RedirectException with a message describing the invalid value.
 *
 * Exceptions and error messages
 * - Multiple redirect files: "Multiple redirect files in project root".
 * - Invalid HTTP status code read from a file: "Invalid HTTP status code in
 *   redirect file: <value>".
 * - Invalid regex pattern: message explains which pattern failed.
 *
 * Programmatic API
 * - new Redirect(string $glob = "redirect.{csv,tsv,ini}", ?Closure $handler = null)
 *   - $glob allows overriding the discovery pattern/location.
 *   - $handler receives (string $uri, int $code). If omitted, the default
 *     implementation sends a Location header and code.
 * - execute(string $uri = "/"): void
 *   - Looks up the given $uri and, if a redirect exists, invokes the handler.
 * - getRedirectUri(string $oldUri): ?RedirectUri
 *   - Returns RedirectUri with target uri and status code, or null if no match.
 * - RedirectUri
 *   - Value object with public string $uri and public int $code.
 *
 * How loading and matching works
 * - Redirect determines the loader from the file extension (INI, CSV, TSV) and
 *   populates an internal RedirectMap.
 * - Literal rules and regex rules are both supported. Matching prefers literal
 *   rules first; if no literal match is found, regex rules are evaluated in a
 *   deterministic order.
 * - Only a single redirect file may exist; if none is found, getRedirectUri
 *   returns null and execute does nothing.
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
