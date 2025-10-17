<?php
namespace Gt\WebEngine\Redirection;

interface RedirectLoader {
	public function load(string $file, RedirectMap $map):void;
}
