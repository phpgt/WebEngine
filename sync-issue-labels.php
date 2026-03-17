<?php
/**
 * The PHP.GT organisation on Github has many repositories. This script syncs
 * all of the issue list labels with WebEngine's - so WebEngine acts as the
 * source of truth for all repository labels.
 *
 * @vibe https://chatgpt.com/share/68dfe267-2648-800c-a07f-15cdcf6ea8b7
 */
$githubOrganisation = "phpgt";
$githubRepoSource = "WebEngine";
$optDeleteExtra = in_array("--delete-extra", $argv, true);
$optDryRun = in_array("--dry-run", $argv, true);
$githubToken = getenv("GITHUB_TOKEN") ?: die("GITHUB_TOKEN not set\n");
$uriBase = "https://api.github.com";

function httpRequest(string $method, string $url, ?array $json = null, ?array &$headersOut = null):array {
	global $githubToken;
	$ch = curl_init($url);

	$headers = [
		"Authorization: Bearer $githubToken",
		"Accept: application/vnd.github+json",
		"User-Agent: phpgt-label-sync"
	];

	$opts = [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_HEADER => true,
	];

	if($json !== null) {
		$body = json_encode($json, JSON_UNESCAPED_SLASHES);
		$headers[] = "Content-Type: application/json";
		$opts[CURLOPT_HTTPHEADER] = $headers;
		$opts[CURLOPT_POSTFIELDS] = $body;
	}

	curl_setopt_array($ch, $opts);
	$fullResponse = curl_exec($ch);
	if($fullResponse === false) {
		die("curl error: " . curl_error($ch) . "\n");
	}

	$responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	$rawHeaders = substr($fullResponse, 0, $headerSize);
	$body = substr($fullResponse, $headerSize);
	curl_close($ch);

	$headersOut = [];
	foreach(explode("\r\n", $rawHeaders) as $h) {
		$p = strpos($h, ":");
		if($p !== false) {
			$headersOut[trim(substr($h, 0, $p))] = trim(substr($h, $p + 1));
		}
	}

	if($responseCode >= 400) {
		fwrite(STDERR, "HTTP $responseCode $method $url: $body\n");

		if($responseCode == 422) {
			return [[], $headersOut];
		} // continue on validation errors
	}

	$decoded = json_decode($body, true);
	return [$decoded ?? [], $headersOut];
}

function paginate(string $path):array {
	global $uriBase;
	$items = [];
	$url = $uriBase . $path;

	do {
		[$page] = httpRequest("GET", $url, null, $headers);
		if(isset($page[0]) || empty($page)) {
			$items = array_merge($items, $page);
		}
		else {
			$items[] = $page;
		}

		$next = null;
		if(!empty($headers["Link"])) {
			foreach(explode(",", $headers["Link"]) as $link) {
				if(preg_match('/<([^>]+)>;\s*rel="next"/', $link, $matches)) {
					$next = $matches[1];
					break;
				}
			}
		}

		$url = $next ?? "";
	}
	while($url);

	return $items;
}

function normaliseDesc(?string $d):string {
	return $d === null ? "" : $d;
}

function uri(string $uri):string {
	return rawurlencode($uri);
}

fwrite(STDERR, "Fetching template labels from $githubOrganisation/$githubRepoSource\n");

$template = paginate("/repos/$githubOrganisation/$githubRepoSource/labels?per_page=100");
$tpl = [];
foreach($template as $l) {
	$tpl[$l["name"]] = [
		"color" => $l["color"],
		"description" => normaliseDesc($l["description"] ?? "")
	];
}

fwrite(STDERR, "Fetching org repositories for $githubOrganisation\n");
$repos = paginate("/orgs/$githubOrganisation/repos?per_page=100&type=public");
$names = array_values(array_filter(array_map(fn($r) => $r['name'], $repos), fn($n) => $n !== $githubRepoSource));

foreach($names as $repo) {
	fwrite(STDERR, "==> $repo\n");
	$existing = paginate("/repos/$githubOrganisation/$repo/labels?per_page=100");
	$have = [];
	foreach($existing as $l) {
		$have[$l["name"]] = [
			"color" => $l["color"],
			"description" => normaliseDesc($l["description"] ?? "")
		];
	}

// Create or update.
	foreach($tpl as $name => $data) {
		if(isset($have[$name])) {
			if($have[$name]["color"] !== $data['color'] || $have[$name]['description'] !== $data['description']) {
				fwrite(STDERR, "  PATCH $name\n");
				if(!$optDryRun) {
					httpRequest("PATCH", "$uriBase/repos/$githubOrganisation/$repo/labels/" . uri($name), [
						'name' => $name,
						'color' => $data['color'],
						'description' => $data['description']
					]);
				}
			}
		}
		else {
			fwrite(STDERR, "  CREATE $name\n");
			if(!$optDryRun) {
				httpRequest("POST", "$uriBase/repos/$githubOrganisation/$repo/labels", [
					'name' => $name,
					'color' => $data['color'],
					'description' => $data['description']
				]);
			}
		}
	}

// Delete extras.
	if($optDeleteExtra) {
		foreach($have as $ename => $_) {
			if(!isset($tpl[$ename])) {
				fwrite(STDERR, "  DELETE $ename\n");
				if(!$optDryRun) {
					httpRequest("DELETE", "$uriBase/repos/$githubOrganisation/$repo/labels/" . uri($ename));
				}
			}
		}
	}
}
fwrite(STDERR, "Done\n");