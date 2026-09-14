<?php
namespace Sentry;

function init(array $options):void {
	$GLOBALS["sentry_options"] = $options;
	if($GLOBALS["sentry_init_fails"] ?? false) {
		throw new \RuntimeException("initialization failed");
	}
}

function captureException(\Throwable $throwable):void {
	if($GLOBALS["sentry_capture_fails"] ?? false) {
		throw new \RuntimeException("transport failed");
	}
	$GLOBALS["sentry_events"][] = $throwable;
}
