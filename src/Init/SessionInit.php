<?php
namespace GT\WebEngine\Init;

use GT\Session\Session;
use GT\Session\SessionSetup;

class SessionInit {
	private Session $session;

	/**
	 * @param array<string, string> $currentCookieArray
	 * @SuppressWarnings("PHPMD.Superglobals")
	 */
	public function __construct(
		string $name,
		string $handler,
		string $savePath,
		bool $useTransSid,
		bool $useCookies,
		int $cookieLifetime = Session::DEFAULT_SESSION_LIFETIME,
		string $cookiePath = Session::DEFAULT_COOKIE_PATH,
		string $cookieDomain = Session::DEFAULT_SESSION_DOMAIN,
		bool $cookieSecure = Session::DEFAULT_SESSION_SECURE,
		bool $cookieHttpOnly = Session::DEFAULT_SESSION_HTTPONLY,
		string $cookieSameSite = Session::DEFAULT_COOKIE_SAMESITE,
		bool $useOnlyCookies = true,
		bool $useStrictMode = Session::DEFAULT_STRICT_MODE,
		array $currentCookieArray = [],
		?SessionSetup $sessionSetup = null,
		string|Session $sessionClass = Session::class,
	) {
		$originalCookie = $_COOKIE;
		$_COOKIE = $currentCookieArray;

		$sessionConfig = [
			"name" => $name,
			"handler" => $handler,
			"save_path" => $savePath,
			"use_trans_sid" => $useTransSid,
			"use_cookies" => $useCookies,
			"cookie_lifetime" => $cookieLifetime,
			"cookie_path" => $cookiePath,
			"cookie_domain" => $cookieDomain,
			"cookie_secure" => $cookieSecure,
			"cookie_httponly" => $cookieHttpOnly,
			"cookie_samesite" => $cookieSameSite,
			"use_only_cookies" => $useOnlyCookies,
			"use_strict_mode" => $useStrictMode,
		];

		$sessionId = $_COOKIE[$sessionConfig["name"]] ?? null;
		$sessionSetup = $sessionSetup ?? new SessionSetup();
		$sessionHandler = $sessionSetup->attachHandler($sessionConfig["handler"]);

		if($sessionClass instanceof Session) {
			$this->session = $sessionClass;
		}
		else {
// @codeCoverageIgnoreStart
			$this->session = new $sessionClass(
				$sessionHandler,
				$sessionConfig,
				$sessionId,
			);
// @codeCoverageIgnoreEnd
		}

		$_COOKIE = $originalCookie;
	}

	public function getSession():Session {
		return $this->session;
	}
}
