<?php
namespace GT\WebEngine\Test\Fixture;

use Gt\Logger\LogHandler\LogHandler;

class TestLogHandler extends LogHandler {
	/** @var array<int, array{level:string,message:string,context:array<string, mixed>}> */
	public static array $records = [];

	public function handle(
		string $level,
		string $message,
		array $context = []
	):void {
		self::$records[] = [
			"level" => $level,
			"message" => $message,
			"context" => $context,
		];
	}

	protected function unwrapContext(array $context):string {
		return json_encode($context) ?: "";
	}
}
