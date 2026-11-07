<?php
namespace GT\WebEngine\Test\Integration;

use GT\WebEngine\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase {
	#[DataProvider("systemTimezones")]
	public function testLoadConfig_resolvesSystemTimezone(string $key, string $systemTimezone):void {
		$originalTimezone = date_default_timezone_get();
		$originalDirectory = getcwd();
		$directory = sys_get_temp_dir() . "/webengine-timezone-" . uniqid();
		$vendorDirectory = "$directory/vendor/phpgt/webengine";
		mkdir($vendorDirectory, recursive: true);
		symlink(dirname(__DIR__, 2) . "/vendor/composer", "$directory/vendor/composer");
		copy(dirname(__DIR__, 2) . "/config.default.ini", "$vendorDirectory/config.default.ini");

		try {
			date_default_timezone_set($systemTimezone);
			chdir($directory);
			$sut = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
			$config = (new \ReflectionMethod(Application::class, "loadConfig"))->invoke($sut);
			self::assertSame($systemTimezone, $config->getString($key));
		}
		finally {
			chdir($originalDirectory);
			date_default_timezone_set($originalTimezone);
			unlink("$vendorDirectory/config.default.ini");
			rmdir($vendorDirectory);
			rmdir("$directory/vendor/phpgt");
			unlink("$directory/vendor/composer");
			rmdir("$directory/vendor");
			rmdir($directory);
		}
	}

	/** @return array<string, array{string, string}> */
	public static function systemTimezones():array {
		return [
			"application in London" => ["app.timezone", "Europe/London"],
			"application in Tokyo" => ["app.timezone", "Asia/Tokyo"],
			"database in London" => ["database.timezone", "Europe/London"],
			"database in Tokyo" => ["database.timezone", "Asia/Tokyo"],
		];
	}
}
