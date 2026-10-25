<?php

namespace tobimori\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tobimori\Driver\GdDriver;
use tobimori\Driver\ImagickDriver;
use tobimori\Tests\Reference\Reference;

class DriverTest extends TestCase
{
	/**
	 * @return iterable<string, array{string}>
	 */
	public static function files(): iterable
	{
		foreach (Images::files() as $name => $content) {
			yield $name => [$content];
		}
	}

	#[DataProvider('files')]
	public function testGd(string $content): void
	{
		$this->assertSame(Reference::gdPixels($content), GdDriver::extractPixels($content));
	}

	#[DataProvider('files')]
	public function testImagick(string $content): void
	{
		if (!ImagickDriver::isAvailable()) {
			$this->markTestSkipped('Imagick is not available');
		}

		$this->assertSame(Reference::imagickPixels($content), ImagickDriver::extractPixels($content));
	}
}
