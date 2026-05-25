<?php
/**
 * Run this script from the root directory of your WebEngine project.
 * e.g. php vendor/phpgt/webengine/setup.php
 *
 * The purpose of this script is to make sure that the project is set up
 * correctly, if it has been installed manually, rather than via the composer
 * create-project command.
 */
$error = null;
if(!is_dir("vendor")) {
	if(!is_file("composer.json")) {
		$error = "The current directory is not a WebEngine project.";
	}
	$error = "No vendor directory found - do you need to run `composer install`?";
}
if(is_file("build.default.ini") || is_file("build.default.json")) {
	$error = "Please run this script from your project's root directory - not WebEngine's directory.";
}
if($error) {
	echo "$error See https://www.php.gt/webengine/setup for more information.", PHP_EOL;
	exit(1);
}
$indexPhpContents = <<<PHP
<?php
require(__DIR__ . "/../vendor/phpgt/webengine/go.php");
PHP;

if(!is_dir("www")) {
	mkdir("www");
}
file_put_contents("www/index.php", $indexPhpContents);
