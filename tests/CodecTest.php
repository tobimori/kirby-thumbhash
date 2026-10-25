<?php

namespace tobimori\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tobimori\Codec;
use tobimori\Tests\Reference\Reference;

class CodecTest extends TestCase
{
	/**
	 * @return iterable<string, array{int, int, string, string, int}>
	 */
	public static function images(): iterable
	{
		yield from Images::cases();
	}

	/**
	 * @return iterable<string, array{list<int>}>
	 */
	public static function hashes(): iterable
	{
		foreach (Hashes::all() as $name => $hash) {
			yield $name => [$hash];
		}
	}

	#[DataProvider('images')]
	public function testEncode(int $w, int $h, string $color, string $alpha, int $seed): void
	{
		$pixels = Images::create($w, $h, $color, $alpha, $seed);
		$hash = Reference::encode($w, $h, $pixels);

		// the same floating point operations in the same order give the same DCT terms, any change to the hash is unlikely to show up in the tests
		$this->assertSame(self::floats(Reference::dct($w, $h, $pixels)), self::floats(Codec::dct($w, $h, $pixels)));

		$this->assertSame($hash, Codec::encode($w, $h, $pixels));
		$this->assertSame(Reference::toString($hash), Codec::toString($hash));
	}

	/**
	 * @param list<int> $hash
	 */
	#[DataProvider('hashes')]
	public function testDecode(array $hash): void
	{
		$expected = Reference::decode($hash);
		$image = Codec::decode($hash);

		$this->assertSame(self::floats(Reference::rgba($hash)), self::floats(['w' => $image['w'], 'h' => $image['h'], 'rgba' => $image['rgba']]));
		$this->assertSame($expected['transparent'], $image['transparent']);

		$this->assertSame($expected['width'], $image['w']);
		$this->assertSame($expected['height'], $image['h']);
		$this->assertSame($expected['uri'], Codec::pngDataUrl($image['w'], $image['h'], $image['rgba']));
	}

	/**
	 * @param list<int> $hash
	 */
	#[DataProvider('hashes')]
	public function testMetadata(array $hash): void
	{
		$this->assertSame(self::floats(Reference::averageRgba($hash)), self::floats(Codec::averageRgba($hash)));
		$this->assertSame(Reference::aspectRatio($hash), Codec::aspectRatio($hash));

		$string = Reference::toString($hash);
		$this->assertSame($string, Codec::toString($hash));
		$this->assertSame(Reference::fromString($string), Codec::fromString($string));
	}

	/**
	 * Casts all numbers to floats, the types of the numbers don't change the hash
	 */
	private static function floats(mixed $value): mixed
	{
		return match (true) {
			is_array($value) => array_map(self::floats(...), $value),
			is_int($value) => (float)$value,
			default => $value
		};
	}
}
