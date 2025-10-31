<?php
namespace GT\WebEngine\Init;

use Closure;
use Gt\Input\Input;
use Gt\Http\Uri;
use Gt\Http\ServerInfo;
use GT\WebEngine\Dispatch\PathNormaliser;

class RequestInit {
	private Input $input;
	private ServerInfo $serverInfo;

	/**
	 * @param array<string, string> $get
	 * @param array<string, string> $post
	 * @param array<string, string|array<string, string>> $files
	 * @param array<string, string> $server
	 */
	public function __construct(
		private PathNormaliser $pathNormaliser,
		private Uri $requestUri,
		private bool $forceTrailingSlash,
		private Closure $redirect,
		array $get,
		array $post,
		array $files,
		array $server,
	) {
		$this->pathNormaliser->normaliseTrailingSlash(
			$this->requestUri,
			$this->forceTrailingSlash,
			$this->redirect,
		);

		$this->input = new Input($get, $post, $files);
		$this->serverInfo = new ServerInfo($server);
	}

	public function getInput():Input {
		return $this->input;
	}

	public function getServerInfo():ServerInfo {
		return $this->serverInfo;
	}
}
