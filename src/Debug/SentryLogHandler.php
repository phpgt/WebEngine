<?php
namespace GT\WebEngine\Debug;

use GT\Logger\LogHandler\LogHandler;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\Logs\Log;
use Sentry\Logs\LogLevel;
use Sentry\Tracing\TraceId;
use Throwable;

/** Bounded structured logging without the SDK's global hub. */
class SentryLogHandler extends LogHandler {
	private const BATCH_SIZE = 100;
	/** @var array<array{level: string, message: string, timestamp: float}> */
	private array $pending = [];
	private ?ClientInterface $client = null;
	private ?string $traceId = null;

	public function setClient(ClientInterface $client):void {
		$this->client = $client;
	}

	/** @param array<string, mixed> $context */
	public function handle(string $level, string $message, array $context = []):void {
		// Context is deliberately excluded: arbitrary values can contain secrets.
		$this->pending []= [
			"level" => strtoupper($level),
			"message" => substr($message, 0, 8192),
			"timestamp" => microtime(true),
		];
		if(count($this->pending) >= self::BATCH_SIZE) {
			$this->flush();
		}
	}

	public function flush():void {
		if(!$this->pending) {
			return;
		}
		$pending = $this->pending;
		$this->pending = [];
		try {
			if(!$this->client) {
				$this->fallback($pending);
				return;
			}
			$this->traceId ??= (string)TraceId::generate();
			$logs = [];
			foreach($pending as $entry) {
				$logs []= (new Log($entry["timestamp"], $this->traceId, $this->mapLevel($entry["level"]), $entry["message"]))
					->setAttribute("sentry.environment", $this->client->getOptions()->getEnvironment() ?? Event::DEFAULT_ENVIRONMENT)
					->setAttribute("logger.level", $entry["level"]);
			}
			if($this->client->captureEvent(Event::createLogs()->setLogs($logs)) === null) {
				$this->fallback($pending);
			}
		}
		catch(Throwable) {
			$this->fallback($pending);
		}
	}

	private function mapLevel(string $level):LogLevel {
		return match($level) {
			"DEBUG" => LogLevel::debug(),
			"WARNING" => LogLevel::warn(),
			"ERROR" => LogLevel::error(),
			"CRITICAL", "ALERT", "EMERGENCY" => LogLevel::fatal(),
			default => LogLevel::info(),
		};
	}

	/** @param array<array{level: string, message: string, timestamp: float}> $entries */
	private function fallback(array $entries):void {
		foreach($entries as $entry) {
			error_log("WebEngine: Sentry log delivery unavailable: {$entry['level']} {$entry['message']}");
		}
	}

	/** @param array<string, mixed> $context */
	protected function unwrapContext(array $context):string {
		return "";
	}
}
