<?php /** @noinspection PhpUnused */
namespace GT\WebEngine\Middleware;

use GT\Config\Config;
use GT\Dom\HTMLDocument;
use GT\DomTemplate\HTMLAttributeBinder;
use GT\Database\Connection\DefaultSettings;
use GT\Database\Connection\Settings;
use GT\Database\Database;
use GT\Dom\Document;
use GT\DomTemplate\BindableCache;
use GT\DomTemplate\Binder;
use GT\DomTemplate\DocumentBinder;
use GT\DomTemplate\ElementBinder;
use GT\DomTemplate\HTMLAttributeCollection;
use GT\DomTemplate\ListBinder;
use GT\DomTemplate\ListElementCollection;
use GT\DomTemplate\PlaceholderBinder;
use GT\DomTemplate\TableBinder;
use GT\Http\Header\ResponseHeaders;
use GT\Http\Request;
use GT\Http\Response;
use GT\Http\Uri;
use GT\ServiceContainer\Container;

/**
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * Reason: This loader is the central place that defines and provides core
 * framework services to the container. It references many service classes by
 * design, but each is created lazily via dedicated factory methods, keeping
 * responsibilities focused and testable.
 */
class DefaultServiceLoader {
	public function __construct(
		protected Config $config,
		protected Container $container
	) {}

	public function loadResponseHeaders():ResponseHeaders {
		$response = $this->container->get(Response::class);
		return $response->headers;
	}

	public function loadDatabase():Database {
		$dbSettings = new Settings(
			$this->config->get("database.query_directory"),
			$this->config->get("database.driver"),
			$this->config->get("database.schema"),
			$this->config->get("database.host"),
			$this->config->get("database.port"),
			$this->config->get("database.username"),
			$this->config->get("database.password"),
			$this->config->get("database.connection_name") ?: DefaultSettings::DEFAULT_NAME,
			$this->config->get("database.collation") ?: DefaultSettings::DEFAULT_COLLATION,
			$this->config->get("database.charset") ?: DefaultSettings::DEFAULT_CHARSET,
		);
		return new Database($dbSettings);
	}

	public function loadBindableCache():BindableCache {
		return new BindableCache();
	}

	public function loadHTMLAttributeBinder():HTMLAttributeBinder {
		return new HTMLAttributeBinder();
	}

	public function loadHTMLAttributeCollection():HTMLAttributeCollection {
		return new HTMLAttributeCollection();
	}

	public function loadPlaceholderBinder():PlaceholderBinder {
		return new PlaceholderBinder();
	}

	public function loadElementBinder():ElementBinder {
		return new ElementBinder();
	}

	public function loadTableBinder():TableBinder {
		return new TableBinder();
	}

	public function loadListElementCollection():ListElementCollection {
		return new ListElementCollection(
			$this->container->get(Document::class),
		);
	}

	public function loadListBinder():ListBinder {
		return new ListBinder();
	}

	public function loadBinder():DocumentBinder {
		$document = $this->container->get(Document::class);
		return new DocumentBinder($document);
	}

	public function loadRequestUri():Uri {
		return $this->container->get(Request::class)->getUri();
	}
}
