<?php
namespace GT\WebEngine\Test\Debug;

use GT\Config\Config;
use GT\Http\ResponseStatusException\ClientError\HttpNotFound;
use Gt\ProtectedGlobal\Protection;
use GT\WebEngine\Debug\SentryReporter;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use ReflectionProperty;
use RuntimeException;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\Transport\TransportInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;

class SentryReporterTest extends TestCase {
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testMissingSdkIsSafe():void {
		$config = $this->config();
		$request = new ServerRequest("GET", "/");
		class_exists(SentryReporter::class);
		$autoloaders = spl_autoload_functions();
		foreach($autoloaders as $autoloader) {
			spl_autoload_unregister($autoloader);
		}
		$withoutSentry = static function(string $class) use ($autoloaders):void {
			if(str_starts_with($class, "Sentry\\")) {
				return;
			}
			foreach($autoloaders as $autoloader) {
				$autoloader($class);
			}
		};
		spl_autoload_register($withoutSentry);
		try {
			$hasSdk = class_exists(\Sentry\ClientBuilder::class);
			$reporter = SentryReporter::create($config, $request);
		}
		finally {
			spl_autoload_unregister($withoutSentry);
			foreach($autoloaders as $autoloader) {
				spl_autoload_register($autoloader);
			}
		}
		self::assertFalse($hasSdk);
		self::assertNull($reporter);
	}

	public function testMissingDsnDoesNotInitialiseSdk():void {
		self::assertNull(SentryReporter::create($this->config(""), new ServerRequest("GET", "/")));
	}

	public function testReportsOriginalThrowableOnceAndSkipsClientErrors():void {
		$error = new RuntimeException("Test exception");
		$client = self::createMock(ClientInterface::class);
		$client->expects(self::once())->method("captureException")->with($error);
		$reporter = new SentryReporter($client, new ServerRequest("GET", "/"));
		$reporter->report($error);
		$reporter->report($error);
		$reporter->report(new HttpNotFound());
	}

	public function testInitialisationFailureDoesNotEscape():void {
		self::assertNull(SentryReporter::create($this->config("invalid"), new ServerRequest("GET", "/")));
	}

	public function testCaptureFailureDoesNotEscape():void {
		$client = self::createMock(ClientInterface::class);
		$client->expects(self::once())->method("captureException")->willThrowException(new RuntimeException("offline"));
		(new SentryReporter($client, new ServerRequest("GET", "/")))->report(new RuntimeException("test"));
	}

	public function testRealSdkReportsWithProtectedGlobalsAndSanitizedRequest():void {
		$request = new ServerRequest("POST", "https://user:secret@example.com/error?token=secret#fragment", [
			"Authorization" => "Bearer secret",
			"Cookie" => "session=secret",
		], "password=secret");
		$reporter = SentryReporter::create($this->config(), $request);
		self::assertNotNull($reporter);
		$event = null;
		$transport = self::createMock(TransportInterface::class);
		$transport->expects(self::once())->method("send")->willReturnCallback(
			function(Event $captured) use (&$event):Result {
				$event = $captured;
				return new Result(ResultStatus::success(), $captured);
			},
		);
		$client = (new ReflectionProperty(SentryReporter::class, "client"))->getValue($reporter);
		(new ReflectionProperty($client, "transport"))->setValue($client, $transport);
		$originalGlobals = [];
		foreach(Protection::GLOBAL_KEYS as $key) {
			$originalGlobals[$key] = $GLOBALS[$key] ?? null;
		}
		try {
			(new Protection())->overrideInternals([]);
			$reporter->report(new RuntimeException("Protected globals exception"));
		}
		finally {
			foreach($originalGlobals as $key => $value) {
				if($value === null) {
					unset($GLOBALS[$key]);
				}
				else {
					$GLOBALS[$key] = $value;
				}
			}
		}
		self::assertInstanceOf(Event::class, $event);
		self::assertSame(["url" => "https://example.com/error", "method" => "POST"], $event->getRequest());
		self::assertSame("Protected globals exception", $event->getExceptions()[0]->getValue());
	}

	private function config(string $dsn = "https://key@example.com/1"):Config {
		$config = self::createStub(Config::class);
		$config->method("getString")->willReturnCallback(
			fn(string $key):?string => $key === "sentry.dsn" ? $dsn : null,
		);
		return $config;
	}
}
