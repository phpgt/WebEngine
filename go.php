<?php
/**
 * Welcome to the PHP.GT WebEngine!
 *
 * This file is the entry point to the WebEngine. Sometimes this is referred to
 * as the "bootstrap" file. Read more about the whole request-response
 * lifecycle in the documentation:
 * https://github.com/PhpGt/WebEngine/wiki/From-request-to-response
 */
use Gt\WebEngine\Application;

chdir(dirname($_SERVER["DOCUMENT_ROOT"]));
ini_set("display_errors", "on");
ini_set("html_errors", "false");
/**
 * Step 1 - Static files:
 * Before any code is executed, return false here if a static file is requested.
 * When running the PHP inbuilt server, this will output the static file.
 * Other webservers should not get to this point, but it's safe to prevent
 * unnecessary execution.
 */
$uri = urldecode(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH));
if(is_file($_SERVER["DOCUMENT_ROOT"] . $uri)) {
	return false;
}

/**
 * Step 2 - Composer:
 * Require the Composer autoloader, so the rest of the script can locate
 * classes by their namespace, rather than having to know where on disk the
 * files exist.
 * @link https://getcomposer.org/doc/00-intro.md
 */
foreach([dirname($_SERVER["DOCUMENT_ROOT"]), __DIR__] as $dir) {
	$autoloadPath = "$dir/vendor/autoload.php";
	if(file_exists($autoloadPath)) {
		require $autoloadPath;
		break;
	}
}

/**
 * Step 3 - Go!
 * That's all we need to start the request-response lifecycle.
 * Buckle up and enjoy the ride!
 * @link https://github.com/PhpGt/WebEngine/wiki/From-request-to-response
 */
if(file_exists("init.php")) {
	require("init.php");
}

$app = new Application();
$app->start();