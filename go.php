<?php
/**
 * Welcome to the PHP.GT WebEngine!
 *
 * This file is the entry point to the WebEngine. Sometimes this is referred to
 * as the "bootstrap" file. Read more about the whole request-response
 * lifecycle in the documentation:
 * https://github.com/PhpGt/WebEngine/wiki/From-request-to-response
 */
use Gt\Config\ConfigFactory;
use Gt\Logger\Log;
use Gt\Logger\LogConfig;
use Gt\Logger\LogLevel;
use GT\WebEngine\Application;

$projectRoot = dirname($_SERVER["DOCUMENT_ROOT"]);
chdir($projectRoot);
ini_set("display_errors", "on");
ini_set("html_errors", "false");

/**
 * Step 1 - Composer:
 * Require the Composer autoloader before doing anything else, so config and
 * logging are available even for static-file requests.
 * @link https://getcomposer.org/doc/00-intro.md
 */
$vendorDirectoryList = [
	$projectRoot,
	__DIR__,
];
foreach($vendorDirectoryList as $dir) {
	$autoloadPath = "$dir/vendor/autoload.php";
	if(file_exists($autoloadPath)) {
		require $autoloadPath;
		break;
	}
}

$configFactory = new ConfigFactory();
$config = $configFactory->createForProject(
	$projectRoot,
	__DIR__ . "/config.default.ini"
);
$minimumLogLevel = strtoupper($config->getString("logger.level") ?: LogLevel::DEBUG);
if(!in_array($minimumLogLevel, LogLevel::ALL_LEVELS, true)) {
	$minimumLogLevel = LogLevel::DEBUG;
}
LogConfig::setDefaultHandlerLevel($minimumLogLevel);

/**
 * Step 2 - Static files:
 * Before any code is executed, return false here if a static file is requested.
 * When running the PHP inbuilt server, this will output the static file.
 * Other webservers should not get to this point, but it's safe to prevent
 * unnecessary execution.
 */
$uri = urldecode(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH));
if(is_file($_SERVER["DOCUMENT_ROOT"] . $uri)) {
	if($config->getBool("logger.log_static_requests")) {
		Log::info("HTTP 200", [
			"uri" => $uri,
		]);
	}
	return false;
}

/**
 * Step 3 - setup:
 * An optional setup.php file can be included in the project root, which will
 * simply be executed directly here. This is useful for configuring the PHP
 * environment across the project, but shouldn't be necessary for most projects.
 */
// TODO: Investigate why requiring the composer autoloader emits a newline character, so we don't have to clear the output buffer in the go script.
ob_clean();
if(file_exists("setup.php")) {
	require("setup.php");
}

/**
 * Step 4 - Go!
 * That's all we need to start the request-response lifecycle.
 * Buckle up and enjoy the ride!
 * @link https://github.com/PhpGt/WebEngine/wiki/From-request-to-response
 */
$app = new Application($config);
$app->start();

if(file_exists("teardown.php")) {
	require("teardown.php");
}
