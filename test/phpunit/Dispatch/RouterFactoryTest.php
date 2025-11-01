<?php
namespace GT\WebEngine\Test\Dispatch;

use GT\WebEngine\Dispatch\RouterFactory;
use Gt\Routing\BaseRouter;
use Gt\Routing\RouterConfig;
use Gt\ServiceContainer\Container;
use PHPUnit\Framework\TestCase;

class RouterFactoryTest extends TestCase {
	private string $tmpDir;

	protected function setUp():void {
		parent::setUp();
		$this->tmpDir = sys_get_temp_dir() . "/phpgt-webengine-test--Dispatch-RouterFactory-" . uniqid();
		if(!is_dir($this->tmpDir)) {
			mkdir($this->tmpDir, recursive: true);
		}
	}

	protected function tearDown():void {
		foreach(scandir($this->tmpDir) ?: [] as $f) {
			if($f === '.' || $f === '..') { continue; }
			@unlink($this->tmpDir . DIRECTORY_SEPARATOR . $f);
		}
		@rmdir($this->tmpDir);
		parent::tearDown();
	}

	private function writeRouterFile(string $namespace, string $className, string $filePath):void {
		$template = <<<'PHP'
		<?php
		namespace %NAMESPACE%;
		use Gt\Routing\BaseRouter;
		use Gt\Routing\RouterConfig;
		use Gt\ServiceContainer\Container;
		class %CLASS% extends BaseRouter {
			public ?RouterConfig $receivedConfig = null;
			public ?Container $receivedContainer = null;
			public function __construct(RouterConfig $config, ?int $errorStatus) {
				parent::__construct($config, errorStatus: $errorStatus);
				$this->receivedConfig = $config;
			}
			public function setContainer(Container $container):void {
				parent::setContainer($container);
				$this->receivedContainer = $container;
			}
		}
		PHP;
		$code = strtr($template, [
			'%NAMESPACE%' => $namespace,
			'%CLASS%' => $className,
		]);
		file_put_contents($filePath, $code);
	}

	/** @noinspection PhpUndefinedFieldInspection */
	public function testCreate_usesAppRouterWhenFileExists():void {
		$appNs = 'MyApp\\Routing';
		$appClass = 'AppRouter';
		$appFile = $this->tmpDir . '/AppRouter.php';
		$this->writeRouterFile($appNs, $appClass, $appFile);

		$defaultNs = 'Default\\Routing';
		$defaultClass = 'DefaultRouter';
		$defaultFile = $this->tmpDir . '/DefaultRouter.php';
		$this->writeRouterFile($defaultNs, $defaultClass, $defaultFile);

		$factory = new RouterFactory();
		$container = new Container();
		$router = $factory->create(
			$container,
			$appNs,
			$appFile,
			$appClass,
			$defaultFile,
			"\\$defaultNs\\$defaultClass",
			303,
			'text/html'
		);

		self::assertInstanceOf(BaseRouter::class, $router);
		self::assertSame("$appNs\\$appClass", ltrim($router::class, '\\'));
		self::assertSame(303, $router->receivedConfig->redirectResponseCode);
		self::assertSame('text/html', $router->receivedConfig->defaultContentType);
		self::assertSame($container, $router->receivedContainer);
	}

	/** @noinspection PhpUndefinedFieldInspection */
	public function testCreate_fallsBackToDefaultWhenAppFileMissing():void {
		$appNs = 'MyMissing\\Routing';
		$appClass = 'MissingRouter';
		$appFile = $this->tmpDir . '/MissingRouter.php'; // not created

		$defaultNs = 'Default\\Routing';
		$defaultClass = 'DefaultRouter';
		$defaultFile = $this->tmpDir . '/DefaultRouter.php';
		$this->writeRouterFile($defaultNs, $defaultClass, $defaultFile);

		$factory = new RouterFactory();
		$container = new Container();
		$router = $factory->create(
			$container,
			$appNs,
			$appFile,
			$appClass,
			$defaultFile,
			"\\$defaultNs\\$defaultClass",
			308,
			'application/json'
		);

		self::assertSame("$defaultNs\\$defaultClass", ltrim($router::class, '\\'));
		self::assertSame(308, $router->receivedConfig->redirectResponseCode);
		self::assertSame('application/json', $router->receivedConfig->defaultContentType);
		self::assertSame($container, $router->receivedContainer);
	}
}
