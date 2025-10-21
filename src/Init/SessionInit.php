<?php
namespace GT\WebEngine\Init;

use Gt\Session\Session;
use Gt\Session\SessionSetup;

class SessionInit {
	private Session $session;

	/**
	 * @param array<string, string> $currentCookieArray
	 */
	public function __construct(
		string $name,
		string $handler,
		string $savePath,
		bool $useTransSid,
		bool $useCookies,
		array $currentCookieArray,
	) {
		$originalCookie = $_COOKIE;
		$_COOKIE = $currentCookieArray;

		$sessionConfig = [
			"name" => $name,
			"handler" => $handler,
			"save_path" => $savePath,
			"use_trans_sid" => $useTransSid,
			"use_cookies" => $useCookies,
		];

		$sessionId = $_COOKIE[$sessionConfig["name"]] ?? null;
		$sessionSetup = new SessionSetup();
		$sessionHandler = $sessionSetup->attachHandler($sessionConfig["handler"]);

		$this->session = new Session(
			$sessionHandler,
			$sessionConfig,
			$sessionId,
		);

		$_COOKIE = $originalCookie;
	}

	public function getSession():Session {
		return $this->session;
	}
}
