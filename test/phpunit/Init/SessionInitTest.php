<?php
namespace GT\WebEngine\Test\Init;

use Gt\Session\FileHandler;
use Gt\Session\Session;
use Gt\Session\SessionSetup;
use GT\WebEngine\Init\SessionInit;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
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
}
