<?php
namespace GT\WebEngine\Test\Debug;

use GT\Config\Config;
use GT\Http\ResponseStatusException\ClientError\HttpNotFound;
use GT\WebEngine\Debug\SentryReporter;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class SentryReporterTest extends TestCase {
	public function testMissingSdkIsSafe():void {
		self::assertFalse(function_exists("Sentry\\init"));
		(new SentryReporter($this->config()))->report(new RuntimeException("test"));
	}

	public function testMissingDsnDoesNotInitialiseSdk():void {
		require __DIR__ . "/../Fixture/sentry-functions.php";
		(new SentryReporter($this->config("")))->report(new RuntimeException("test"));
		self::assertArrayNotHasKey("sentry_options", $GLOBALS);
		self::assertArrayNotHasKey("sentry_events", $GLOBALS);
	}

	public function testReportsOriginalThrowableOnceAndSkipsClientErrors():void {
		require __DIR__ . "/../Fixture/sentry-functions.php";
		$reporter = new SentryReporter($this->config());
		$error = new RuntimeException("Test exception");
		$reporter->report($error);
		$reporter->report($error);
		$reporter->report(new HttpNotFound());
		self::assertSame(["dsn" => "https://key@example.com/1"], $GLOBALS["sentry_options"]);
		self::assertSame([$error], $GLOBALS["sentry_events"]);
	}

	public function testInitialisationFailureDoesNotEscape():void {
		require __DIR__ . "/../Fixture/sentry-functions.php";
		$GLOBALS["sentry_init_fails"] = true;
		(new SentryReporter($this->config()))->report(new RuntimeException("test"));
		self::assertArrayNotHasKey("sentry_events", $GLOBALS);
	}

	public function testCaptureFailureDoesNotEscape():void {
		require __DIR__ . "/../Fixture/sentry-functions.php";
		$GLOBALS["sentry_capture_fails"] = true;
		(new SentryReporter($this->config()))->report(new RuntimeException("test"));
		self::assertArrayNotHasKey("sentry_events", $GLOBALS);
	}

	private function config(string $dsn = "https://key@example.com/1"):Config {
		$config = self::createStub(Config::class);
		$config->method("getString")->willReturnCallback(
			fn(string $key):?string => $key === "sentry.dsn" ? $dsn : null,
		);
		return $config;
	}
}
