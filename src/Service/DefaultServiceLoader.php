<?php
namespace GT\WebEngine\Service;

use Gt\Config\Config;
use Gt\DomTemplate\HTMLAttributeBinder;
use Gt\Database\Connection\DefaultSettings;
use Gt\Database\Connection\Settings;
use Gt\Database\Database;
use Gt\Dom\Document;
use Gt\DomTemplate\BindableCache;
use Gt\DomTemplate\Binder;
use Gt\DomTemplate\DocumentBinder;
use Gt\DomTemplate\ElementBinder;
use Gt\DomTemplate\HTMLAttributeCollection;
use Gt\DomTemplate\ListBinder;
use Gt\DomTemplate\ListElementCollection;
use Gt\DomTemplate\PlaceholderBinder;
use Gt\DomTemplate\TableBinder;
use Gt\Http\Header\ResponseHeaders;
use Gt\Http\Request;
use Gt\Http\Response;
use Gt\Http\Uri;
use Gt\ServiceContainer\Container;

class DefaultServiceLoader {
	protected string $uniqid;

	public function __construct(
		protected Config $config,
		protected Container $container
	) {
		$this->uniqid = uniqid(more_entropy: true);
	}

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

	public function loadBinder():Binder {
		$document = $this->container->get(Document::class);
		return new DocumentBinder($document);
	}

	public function loadRequestUri():Uri {
		return $this->container->get(Request::class)->getUri();
	}
}
