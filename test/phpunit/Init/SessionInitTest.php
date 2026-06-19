<?php
namespace GT\WebEngine\Test\Init;

use GT\Session\FileHandler;
use GT\Session\Session;
use GT\Session\SessionSetup;
use GT\WebEngine\Init\SessionInit;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use SessionHandlerInterface;

class SessionInitTest extends TestCase {
	private string $tmpDir;
	private array $originalCookie;

	protected function setUp():void {
		parent::setUp();
		$this->tmpDir = sys_get_temp_dir() . "/phpgt-webengine-test--Init-SessionInit-" . uniqid();
		if(!is_dir($this->tmpDir)) {
			mkdir($this->tmpDir, recursive: true);
		}
		$this->originalCookie = $_COOKIE;
	}

	protected function tearDown():void {
		$_COOKIE = $this->originalCookie;
		@rmdir($this->tmpDir);
		parent::tearDown();
	}

	public function testConstruct_buildsSessionFromConfig_andRestoresCookie():void {
		$idString = "abc123sessionid";
		$providedCookie = [
			"GT" => $idString,
		];

		$sessionSetup = self::createMock(SessionSetup::class);
		$sessionSetup->expects(self::once())
			->method("attachHandler");
		$sessionClass = self::createMock(Session::class);
		$sessionClass->expects(self::once())
			->method("getId")
			->willReturn($idString);

		$sut = new SessionInit(
			name: "GT",
			handler: FileHandler::class,
			savePath: $this->tmpDir,
			useTransSid: false,
			useCookies: true,
			currentCookieArray: $providedCookie,
			sessionSetup: $sessionSetup,
			sessionClass: $sessionClass,
		);

		$session = $sut->getSession();
		self::assertSame($idString, $session->getId());
	}

	public function testConstruct_passesCookieOptionsToSession():void {
		CapturingSession::$capturedConfig = [];

		$sut = new SessionInit(
			name: "GT",
			handler: FileHandler::class,
			savePath: $this->tmpDir,
			useTransSid: false,
			useCookies: true,
			cookieSecure: false,
			cookieSameSite: "Strict",
			currentCookieArray: [],
			sessionClass: CapturingSession::class,
		);

		self::assertInstanceOf(CapturingSession::class, $sut->getSession());
		self::assertFalse(CapturingSession::$capturedConfig["cookie_secure"]);
		self::assertSame("Strict", CapturingSession::$capturedConfig["cookie_samesite"]);
	}
}

class CapturingSession extends Session {
	/** @var array<string, mixed> */
	public static array $capturedConfig = [];

	/** @param array<string, mixed> $config */
	public function __construct(
		SessionHandlerInterface $sessionHandler,
		iterable $config = [],
		?string $id = null,
	) {
		self::$capturedConfig = is_array($config) ? $config : iterator_to_array($config);
	}
}
