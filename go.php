<?php
/**
 * Welcome to the PHP.Gt WebEngine!
 *
 * This file is the entry point to the WebEngine. The whole request-response
 * lifecycle is documented at:
 * https://github.com/PhpGt/WebEngine/wiki/From-request-to-response
 */
chdir(dirname($_SERVER["DOCUMENT_ROOT"]));
ini_set("display_errors", "on");
ini_set("html_errors", "false");
/**
 * Before any code is executed, return false here if a static file is requested.
 * When running the PHP inbuilt server, this will output the static file.
 * Other webservers should not get to this point, but it's safe to prevent
 * unnecessary execution.
 */
$uri = urldecode(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH));
if(strstr($uri, ".")
&& is_file($_SERVER["DOCUMENT_ROOT"] . $uri)) {
	return false;
}

/**
 * Require the Composer autoloader, so the rest of the script can locate
 * classes by their namespace, rather than having to know where on disk the
 * files exist.
 * @link https://getcomposer.org/doc/00-intro.md
 */
foreach([dirname($_SERVER["DOCUMENT_ROOT"]), __DIR__] as $dir) {
	$autoloadPath = "$dir/vendor/autoload.php";
	if(file_exists($autoloadPath)) {
		/** @noinspection PhpIncludeInspection */
		require $autoloadPath;
		break;
	}
}

/**
 * That's all we need to start the request-response lifecycle.
 * Buckle up and enjoy the ride!
 * @link https://github.com/PhpGt/WebEngine/wiki/From-request-to-response
 */
if(file_exists("init.php")) {
	require("init.php");
}
$lifecycle = new Gt\WebEngine\Middleware\Lifecycle();
try {
	$lifecycle->start();
}
catch(Exception $e) {
	if(function_exists("exception_handler")) {
		call_user_func("exception_handler", $e);
	}
	else {
		throw $e;
	}
}
