<?php
namespace GT\WebEngine\Logic;

use Closure;
use Gt\Routing\LogicStream\LogicStreamWrapper;

class LogicStreamHandler {
	private Closure $streamWrapperRegisterCallback;

	public function __construct(
		private readonly string $streamName = LogicStreamWrapper::STREAM_NAME,
		private readonly string $logicStreamClassName = LogicStreamWrapper::class,
		?Closure $streamWrapperRegisterCallback = null,
	) {
		$this->streamWrapperRegisterCallback = $streamWrapperRegisterCallback ??
			fn() => stream_wrapper_register($this->streamName, $this->logicStreamClassName);
	}

	public function setup():void {
		($this->streamWrapperRegisterCallback)(
			$this->streamName,
			$this->logicStreamClassName,
		);
	}
}
