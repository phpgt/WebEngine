<?php
namespace GT\WebEngine\Redirection;

interface RedirectLoader {
	public function load(string $file, RedirectMap $map):void;
}
