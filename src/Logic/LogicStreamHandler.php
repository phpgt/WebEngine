<?php
namespace GT\WebEngine\Logic;

use Closure;

/**
 * Handles the registration of a custom stream wrapper, as defined by the
 * GT\Routing\LogicStreamWrapper. This functionality allows you to create
 * a classless PHP script with a single go() function.
 */
class LogicStreamHandler {
	private Closure $streamWrapperRegisterCallback;

	public function __construct(
		private readonly string $streamName = WebEngineLogicStreamWrapper::STREAM_NAME,
		private readonly string $logicStreamClassName = WebEngineLogicStreamWrapper::class,
		?Closure $streamWrapperRegisterCallback = null,
	) {
		$this->streamWrapperRegisterCallback = $streamWrapperRegisterCallback ??
			fn() => stream_wrapper_register($this->streamName, $this->logicStreamClassName);
	}

	private function isProtocolDefined(string $protocol):bool {
		return in_array($protocol, stream_get_wrappers(), true);
	}

	public function setup():void {
		if($this->isProtocolDefined($this->streamName)) {
			return;
		}

		($this->streamWrapperRegisterCallback)(
			$this->streamName,
			$this->logicStreamClassName,
		);
	}
}
