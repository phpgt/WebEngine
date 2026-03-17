<?php
namespace GT\WebEngine\Test\Fixture;

use Attribute;

#[Attribute(Attribute::TARGET_FUNCTION)]
class TestAttribute {
	public function __construct(
		public readonly string $name,
		public readonly int $count,
	) {}
}
