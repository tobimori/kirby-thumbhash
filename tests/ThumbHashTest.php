<?php

namespace tobimori\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tobimori\Tests\Reference\Reference;
use tobimori\ThumbHash;

class ThumbHashTest extends TestCase
{
	/**
	 * @return iterable<string, array{list<int>}>
	 */
	public static function hashes(): iterable
	{
		foreach (Hashes::all() as $name => $hash) {
			yield $name => [$hash];
		}
	}

	/**
	 * @param list<int> $hash
	 */
	#[DataProvider('hashes')]
	public function testDecode(array $hash): void
	{
		$this->assertSame(Reference::decode($hash), ThumbHash::decode(Reference::toString($hash)));
		$this->assertSame(Reference::decode($hash), ThumbHash::decode($hash));
	}

	/**
	 * @param list<int> $hash
	 */
	#[DataProvider('hashes')]
	public function testUri(array $hash): void
	{
		$image = Reference::decode($hash);
		foreach ([0.0, 0.5, 1.0, 2.5, 10.0] as $blurRadius) {
			$this->assertSame(Reference::uri($image, $blurRadius), ThumbHash::uri($image, $blurRadius));
		}
		$this->assertSame(Reference::uri($image, 1.0), ThumbHash::uri($image));
	}

	public function testUriWithOtherData(): void
	{
		foreach (["data:image/svg+xml,<svg> <g>\n\t</g>  </svg>", 'https://example.com/a b%20c?d=e&f=g#h', "\u{1F600} ä ", ''] as $uri) {
			foreach ([true, false] as $transparent) {
				$image = ['uri' => $uri, 'width' => 32, 'height' => 17, 'transparent' => $transparent];
				$this->assertSame(Reference::uri($image, 1.5), ThumbHash::uri($image, 1.5));
			}
		}
	}
}
