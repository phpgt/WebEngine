<?php
namespace GT\WebEngine\Test\Debug;

use GT\ProtectedGlobal\Protection;
use GT\WebEngine\Debug\SentryLogHandler;
use PHPUnit\Framework\TestCase;
use Sentry\ClientBuilder;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventId;
use Sentry\Options;
use Sentry\Serializer\PayloadSerializer;
use Sentry\Transport\TransportInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use RuntimeException;

class SentryLogHandlerTest extends TestCase {
	#[\PHPUnit\Framework\Attributes\DataProvider("logMessages")]
	public function testNullBytesAreEscapedBeforeMessagesAreBounded(string $message, string $expected):void {
		$client = self::createMock(ClientInterface::class);
		$client->method("getOptions")->willReturn(new Options());
		$client->expects(self::once())->method("captureEvent")->willReturnCallback(
			function(Event $event) use ($expected):EventId {
				self::assertSame($expected, $event->getLogs()[0]->getBody());
				self::assertStringNotContainsString("\0", $event->getLogs()[0]->getBody());
				return $event->getId();
			},
		);
		$handler = new SentryLogHandler();
		$handler->setClient($client);
		$handler->handle("ERROR", $message);
		$handler->flush();
	}

	/** @return array<string, array{string, string}> */
	public static function logMessages():array {
		$exception = new class("CSRF Token not found") extends RuntimeException {};
		$message = get_class($exception) . ": " . $exception->getMessage();
		return [
			"anonymous exception" => [$message, str_replace("\0", "\\0", $message)],
			"multiple null bytes" => ["\0before\0after\0", "\\0before\\0after\\0"],
			"ordinary message" => ["Ordinary message", "Ordinary message"],
			"literal escape" => ["Already escaped \\0", "Already escaped \\0"],
			"expanded message stays bounded" => [str_repeat("\0", 8192), str_repeat("\\0", 4096)],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("unavailableClients")]
	public function testUnavailableDeliveryFallsBackOnceWithoutContext(string $failure):void {
		$handler = new SentryLogHandler();
		if($failure !== "missing client") {
			$client = self::createMock(ClientInterface::class);
			$client->method("getOptions")->willReturn(new Options());
			$capture = $client->expects(self::once())->method("captureEvent");
			if($failure === "exception") {
				$capture->willThrowException(new RuntimeException("offline"));
			}
			else {
				$capture->willReturn(null);
			}
			$handler->setClient($client);
		}
		$logFile = tempnam(sys_get_temp_dir(), "sentry-fallback-");
		$originalLog = ini_set("error_log", $logFile);
		try {
			$handler->handle("error", "Fallback message", ["password" => "secret"]);
			$handler->flush();
			$handler->flush();
			$log = file_get_contents($logFile);
			self::assertSame(1, substr_count($log, "WebEngine: Sentry log delivery unavailable: ERROR Fallback message"));
			self::assertStringNotContainsString("secret", $log);
		}
		finally {
			ini_set("error_log", $originalLog);
			unlink($logFile);
		}
	}

	/** @return array<string, array{string}> */
	public static function unavailableClients():array {
		return [
			"missing client" => ["missing client"],
			"rejected event" => ["rejected event"],
			"exception" => ["exception"],
		];
	}

	public function testRealSdkSendsStructuredLogsWithProtectedGlobals():void {
		$payload = "";
		$options = new Options([
			"dsn" => "https://key@example.com/1",
			"environment" => "staging",
			"default_integrations" => false,
		]);
		$transport = self::createMock(TransportInterface::class);
		$transport->expects(self::once())->method("send")->willReturnCallback(
			function(Event $event) use (&$payload, $options):Result {
				$payload = (new PayloadSerializer($options))->serialize($event);
				return new Result(ResultStatus::success(), $event);
			},
		);
		$client = (new ClientBuilder($options))->setTransport($transport)->getClient();
		$handler = new SentryLogHandler();
		$handler->setClient($client);
		$original = [];
		foreach(Protection::GLOBAL_KEYS as $key) {
			$original[$key] = $GLOBALS[$key] ?? null;
		}
		try {
			(new Protection())->overrideInternals([]);
			foreach(["DEBUG", "INFO", "NOTICE", "WARNING", "ERROR", "CRITICAL", "ALERT", "EMERGENCY"] as $level) {
				$handler->handle($level, "Message {$level}", ["password" => "secret"]);
			}
			$handler->flush();
			$handler->flush();
		}
		finally {
			foreach($original as $key => $value) {
				if($value === null) {
					unset($GLOBALS[$key]);
				}
				else {
					$GLOBALS[$key] = $value;
				}
			}
		}
		$lines = explode("\n", trim($payload));
		$header = json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR);
		$body = json_decode($lines[2], true, flags: JSON_THROW_ON_ERROR);
		self::assertSame("log", $header["type"]);
		self::assertSame(8, $header["item_count"]);
		self::assertSame(["debug", "info", "info", "warn", "error", "fatal", "fatal", "fatal"], array_column($body["items"], "level"));
		self::assertSame("staging", $body["items"][0]["attributes"]["sentry.environment"]["value"]);
		self::assertStringNotContainsString("secret", $payload);
	}

	public function testBatchesAreBoundedAndEarlyLogsAreRetained():void {
		$client = self::createMock(ClientInterface::class);
		$client->method("getOptions")->willReturn(new Options(["environment" => null]));
		$counts = [];
		$client->expects(self::exactly(2))->method("captureEvent")->willReturnCallback(
			function(Event $event) use (&$counts):EventId {
				$counts []= count($event->getLogs());
				self::assertSame("production", $event->getLogs()[0]->attributes()->toSimpleArray()["sentry.environment"]);
				return $event->getId();
			},
		);
		$handler = new SentryLogHandler();
		$handler->handle("ERROR", "Before initialization");
		$handler->setClient($client);
		for($i = 0; $i < 100; $i++) {
			$handler->handle("ERROR", "After initialization");
		}
		$handler->flush();
		self::assertSame([100, 1], $counts);
	}

	public function testDeliveryFailureDoesNotEscapeOrRetry():void {
		$client = self::createMock(ClientInterface::class);
		$client->method("getOptions")->willReturn(new Options());
		$client->expects(self::once())->method("captureEvent")->willThrowException(new RuntimeException("offline"));
		$handler = new SentryLogHandler();
		$handler->setClient($client);
		$handler->handle("ERROR", "Test delivery failure");
		$handler->flush();
		$handler->flush();
	}
}
