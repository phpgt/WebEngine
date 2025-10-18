<?php
namespace Gt\WebEngine\Middleware;

use Gt\Config\ConfigFactory;
use Gt\Config\ConfigSection;
use Gt\Http\RequestFactory;
use Gt\Http\StatusCode;
use Gt\Logger\Log;
use Gt\Session\Session;
use Gt\WebEngine\Debug\Timer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * The fundamental purpose of any PHP framework is to provide a mechanism for
 * generating an HTTP response for an incoming HTTP request. Because this is
 * such a common requirement, the PHP Framework Interop Group have specified a
 * "PHP standards recommendation" (PSR) to help define the expected contract
 * between the components of a web framework. The PSR that defines the common
 * interfaces for HTTP server request handlers is PSR-15.
 *
 * @link https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-15-request-handlers.md
 *
 * This Lifecycle class implements the PSR-15 MiddlewareInterface, which defines
 * a single "process" function that takes a Request and a RequestHandler,
 * and in turn returns a Response.
 *
 * @link https://github.com/PhpGt/WebEngine/wiki/HTTP-Middleware
 *
 * At the start of the lifecycle, when using an ordinary HTTP server such as
 * Apache or Nginx, no Request objects are available yet:
 * that's the job of the "start" function. It will create an appropriate
 * Request object and pass it to the "process" function for handling.
 *
 * An optional execution would be to use a PHP-based HTTP server that provides
 * its own Request object, and pass it directly to the "process" function.
 */
class Lifecycle implements MiddlewareInterface {
	private Timer $timer;

	public function start():void {
// Before we start, we check if the current URI should be redirected. If it
// should, we won't go any further into the lifecycle.
//		$this->handleRedirects();

// The first thing that's done within the WebEngine lifecycle is start a timer.
// This timer is only used again at the end of the call, when finish() is
// called - at which point the entire duration of the request is logged out (and
// slow requests are highlighted as a NOTICE).
//		$this->timer = new Timer();

// Starting the output buffer is done before any logic is executed, so any calls
// to any area of code will not accidentally send output to the client.
//		ob_start();

//		$originalGlobals = [
//			"get" => $_GET,
//			"post" => $_POST,
//			"files" => $_FILES,
//			"server" => $_SERVER,
//		];
// A PSR-7 HTTP Request object is created from the current global state, ready
// for processing by the Handler.
//		$requestFactory = new RequestFactory();
//		$request = $requestFactory->createServerRequestFromGlobalState(
//			$originalGlobals["server"],
//			$originalGlobals["files"],
//			$originalGlobals["get"],
//			$originalGlobals["post"],
//		);

// The handler is an individual component that processes a request and produces
// a response, as defined by PSR-7. It's where all your application's logic is
// executed - the brain of WebEngine. Here we pass in a reference to the finish
// function, so the RequestHandler can complete the request early if needed.
//		$handler = new RequestHandler(
//			ConfigFactory::createForProject(
//				getcwd(),
//				"vendor/phpgt/webengine/config.default.ini"
//			),
//			$this->finish(...),
//			$originalGlobals["get"],
//			$originalGlobals["post"],
//			$originalGlobals["files"],
//			$originalGlobals["server"],
//		);

//		set_error_handler(function(int $errno, string $errstr, ?string $errFile = null, ?int $errLine = null, ?array $errContext = null):bool {
//			return true;
//		});

// The request and request handler are passed to the PSR-15 process function,
// which will return our PSR-7 HTTP Response.
		try {
			$response = $this->process($request, $handler);
		}
		catch(Throwable $throwable) {
			$ipSess = $originalGlobals["server"]["REMOTE_ADDR"] . "#" . substr(session_id(), 0, 4);
			$trace = $throwable->getTrace();
			$stopTraceAt = null;
			foreach($trace as $i => $traceItem) {
				if(str_contains($traceItem["file"] ?? "", "vendor/phpgt/")) {
					$stopTraceAt = $i;
					break;
				}

				$orderedItem = [];
				foreach(["class", "function", "type", "file", "line"] as $key) {
					if(isset($traceItem[$key])) {
						$orderedItem[$key] = $traceItem[$key];
					}
				}
				$trace[$i] = $orderedItem + $traceItem;

				if(str_starts_with($traceItem["file"] ?? "", "gt-logic-stream://")) {
					$trace[$i]["file"] = substr($traceItem["file"], strlen("gt-logic-stream://"));
				}
				if(str_starts_with($traceItem["function"], "Gt\\AppLogic\\")) {
					$trace[$i]["function"] = substr($traceItem["function"], strlen("Gt\\AppLogic\\"));
					$trace[$i]["function"] = str_replace("_php\\", ".php\\", $trace[$i]["function"]);
				}
			}
			if($stopTraceAt) {
				$trace = array_slice($trace, 0, $stopTraceAt);
			}
			
			$cwd = getcwd();
			$file = $throwable->getFile();
			if(str_starts_with($file, $cwd)) {
				$file = substr($file, strlen($cwd));
				$file = trim($file, "/");
			}

			Log::critical($throwable->getMessage(), [
				"session" => $ipSess,
				"uri" => $request->getUri(),
				"file" => $file,
				"line" => $throwable->getLine(),
				"trace" => $trace,
				"body" => file_get_contents("php://input"),
			]);

			$errorHandler = new ErrorRequestHandler(
				ConfigFactory::createForProject(
					getcwd(),
					"vendor/phpgt/webengine/config.default.ini"
				),
				$this->finish(...),
				$throwable,
				$handler->getServiceContainer(),
				$originalGlobals["get"],
				$originalGlobals["post"],
				$originalGlobals["files"],
				$originalGlobals["server"],
			);

			$response = $this->process($request, $errorHandler);
		}

// Now we can finish the HTTP lifecycle by providing the HTTP response for
// outputting to the browser, along with the buffer so we can display the
// contents in a debug area.
		$this->finish(
			$response,
			$handler->getConfigSection("app"),
		);
	}

	/**
	 * Process an incoming server request and return a response,
	 * delegating response creation to a handler.
	 */
	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler,
	):ResponseInterface {
		return $handler->handle($request);
	}

	public function debugOutput(
		string $name,
		string $message,
		mixed $detail = null,
		?string $detailName = null,
	):void {
		$detailJs = "";
		if(!is_null($detail)) {
			if(!is_null($detailName)) {
				$detailJs .= "console.group(\"$detailName\");";
			}
			$detailJs .= "console.log(`" . print_r($detail, true) . "`)";
			if(!is_null($detailName)) {
				$detailJs .= "console.groupEnd();";
			}
		}
		$js = <<<JS
			<script class="webengine-debug--$name">
			console.group("%cphp.gt/webengine", "display: inline-block; padding: 0.5em 1em; background: #26a5e3; color: white; cursor: pointer");
			console.info(`$message`);
			$detailJs
			console.groupEnd();
			</script>
			JS;
//		$js = str_replace("</script", "<\\/script", $js);
		echo $js;
	}

	public function finish(
		ResponseInterface $response,
		ConfigSection $appConfig
	):never {
		$buffer = trim(ob_get_clean());
		http_response_code($response->getStatusCode() ?? StatusCode::OK);

		foreach($response->getHeaders() as $key => $value) {
			$stringValue = implode(", ", $value);
			if(strtolower($key) === "location" && str_starts_with($stringValue, "/_")) {
				continue;
			}
			header("$key: $stringValue", true);
		}

		if(strlen($buffer) > 0) {
			$newLine = str_contains($buffer, "\n") ? "\n" : "";
			Log::debug("Logic output: {$newLine}{$buffer}");
		}

		$renderBufferSize = $appConfig->getInt("render_buffer_size");
		$body = $response->getBody();
		$body->rewind();
		ob_start();
		while(!$body->eof()) {
			echo $body->read($renderBufferSize);
			ob_flush();
			flush();
		}

		if(strlen($buffer) > 0) {
			$this->debugOutput("buffer", $buffer);
			exit;
		}

// The very last thing that's done before the script ends is to stop the Timer,
// so we know exactly how long the request-response lifecycle has taken.
		$this->timer->stop();
		$delta = number_format($this->timer->getDelta(), 2);
		if($delta >= $appConfig->getFloat("slow_delta")) {
			Log::warning("Lifecycle end with VERY SLOW delta time: $delta seconds. https://www.php.gt/webengine/slow-delta");
		}
		elseif($delta >= $appConfig->getFloat("very_slow_delta")) {
			Log::notice("Lifecycle end with SLOW delta time: $delta seconds. https://www.php.gt/webengine/slow-delta");
		}

		exit;
	}

	private function handleRedirects():void {
		$redirectFiles = [
			"\t" => "redirect.tsv",
			"," => "redirect.csv",
		];
		foreach($redirectFiles as $separatorCharacter => $fileName) {
			if(!is_file($fileName)) {
				continue;
			}

			$currentUri = $_SERVER["REQUEST_URI"];

			$lines = file($fileName);
			usort($lines, function(string $lineA, string $lineB):int {
				$lineARegex = str_starts_with($lineA, "~");
				$lineBRegex = str_starts_with($lineB, "~");
				if($lineARegex && !$lineBRegex) {
					return -1;
				}

				if(!$lineARegex && $lineBRegex) {
					return 1;
				}

				return 0;
			});

			foreach($lines as $line) {
				$row = str_getcsv($line, $separatorCharacter);
				if(!$row || !$row[0]) {
					continue;
				}

				$matchingUri = $row[0];
				$redirectUri = $row[1];
				$responseCode = $row[2] ?? 302;

				$match = $currentUri === $matchingUri;
				if($matchingUri[0] === "~") {
					$matchingUri = substr($matchingUri, 1);
					if(preg_match("~$matchingUri~", $currentUri, $matches)) {
						$match = true;
						$matchIndex = 1;
						while(str_contains($redirectUri, '$' . $matchIndex)) {
							$redirectUri = str_replace('$' . $matchIndex, $matches[$matchIndex], $redirectUri);
						}
					}
				}

				if($match) {
					Log::notice("Redirecting: $currentUri -> $redirectUri ($responseCode)");
					header("Location: $redirectUri", true, $responseCode);
					exit;
				}
			}
			return;
		}
	}

}
