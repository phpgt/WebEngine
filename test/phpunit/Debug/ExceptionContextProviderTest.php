<?php
namespace GT\WebEngine\Test\Debug;

use Error;
use Exception;
use GT\ServiceContainer\ServiceNotFoundException;
use GT\WebEngine\Debug\ExceptionContextProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

class ExceptionContextProviderTest extends TestCase {
	#[DataProvider("missingImports")]
	public function testMissingImportProvidesGuidance(Throwable $throwable):void {
		$context = (new ExceptionContextProvider())->getContext($throwable);

		self::assertNotNull($context);
		self::assertStringContainsString("AnotherThing could not be found", $context->message);
		self::assertStringContainsString("usually", $context->message);
		self::assertStringContainsString("use statement", $context->suggestion);
		self::assertStringNotContainsString("use App\\AnotherThing", $context->suggestion);
		self::assertSame(
			"https://www.php.gt/docs/WebEngine/Class-imports-in-page-logic/",
			$context->documentationUrl,
		);
	}

	public static function missingImports():iterable {
		yield "index parameter" => [new ServiceNotFoundException('GT\\AppLogic\\page\\index_php\\AnotherThing')];
		yield "shared logic parameter" => [new ServiceNotFoundException('Gt\\AppLogic\\page\\_common_php\\AnotherThing')];
		yield "nested page class" => [new Error('Class "GT\\AppLogic\\page\\account\\edit_php\\AnotherThing" not found')];
		yield "relative qualified class" => [new Error('Class "GT\\AppLogic\\page\\index_php\\Example\\AnotherThing" not found')];
		yield "shared logic class" => [new Error('Class "GT\\AppLogic\\page\\_common_php\\AnotherThing" not found')];
	}

	#[DataProvider("unrelatedErrors")]
	public function testUnrelatedErrorsHaveNoContext(Throwable $throwable):void {
		self::assertNull((new ExceptionContextProvider())->getContext($throwable));
	}

	public static function unrelatedErrors():iterable {
		yield "registered service missing" => [new ServiceNotFoundException('App\\AnotherThing')];
		yield "other namespace" => [new Error('Class "App\\AnotherThing" not found')];
		yield "similar namespace" => [new Error('Class "GT\\AppLogicExtra\\page\\index_php\\AnotherThing" not found')];
		yield "no logic file" => [new ServiceNotFoundException('GT\\AppLogic\\AnotherThing')];
		yield "unrelated error" => [new Error('Undefined constant "GT\\AppLogic\\page\\index_php\\AnotherThing"')];
		yield "unrelated exception" => [new Exception('Class "GT\\AppLogic\\page\\index_php\\AnotherThing" not found')];
	}
}
